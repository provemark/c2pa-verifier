<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-036: a redacted hard binding. Variants from bin/make-spec036-variants.php under
 * tests/Fixtures/hard-binding-redacted/ (the PNG fixture whose claim gains a redacted_assertions
 * naming a hash assertion, the data hash rebound, re-signed under a throwaway root); both
 * c2patool versions' answers under tests/Fixtures/c2patool/hard-binding-redacted/.
 */

const SPEC036_SETTINGS = 'hard-binding-redacted/probe-root.settings.json';
const SPEC036_CODES = ['assertion.hardBinding.redacted', 'assertion.selfRedacted', 'assertion.notRedacted', 'assertion.action.redacted', 'general.error'];

function spec036Verify(string $variant): VerificationReport
{
    return spec020Verify("hard-binding-redacted/{$variant}.png", SPEC036_SETTINGS);
}

/** @return list<string> "code url", the active manifest's failures among the redaction codes */
function spec036Faults(VerificationReport $report): array
{
    $faults = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && $status->code->isFailure() && in_array($status->code->value, SPEC036_CODES, true)) {
            $faults[] = $status->code->value.' '.$status->url;
        }
    }
    sort($faults);

    return $faults;
}

/** @return list<string> "code url", c2patool's active-manifest failures, every code */
function spec036OracleFaults(string $variant, string $version = '0.28.0'): array
{
    $oracle = spec020Oracle("hard-binding-redacted/{$variant}--{$version}.json");
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $faults = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        $faults[] = $entry['code'].' '.$entry['url'];
    }
    sort($faults);

    return $faults;
}

/**
 * All of our failures, every code: nothing beyond the redaction faults may appear.
 *
 * @return list<string>
 */
function spec036AllFaults(VerificationReport $report): array
{
    return array_values(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure())));
}

/** A variant whose failures are exactly c2patool 0.28.0's, and Invalid in both versions. */
function spec036AsOracle(string $variant): void
{
    $report = spec036Verify($variant);
    $theirs = spec036OracleFaults($variant);
    expect($theirs)->not->toBe([], $variant)
        ->and(spec036Faults($report))->toBe($theirs, $variant)
        ->and(count(spec036AllFaults($report)))->toBe(count($theirs), $variant)
        ->and($report->result->state->value)->toBe('Invalid', $variant)
        ->and(spec020Oracle("hard-binding-redacted/{$variant}--0.27.22.json")['validation_state'])->toBe('Invalid', $variant);
}

it('AC1: a relative entry naming the hard binding', function (): void {
    spec036AsOracle('hash-data-relative');
    expect(spec036Faults(spec036Verify('hash-data-relative')))->toBe(['assertion.hardBinding.redacted self#jumbf=c2pa.assertions/c2pa.hash.data']);
})->group('SPEC-036');

it('AC2: an absolute entry naming the claim\'s own hard binding', function (): void {
    spec036AsOracle('hash-data-absolute');
    $report = spec036Verify('hash-data-absolute');
    $entry = 'self#jumbf=/c2pa/'.($report->store?->active->label ?? 'x').'/c2pa.assertions/c2pa.hash.data';
    expect(spec036Faults($report))->toBe(["assertion.hardBinding.redacted {$entry}", "assertion.notRedacted {$entry}", "assertion.selfRedacted {$entry}"]);
})->group('SPEC-036');

it('AC3: the other three hard-binding labels', function (): void {
    foreach (['hash-boxes-relative' => 'c2pa.hash.boxes', 'hash-bmff-relative' => 'c2pa.hash.bmff.v2', 'hash-collection-relative' => 'c2pa.hash.collection.data'] as $variant => $label) {
        spec036AsOracle($variant);
        expect(spec036Faults(spec036Verify($variant)))->toBe(["assertion.hardBinding.redacted self#jumbf=c2pa.assertions/{$label}"], $variant);
    }
})->group('SPEC-036');

it('AC4: an entry that names no hard binding gets no such code', function (): void {
    // a guard, green before and after: SPEC-035's files, each keeping its result
    foreach ([
        ['redactions/redacted-with-action.png', 'redactions/probe-root.settings.json', 'Trusted'],
        ['redactions/claim-signature-changed.png', 'redactions/probe-root.settings.json', 'Invalid'],
        ['ingredient-manifest/redacted.png', 'ingredient-manifest/throw-away-root.settings.json', 'Invalid'],
        ['binding/claim-redacted.png', null, 'Invalid'],
    ] as [$file, $settings, $state]) {
        $report = spec020Verify($file, $settings);
        $codes = spec036Codes($report);
        expect(in_array('assertion.hardBinding.redacted', $codes, true))->toBeFalse($file)
            ->and(in_array('general.error', $codes, true))->toBeFalse($file)
            ->and($report->result->state->value)->toBe($state, $file);
    }
})->group('SPEC-036');

it('AC5: nothing else moves', function (): void {
    // a guard: the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 130b;
    // here, the unchanged PNG fixture the variants are built from keeps c2patool's verdict and carries no redaction code
    $report = spec020Verify('fixture-signed.png');
    expect(array_values(array_intersect(spec036Codes($report), SPEC036_CODES)))->toBe([])
        ->and($report->result->state->value)->toBe(spec020Oracle('png.json')['validation_state']);
})->group('SPEC-036');

it('AC6: the vocabulary grows by one code, verbatim', function (): void {
    $case = spec036Case('assertion.hardBinding.redacted');
    expect($case?->name)->toBe('AssertionHardBindingRedacted')
        ->and($case?->isFailure())->toBeTrue();
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(in_array('Report\StatusCode :: const AssertionHardBindingRedacted', $surface, true))->toBeTrue();
})->group('SPEC-036');

/** The case with this value, or null: a string parameter, so the analyser cannot decide it before the case exists. */
function spec036Case(string $value): ?StatusCode
{
    return StatusCode::tryFrom($value);
}

/**
 * Every status code of the report, as strings the analyser does not narrow to today's cases.
 *
 * @return list<string>
 */
function spec036Codes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
}
