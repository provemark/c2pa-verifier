<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Jumbf\JumbfException;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Manifest\UpdateManifestCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-022: update manifests (c2um). Oracles: c2patool 0.27.22 on
 * tests/Fixtures/c2pa-rs/update_manifest.jpg (--settings full.settings.json)
 * and on the six variants of step 57 (tests/Fixtures/update-manifest/, with
 * their throw-away settings); four of those make c2patool exit without JSON
 * and their standard error is recorded beside the others.
 */

const SPEC022_VARIANT_SETTINGS = 'update-manifest/throw-away-root.settings.json';

/** @return list<string> the failure codes of a report, unique and sorted */
function spec022Failures(VerificationReport $report): array
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

// AC1
it('AC1: the update-manifest fixture reads, and its verdict is c2patool\'s', function (): void {
    $relative = 'c2pa-rs/update_manifest.jpg';
    $report = spec020Verify($relative, SPEC021_SETTINGS);
    $oracle = spec020Oracle('c2pa-rs/update_manifest.json');
    $store = $report->store ?? throw new RuntimeException('no store');

    expect($report->result->state->value)->toBe($oracle['validation_state'])
        ->and($report->result->state)->toBe(ValidationState::Trusted)
        ->and(spec022Failures($report))->toBe([])
        ->and(count($store->manifests))->toBe(2)
        ->and($store->active->label)->toBe($oracle['active_manifest'])
        ->and($store->active->isUpdateManifest)->toBeTrue()
        ->and($store->active->claim->version)->toBe(2)
        ->and(in_array('ingredients', $report->result->checksPerformed, true))->toBeTrue();
    // the parent is a standard manifest, and the one delta is c2patool's
    $parent = $store->manifests[(string) array_key_first($store->manifests)];
    expect($parent->isUpdateManifest)->toBeFalse();
    $deltas = spec020Deltas($report->toArray());
    expect($deltas)->toHaveCount(1)
        ->and($deltas[0]['ingredientAssertionURI'])->toBe(spec020Deltas($oracle)[0]['ingredientAssertionURI'])
        ->and(array_column($deltas[0]['validationDeltas']['success'], 'code'))->toContain('ingredient.manifest.validated')
        ->and($deltas[0]['validationDeltas']['success'][0]['url'])->toBe(spec020Deltas($oracle)[0]['validationDeltas']['success'][0]['url']);
})->group('SPEC-022');

// AC2
it('AC2: the hard binding comes from the parent and is reported under the active manifest', function (): void {
    $report = spec020Verify('c2pa-rs/update_manifest.jpg', SPEC021_SETTINGS);
    $store = $report->store ?? throw new RuntimeException('no store');
    $parentLabel = (string) array_key_first($store->manifests);
    $dataHash = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.dataHash.')));

    expect($dataHash)->toHaveCount(1)
        ->and($dataHash[0]->code)->toBe(StatusCode::AssertionDataHashMatch)
        ->and($dataHash[0]->ingredientUri)->toBeNull()
        ->and($dataHash[0]->url)->toBe("self#jumbf=/c2pa/{$parentLabel}/c2pa.assertions/c2pa.hash.data")
        ->and(array_key_exists('c2pa.hash.data', $store->active->assertions))->toBeFalse()
        ->and(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::ClaimHardBindingsMissing))->toBe([]);
})->group('SPEC-022');

// AC3
it('AC3: the stale exclusion is adjusted to the store, and no further', function (): void {
    // the fixture: the parent excludes 18874 bytes where the store now occupies 43607
    $store = Corpus::manifestStore('c2pa-rs/update_manifest.jpg') ?? throw new RuntimeException('no store');
    $parent = $store->manifests[(string) array_key_first($store->manifests)];
    $data = $parent->assertions['c2pa.hash.data']->data;
    assert(is_array($data) && is_array($data['exclusions']) && is_array($data['exclusions'][0]));
    expect($data['exclusions'][0]['start'])->toBe(9964)
        ->and($data['exclusions'][0]['length'])->toBe(18874);

    $report = spec020Verify('c2pa-rs/update_manifest.jpg', SPEC021_SETTINGS);
    expect(spec022Failures($report))->toBe([]);

    // one byte outside the store: the adjustment widens the exclusion to the store, never beyond it
    $changed = spec020Verify('update-manifest/pixel-changed.jpg', SPEC022_VARIANT_SETTINGS);
    expect($changed->result->state)->toBe(ValidationState::Invalid)
        ->and(spec022Failures($changed))->toBe(['assertion.dataHash.mismatch'])
        ->and(spec020Oracle('update-manifest/pixel-changed.json')['validation_state'])->toBe('Invalid');
})->group('SPEC-022');

// AC4
it('AC4: an update manifest that breaks §11.2.3', function (): void {
    // (a) an action outside the four allowed values
    $action = spec020Verify('update-manifest/action-not-allowed.jpg', SPEC022_VARIANT_SETTINGS);
    expect($action->result->state)->toBe(ValidationState::Invalid)
        ->and(in_array('manifest.update.invalid', spec022Failures($action), true))->toBeTrue()
        ->and(spec020Oracle('update-manifest/action-not-allowed.json')['validation_state'])->toBe('Invalid')
        ->and(spec021OracleFailures('update-manifest/action-not-allowed.json'))->toBe(['manifest.update.invalid']);

    // (b) a hash assertion in an update manifest (c2patool exits without JSON: "assertion missing")
    $hash = spec020Verify('update-manifest/hash-in-update.jpg', SPEC022_VARIANT_SETTINGS);
    expect($hash->result->state)->toBe(ValidationState::Invalid)
        ->and(in_array('manifest.update.invalid', spec022Failures($hash), true))->toBeTrue();

    // (c) no parentOf ingredient (c2patool: "claim missing hard binding", exit 1)
    $input = spec020Verify('update-manifest/ingredient-inputto.jpg', SPEC022_VARIANT_SETTINGS);
    expect($input->result->state)->toBe(ValidationState::Invalid)
        ->and(in_array('manifest.update.wrongParents', spec022Failures($input), true))->toBeTrue()
        ->and(in_array('claim.hardBindings.missing', spec022Failures($input), true))->toBeTrue();

    // (d) more than one ingredient assertion: the seam, since a second one cannot be spliced into this
    // claim (its CBOR uses indefinite lengths) — SPEC-022 amendment 1
    $statuses = UpdateManifestCheck::rules(true, ['c2pa.actions'], ['c2pa.opened'], 2, 2);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses))->toBe(['manifest.update.invalid']);
})->group('SPEC-022');

// AC5
it('AC5: a parentOf chain that never reaches a standard manifest is claim.hardBindings.missing', function (): void {
    $report = spec020Verify('update-manifest/no-standard-parent.jpg', SPEC022_VARIANT_SETTINGS);
    $missing = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::ClaimHardBindingsMissing));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and($missing)->toHaveCount(1)
        ->and($missing[0]->ingredientUri)->toBeNull()
        ->and($missing[0]->url)->toContain((string) ($report->store?->active->label))
        ->and(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.dataHash.')))->toBe([]);
})->group('SPEC-022');

// AC6
it('AC6: a standard manifest with two parentOf ingredients is manifest.multipleParents', function (): void {
    $report = spec020Verify('update-manifest/two-parents.png', SPEC022_VARIANT_SETTINGS);
    $parents = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::ManifestMultipleParents));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and($parents)->toHaveCount(1)
        ->and($parents[0]->url)->toBe(sprintf('self#jumbf=/c2pa/%s/c2pa.claim.v2', $report->store?->active->label))
        ->and(spec021OracleFailures('update-manifest/two-parents.json'))->toBe(['manifest.multipleParents']);
})->group('SPEC-022');

// AC7
it('AC7: c2cm and c2tm stay refused; only c2um was opened', function (): void {
    $original = (string) file_get_contents(Corpus::fixtures().'/binding/no-hard-binding.bin');
    $uuidAt = strpos($original, (string) hex2bin('63326d61'), 8);   // the manifest box's c2ma UUID
    expect($uuidAt)->not->toBeFalse();

    foreach (['6332636d' => 'compressed manifests (c2cm)', '6332746d' => 'time-stamp manifests (c2tm)'] as $hex => $needle) {
        $edited = substr_replace($original, (string) hex2bin($hex), (int) $uuidAt, 4);
        expect(static fn () => (new JumbfParser)->parse($edited))->toThrow(JumbfException::class, $needle);
    }
    // c2um is read now: the box parses, and the manifest knows what it is
    $update = substr_replace($original, (string) hex2bin('6332756d'), (int) $uuidAt, 4);
    $tree = (new JumbfParser)->parse($update);
    $store = ManifestStore::fromTree($tree);
    expect($store->active->isUpdateManifest)->toBeTrue();
})->group('SPEC-022');

// AC8
it('AC8: an empty claim_generator_info counts as absent, like null', function (): void {
    $update = Corpus::manifestStore('c2pa-rs/update_manifest.jpg') ?? throw new RuntimeException('no store');
    $parent = $update->manifests[array_key_first($update->manifests)];
    expect($parent->claim->claimGeneratorInfo)->toBeNull();

    $ocsp = Corpus::manifestStore('c2pa-rs/ocsp.jpg') ?? throw new RuntimeException('no store');
    $first = $ocsp->manifests[(string) array_key_first($ocsp->manifests)];
    expect($first->claim->claimGeneratorInfo)->toBeNull();

    // and the rendering is c2patool's for both files
    foreach (['c2pa-rs/update_manifest.jpg' => 'c2pa-rs/update_manifest.json', 'c2pa-rs/ocsp.jpg' => 'c2pa-rs/ocsp.json'] as $relative => $oracle) {
        $store = Corpus::manifestStore($relative) ?? throw new RuntimeException($relative);
        $array = $store->toArray();
        assert(is_array($array['manifests']));
        foreach (array_keys($array['manifests']) as $label) {
            $mine = $array['manifests'][$label];
            assert(is_array($mine));
            $theirs = spec020OracleManifest($oracle, (string) $label);
            expect(array_key_exists('claim_generator_info', $mine))->toBe(array_key_exists('claim_generator_info', $theirs), "{$relative}: {$label}");
        }
    }
})->group('SPEC-022');

// AC9
it('AC9: the corpora are unchanged and update_manifest joins them', function (): void {
    expect(in_array('update_manifest', SPEC013_RS_MULTI, true))->toBeFalse('update_manifest still counts as refused')
        ->and(in_array('update_manifest', SPEC013_NOT_YET, true))->toBeFalse('update_manifest still counts as not-yet');
    $graph = ManifestGraph::fromStore(Corpus::manifestStore('c2pa-rs/update_manifest.jpg') ?? throw new RuntimeException('no store'));
    expect(array_keys($graph->referenced))->toHaveCount(1)
        ->and($graph->missing)->toBe([])
        ->and($graph->unreferenced)->toBe([]);
})->group('SPEC-022');
