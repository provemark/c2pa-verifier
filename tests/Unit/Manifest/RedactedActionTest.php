<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Manifest\ActionsCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-037: the c2pa.redacted action. Children from bin/make-spec037-variants.php under
 * tests/Fixtures/redacted-action/ (built by c2patool 0.28.0 with -p and "redactions", step 131's
 * shapes), verified with their throwaway root; both c2patool versions' answers under
 * tests/Fixtures/c2patool/redacted-action/. The comparison is on the rule's two codes.
 */

const SPEC037_SETTINGS = 'redacted-action/probe-root.settings.json';
const SPEC037_CODES = ['assertion.action.redactionMismatch', 'assertion.notRedacted'];

function spec037Verify(string $probe): VerificationReport
{
    return spec020Verify("redacted-action/{$probe}.png", SPEC037_SETTINGS);
}

/**
 * The rule's faults, "code url", with the active manifest's label written as <active>.
 *
 * @param  list<ValidationStatus>  $statuses
 * @return list<string>
 */
function spec037Faults(array $statuses, string $active): array
{
    $faults = [];
    foreach ($statuses as $status) {
        if ($status->ingredientUri === null && in_array($status->code->value, SPEC037_CODES, true)) {
            $faults[] = $status->code->value.' '.str_replace($active, '<active>', $status->url);
        }
    }
    sort($faults);

    return $faults;
}

/**
 * c2patool's faults of the same codes, labels normalised the same way.
 *
 * @return list<string>
 */
function spec037OracleFaults(string $probe, string $version = '0.28.0'): array
{
    $oracle = spec020Oracle("redacted-action/{$probe}--{$version}.json");
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']) && is_string($oracle['active_manifest']));
    $faults = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        if (in_array($entry['code'], SPEC037_CODES, true)) {
            $faults[] = $entry['code'].' '.str_replace($oracle['active_manifest'], '<active>', $entry['url']);
        }
    }
    sort($faults);

    return $faults;
}

/** A probe both oracles fault with exactly $expected; ours must give the same, and Invalid. */
function spec037AsOracle(string $probe, string $expected): void
{
    $report = spec037Verify($probe);
    $want = ["{$expected} self#jumbf=/c2pa/<active>/c2pa.assertions/c2pa.actions.v2"];
    expect(spec037OracleFaults($probe))->toBe($want, $probe)
        ->and(spec037OracleFaults($probe, '0.27.22'))->toBe($want, $probe)
        ->and(spec037Faults($report->result->statuses, $report->store?->active->label ?? '?'))->toBe($want, $probe)
        ->and($report->result->state->value)->toBe('Invalid', $probe);
}

/**
 * The seam: one v2 (or v1) c2pa.redacted action with these parameters, in a claim with a parentOf
 * ingredient, against a store holding urn:c2pa:parent whose claim lists com.example.secret.
 *
 * @param  array<string, mixed>  $parameters
 * @return list<string> the rule's codes
 */
function spec037Seam(array $parameters, int $version = 2): array
{
    $label = $version === 2 ? 'c2pa.actions.v2' : 'c2pa.actions';
    $statuses = (new ActionsCheck)->checkAssertions(
        'urn:c2pa:child',
        $version,
        [['url' => "self#jumbf=/c2pa/urn:c2pa:child/c2pa.assertions/{$label}", 'data' => ['actions' => [
            // the opening the builder adds for the parent; without it the opening rule stops before the content rules (SPEC-033 amendment 1)
            ['action' => 'c2pa.opened', 'parameters' => ['ingredients' => [['url' => 'self#jumbf=c2pa.assertions/c2pa.ingredient.v3']]]],
            ['action' => 'c2pa.redacted', 'reason' => 'c2pa.PII.present', 'parameters' => $parameters],
        ]]]],
        false,
        [$label, 'c2pa.ingredient.v3', 'c2pa.hash.data'],
        ['c2pa.ingredient.v3' => 'parentOf'],
        ['urn:c2pa:parent' => ['com.example.secret', 'c2pa.actions.v2', 'c2pa.hash.data'], 'urn:c2pa:child' => [$label, 'c2pa.ingredient.v3', 'c2pa.hash.data']],
    );

    return spec037Codes($statuses);
}

/**
 * Codes as strings the analyser does not narrow to today's cases.
 *
 * @param  list<ValidationStatus>  $statuses
 * @return list<string>
 */
function spec037Codes(array $statuses): array
{
    return array_values(array_filter(array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses), static fn (string $c): bool => in_array($c, SPEC037_CODES, true)));
}

it('AC1: the shapes both oracles accept stay Trusted', function (): void {
    foreach (['valid', 'no-parameters', 'present-not-redacted', 'no-redaction-list'] as $probe) {
        $report = spec037Verify($probe);
        $actionFaults = array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure() && (str_starts_with($s->code->value, 'assertion.action.') || $s->code->value === 'assertion.notRedacted'));
        expect($report->result->state->value)->toBe('Trusted', $probe)
            ->and($actionFaults)->toBe([], $probe)
            ->and(spec020Oracle("redacted-action/{$probe}--0.28.0.json")['validation_state'])->toBe('Trusted', $probe)
            ->and(spec020Oracle("redacted-action/{$probe}--0.27.22.json")['validation_state'])->toBe('Trusted', $probe);
    }
    // and through the seam: a reference the named claim lists passes
    expect(spec037Seam(['redacted' => 'self#jumbf=/c2pa/urn:c2pa:parent/c2pa.assertions/com.example.secret']))->toBe([]);
})->group('SPEC-037');

it('AC2: parameters without redacted', function (): void {
    spec037AsOracle('parameters-without-redacted', 'assertion.action.redactionMismatch');
})->group('SPEC-037');

it('AC3: a reference that names no manifest here', function (): void {
    spec037AsOracle('relative', 'assertion.action.redactionMismatch');
    spec037AsOracle('foreign-manifest', 'assertion.action.redactionMismatch');
})->group('SPEC-037');

it('AC4: a label the named manifest does not list', function (): void {
    spec037AsOracle('unknown-label', 'assertion.notRedacted');
})->group('SPEC-037');

it('AC5: a redacted that is not a string', function (): void {
    expect(spec037Seam(['redacted' => 42]))->toBe(['assertion.action.redactionMismatch'])
        // open question 3: a data-box reference gets no pass route of its own
        ->and(spec037Seam(['redacted' => 'self#jumbf=/c2pa/urn:c2pa:parent/c2pa.databoxes/c2pa.data']))->toBe(['assertion.notRedacted']);
})->group('SPEC-037');

it('AC6: v1 claims are not checked', function (): void {
    expect(spec037Seam(['description' => 'no redacted field'], 2))->toBe(['assertion.action.redactionMismatch'])
        ->and(spec037Seam(['description' => 'no redacted field'], 1))->toBe([]);
})->group('SPEC-037');

it('AC7: nothing else moves', function (): void {
    // a guard: the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 132b;
    // here, SPEC-035's redacting child with its c2pa.redacted action keeps its verdict
    $report = spec020Verify('redactions/redacted-with-action.png', 'redactions/probe-root.settings.json');
    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec037Codes($report->result->statuses))->toBe([]);
})->group('SPEC-037');

it('AC8: the vocabulary grows by one code, verbatim', function (): void {
    $case = spec037Case('assertion.action.redactionMismatch');
    expect($case?->name)->toBe('AssertionActionRedactionMismatch')
        ->and($case?->isFailure())->toBeTrue();
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(in_array('Report\StatusCode :: const AssertionActionRedactionMismatch', $surface, true))->toBeTrue();
})->group('SPEC-037');

/** The case with this value, or null: a string parameter, so the analyser cannot decide it before the case exists. */
function spec037Case(string $value): ?StatusCode
{
    return StatusCode::tryFrom($value);
}
