<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-055: WAV through the verifier — detection, the verdicts, and the
 * signed fixture against c2patool 0.27.22 and 0.28.1, whose answers are
 * recorded under tests/Fixtures/c2patool/wav/ (step 207). The extractor's
 * criteria are in tests/Unit/Container/WavManifestStoreExtractorTest.php.
 */

/** @return resource */
function spec055VerifierStream(string $relative)
{
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

/** @return resource */
function spec055MemoryStream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

function spec055Settings(): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/trust/full.settings.json'));
}

/**
 * The active manifest's codes of one kind, sorted, from a report or a c2patool JSON — the same shape for both.
 *
 * @param  array<string, mixed>  $tree
 * @return list<string>
 */
function spec055Codes(array $tree, string $kind): array
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

/** @return array<string, mixed> */
function spec055Report(VerificationReport $report): array
{
    /** @var array<string, mixed> */
    return $report->toArray();
}

it('AC7: a C2PA that is not the last chunk is judged by the data hash', function (string $variant): void {
    $report = spec055Report((new Verifier)->verify(spec055VerifierStream("wav/{$variant}.wav")));

    expect($report['validation_state'])->toBe('Invalid')
        ->and(spec055Codes($report, 'success'))->toContain('claimSignature.validated')
        ->and(spec055Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->with(['c2pa-before-data', 'c2pa-first', 'chunk-after-c2pa'])->group('SPEC-055');

it('AC14: RIFF with form WAVE is detected as wav, and nothing else is guessed', function (): void {
    $detector = new FormatDetector;

    expect($detector->detect(spec055VerifierStream('fixture-signed.wav')))->toBe('wav')
        ->and($detector->detect(spec055VerifierStream('fixture-signed.webp')))->toBe('webp')
        ->and($detector->detect(spec055VerifierStream('wav/riff-form-xxxx.wav')))->toBeNull()
        ->and($detector->detect(spec055VerifierStream('wav/rf64.wav')))->toBeNull();
})->group('SPEC-055');

it('AC14: another RIFF form and RF64 stay unknown, an error naming the bytes, nothing read past them', function (string $variant, string $hex): void {
    $stream = spec055VerifierStream("wav/{$variant}.wav");
    $report = (new Verifier)->verify($stream);

    expect($report->format)->toBe('unknown')
        ->and($report->hasManifest)->toBeFalse()
        ->and($report->result->state)->toBe(ValidationState::Invalid)
        ->and(array_map(static fn ($s): string => $s->code->value, $report->result->statuses))->toBe(['general.error'])
        ->and($report->result->statuses[0]->explanation)->toContain($hex)
        ->and($report->result->statuses[0]->explanation)->toContain('WAV')
        ->and(ftell($stream))->toBe(0);
})->with([
    'form XXXX' => ['riff-form-xxxx', '58 58 58 58'],
    'RF64' => ['rf64', '52 46 36 34'],
])->group('SPEC-055');

it('AC15: a C2PA inside the LIST chunk is no manifest, and no failure', function (): void {
    $report = (new Verifier)->verify(spec055VerifierStream('wav/c2pa-in-list.wav'));

    expect($report->format)->toBe('wav')
        ->and($report->hasManifest)->toBeFalse()
        ->and(spec055Codes(spec055Report($report), 'failure'))->toBe([]);
})->group('SPEC-055');

it('AC16: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say, without and with trust settings', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/c2patool/wav/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $report = (new Verifier)->verify(spec055VerifierStream('fixture-signed.wav'), $trusted ? spec055Settings() : null);
    $ours = spec055Report($report);

    expect($report->format)->toBe('wav')
        ->and($report->hasManifest)->toBeTrue()
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and(spec055Codes($ours, 'success'))->toBe(spec055Codes($oracle, 'success'))
        ->and(spec055Codes($ours, 'failure'))->toBe(spec055Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['fixture-signed', false],
    '0.27.22 trusted' => ['fixture-signed.trusted', true],
    '0.28.1' => ['fixture-signed.0.28.1', false],
    '0.28.1 trusted' => ['fixture-signed.0.28.1.trusted', true],
])->group('SPEC-055');

it('AC16: one byte of the audio data flipped is assertion.dataHash.mismatch', function (): void {
    $bytes = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/fixture-signed.wav');
    $bytes[100] = chr(ord($bytes[100]) ^ 1);   // inside the data chunk (data from offset 78)

    $report = spec055Report((new Verifier)->verify(spec055MemoryStream($bytes)));

    expect($report['validation_state'])->toBe('Invalid')
        ->and(spec055Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->group('SPEC-055');

it('AC17: the WebP whose form type says WAVE is read as a WAV and fails its data hash, as c2patool says', function (): void {
    $report = (new Verifier)->verify(spec055VerifierStream('webp/riff-not-webp.webp'));
    $tree = spec055Report($report);

    expect($report->format)->toBe('wav')
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec055Codes($tree, 'success'))->toContain('claimSignature.validated')
        ->and(spec055Codes($tree, 'failure'))->toContain('assertion.dataHash.mismatch');
})->group('SPEC-055');

it('AC18: the signed WAV of another writer verifies as c2patool 0.27.22 and 0.28.1 say, without and with the test roots', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/c2patool/wav-writers/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $settings = $trusted ? TrustSettings::fromJson((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/matrix/test-roots.settings.json')) : null;
    $report = (new Verifier)->verify(spec055VerifierStream('wav-writers/c2pa-python-sample1_signed.wav'), $settings);
    $ours = spec055Report($report);

    expect($report->format)->toBe('wav')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and(spec055Codes($ours, 'success'))->toBe(spec055Codes($oracle, 'success'))
        ->and(spec055Codes($ours, 'failure'))->toBe(spec055Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['c2pa-python-sample1_signed', false],
    '0.27.22 trusted' => ['c2pa-python-sample1_signed.trusted', true],
    '0.28.1' => ['c2pa-python-sample1_signed.0.28.1', false],
    '0.28.1 trusted' => ['c2pa-python-sample1_signed.0.28.1.trusted', true],
])->group('SPEC-055');

it('AC18: the unsigned WAV and the nested-LIST bomb of c2pa-rs are WAVs with no manifest and no failure', function (string $file): void {
    $report = (new Verifier)->verify(spec055VerifierStream("wav-writers/{$file}.wav"));

    expect($report->format)->toBe('wav')
        ->and($report->hasManifest)->toBeFalse()
        ->and(spec055Codes(spec055Report($report), 'failure'))->toBe([]);
})->with(['c2pa-rs-sample1', 'c2pa-rs-riff_bomb_1000'])->group('SPEC-055');

it('AC18: the c2pa-rs WAV whose RIFF size exceeds the file is one general.error naming both sizes', function (): void {
    $report = (new Verifier)->verify(spec055VerifierStream('wav-writers/c2pa-rs-sample3.invalid.wav'));

    expect($report->format)->toBe('wav')
        ->and($report->result->state)->toBe(ValidationState::Invalid)
        ->and(array_map(static fn ($s): string => $s->code->value, $report->result->statuses))->toBe(['general.error'])
        ->and($report->result->statuses[0]->explanation)->toContain('1441174')
        ->and($report->result->statuses[0]->explanation)->toContain('441172');
})->group('SPEC-055');
