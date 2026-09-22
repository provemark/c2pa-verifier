<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Hash\HashException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-029: `c2pa.hash.bmff.v2`. Step 86 measured it by instrumenting c2pa-rs
 * on this repository's `c2pa-rs/video1.mp4`, and the finding is that v2 shares
 * v3's digest exactly — what differs is the exclusion list, and v2's is the
 * precise one: six of its eight paths are nested, four carry a `subset`.
 *
 * The ranges asserted below are not constructed. They are what the instrumented
 * c2pa-rs printed for this file, copied:
 *
 *   marker 30686 / 30686..31736 / 31761..33309 / 33334..33391
 *   marker 33392 / 33392..37953
 *   marker 37954 / 37954..92141
 *   marker 92142 / 92142..828570
 *
 * Four markers for the four included top-level boxes; the two gaps inside
 * `moov` are the two 40-byte `stco` boxes, excluded from offset 16 to their end
 * by `subset: [{offset: 16, length: 0}]`.
 */

function spec029File(): string
{
    return Corpus::fixtures().'/c2pa-rs/video1.mp4';
}

/** @return resource */
function spec029Stream(?string $bytes = null)
{
    if ($bytes === null) {
        $stream = fopen(spec029File(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('cannot open video1.mp4');
        }

        return $stream;
    }
    // AC2: the file is 828 kB, and a copy of it to change one byte would be
    // 828 kB more in every clone. A stream is a stream.
    $memory = fopen('php://memory', 'r+b');
    if ($memory === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($memory, $bytes);
    rewind($memory);

    return $memory;
}

/**
 * The plan the digest follows: each range, and whether a marker precedes it.
 *
 * @return list<array{offset: int, length: int, marker: bool}>
 */
function spec029Plan(): array
{
    $extractor = new IsobmffManifestStoreExtractor;
    $stream = spec029Stream();
    $tree = $extractor->boxTree($stream);
    $exclusions = spec029Exclusions();

    return BmffHashCheck::plan($tree, $exclusions, function (int $at, int $length) use ($stream): string {
        if ($length < 1 || fseek($stream, $at) !== 0) {
            return '';
        }
        $bytes = fread($stream, $length);

        return $bytes === false ? '' : $bytes;
    });
}

/** @return list<array<string, mixed>> the file's own eight exclusions */
function spec029Exclusions(): array
{
    $stream = spec029Stream();
    $bytes = (new IsobmffManifestStoreExtractor)->extract($stream);
    if ($bytes === null) {
        throw new RuntimeException('no store in video1.mp4');
    }
    $store = ManifestStore::fromTree(
        (new JumbfParser)->parse($bytes->bytes),
    );
    $data = $store->active->assertions['c2pa.hash.bmff.v2']->data;
    assert(is_array($data) && is_array($data['exclusions']));

    /** @var list<array<string, mixed>> */
    return $data['exclusions'];
}

function spec029Verify(?string $bytes = null): VerificationReport
{
    // the C2PA test anchors plus the cross-certificate that signs this file's two
    // DigiCert timestamps. Both sides need it: c2patool falls back to the operating
    // system's trust store for a responder (step 40 §5) and this verifier never
    // does, so without the anchor the two judge the 2022 signers at different
    // moments and the comparison is not between equals (SPEC-029 amendment 1).
    $settings = TrustSettings::fromJson(
        (string) file_get_contents(Corpus::fixtures().'/trust/full-plus-digicert-g4.settings.json'),
    );

    return (new Verifier)->verify(spec029Stream($bytes), $settings);
}

/** @return list<string> */
function spec029Codes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
}

it('AC1: video1.mp4 verifies, and its failures are c2patool\'s', function (): void {
    $report = spec029Verify();

    /** @var array<string, mixed> $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/timestamp/video1-full-plus-digicert-g4.json'), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $results */
    $results = (array) ($oracle['validation_results'] ?? []);

    // the active manifest and its ingredient deltas together: c2patool keeps the
    // ingredient's statuses in a list of their own, this verifier reports one list
    $scopes = [(array) ($results['activeManifest'] ?? [])];
    foreach ((array) ($results['ingredientDeltas'] ?? []) as $delta) {
        assert(is_array($delta));
        $scopes[] = (array) ($delta['validationDeltas'] ?? []);
    }
    $theirs = [];
    foreach ($scopes as $scope) {
        foreach ((array) ($scope['failure'] ?? []) as $status) {
            assert(is_array($status) && is_string($status['code']));
            $theirs[$status['code']] = true;
        }
    }
    $ours = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $ours[$status->code->value] = true;
        }
    }
    ksort($theirs);
    ksort($ours);

    expect($report->result->state)->toBe(ValidationState::Valid)
        ->and(spec029Codes($report))->toContain(StatusCode::AssertionBmffHashMatch->value)
        ->and(array_keys($ours))->toBe(array_keys($theirs))
        // the binding, the timestamp and the ingredient hash all pass; what fails is
        // the ingredient's own chain, which ends at an intermediate nothing signs
        ->and(array_keys($ours))->toBe(['signingCredential.untrusted']);
})->group('SPEC-029');

it('AC2: a changed byte in a hashed region is a mismatch', function (): void {
    $bytes = (string) file_get_contents(spec029File());
    $bytes[92142 + 1000] = chr(ord($bytes[92142 + 1000]) ^ 0xFF);   // inside mdat

    expect(spec029Codes(spec029Verify($bytes)))->toContain(StatusCode::AssertionBmffHashMismatch->value);
})->group('SPEC-029');

it('AC3: the nested paths resolve to exactly the ranges c2pa-rs hashes', function (): void {
    expect(spec029Plan())->toBe([
        ['offset' => 30686, 'length' => 1051, 'marker' => true],    // moov, to the first stco subset
        ['offset' => 31761, 'length' => 1549, 'marker' => false],   // after it — no new box, no marker
        ['offset' => 33334, 'length' => 58, 'marker' => false],     // after the second
        ['offset' => 33392, 'length' => 4562, 'marker' => true],    // the other uuid box
        ['offset' => 37954, 'length' => 54188, 'marker' => true],   // free, hashed under v2
        ['offset' => 92142, 'length' => 736429, 'marker' => true],  // mdat, to the end
    ]);
})->group('SPEC-029');

it('AC4: subset narrows, and length 0 runs to the end of the box', function (): void {
    // the arithmetic, on a box of its own: AC3 covers the nesting, with the real
    // file and the ranges the instrumented c2pa-rs printed
    $box = [['offset' => 100, 'length' => 40, 'type' => 'stco', 'path' => '/stco']];
    $read = static fn (int $at, int $length): string => str_repeat("\0", max($length, 0));

    // length 0: offset 16 to the end, so 24 bytes gone and 16 kept
    expect(BmffHashCheck::plan($box, [['xpath' => '/stco', 'subset' => [['offset' => 16, 'length' => 0]]]], $read))
        ->toBe([['offset' => 100, 'length' => 16, 'marker' => true]]);

    // an explicit length takes exactly that many, clipped to the box
    expect(BmffHashCheck::plan($box, [['xpath' => '/stco', 'subset' => [['offset' => 16, 'length' => 8]]]], $read))
        ->toBe([
            ['offset' => 100, 'length' => 16, 'marker' => true],
            ['offset' => 124, 'length' => 16, 'marker' => false],
        ]);
})->group('SPEC-029');

it('AC5: the data filter tells two uuid boxes apart', function (): void {
    $plan = spec029Plan();
    $offsets = array_column($plan, 'offset');

    // the C2PA box at 24 is excluded; the other uuid box at 33392 is hashed
    expect($offsets)->not->toContain(24)
        ->and($offsets)->toContain(33392);
})->group('SPEC-029');

it('AC6: flags is refused when the path exists, and ignored when it cannot match', function (): void {
    // video1.mp4 carries two flags exclusions on /moof paths, and a non-fragmented
    // file has no moof — so reading the list must not refuse this file (AC1 proves
    // it does not). Refusing has to come from resolving a path that is there.
    $box = [['offset' => 0, 'length' => 16, 'type' => 'tfhd', 'path' => '/tfhd']];
    $read = static fn (int $at, int $length): string => str_repeat("\0", max($length, 0));

    expect(fn () => BmffHashCheck::plan($box, [['xpath' => '/tfhd', 'flags' => "\x01\x00\x00"]], $read))
        ->toThrow(HashException::class);

    // and a box nested deeper than the bound is refused, never read short
    expect(IsobmffManifestStoreExtractor::DEFAULT_MAX_BOX_DEPTH)->toBe(8);
})->group('SPEC-029');

it('AC7: every v3 fixture answers exactly as it did', function (): void {
    foreach (['fixture-signed.mp4', 'fixture-signed.avif', 'fixture-signed.mov', 'fixture-signed.heic'] as $file) {
        $stream = fopen(Corpus::fixtures().'/'.$file, 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$file}");
        }
        $settings = TrustSettings::fromJson(
            (string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json'),
        );
        $report = (new Verifier)->verify($stream, $settings);

        // not toContain($needle, $file): Pest reads the second argument as another
        // needle, not a message — the eleventh time in this project
        expect($report->result->state)->toBe(ValidationState::Trusted, $file)
            ->and(in_array(StatusCode::AssertionBmffHashMatch->value, spec029Codes($report), true))->toBeTrue($file);
    }
})->group('SPEC-029');
