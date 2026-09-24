<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-033: the actions content rules. Probes from bin/make-spec033-variants.php
 * under tests/Fixtures/actions-rules/, verified with their throwaway root as
 * anchor; both c2patool versions' answers under tests/Fixtures/c2patool/actions-rules/.
 * The comparison is on the `assertion.action.*` failures: code and url, with the
 * active manifest's label written as <active> so that two signings compare.
 */

function spec033Verify(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/actions-rules/{$probe}.jpg", 'rb');
    assert($stream !== false);

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/actions-rules/probe-root.settings.json')));
}

/** @return array<string, mixed> */
function spec033Oracle(string $probe, string $version = '0.28.0'): array
{
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/actions-rules/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($oracle));

    /** @var array<string, mixed> $oracle */
    return $oracle;
}

/**
 * The oracle's `assertion.action.*` failures, sorted, labels normalised.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<string> "code url"
 */
function spec033OracleActionFaults(array $oracle): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']) && is_string($oracle['active_manifest']));
    $faults = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        if (str_starts_with($entry['code'], 'assertion.action.')) {
            $faults[] = $entry['code'].' '.str_replace($oracle['active_manifest'], '<active>', $entry['url']);
        }
    }
    sort($faults);

    return $faults;
}

/** @return list<string>  "code url" */
function spec033ActionFaults(VerificationReport $report): array
{
    $label = $report->store?->active->label ?? '';
    $faults = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && $status->code->isFailure() && str_starts_with($status->code->value, 'assertion.action.')) {
            $faults[] = $status->code->value.' '.str_replace($label, '<active>', $status->url);
        }
    }
    sort($faults);

    return $faults;
}

/** A probe whose faults must be exactly c2patool 0.28.0's, and whose state is Invalid. */
function spec033AsOracle(string $probe): void
{
    $report = spec033Verify($probe);
    $theirs = spec033OracleActionFaults(spec033Oracle($probe));
    expect($theirs)->not->toBe([], "{$probe}: the oracle must have faulted it")
        ->and(spec033ActionFaults($report))->toBe($theirs, $probe)
        ->and($report->result->state->value)->toBe('Invalid', $probe);
}

/** A control: no action fault, Trusted, as c2patool 0.28.0. */
function spec033Clean(string $probe): void
{
    $report = spec033Verify($probe);
    expect(spec033ActionFaults($report))->toBe([], $probe)
        ->and($report->result->state->value)->toBe('Trusted', $probe)
        ->and(spec033Oracle($probe)['validation_state'])->toBe('Trusted', $probe);
}

it('AC1: one opening', function (): void {
    spec033AsOracle('created-then-opened');   // "more than one" on the claim label, and the opened action's missing references
    spec033AsOracle('edited-then-created');   // amendment 1: SPEC-018's fault, and nothing more
    expect(spec033ActionFaults(spec033Verify('edited-then-created')))->toHaveCount(1);
})->group('SPEC-033');

it('AC2: opened, placed, removed without references', function (): void {
    foreach (['placed-no-parameters', 'placed-no-ingredients', 'placed-empty-ingredients', 'placed-unresolvable'] as $probe) {
        spec033AsOracle($probe);
        expect(spec033Oracle($probe, '0.27.22')['validation_state'])->toBe('Invalid', $probe);
    }
})->group('SPEC-033');

it('AC3: references of the wrong relationship', function (): void {
    spec033AsOracle('placed-parent');
    spec033AsOracle('opened-component');
    spec033Clean('placed-component');
    spec033Clean('opened-parent');
})->group('SPEC-033');

it('AC4: transcoded and repackaged', function (): void {
    spec033AsOracle('transcoded-component');
    spec033Clean('repackaged-no-reference');
})->group('SPEC-033');

it('AC5: translation', function (): void {
    spec033AsOracle('translated-no-parameters');
    spec033AsOracle('translated-source-only');
    spec033Clean('translated-both');
})->group('SPEC-033');

it('AC6: related assertions', function (): void {
    foreach (['related-empty', 'related-missing', 'related-actions'] as $probe) {
        spec033AsOracle($probe);
        // 0.28.0's rule: 0.27.22 did not check it, a named change
        expect(spec033Oracle($probe, '0.27.22')['validation_state'])->toBe('Trusted', $probe);
    }
    spec033Clean('related-note');
})->group('SPEC-033');

it('AC7: watermarks', function (): void {
    spec033AsOracle('watermarked-no-soft-binding');
    expect(spec033Oracle('watermarked-no-soft-binding', '0.27.22')['validation_state'])->toBe('Trusted');
    spec033Clean('watermarked-with-soft-binding');
})->group('SPEC-033');

it('AC8: nothing else moves', function (): void {
    // the only v2 actions of the corpus the rules touch: four c2pa.opened, each naming one parentOf ingredient
    foreach (['c2pa-rs/CACA.jpg', 'ingredient-manifest/ingredient-signature-broken.jpg'] as $relative) {
        $stream = fopen(Corpus::fixtures()."/{$relative}", 'rb');
        assert($stream !== false);
        $report = (new Verifier)->verify($stream);
        $mismatch = array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'assertion.action.ingredientMismatch');
        expect($mismatch)->toBe([], $relative);
    }
    // the rest of the corpus is the drift alarms' (SPEC-013 AC10–AC13), and the before/after run of step 125b
})->group('SPEC-033');

it('AC9: the vocabulary grows by two codes, verbatim', function (): void {
    $want = ['assertion.action.ingredientMismatch' => 'AssertionActionIngredientMismatch', 'assertion.action.softBindingMissing' => 'AssertionActionSoftBindingMissing'];
    $found = [];
    foreach (StatusCode::cases() as $case) {
        if (isset($want[$case->value])) {
            $found[$case->value] = [$case->name, $case->isFailure()];
        }
    }
    ksort($found);
    expect($found)->toBe([
        'assertion.action.ingredientMismatch' => ['AssertionActionIngredientMismatch', true],
        'assertion.action.softBindingMissing' => ['AssertionActionSoftBindingMissing', true],
    ]);
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // SPEC-033 amendment 2: this spec's two symbols are recorded; the surface's size is ApiSurfaceTest's alone
    expect(in_array('Report\StatusCode :: const AssertionActionIngredientMismatch', $surface, true))->toBeTrue()
        ->and(in_array('Report\StatusCode :: const AssertionActionSoftBindingMissing', $surface, true))->toBeTrue();
})->group('SPEC-033');
