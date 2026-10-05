<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\Id3ManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-057: FLAC — the C2PA ID3 tag in front of the stream, read by
 * SPEC-056's extractor. The fixture and the variants were measured against
 * c2patool 0.27.22 and 0.28.1 in step 234 (tests/Fixtures/flac/README.md);
 * the recordings are under tests/Fixtures/c2patool/flac/.
 */

function spec057File(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$relative);
}

/** @return resource */
function spec057Stream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** @return array<string, mixed> */
function spec057Report(VerificationReport $report): array
{
    /** @var array<string, mixed> */
    return $report->toArray();
}

/**
 * @param  array<string, mixed>  $tree
 * @return list<string>
 */
function spec057Codes(array $tree, string $kind): array
{
    $results = $tree['validation_results'] ?? [];
    assert(is_array($results));
    $active = $results['activeManifest'] ?? [];
    assert(is_array($active));
    $entries = $active[$kind] ?? [];
    assert(is_array($entries));
    $codes = [];
    foreach ($entries as $entry) {
        assert(is_array($entry) && is_string($entry['code']));
        $codes[] = $entry['code'];
    }
    sort($codes);

    return $codes;
}

it('AC1: the fixture yields the store, byte-exact, with its range', function (): void {
    $store = (new Id3ManifestStoreExtractor)->extract(spec057Stream(spec057File('fixture-signed.flac')));

    expect($store)->toBeInstanceOf(ManifestStoreBytes::class);
    assert($store instanceof ManifestStoreBytes);
    expect(strlen($store->bytes))->toBe(13463)
        ->and(hash('sha256', $store->bytes))->toBe('044f6afdd2ba674bdcad9e66bdf89db3bba518108c2eda0d30206a7fb130a5a5')
        ->and(bin2hex(substr($store->bytes, 0, 8)))->toBe('000034976a756d62')
        ->and($store->ranges)->toBe([['start' => 63, 'length' => 13463]]);
})->group('SPEC-057');

it('AC2: a FLAC without a tag has no manifest', function (string $file): void {
    $bytes = spec057File($file);
    $report = (new Verifier)->verify(spec057Stream($bytes));

    expect((new Id3ManifestStoreExtractor)->extract(spec057Stream($bytes)))->toBeNull()
        ->and($report->format)->toBe('flac')
        ->and($report->hasManifest)->toBeFalse()
        ->and(spec057Codes(spec057Report($report), 'failure'))->toBe([]);
})->with(['fixture-unsigned.flac', 'flac/tag-at-end.flac'])->group('SPEC-057');

it('AC3: a tag merged with the source\'s own is read, and verifies as c2patool says', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec057File("c2patool/flac/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $settings = $trusted ? TrustSettings::fromJson(spec057File('trust/full.settings.json')) : null;
    $report = (new Verifier)->verify(spec057Stream(spec057File('flac/signed-with-id3.flac')), $settings);
    $ours = spec057Report($report);

    expect($report->format)->toBe('flac')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and(spec057Codes($ours, 'success'))->toBe(spec057Codes($oracle, 'success'))
        ->and(spec057Codes($ours, 'failure'))->toBe(spec057Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['signed-with-id3', false],
    '0.27.22 trusted' => ['signed-with-id3.trusted', true],
    '0.28.1' => ['signed-with-id3.0.28.1', false],
    '0.28.1 trusted' => ['signed-with-id3.0.28.1.trusted', true],
])->group('SPEC-057');

it('AC4: flac, mp3, or nothing guessed', function (): void {
    $detector = new FormatDetector;
    $detect = static fn (string $file): ?string => $detector->detect(spec057Stream(spec057File($file)));
    $unknown = (new Verifier)->verify(spec057Stream(spec057File('flac/marker-damaged.flac')));

    expect($detect('fixture-signed.flac'))->toBe('flac')
        ->and($detect('fixture-unsigned.flac'))->toBe('flac')
        ->and($detect('flac/zeros-after-tag.flac'))->toBe('flac')
        ->and($detect('flac/marker-damaged.flac'))->toBeNull()
        ->and($detect('flac/tag-then-other.flac'))->toBeNull()
        ->and($detect('fixture-signed.mp3'))->toBe('mp3')
        ->and($unknown->result->statuses[0]->explanation)->toContain('FLAC');
})->group('SPEC-057');

it('AC4: zero bytes after the tag are read and judged by the data hash, as c2patool judges them', function (): void {
    $report = spec057Report((new Verifier)->verify(spec057Stream(spec057File('flac/zeros-after-tag.flac'))));

    expect($report['format'])->toBe('flac')
        ->and($report['validation_state'])->toBe('Invalid')
        ->and(spec057Codes($report, 'success'))->toContain('claimSignature.validated')
        ->and(spec057Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->group('SPEC-057');

it('AC5: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec057File("c2patool/flac/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $settings = $trusted ? TrustSettings::fromJson(spec057File('trust/full.settings.json')) : null;
    $report = (new Verifier)->verify(spec057Stream(spec057File('fixture-signed.flac')), $settings);
    $ours = spec057Report($report);

    expect($report->format)->toBe('flac')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec057Codes($ours, 'success'))->toBe(spec057Codes($oracle, 'success'))
        ->and(spec057Codes($ours, 'failure'))->toBe(spec057Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['fixture-signed', false],
    '0.27.22 trusted' => ['fixture-signed.trusted', true],
    '0.28.1' => ['fixture-signed.0.28.1', false],
    '0.28.1 trusted' => ['fixture-signed.0.28.1.trusted', true],
])->group('SPEC-057');

it('AC5: one byte of the FLAC stream flipped is assertion.dataHash.mismatch', function (): void {
    $bytes = spec057File('fixture-signed.flac');
    $bytes[20000] = chr(ord($bytes[20000]) ^ 1);   // inside the stream, which starts at 13526
    $report = spec057Report((new Verifier)->verify(spec057Stream($bytes)));

    expect($report['validation_state'])->toBe('Invalid')
        ->and(spec057Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->group('SPEC-057');

it('AC6: SPEC-056\'s rules hold for FLAC', function (string $case): void {
    $bytes = spec057File('fixture-signed.flac');
    if ($case === 'grouping flag') {
        $bytes[19] = "\x40";   // the GEOB frame at 10: its second flags byte
    } else {
        $bytes = substr_replace($bytes, pack('N', 13464), 63, 4);   // the store's LBox +1
    }
    $report = (new Verifier)->verify(spec057Stream($bytes));

    expect($report->format)->toBe('flac')
        ->and($report->hasManifest)->toBeTrue()
        ->and(array_map(static fn ($s): string => $s->code->value, $report->result->statuses))->toBe(['general.error']);
})->with(['grouping flag', 'LBox +1'])->group('SPEC-057');
