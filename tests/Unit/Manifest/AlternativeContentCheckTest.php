<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Hash\AlternativeContentCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-065: the alternative content representation (C2PA 2.4 §15.10.3.2.7). The probes come from
 * bin/make-manifest-probe-variants.php (acr-*) under tests/Fixtures/manifest-probes/, with both c2patool
 * versions' answers.
 */

function spec065Probe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/manifest-probes/{$name}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/manifest-probes/throw-away-root.settings.json')));
}

function spec065Oracle(string $name): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/manifest-probes/{$name}--0.28.1.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<string> */
function spec065Codes(VerificationReport $report): array
{
    return array_values(array_unique(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter(
        $report->result->statuses,
        static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.alternativeContentRepresentation.'),
    ))));
}

function spec065Explained(VerificationReport $report): string
{
    return implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, array_filter(
        $report->result->statuses,
        static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.alternativeContentRepresentation.'),
    )));
}

it('AC1: an embedded OPI that matches is match, and the file keeps its state', function (): void {
    $report = spec065Probe('acr-embedded-ok');

    expect(spec065Codes($report))->toBe(['assertion.alternativeContentRepresentation.match'])
        ->and($report->result->state->value)->toBe('Trusted')
        ->and($report->result->checksPerformed)->toContain('alternativeContent');
})->group('SPEC-065');

it('AC2: a hash that does not match is hashMismatch', function (): void {
    $report = spec065Probe('acr-embedded-mismatch');

    expect(spec065Codes($report))->toBe(['assertion.alternativeContentRepresentation.hashMismatch'])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-065');

it('AC3, AC4, AC5: the shape, the index and the count are malformed, naming the fault', function (string $probe, string $says): void {
    $report = spec065Probe($probe);

    expect(spec065Codes($report))->toBe(['assertion.alternativeContentRepresentation.malformed'], $probe)
        ->and(spec065Explained($report))->toContain($says)
        ->and($report->result->state->value)->toBe('Invalid', $probe);
})->with([
    'both fields' => ['acr-both', 'exactly one'],
    'neither field' => ['acr-neither', 'exactly one'],
    'an embedded reference without hash' => ['acr-embedded-no-hash', 'no hash'],
    'an index without a multi-asset hash' => ['acr-index-no-multi-asset', 'c2pa.hash.multi-asset'],
    'two OPI assertions' => ['acr-two', 'at most one'],
])->group('SPEC-065');

it('AC3, AC4: each shape fault in the unit, the index against the parts', function (): void {
    $faults = static fn (mixed $data, ?int $parts = null): array => AlternativeContentCheck::shapeFaults($data, $parts);
    $opi = static fn (array $parameters): array => ['type' => 'exif.originalPreservationImage', 'parameters' => $parameters];

    expect($faults(['type' => 'exif.originalPreservationImage', 'parameters' => 'x']))->not->toBe([])
        ->and($faults($opi(['multiAssetPartIndex' => -1]), 3))->not->toBe([])
        ->and($faults($opi(['multiAssetPartIndex' => '0']), 3))->not->toBe([])
        ->and($faults($opi(['multiAssetPartIndex' => 3]), 3))->not->toBe([])
        ->and($faults($opi(['multiAssetPartIndex' => 2]), 3))->toBe([])
        ->and($faults($opi(['embeddedOriginalPreservationImage' => ['url' => 7]])))->not->toBe([])
        ->and($faults(['type' => 'com.example.other']))->toBe([]);
})->group('SPEC-065');

it('AC6: a generic representation is not checked', function (): void {
    $report = spec065Probe('acr-generic');

    expect(spec065Codes($report))->toBe([])
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-065');

it('AC7: both c2patool versions ignore the assertion; the failures here are stricter by the rule', function (): void {
    foreach (['acr-embedded-ok', 'acr-embedded-mismatch', 'acr-embedded-no-hash', 'acr-both', 'acr-neither', 'acr-index-no-multi-asset', 'acr-two', 'acr-generic'] as $probe) {
        expect(spec065Oracle($probe))->toBe('Trusted', $probe);
    }
})->group('SPEC-065');

it('AC8: three codes, verbatim', function (): void {
    expect(StatusCode::from('assertion.alternativeContentRepresentation.malformed')->isFailure())->toBeTrue()
        ->and(StatusCode::from('assertion.alternativeContentRepresentation.hashMismatch')->isFailure())->toBeTrue()
        ->and(StatusCode::from('assertion.alternativeContentRepresentation.match')->isSuccess())->toBeTrue();
})->group('SPEC-065');
