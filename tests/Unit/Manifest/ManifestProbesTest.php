<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-007 amendment 7: the manifest probes of the reading of C2PA 2.4 (L9, L10, L11,
 * L13), made by bin/make-manifest-probe-variants.php under tests/Fixtures/manifest-probes/, with both c2patool
 * versions' answers.
 */

function spec007Probe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/manifest-probes/{$name}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/manifest-probes/throw-away-root.settings.json')));
}

function spec007ProbeOracle(string $name, string $version): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/manifest-probes/{$name}--{$version}.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

function spec007ProbeFailure(VerificationReport $report, string $code): string
{
    return implode(' | ', array_map(
        static fn (ValidationStatus $s): string => $s->explanation,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code),
    ));
}

it('the edits themselves keep a file whole: the control and a shorter valid claim_generator_info are Trusted everywhere', function (): void {
    foreach (['control', 'cgi-shorter', 'parent'] as $name) {
        expect(spec007Probe($name)->result->state->value)->toBe('Trusted', $name);
        foreach (['0.28.1', '0.27.22'] as $version) {
            expect(spec007ProbeOracle($name, $version))->toBe('Trusted', "{$name} {$version}");
        }
    }
})->group('SPEC-007');

it('AC16: a manifest box of type c2md is read as a standard manifest (SPEC-007 amendment 7)', function (): void {
    expect(spec007Probe('type-c2md')->result->state->value)->toBe('Trusted')
        ->and(spec007ProbeOracle('type-c2md', '0.28.1'))->toBe('Trusted')
        ->and(spec007ProbeOracle('type-c2md', '0.27.22'))->toBe('Trusted');
})->group('SPEC-007');

it('AC17: two manifests with one label make the store malformed, wherever the second stands (SPEC-007 amendment 7)', function (): void {
    foreach (['duplicate-label-last', 'duplicate-label-middle'] as $name) {
        $report = spec007Probe($name);

        expect($report->result->state->value)->toBe('Invalid', $name)
            ->and(spec007ProbeFailure($report, 'claim.malformed'))->toContain('two manifests labelled');
    }
    // c2patool takes the last box as active: Invalid where it is the copy, Trusted where it is not
    expect(spec007ProbeOracle('duplicate-label-last', '0.28.1'))->toBe('Invalid')
        ->and(spec007ProbeOracle('duplicate-label-middle', '0.28.1'))->toBe('Trusted');
})->group('SPEC-007');

it('AC18: a version 2 manifest whose label is not a C2PA URN is claim.malformed, as in c2patool (SPEC-007 amendment 7)', function (): void {
    $report = spec007Probe('label-not-urn');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec007ProbeFailure($report, 'claim.malformed'))->toContain('urx:c2pa:')
        ->and(spec007ProbeOracle('label-not-urn', '0.28.1'))->toBe('Invalid')
        ->and(spec007ProbeOracle('label-not-urn', '0.27.22'))->toBe('Invalid');
})->group('SPEC-007');

it('AC19: a version 2 claim whose claim_generator_info is an empty map is claim.malformed; c2patool cannot read it (SPEC-007 amendment 7)', function (): void {
    $report = spec007Probe('cgi-empty');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec007ProbeFailure($report, 'claim.malformed'))->toContain('claim_generator_info')
        ->and(spec007ProbeOracle('cgi-empty', '0.28.1'))->toBeNull()
        ->and(spec007ProbeOracle('cgi-empty', '0.27.22'))->toBeNull();
})->group('SPEC-007');

it('AC12: the pad of a data hash is ignored, missing or of another type, as C2PA 2.4 §15.12.1.1 says (SPEC-012 amendment 10)', function (): void {
    foreach (['datahash-no-pad', 'datahash-pad-text'] as $name) {
        $report = spec007Probe($name);

        expect($report->result->state->value)->toBe('Trusted', $name)
            ->and(spec007ProbeFailure($report, 'assertion.dataHash.match'))->not->toBe('', $name);
    }
    // the named difference: c2patool cannot decode a data hash without pad; it reads a text pad
    expect(spec007ProbeOracle('datahash-no-pad', '0.28.1'))->toBeNull()
        ->and(spec007ProbeOracle('datahash-no-pad', '0.27.22'))->toBeNull()
        ->and(spec007ProbeOracle('datahash-pad-text', '0.28.1'))->toBe('Trusted');
})->group('SPEC-012');
