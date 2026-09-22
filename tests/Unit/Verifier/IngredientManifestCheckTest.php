<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\Relationship;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\IngredientManifestCheck;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-021: the manifests the graph found, validated. Oracles: c2patool
 * 0.27.22 on the eighteen multi-manifest corpus files (--settings
 * full.settings.json) and on the three signed variants of step 56
 * (tests/Fixtures/ingredient-manifest/, with their throw-away settings).
 */

const SPEC021_SETTINGS = 'trust/full.settings.json';
const SPEC021_VARIANT_SETTINGS = 'ingredient-manifest/throw-away-root.settings.json';

/** @return list<string> the failure codes of a report, unique and sorted */
function spec021Failures(VerificationReport $report): array
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

/** @return list<string> the failure codes of an oracle's validation_status, unique and sorted */
function spec021OracleFailures(string $relative): array
{
    $oracle = spec020Oracle($relative);
    $codes = [];
    foreach ((array) ($oracle['validation_status'] ?? []) as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[$status['code']] = true;
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/** @return list<ValidationStatus> the statuses scoped to an ingredient assertion */
function spec021Scoped(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri !== null));
}

// AC1
it('AC1: the box hash — validated, legacy (silent), mismatch', function (): void {
    // the box form: c2pa-rs's v3 reference hashes the manifest superbox's payload
    $report = spec020Verify('c2pa-rs/CACA.jpg', SPEC021_SETTINGS);
    $validated = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientManifestValidated));
    expect($validated)->toHaveCount(1)
        ->and($validated[0]->url)->toBe('self#jumbf=/c2pa/urn:c2pa:5259041e-8171-49c9-acc2-915f1a48e3a5:contentauth')
        ->and($validated[0]->ingredientUri)->toBe('self#jumbf=/c2pa/urn:c2pa:fff4c43d-ffb0-4f23-bd0b-f47f1cf82057:contentauth/c2pa.assertions/c2pa.ingredient.v3');
    // and c2patool says the same, in the same delta
    $delta = spec020Deltas(spec020Oracle('c2pa-rs/CACA.json'))[0];
    expect($delta['ingredientAssertionURI'])->toBe($validated[0]->ingredientUri)
        ->and($delta['validationDeltas']['success'][0]['code'])->toBe('ingredient.manifest.validated')
        ->and($delta['validationDeltas']['success'][0]['url'])->toBe($validated[0]->url);

    // the legacy form: the ten Adobe 2022 files hash the claim's CBOR bytes — neither success nor failure
    $legacy = spec020Verify('public-testfiles/adobe-20220124-CACA.jpg', SPEC021_SETTINGS);
    foreach ($legacy->result->statuses as $status) {
        expect(in_array($status->code, [StatusCode::IngredientManifestValidated, StatusCode::IngredientManifestMismatch], true))->toBeFalse($status->code->value);
    }
    expect($legacy->result->state)->toBe(ValidationState::Trusted);

    // the mismatch: a reference that matches neither form
    $mismatch = spec021Mismatch();
    expect($mismatch[0]->code)->toBe(StatusCode::IngredientManifestMismatch)
        ->and($mismatch[0]->url)->toBe('self#jumbf=/c2pa/urn:c2pa:5259041e-8171-49c9-acc2-915f1a48e3a5:contentauth');
})->group('SPEC-021');

/**
 * The hash statuses for c2pa-rs/CACA with the reference's hash replaced by one that matches nothing:
 * the seam, since no corpus file has a mismatching reference.
 *
 * @return list<ValidationStatus>
 */
function spec021Mismatch(): array
{
    $store = Corpus::manifestStore('c2pa-rs/CACA.jpg') ?? throw new RuntimeException('no store');
    $graph = ManifestGraph::fromStore($store);
    $ingredient = $graph->ingredients[$store->active->label][0];
    $reference = $ingredient->manifest ?? throw new RuntimeException('no reference');
    $broken = new IngredientAssertion(
        $ingredient->label, $ingredient->url, $ingredient->version, $ingredient->relationship,
        $ingredient->title, $ingredient->format, $ingredient->documentId, $ingredient->instanceId,
        new HashedUri($reference->url, new CborBytes(str_repeat("\x00", 32)), $reference->alg),
        $ingredient->claimSignature, $ingredient->thumbnail, $ingredient->validationStatus,
        $ingredient->validationResults, $ingredient->digitalSourceType, $ingredient->data,
    );
    $label = $ingredient->manifestLabel() ?? throw new RuntimeException('no reference');

    return (new IngredientManifestCheck)->hash($store->manifests[$label], $broken);
}

// AC2
it('AC2: a matching box hash does not stand in for validating the manifest', function (): void {
    $report = spec020Verify('ingredient-manifest/ingredient-signature-broken.jpg', SPEC021_VARIANT_SETTINGS);
    $scoped = spec021Scoped($report);
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $scoped);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and($codes)->toContain('ingredient.manifest.validated')
        ->and($codes)->toContain('claimSignature.mismatch');
    // one scope for the validation statuses: the active manifest's ingredient assertion. (The graph's
    // own `ingredient.unknownProvenance` for the ingredient manifest's parent is scoped to that
    // manifest's assertion — SPEC-020's, and a second scope in the report.)
    $validation = array_values(array_filter($scoped, static fn (ValidationStatus $s): bool => $s->code !== StatusCode::IngredientUnknownProvenance));
    expect(array_values(array_unique(array_map(static fn (ValidationStatus $s): string => (string) $s->ingredientUri, $validation))))->toBe(['self#jumbf=/c2pa/urn:c2pa:fff4c43d-ffb0-4f23-bd0b-f47f1cf82057:contentauth/c2pa.assertions/c2pa.ingredient.v3']);
    // c2patool: the same two codes in the same delta, and the same state
    $oracle = spec020Oracle('ingredient-manifest/ingredient-signature-broken.json');
    $delta = spec020Deltas($oracle)[0];
    expect($oracle['validation_state'])->toBe('Invalid')
        ->and(array_column($delta['validationDeltas']['success'], 'code'))->toContain('ingredient.manifest.validated')
        ->and(array_column($delta['validationDeltas']['failure'], 'code'))->toContain('claimSignature.mismatch')
        ->and(spec021Failures($report))->toBe(spec021OracleFailures('ingredient-manifest/ingredient-signature-broken.json'));
})->group('SPEC-021');

// AC3
it('AC3: every multi-manifest corpus file: the state and the failure codes are c2patool\'s', function (): void {
    $expired = ['c2pa-rs/ocsp', 'c2pa-rs/ocsp_with_assertion', 'c2pa-rs/exp-test1'];
    $refused = ['writers/c2pa-rs-cawg_ica'];   // a CAWG identity assertion: SPEC-013 amendment 7
    $checked = 0;
    foreach (spec020Multi() as $name => $relative) {
        $report = spec020Verify($relative, SPEC021_SETTINGS);
        $oracle = (string) preg_replace('/\.[a-z]+$/', '.json', $relative);
        $theirs = spec021OracleFailures($oracle);
        $ours = spec021Failures($report);
        if (in_array($name, $refused, true)) {
            // toContain() is variadic in Pest: a message would read as a second needle
            expect(in_array('general.error', $ours, true))->toBeTrue("{$name}: no refusal");
            $checked++;

            continue;
        }
        // the one named leniency: without a configured TSA the signer is judged at now (ADR-0004 decision 3)
        $ours = array_values(array_diff($ours, in_array($name, $expired, true) ? ['signingCredential.expired'] : []));
        expect($ours)->toBe($theirs, $name);
        $state = in_array($name, $expired, true) && $theirs === ['signingCredential.untrusted'] ? 'Invalid' : spec020Oracle($oracle)['validation_state'];
        expect($report->result->state->value)->toBe($state, $name)
            ->and(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::GeneralError))->toBe([], $name);
        $checked++;
    }
    expect($checked)->toBe(17);
})->group('SPEC-021');

// AC4
it('AC4: a fault the ingredient assertion recorded is dropped, and only that', function (): void {
    foreach (['public-testfiles/adobe-20220124-CIE-sig-CA.jpg' => 'claimSignature.mismatch', 'c2pa-rs/CIE-sig-CA.jpg' => 'claimSignature.mismatch', 'c2pa-rs/CACAE-uri-CA.jpg' => 'assertion.hashedURI.mismatch'] as $relative => $recorded) {
        $report = spec020Verify($relative, SPEC021_SETTINGS);
        expect($report->result->state)->toBe(ValidationState::Trusted, $relative)
            ->and(spec021Failures($report))->toBe([], $relative);
        // the record itself stays visible to the caller, in the ingredient's rendering
        $array = $report->toArray();
        $found = false;
        assert(is_array($array['manifests']));
        foreach ($array['manifests'] as $manifest) {
            assert(is_array($manifest));
            foreach ((array) ($manifest['ingredients'] ?? []) as $ingredient) {
                assert(is_array($ingredient));
                foreach ((array) ($ingredient['validation_status'] ?? []) as $status) {
                    assert(is_array($status));
                    $found = $found || $status['code'] === $recorded;
                }
            }
        }
        expect($found)->toBeTrue("{$relative}: the recorded {$recorded} is no longer rendered");
    }
    // the file where one fault was NOT recorded: it is reported, and the codes are c2patool's
    $report = spec020Verify('public-testfiles/adobe-20220124-E-uri-CIE-sig-CA.jpg', SPEC021_SETTINGS);
    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec021Failures($report))->toBe(spec021OracleFailures('public-testfiles/adobe-20220124-E-uri-CIE-sig-CA.json'))
        ->and(spec021Failures($report))->toBe(['assertion.hashedURI.mismatch']);
})->group('SPEC-021');

// AC5
it('AC5: a recorded status never cancels the active manifest\'s own failure', function (): void {
    // the seam: a status whose url names the active manifest is kept even when the record matches it
    $active = 'urn:c2pa:active';
    $recorded = [
        ['code' => 'claimSignature.mismatch', 'url' => "self#jumbf=/c2pa/{$active}/c2pa.signature", 'explanation' => 'x'],
        ['code' => 'assertion.hashedURI.mismatch', 'url' => 'self#jumbf=c2pa.assertions/c2pa.actions', 'explanation' => 'x'],
    ];
    $ingredient = new IngredientAssertion(
        'c2pa.ingredient', "self#jumbf=/c2pa/{$active}/c2pa.assertions/c2pa.ingredient", 1,
        Relationship::ComponentOf, 'x', 'image/png', null, 'xmp:iid:1',
        new HashedUri('self#jumbf=/c2pa/urn:c2pa:ingredient', new CborBytes(str_repeat("\x00", 32)), null),
        null, null, $recorded, null, null, [],
    );
    $keys = IngredientManifestCheck::recorded($ingredient);
    expect($keys)->toBe([
        "claimSignature.mismatch self#jumbf=/c2pa/{$active}/c2pa.signature",
        'assertion.hashedURI.mismatch self#jumbf=/c2pa/urn:c2pa:ingredient/c2pa.assertions/c2pa.actions',
    ]);
    $mine = [
        new ValidationStatus(StatusCode::ClaimSignatureMismatch, "self#jumbf=/c2pa/{$active}/c2pa.signature", 'x', $ingredient->url),
        new ValidationStatus(StatusCode::AssertionHashedUriMismatch, 'self#jumbf=/c2pa/urn:c2pa:ingredient/c2pa.assertions/c2pa.actions', 'x', $ingredient->url),
    ];
    $kept = (new IngredientManifestCheck)->drop($mine, $keys, $active);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $kept))->toBe(['claimSignature.mismatch']);

    // and on the file: both recorded statuses name the active manifest, so neither is dropped
    $report = spec020Verify('ingredient-manifest/records-active-fault.png', SPEC021_VARIANT_SETTINGS);
    $oracle = spec020Oracle('ingredient-manifest/records-active-fault.json');
    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec021Failures($report))->toBe(['claimSignature.mismatch'])
        ->and(spec021Failures($report))->toBe(spec021OracleFailures('ingredient-manifest/records-active-fault.json'))
        ->and(array_column(spec020Deltas($report->toArray())[0]['validationDeltas']['informational'], 'code'))->toBe(['ingredient.unknownProvenance'])
        ->and(array_column(spec020Deltas($oracle)[0]['validationDeltas']['informational'], 'code'))->toBe(['ingredient.unknownProvenance']);
})->group('SPEC-021');

// AC6
it('AC6: a redaction is refused, and no ingredient is validated in that store', function (): void {
    $report = spec020Verify('ingredient-manifest/redacted.png', SPEC021_VARIANT_SETTINGS);
    $refusals = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::GeneralError));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and($refusals)->toHaveCount(1)
        ->and(str_contains($refusals[0]->explanation, 'redact'))->toBeTrue($refusals[0]->explanation)
        ->and(spec021Scoped($report))->toBe([])
        ->and(spec020Oracle('ingredient-manifest/redacted.json')['validation_state'])->toBe('Invalid');
})->group('SPEC-021');

// AC7
it('AC7: the data hash never runs on an ingredient manifest', function (): void {
    $report = spec020Verify('public-testfiles/adobe-20220124-CACA.jpg', SPEC021_SETTINGS);
    $dataHash = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.dataHash.')));

    expect($dataHash)->toHaveCount(1)
        ->and($dataHash[0]->code)->toBe(StatusCode::AssertionDataHashMatch)
        ->and($dataHash[0]->ingredientUri)->toBeNull()
        ->and($dataHash[0]->url)->toContain($report->store?->active->label ?? 'x');
    // the ingredient manifest does carry a hard binding of its own — it binds *its* asset, not this file
    $store = Corpus::manifestStore('public-testfiles/adobe-20220124-CACA.jpg') ?? throw new RuntimeException('no store');
    $ingredientLabel = (string) array_key_first($store->manifests);
    expect(array_key_exists('c2pa.hash.data', $store->manifests[$ingredientLabel]->assertions))->toBeTrue();
})->group('SPEC-021');

// AC8
it('AC8: checks_performed gains ingredients, and only where the graph reached a manifest', function (): void {
    $multi = spec020Verify('public-testfiles/adobe-20220124-CACA.jpg', SPEC021_SETTINGS);
    $single = spec020Verify('public-testfiles/adobe-20220124-CA.jpg', SPEC021_SETTINGS);

    expect($multi->result->checksPerformed)->toBe(['timestamp', 'signature', 'certificate', 'trust', 'hashedUris', 'actions', 'ingredients', 'dataHash'])
        ->and($single->result->checksPerformed)->toBe(['timestamp', 'signature', 'certificate', 'trust', 'hashedUris', 'actions', 'dataHash']);
    // the deltas are grouped by assertion URI in walk order, active failures first in the flat list
    $graph = ManifestGraph::fromStore($multi->store ?? throw new RuntimeException('no store'));
    $uris = array_column(spec020Deltas($multi->toArray()), 'ingredientAssertionURI');
    expect($uris)->toBe(array_values(array_filter($graph->walk, static fn (string $uri): bool => in_array($uri, $uris, true))));
})->group('SPEC-021');

// AC9
it('AC9: a manifest named by more than one assertion is validated once', function (): void {
    $relative = 'public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg';
    $report = spec020Verify($relative, SPEC021_SETTINGS);
    $store = Corpus::manifestStore($relative) ?? throw new RuntimeException('no store');
    $graph = ManifestGraph::fromStore($store);
    $twice = array_values(array_filter($graph->referenced, static fn (array $by): bool => count($by) > 1));

    expect($twice)->not->toBe([]);
    // every status this spec adds belongs to the assertion that named its manifest first (the graph's
    // own statuses — unknown provenance, missing, malformed — are SPEC-020's and stay where they were)
    $graphCodes = [StatusCode::IngredientUnknownProvenance, StatusCode::IngredientManifestMissing, StatusCode::AssertionIngredientMalformed];
    $validation = array_values(array_filter(spec021Scoped($report), static fn (ValidationStatus $s): bool => ! in_array($s->code, $graphCodes, true)));
    expect($validation)->not->toBe([]);
    foreach ($graph->referenced as $label => $by) {
        foreach ($validation as $status) {
            if (str_contains($status->url, $label)) {
                expect($status->ingredientUri)->toBe($by[0], "{$label}: scoped to a later assertion");
            }
        }
    }
    expect($report->result->state->value)->toBe(spec020Oracle('public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.json')['validation_state'])
        ->and(spec021Failures($report))->toBe([]);
})->group('SPEC-021');
