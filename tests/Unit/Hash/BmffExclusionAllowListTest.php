<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-071: a BMFF hash may exclude only what c2pa-rs writes — the C2PA uuid
 * box, ftyp, mfra, free, skip, meta/iloc, mfra/tfra, and the offset fields of
 * stco, co64, tfhd and trun from offset 16 on — and nothing else, judged by
 * path. The files are tests/Fixtures/bmff-exclusion-coverage/ (steps 356 and
 * 358, re-signed under a throw-away root).
 */

function spec071Verify(string $relative, ?string $settings = 'bmff-exclusion-coverage/probe-root.settings.json'): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings)));
}

/** @return list<ValidationStatus> */
function spec071Bmff(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.bmffHash.')));
}

it('leaves c2pa-rs\'s own exclusions as they were', function (string $relative, ?string $settings, string $state): void {
    $report = spec071Verify($relative, $settings);

    expect($report->result->state->value)->toBe($state, $relative)
        ->and(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, spec071Bmff($report)))->toBe([StatusCode::AssertionBmffHashAdditionalExclusionsPresent, StatusCode::AssertionBmffHashMatch]);
})->with([
    'the v3 default, re-signed' => ['bmff-exclusion-coverage/unchanged-resigned.mp4', 'bmff-exclusion-coverage/probe-root.settings.json', 'Trusted'],
    'the v3 default, as signed' => ['fixture-signed.mp4', null, 'Valid'],
    'the v2 list with nested offset fields' => ['c2pa-rs/video1.mp4', null, 'Invalid'],   // Invalid for its own reasons (SPEC-029); the binding matches
])->group('SPEC-071');

it('reports an exclusion of the media as a mismatch, without hashing', function (string $name, string $path, ?string $settings): void {
    $report = spec071Verify("bmff-exclusion-coverage/{$name}.mp4", $settings);
    $statuses = spec071Bmff($report);

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionBmffHashMismatch)
        ->and($statuses[0]->explanation)->toContain("the exclusion {$path} is refused: a BMFF hash may exclude only the C2PA uuid box, ftyp, mfra, free, skip, meta/iloc, mfra/tfra and the offset fields of stco, co64, tfhd and trun")
        ->and($report->result->state->value)->toBe('Invalid');
    // the deliberate divergence: c2patool calls each of them Trusted
    /** @var array{validation_state: string} $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/bmff-exclusion-coverage/{$name}--0.28.2.json"), true, 512, JSON_THROW_ON_ERROR);
    expect($oracle['validation_state'])->toBe('Trusted');
})->with([
    'mdat' => ['mdat-excluded', '/mdat'],
    'mdat, a sample byte changed' => ['mdat-excluded-edited', '/mdat'],
    'moov' => ['moov-excluded', '/moov'],
    'moov, a byte changed' => ['moov-excluded-edited', '/moov'],
    'a box the file does not have' => ['zzzz-excluded', '/zzzz'],
])->with([
    'under the throw-away root' => ['bmff-exclusion-coverage/probe-root.settings.json'],
    'without trust settings' => [null],
])->group('SPEC-071');

it('refuses an offset table excluded whole or from its header', function (string $name): void {
    $statuses = spec071Bmff(spec071Verify("bmff-exclusion-coverage/{$name}.mp4"));

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionBmffHashMismatch)
        ->and($statuses[0]->explanation)->toContain('the exclusion /moov/trak/mdia/minf/stbl/stco is refused: an offset table may be excluded only with a subset whose every range starts at offset 16 or later');
})->with(['stco-excluded-whole', 'stco-excluded-from-8'])->group('SPEC-071');

/** @param array<string, mixed> $exclusion */
function spec071Allowed(array $exclusion): bool
{
    return BmffHashCheck::refusedExclusion($exclusion) === null;
}

it('names exactly what c2pa-rs writes as allowed, and nothing more', function (array $exclusion, bool $allowed): void {
    /** @var array<string, mixed> $exclusion */
    expect(spec071Allowed($exclusion))->toBe($allowed);
})->with([
    'the C2PA uuid box' => [['xpath' => '/uuid', 'data' => [['offset' => 8, 'value' => new CborBytes(IsobmffManifestStoreExtractor::C2PA_UUID)]]], true],
    'another uuid box' => [['xpath' => '/uuid', 'data' => [['offset' => 8, 'value' => new CborBytes(str_repeat("\1", 16))]]], false],
    'a uuid box without a data filter' => [['xpath' => '/uuid'], false],
    'ftyp' => [['xpath' => '/ftyp'], true],
    'mfra' => [['xpath' => '/mfra'], true],
    'free with a subset' => [['xpath' => '/free', 'subset' => [['offset' => 0, 'length' => 4]]], true],
    'skip' => [['xpath' => '/skip'], true],
    'meta/iloc' => [['xpath' => '/meta/iloc'], true],
    'mfra/tfra' => [['xpath' => '/mfra/tfra'], true],
    'stco from 16, as c2pa-rs writes it' => [['xpath' => '/moov/trak/mdia/minf/stbl/stco', 'subset' => [['offset' => 16, 'length' => 0]]], true],
    'co64 from 16' => [['xpath' => '/moov/trak/mdia/minf/stbl/co64', 'subset' => [['offset' => 16, 'length' => 0]]], true],
    'tfhd 16+8, as c2pa-rs writes it' => [['xpath' => '/moof/traf/tfhd', 'subset' => [['offset' => 16, 'length' => 8]]], true],
    'trun 16+4' => [['xpath' => '/moof/traf/trun', 'subset' => [['offset' => 16, 'length' => 4]]], true],
    'trun with a second range from 12' => [['xpath' => '/moof/traf/trun', 'subset' => [['offset' => 16, 'length' => 4], ['offset' => 12, 'length' => 4]]], false],
    'tfhd with an empty subset' => [['xpath' => '/moof/traf/tfhd', 'subset' => []], false],
    'mdat' => [['xpath' => '/mdat'], false],
    'moov' => [['xpath' => '/moov'], false],
    'a track' => [['xpath' => '/moov/trak'], false],
    'Pixel\'s indexed track (out of scope: fail closed)' => [['xpath' => '/moov[1]/trak[3]'], false],
])->group('SPEC-071');
