<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * Step 60, the absence audit for M7 (SPEC-020, SPEC-021, SPEC-022): for every rule of the form "the
 * verifier does X when Y is present", a *signed* store in which Y is absent. The stores are this
 * project's own two-manifest stores, built by bin/make-m7-absence-variants.php; the oracle is
 * c2patool 0.27.22 beside each file (tests/Fixtures/c2patool/m7-absence/).
 */

const M7_ABSENCE_SETTINGS = 'm7-absence/both-roots.settings.json';

function m7Verify(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/m7-absence/{$name}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.M7_ABSENCE_SETTINGS));

    return (new Verifier)->verify($stream, $settings);
}

/** @return list<string> the failure codes, unique and sorted */
function m7Failures(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $codes[$status->code->value] = true;
        }
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/** @return array<string, mixed> */
function m7Oracle(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/m7-absence/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> the oracle's failure codes, unique and sorted */
function m7OracleFailures(string $name): array
{
    $codes = [];
    foreach ((array) (m7Oracle($name)['validation_status'] ?? []) as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[$status['code']] = true;
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

it('the control: a two-manifest store this project built itself verifies whole', function (): void {
    $report = m7Verify('two-manifests');
    $store = $report->store ?? throw new RuntimeException('no store');
    $graph = ManifestGraph::fromStore($store);

    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(m7Failures($report))->toBe([])
        ->and(m7Failures($report))->toBe(m7OracleFailures('two-manifests'))
        ->and(m7Oracle('two-manifests')['validation_state'])->toBe('Trusted')
        ->and(count($store->manifests))->toBe(2)
        ->and(array_keys($graph->referenced))->toHaveCount(1)
        ->and($graph->unreferenced)->toBe([])
        ->and(in_array('ingredients', $report->result->checksPerformed, true))->toBeTrue();
    // the ingredient manifest really was validated: its own statuses are in the delta
    $scoped = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri !== null));
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $scoped);
    expect($codes)->toContain('ingredient.manifest.validated')
        ->and($codes)->toContain('claimSignature.validated')
        ->and($codes)->toContain('signingCredential.trusted');
})->group('SPEC-021');

it('absence: an ingredient manifest without an actions assertion costs the file its verdict, as at c2patool', function (): void {
    $report = m7Verify('ingredient-no-actions');
    $malformed = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionActionMalformed));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(m7Failures($report))->toBe(['assertion.action.malformed'])
        ->and(m7Failures($report))->toBe(m7OracleFailures('ingredient-no-actions'))
        ->and(m7Oracle('ingredient-no-actions')['validation_state'])->toBe('Invalid')
        ->and($malformed)->toHaveCount(1)
        // and it is the *ingredient's* fault, scoped to the assertion that named it
        ->and($malformed[0]->ingredientUri)->not->toBeNull()
        ->and($malformed[0]->url)->not->toContain((string) $report->store?->active->label);
})->group('SPEC-021');

it('absence: a manifest nobody references is never validated — a broken signature there changes nothing', function (): void {
    $report = m7Verify('unreferenced-broken');
    $store = $report->store ?? throw new RuntimeException('no store');
    $graph = ManifestGraph::fromStore($store);

    // the specification says to ignore manifests the walk does not reach (§15.11.3.3), and c2patool does
    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(m7Failures($report))->toBe([])
        ->and(m7Failures($report))->toBe(m7OracleFailures('unreferenced-broken'))
        ->and(m7Oracle('unreferenced-broken')['validation_state'])->toBe('Trusted')
        ->and($graph->referenced)->toBe([])
        ->and($graph->unreferenced)->toHaveCount(1)
        ->and(in_array('ingredients', $report->result->checksPerformed, true))->toBeFalse();
    // it is still *rendered*, as c2patool renders it — the caller sees there is something in the file
    $array = $report->toArray();
    assert(is_array($array['manifests']));
    expect(array_keys($array['manifests']))->toHaveCount(2)
        ->and(array_keys((array) m7Oracle('unreferenced-broken')['manifests']))->toHaveCount(2);
    // and its signature really is broken: validated on its own, it fails
    $ignored = $store->manifests[$graph->unreferenced[0]];
    $signature = (new ClaimSignatureCheck)->check($ignored);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $signature))->toBe(['claimSignature.mismatch']);
})->group('SPEC-021');

it('absence: a v3 ingredient assertion without claimSignature is read, as at c2patool', function (): void {
    $report = m7Verify('no-claim-signature');
    $store = $report->store ?? throw new RuntimeException('no store');
    $graph = ManifestGraph::fromStore($store);
    $ingredient = $graph->ingredients[$store->active->label][0];

    // §18.16.12.3 says both hashed URIs shall be stored; neither this verifier nor c2pa-rs refuses
    // one that is missing (c2pa-rs needs it only when a redaction forces the signature method)
    expect($ingredient->manifest)->not->toBeNull()
        ->and($ingredient->claimSignature)->toBeNull()
        ->and($report->result->state)->toBe(ValidationState::Trusted)
        ->and(m7Failures($report))->toBe([])
        ->and(m7Oracle('no-claim-signature')['validation_state'])->toBe('Trusted');
})->group('SPEC-021');
