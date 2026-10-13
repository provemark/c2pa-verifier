<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-072: Pixel Camera video — the QuickTime-style meta box, indexed xpath
 * steps, and Pixel's exclusions on the allow-list (a track only when its
 * handler is timed metadata). The real Pixel file has no licence and stays
 * outside the repository (AC1, behind C2PA_PIXEL_VIDEO); CI rests on the
 * synthetic fixtures under tests/Fixtures/pixel-shape/ (step 360), whose
 * binding c2patool 0.28.2 confirms.
 */

function spec072Verify(string $path, ?string $settings): VerificationReport
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return (new Verifier)->verify($stream, $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings)));
}

/** @return list<ValidationStatus> */
function spec072Bmff(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.bmffHash.')));
}

/** @return resource */
function spec072Memory(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

function spec072Box(string $type, string $body): string
{
    return pack('N', 8 + strlen($body)).$type.$body;
}

it('verifies the Pixel Camera video\'s binding, as c2patool does (local only)', function (): void {
    $path = (string) getenv('C2PA_PIXEL_VIDEO');
    $report = spec072Verify($path, 'trust/google-pixel-intermediates.settings.json');
    $statuses = spec072Bmff($report);

    expect(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))->toBe([StatusCode::AssertionBmffHashAdditionalExclusionsPresent, StatusCode::AssertionBmffHashMatch])
        ->and($statuses[0]->explanation)->toContain('these boxes are not covered by the signature')
        ->and($report->result->state->value)->toBe('Valid');   // c2patool 0.28.2 under these settings: Valid, signingCredential.untrusted
})->skip(fn (): bool => ! is_file((string) getenv('C2PA_PIXEL_VIDEO')), 'the Pixel file has no licence and is not in the repository; set C2PA_PIXEL_VIDEO to its path (sha256 2f1c2bd8…, c2pa-rs issue #2373)')->group('SPEC-072');

it('verifies a file of the Pixel shape, and c2patool agrees', function (): void {
    $report = spec072Verify(Corpus::fixtures().'/pixel-shape/pixel-shape.mp4', 'pixel-shape/probe-root.settings.json');
    $statuses = spec072Bmff($report);

    expect(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))->toBe([StatusCode::AssertionBmffHashAdditionalExclusionsPresent, StatusCode::AssertionBmffHashMatch])
        ->and($statuses[0]->explanation)->toContain('these boxes are not covered by the signature: what they hold (user data, metadata, a timed-metadata track, such as a location or a capture time) is not verified')
        ->and($report->result->state->value)->toBe('Trusted');
    /** @var array{validation_state: string, validation_results: array{activeManifest: array{success: list<array{code: string}>}}} $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/pixel-shape/pixel-shape--0.28.2.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($oracle['validation_state'])->toBe('Trusted')
        ->and(array_column($oracle['validation_results']['activeManifest']['success'], 'code'))->toContain('assertion.bmffHash.match');
})->group('SPEC-072');

it('refuses a track excluded by index unless its handler is timed metadata', function (string $name, string $path, string $found): void {
    $statuses = spec072Bmff(spec072Verify(Corpus::fixtures()."/pixel-shape/{$name}.mp4", 'pixel-shape/probe-root.settings.json'));

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionBmffHashMismatch)
        ->and($statuses[0]->explanation)->toContain("the exclusion {$path} is refused: a track may be excluded only when its handler is meta (timed metadata); {$found}");
})->with([
    'the video track' => ['pixel-shape-video-track', '/moov[1]/trak[1]', 'this one\'s is vide'],
    'a track the file does not have' => ['pixel-shape-no-track', '/moov[1]/trak[9]', 'it resolves to no track'],
])->group('SPEC-072');

it('refuses a malformed index as malformed', function (string $xpath): void {
    expect(BmffHashCheck::shapeFault(['exclusions' => [['xpath' => $xpath]], 'hash' => 'x']))->toContain("the xpath {$xpath} has a malformed step");
})->with(['/moov[0]/trak', '/moov[1]/trak[-1]', '/moov[a]', '/moov[1', '/moov[]', '/moov[1][2]'])->group('SPEC-072');

it('accepts the indexed and unindexed forms the allow-list names', function (): void {
    expect(BmffHashCheck::shapeFault(['exclusions' => [['xpath' => '/moov[1]/trak[3]'], ['xpath' => '/moov/trak/mdia/minf/stbl/stco', 'subset' => [['offset' => 16, 'length' => 0]]]], 'hash' => 'x']))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/moov[1]/trak[3]']))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/moov/udta']))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/moov/meta']))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/moov/free']))->toBeNull()
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/moov/trak[3]']))->not->toBeNull()
        ->and(BmffHashCheck::refusedExclusion(['xpath' => '/moov[1]/trak[3]/mdia']))->not->toBeNull();
})->group('SPEC-072');

it('walks both forms of the meta box', function (): void {
    $hdlr = spec072Box('hdlr', str_repeat("\0", 8).'mdta'.str_repeat("\0", 13));
    $keys = spec072Box('keys', "\0\0\0\0\0\0\0\0");
    $quicktime = spec072Box('moov', spec072Box('meta', $hdlr.$keys));
    $iso = spec072Box('moov', spec072Box('meta', "\0\0\0\0".$hdlr.$keys));
    $paths = static fn (string $file): array => array_map(static fn (array $b): string => $b['path'].'@'.$b['offset'], (new IsobmffManifestStoreExtractor)->boxTree(spec072Memory($file)));

    expect($paths($quicktime))->toBe(['/moov@0', '/moov/meta@8', '/moov/meta/hdlr@16', '/moov/meta/keys@'.(16 + strlen($hdlr))])
        ->and($paths($iso))->toBe(['/moov@0', '/moov/meta@8', '/moov/meta/hdlr@20', '/moov/meta/keys@'.(20 + strlen($hdlr))]);
    // neither form (no hdlr at 12 or 16): read as ISO, as before — here a free box after the four bytes
    $neither = spec072Box('moov', spec072Box('meta', "\0\0\0\0".spec072Box('free', 'abcd')));
    expect($paths($neither))->toBe(['/moov@0', '/moov/meta@8', '/moov/meta/free@20']);
})->group('SPEC-072');
