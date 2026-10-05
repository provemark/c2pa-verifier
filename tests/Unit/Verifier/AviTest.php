<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\AviManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-058: AVI — the RIFF walk with the form `AVI `. The fixture and the
 * variants were measured against c2patool 0.27.22 and 0.28.1 in step 209
 * (tests/Fixtures/avi/README.md); the recordings are under
 * tests/Fixtures/c2patool/avi/ (step 240).
 */

function spec058File(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$relative);
}

/** @return resource */
function spec058Stream(string $bytes)
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
function spec058Report(VerificationReport $report): array
{
    /** @var array<string, mixed> */
    return $report->toArray();
}

/**
 * @param  array<string, mixed>  $tree
 * @return list<string>
 */
function spec058Codes(array $tree, string $kind): array
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
    $store = (new AviManifestStoreExtractor)->extract(spec058Stream(spec058File('fixture-signed.avi')));

    expect($store)->toBeInstanceOf(ManifestStoreBytes::class);
    assert($store instanceof ManifestStoreBytes);
    expect(strlen($store->bytes))->toBe(13463)
        ->and(hash('sha256', $store->bytes))->toBe('83b33b30ffa81b3c0668b8383fee7a553af4cd34af049e3f090fbd6359ffb287')
        ->and(bin2hex(substr($store->bytes, 0, 8)))->toBe('000034976a756d62')
        ->and($store->ranges)->toBe([['start' => 11700, 'length' => 13471]]);
})->group('SPEC-058');

it('AC2: no C2PA chunk in the first RIFF chunk is no manifest', function (string $file): void {
    $bytes = spec058File($file);
    $report = (new Verifier)->verify(spec058Stream($bytes));

    expect((new AviManifestStoreExtractor)->extract(spec058Stream($bytes)))->toBeNull()
        ->and($report->format)->toBe('avi')
        ->and($report->hasManifest)->toBeFalse()
        ->and(spec058Codes(spec058Report($report), 'failure'))->toBe([]);
})->with(['fixture-unsigned.avi', 'avi/unsigned-avix.avi', 'avi/c2pa-in-movi.avi', 'avi/c2pa-only-in-avix.avi'])->group('SPEC-058');

it('AC3: a file of several RIFF chunks verifies as c2patool says', function (string $recorded): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec058File("c2patool/avi/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $report = (new Verifier)->verify(spec058Stream(spec058File('avi/signed-avix.avi')));
    $ours = spec058Report($report);

    expect($report->format)->toBe('avi')
        ->and($ours['validation_state'])->toBe('Valid')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec058Codes($ours, 'success'))->toBe(spec058Codes($oracle, 'success'))
        ->and(spec058Codes($ours, 'failure'))->toBe(spec058Codes($oracle, 'failure'));
})->with(['0.27.22' => ['signed-avix'], '0.28.1' => ['signed-avix.0.28.1']])->group('SPEC-058');

it('AC3: a change after the first RIFF chunk fails the data hash, as c2patool says', function (string $variant): void {
    $report = spec058Report((new Verifier)->verify(spec058Stream(spec058File("avi/{$variant}.avi"))));

    expect($report['format'])->toBe('avi')
        ->and($report['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($report, 'success'))->toContain('claimSignature.validated')
        ->and(spec058Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->with(['avix-byte-flipped', 'avix-truncated', 'avix-size-plus-one', 'avix-trailing-bytes', 'second-form-avi', 'avix-with-c2pa'])->group('SPEC-058');

it('AC4: the rules SPEC-003 and SPEC-055 already hold', function (string $variant): void {
    $report = (new Verifier)->verify(spec058Stream(spec058File("avi/{$variant}.avi")));

    expect($report->format)->toBe('avi')
        ->and($report->hasManifest)->toBeTrue()
        ->and(array_map(static fn ($s): string => $s->code->value, $report->result->statuses))->toBe(['general.error']);
})->with(['two-c2pa', 'lbox-differs', 'length-differs', 'pad-nonzero', 'riff-size-plus-one', 'truncated-in-c2pa'])->group('SPEC-058');

it('AC4: a C2PA chunk not last, and bytes after the RIFF chunk, are judged by the data hash', function (string $variant): void {
    $report = spec058Report((new Verifier)->verify(spec058Stream(spec058File("avi/{$variant}.avi"))));

    expect($report['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($report, 'success'))->toContain('claimSignature.validated')
        ->and(spec058Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->with(['c2pa-before-idx1', 'trailing-bytes'])->group('SPEC-058');

it('AC5: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec058File("c2patool/avi/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $settings = $trusted ? TrustSettings::fromJson(spec058File('trust/full.settings.json')) : null;
    $report = (new Verifier)->verify(spec058Stream(spec058File('fixture-signed.avi')), $settings);
    $ours = spec058Report($report);

    expect($report->format)->toBe('avi')
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec058Codes($ours, 'success'))->toBe(spec058Codes($oracle, 'success'))
        ->and(spec058Codes($ours, 'failure'))->toBe(spec058Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['fixture-signed', false],
    '0.27.22 trusted' => ['fixture-signed.trusted', true],
    '0.28.1' => ['fixture-signed.0.28.1', false],
    '0.28.1 trusted' => ['fixture-signed.0.28.1.trusted', true],
])->group('SPEC-058');

it('AC5: detection, a flipped movi byte, the message and its article', function (): void {
    $detector = new FormatDetector;
    $bytes = spec058File('fixture-signed.avi');
    $bytes[6000] = chr(ord($bytes[6000]) ^ 1);   // inside LIST movi (5742–11531)
    $flipped = spec058Report((new Verifier)->verify(spec058Stream($bytes)));
    $unknown = (new Verifier)->verify(spec058Stream("RIFF\x04\x00\x00\x00XXXX"));
    try {
        (new AviManifestStoreExtractor)->extract(spec058Stream(spec058File('avi/two-c2pa.avi')));
        $two = '';
    } catch (ContainerException $e) {
        $two = $e->getMessage();
    }

    expect($detector->detect(spec058Stream(spec058File('fixture-signed.avi'))))->toBe('avi')
        ->and($detector->detect(spec058Stream(spec058File('fixture-signed.wav'))))->toBe('wav')
        ->and($detector->detect(spec058Stream(spec058File('fixture-signed.webp'))))->toBe('webp')
        ->and($flipped['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($flipped, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and($unknown->result->statuses[0]->explanation)->toContain('AVI')
        ->and($two)->toContain('an AVI carries at most one manifest store');
})->group('SPEC-058');
