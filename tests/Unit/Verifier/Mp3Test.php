<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-056: MP3 through the verifier — detection, the verdicts, and the
 * signed fixture against c2patool 0.27.22 and 0.28.1, recorded under
 * tests/Fixtures/c2patool/mp3/ (step 227). The extractor's criteria are in
 * tests/Unit/Container/Id3ManifestStoreExtractorTest.php.
 */

/** @return resource */
function spec056VerifierStream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

function spec056File(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$relative);
}

/**
 * The active manifest's codes of one kind, sorted, from a report or a c2patool JSON.
 *
 * @param  array<string, mixed>  $tree
 * @return list<string>
 */
function spec056Codes(array $tree, string $kind): array
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
function spec056Report(VerificationReport $report): array
{
    /** @var array<string, mixed> */
    return $report->toArray();
}

it('AC11: bytes after the tag are judged by the data hash', function (string $variant): void {
    $report = spec056Report((new Verifier)->verify(spec056VerifierStream(spec056File("mp3/{$variant}.mp3"))));

    expect($report['format'])->toBe('mp3')
        ->and($report['validation_state'])->toBe('Invalid')
        ->and(spec056Codes($report, 'success'))->toContain('claimSignature.validated')
        ->and(spec056Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->with(['id3v1-appended'])->group('SPEC-056');

it('AC11: a tag size one too large leaves no MPEG audio after the tag, so the file is unknown (amendment 1)', function (): void {
    $report = (new Verifier)->verify(spec056VerifierStream(spec056File('mp3/tag-size-plus-one.mp3')));

    expect($report->format)->toBe('unknown')
        ->and($report->hasManifest)->toBeFalse()
        ->and(array_map(static fn ($s): string => $s->code->value, $report->result->statuses))->toBe(['general.error']);
})->group('SPEC-056');

it('AC13: mp3 when MPEG audio follows the tag or opens the file, and nothing else is guessed', function (): void {
    $detector = new FormatDetector;
    $signed = spec056File('fixture-signed.mp3');
    // the fixture's tag followed by a FLAC stream marker: ID3 is not enough
    $flac = substr($signed, 0, 13548).'fLaC'.str_repeat("\0", 64);

    expect($detector->detect(spec056VerifierStream($signed)))->toBe('mp3')
        ->and($detector->detect(spec056VerifierStream(spec056File('mp3/unsigned-no-tag.mp3'))))->toBe('mp3')
        ->and($detector->detect(spec056VerifierStream(spec056File('mp3/junk-before-tag.mp3'))))->toBeNull()
        ->and($detector->detect(spec056VerifierStream($flac)))->toBeNull()
        ->and($detector->detect(spec056VerifierStream(spec056File('fixture-signed.webp'))))->toBe('webp');
})->group('SPEC-056');

it('AC13: a tagless MP3 has no manifest and no failure; an unknown file names MP3 among the formats', function (): void {
    $untagged = (new Verifier)->verify(spec056VerifierStream(spec056File('mp3/unsigned-no-tag.mp3')));
    $junk = (new Verifier)->verify(spec056VerifierStream(spec056File('mp3/junk-before-tag.mp3')));

    expect($untagged->format)->toBe('mp3')
        ->and($untagged->hasManifest)->toBeFalse()
        ->and(spec056Codes(spec056Report($untagged), 'failure'))->toBe([])
        ->and($junk->format)->toBe('unknown')
        ->and($junk->result->statuses[0]->explanation)->toContain('MP3');
})->group('SPEC-056');

it('AC14: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say, without and with trust settings', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec056File("c2patool/mp3/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $settings = $trusted ? TrustSettings::fromJson(spec056File('trust/full.settings.json')) : null;
    $report = (new Verifier)->verify(spec056VerifierStream(spec056File('fixture-signed.mp3')), $settings);
    $ours = spec056Report($report);

    expect($report->format)->toBe('mp3')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and(spec056Codes($ours, 'success'))->toBe(spec056Codes($oracle, 'success'))
        ->and(spec056Codes($ours, 'failure'))->toBe(spec056Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['fixture-signed', false],
    '0.27.22 trusted' => ['fixture-signed.trusted', true],
    '0.28.1' => ['fixture-signed.0.28.1', false],
    '0.28.1 trusted' => ['fixture-signed.0.28.1.trusted', true],
])->group('SPEC-056');

it('AC14: one byte of the audio flipped is assertion.dataHash.mismatch', function (): void {
    $bytes = spec056File('fixture-signed.mp3');
    $bytes[14000] = chr(ord($bytes[14000]) ^ 1);   // inside the MPEG audio, after the tag at 13548
    $report = spec056Report((new Verifier)->verify(spec056VerifierStream($bytes)));

    expect($report['validation_state'])->toBe('Invalid')
        ->and(spec056Codes($report, 'failure'))->toContain('assertion.dataHash.mismatch');
})->group('SPEC-056');

it('AC15: the MP3 c2pa-ts signed is read, and fails its data hash as c2patool 0.28.1 says (amendment 2)', function (): void {
    $report = (new Verifier)->verify(spec056VerifierStream(spec056File('mp3-writers/c2pa-ts-signed.mp3')));
    $tree = spec056Report($report);

    expect($report->format)->toBe('mp3')
        ->and($report->hasManifest)->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec056Codes($tree, 'success'))->toContain('claimSignature.validated')
        ->and(implode(',', spec056Codes($tree, 'failure')))->toContain('assertion.dataHash.');
})->group('SPEC-056');

it('AC16: MPEG audio after zero padding or a further tag is mp3, and verifies as c2patool says (amendment 2)', function (string $recorded, string $file): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec056File("c2patool/mp3/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $report = (new Verifier)->verify(spec056VerifierStream(spec056File("mp3/{$file}.mp3")));
    $ours = spec056Report($report);

    expect($report->format)->toBe('mp3')
        ->and($ours['validation_state'])->toBe('Valid')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec056Codes($ours, 'success'))->toBe(spec056Codes($oracle, 'success'))
        ->and(spec056Codes($ours, 'failure'))->toBe(spec056Codes($oracle, 'failure'));
})->with([
    'zeros, 0.27.22' => ['signed-zeros-after-tag', 'signed-zeros-after-tag'],
    'zeros, 0.28.1' => ['signed-zeros-after-tag.0.28.1', 'signed-zeros-after-tag'],
    'second tag, 0.27.22' => ['signed-second-empty-tag', 'signed-second-empty-tag'],
    'second tag, 0.28.1' => ['signed-second-empty-tag.0.28.1', 'signed-second-empty-tag'],
])->group('SPEC-056');

it('AC17: MPEG audio without a tag needs two frame headers (amendment 2)', function (): void {
    $detector = new FormatDetector;
    $untagged = spec056File('mp3/unsigned-no-tag.mp3');

    expect($detector->detect(spec056VerifierStream($untagged)))->toBe('mp3')
        // a UTF-16LE text file opens with FF FE, which passes a one-header check
        ->and($detector->detect(spec056VerifierStream("\xFF\xFE".mb_convert_encoding("Hello world\n", 'UTF-16LE', 'UTF-8'))))->toBeNull()
        // one valid header, then not another where the first frame ends (288 bytes on)
        ->and($detector->detect(spec056VerifierStream(substr($untagged, 0, 4).str_repeat("\x11", 400))))->toBeNull();
})->group('SPEC-056');

it('AC20: a v2.3 tag with header bit 0x10 is followed by its audio, not by a footer (amendment 2)', function (): void {
    expect((new FormatDetector)->detect(spec056VerifierStream(spec056File('mp3/footer-bit-v23.mp3'))))->toBe('mp3');
})->group('SPEC-056');
