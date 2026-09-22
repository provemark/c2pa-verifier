<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;

/*
 * SPEC-020 AC3, AC6, AC8, AC9: the report's second half — ingredientDeltas
 * as c2patool prints them on the sixteen single-manifest corpus files with
 * ingredients, the missing manifest of E-clm-CAICAI, the `ingredients`
 * rendering on every corpus file with ingredients, and the shape rules of
 * ValidationResult. AC10 (the corpus verdicts unchanged) is SPEC-013's
 * drift alarms and SPEC-019 AC11, which keep running.
 */

/*
 * The single-manifest corpus files that carry an ingredient assertion *in the file*. SPEC-020's
 * AC3 said sixteen and counted `c2pa-rs/cloud` and the Photoshop file among them; both declare
 * their manifest by URL and carry no store, so c2patool's JSON for them describes a manifest it
 * fetched over the network and there is nothing here to compare (SPEC-020 amendment 1).
 */
const SPEC020_SINGLE = [
    'public-testfiles/adobe-20220124-CA', 'public-testfiles/adobe-20220124-CAI', 'public-testfiles/adobe-20220124-CI', 'public-testfiles/adobe-20220124-CII',
    'public-testfiles/adobe-20220124-E-dat-CA', 'public-testfiles/adobe-20220124-E-sig-CA', 'public-testfiles/adobe-20220124-E-uri-CA',
    'public-testfiles/adobe-20220124-XCA', 'public-testfiles/adobe-20220124-XCI',
    'c2pa-rs/CA', 'c2pa-rs/CA_ct', 'c2pa-rs/E-sig-CA', 'c2pa-rs/XCA', 'c2pa-rs/boxhash',
    'writers/adobe-20260425-lightroom-classic-church',
];

/** @return array{0: string, 1: array<string, mixed>} the fixture path and the oracle JSON for a corpus name */
function spec020Corpus(string $name): array
{
    [$dir, $base] = explode('/', $name, 2);
    $files = array_values(array_filter(glob(Corpus::fixtures()."/{$dir}/{$base}.*") ?: [], static fn (string $f): bool => ! str_ends_with($f, '.json')));
    $path = $files[0] ?? throw new RuntimeException("no file for {$name}");

    return ["{$dir}/".basename($path), spec020Oracle("{$name}.json")];
}

/**
 * A report's or an oracle's active-manifest statuses of one kind, typed.
 *
 * @param  array<string, mixed>  $array
 * @return list<array<string, string>>
 */
function spec020Active(array $array, string $kind): array
{
    $results = $array['validation_results'] ?? [];
    assert(is_array($results) && is_array($results['activeManifest']));

    /** @var list<array<string, string>> */
    return array_values((array) $results['activeManifest'][$kind]);
}

/**
 * The ingredients one manifest of a store renders, typed.
 *
 * @param  array<string, mixed>  $array  a ManifestStore::toArray()
 * @return list<array<string, mixed>>
 */
function spec020Rendered(array $array, string $label): array
{
    assert(is_array($array['manifests']));
    $manifest = $array['manifests'][$label] ?? null;
    assert(is_array($manifest));

    /** @var list<array<string, mixed>> */
    return array_values((array) ($manifest['ingredients'] ?? []));
}

/**
 * The assertion labels one manifest of a store renders.
 *
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function spec020AssertionLabels(array $array, string $label): array
{
    assert(is_array($array['manifests']));
    $manifest = $array['manifests'][$label] ?? null;
    assert(is_array($manifest));

    /** @var list<string> */
    return array_column((array) $manifest['assertions'], 'label');
}

// AC3
it('AC3: the fifteen single-manifest files: ingredientDeltas equal c2patool\'s, informational, in order', function (): void {
    expect(SPEC020_SINGLE)->toHaveCount(15);
    foreach (SPEC020_SINGLE as $name) {
        [$relative, $oracle] = spec020Corpus($name);
        $report = spec020Verify($relative, 'trust/full.settings.json');
        $array = $report->toArray();

        expect(spec020Deltas($array))->toBe(spec020Deltas($oracle), $name)
            ->and(spec020Deltas($array))->not->toBe([], $name);
        foreach (spec020Active($array, 'informational') as $status) {
            expect(str_starts_with($status['code'], 'ingredient.'))->toBeFalse("{$name}: {$status['code']} under activeManifest");
        }
        foreach ($report->result->statuses as $status) {
            if ($status->code->isFailure()) {
                expect(str_starts_with($status->code->value, 'ingredient.'))->toBeFalse("{$name}: a new failure {$status->code->value}");
            }
        }
        expect($array['validation_state'])->toBe($oracle['validation_state'], $name);
    }
})->group('SPEC-020');

// AC6
it('AC6: E-clm-CAICAI: ingredient.manifest.missing with the bare label, scoped, next to the amendment-5 refusal', function (): void {
    $report = spec020Verify('public-testfiles/adobe-20220124-E-clm-CAICAI.jpg', 'trust/full.settings.json');
    $missing = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientManifestMissing));
    $refusals = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::GeneralError));

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->url)->toBe('contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019')
        ->and($missing[0]->ingredientUri)->toBe('self#jumbf=/c2pa/contentauth:urn:uuid:a4ec0a2e-2a4a-4652-bede-762c0362b236/c2pa.assertions/c2pa.ingredient__1')
        ->and($refusals)->toBe([])   // SPEC-021 lifted the multi-manifest refusal: the file is Invalid on its own merits now
        ->and($report->result->state)->toBe(ValidationState::Invalid);
    $array = $report->toArray();
    $codes = array_column(spec020Failures($array), 'code');
    expect(array_values(array_unique($codes)))->toContain('ingredient.manifest.missing')
        ->and(count(array_keys($codes, 'ingredient.manifest.missing', true)))->toBe(1);
    // c2patool: the same code and url in the delta of the same assertion
    [, $oracle] = spec020Corpus('public-testfiles/adobe-20220124-E-clm-CAICAI');
    $delta = null;
    foreach (spec020Deltas($oracle) as $d) {
        if ($d['ingredientAssertionURI'] === $missing[0]->ingredientUri) {
            $delta = $d;
        }
    }
    expect($delta)->not->toBeNull()
        ->and($delta['validationDeltas']['failure'][0]['code'] ?? null)->toBe('ingredient.manifest.missing')
        ->and($delta['validationDeltas']['failure'][0]['url'] ?? null)->toBe($missing[0]->url);
})->group('SPEC-020');

// AC8
it('AC8: ingredients render as c2patool prints them, and leave the assertions list', function (): void {
    $keys = ['title', 'format', 'document_id', 'instance_id', 'relationship', 'active_manifest', 'label', 'manifest_data', 'thumbnail', 'metadata', 'validation_status'];
    $names = [...SPEC020_SINGLE, ...array_keys(spec020Multi())];
    $compared = 0;
    foreach ($names as $name) {
        [$relative, $oracle] = spec020Corpus($name);
        $store = Corpus::manifestStore($relative) ?? throw new RuntimeException($relative);
        $array = $store->toArray();
        assert(is_array($oracle['manifests']));
        foreach (array_keys($oracle['manifests']) as $manifestLabel) {
            $label = (string) $manifestLabel;
            $manifest = spec020OracleManifest("{$name}.json", $label);
            $expected = (array) ($manifest['ingredients'] ?? []);
            $rendered = spec020Rendered($array, $label);
            expect(count($rendered))->toBe(count($expected), "{$name}: {$label}: ingredient count");
            foreach ($expected as $n => $theirs) {
                assert(is_array($theirs) && is_int($n));
                $mine = $rendered[$n];
                foreach ($keys as $key) {
                    expect(array_key_exists($key, $mine))->toBe(array_key_exists($key, $theirs), "{$name}: {$label}[{$n}].{$key} presence");
                    if (array_key_exists($key, $theirs)) {
                        expect($mine[$key])->toBe($theirs[$key], "{$name}: {$label}[{$n}].{$key}");
                    }
                }
                expect(array_key_exists('validation_results', $mine))->toBe(array_key_exists('validation_results', $theirs), "{$name}: {$label}[{$n}].validation_results presence");
                if (array_key_exists('validation_results', $theirs)) {
                    expect(array_keys((array) $mine['validation_results']))->toBe(array_keys((array) $theirs['validation_results']), "{$name}: {$label}[{$n}].validation_results keys");
                }
                $compared++;
            }
            $labels = spec020AssertionLabels($array, $label);
            foreach ($labels as $assertionLabel) {
                expect(str_starts_with($assertionLabel, 'c2pa.ingredient') || str_starts_with($assertionLabel, 'c2pa.thumbnail.ingredient'))->toBeFalse("{$name}: {$label}: {$assertionLabel} still listed");
            }
            expect(count($labels))->toBe(count((array) $manifest['assertions']), "{$name}: {$label}: assertion count");
        }
    }
    expect($compared)->toBeGreaterThan(60);
})->group('SPEC-020');

// AC9
it('AC9: scoped statuses land in ingredientDeltas, the flat list and the state follow c2pa-rs', function (): void {
    $active = 'self#jumbf=/c2pa/urn:c2pa:active';
    $a = "{$active}/c2pa.assertions/c2pa.ingredient.v3";
    $b = "{$active}/c2pa.assertions/c2pa.ingredient.v3__1";
    $validated = new ValidationStatus(StatusCode::ClaimSignatureValidated, "{$active}/c2pa.signature", 'ok');
    $inside = new ValidationStatus(StatusCode::AssertionDataHashMatch, "{$active}/c2pa.assertions/c2pa.hash.data", 'ok');
    $trusted = new ValidationStatus(StatusCode::SigningCredentialTrusted, "{$active}/c2pa.signature", 'ok');
    $untrustedActive = new ValidationStatus(StatusCode::SigningCredentialUntrusted, "{$active}/c2pa.signature", 'no');
    $unknownA = new ValidationStatus(StatusCode::IngredientUnknownProvenance, $a, 'x: ingredient does not have provenance', $a);
    $untrustedB = new ValidationStatus(StatusCode::SigningCredentialUntrusted, 'self#jumbf=/c2pa/urn:c2pa:ingredient/c2pa.signature', 'no', $b);
    $missingB = new ValidationStatus(StatusCode::IngredientManifestMissing, 'urn:c2pa:gone', 'ingredient not found', $b);

    // no scoped status: no key
    $none = ValidationResult::fromStatuses([$validated, $inside, $trusted], ['signature'])->toArray();
    expect(spec020Deltas($none))->toBe([])
        ->and($none['validation_state'])->toBe('Trusted');

    // scoped: grouped by URI in first-seen order, kinds separated, active untouched
    $result = ValidationResult::fromStatuses([$validated, $inside, $trusted, $untrustedB, $unknownA, $missingB], ['signature']);
    $array = $result->toArray();
    expect(array_column(spec020Active($array, 'success'), 'code'))->toBe(['claimSignature.validated', 'assertion.dataHash.match', 'signingCredential.trusted'])
        ->and(spec020Deltas($array))->toBe([
            ['ingredientAssertionURI' => $b, 'validationDeltas' => ['success' => [], 'informational' => [], 'failure' => [$untrustedB->toArray(), $missingB->toArray()]]],
            ['ingredientAssertionURI' => $a, 'validationDeltas' => ['success' => [], 'informational' => [$unknownA->toArray()], 'failure' => []]],
        ])
        ->and(spec020Failures($array))->toBe([$untrustedB->toArray(), $missingB->toArray()])
        ->and($array['validation_state'])->toBe('Invalid');

    // the state rule: a scoped untrusted alone keeps Valid, and denies Trusted
    expect(ValidationResult::fromStatuses([$validated, $inside, $trusted, $untrustedB], [])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$validated, $inside, $untrustedActive, $untrustedB], [])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$validated, $inside, $trusted, $unknownA], [])->state)->toBe(ValidationState::Trusted)
        ->and(ValidationResult::fromStatuses([$validated, $inside, $trusted, $missingB], [])->state)->toBe(ValidationState::Invalid);
    // the flat list: active failures first, then the deltas' in order
    $mixed = ValidationResult::fromStatuses([$validated, $inside, $missingB, $untrustedActive], [])->toArray();
    expect(array_column(spec020Failures($mixed), 'code'))->toBe(['signingCredential.untrusted', 'ingredient.manifest.missing']);
    // ValidationStatus::toArray() stays three keys: the scope is where it renders, not what it says
    expect(array_keys($missingB->toArray()))->toBe(['code', 'url', 'explanation']);
})->group('SPEC-020');
