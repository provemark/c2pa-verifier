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

const SPEC021_VARIANT_SETTINGS = 'ingredient-manifest/throw-away-root.settings.json';

/** @return list<string> the failure codes of a report, unique and sorted */
function spec021Failures(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure() && ! $status->acknowledged) {   // SPEC-070: acknowledged is informational
            $codes[$status->code->value] = true;
        }
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
it('AC3: an inputTo ingredient\'s manifest is validated as any other (C2PA 2.4 §15.11.2.1, step 432)', function (): void {
    // the update manifest's one ingredient is inputTo; its manifest is the Adobe parent, hashed and validated
    $report = spec020Verify('update-manifest/ingredient-inputto.jpg', 'update-manifest/throw-away-root.settings.json');
    $validated = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientManifestValidated));

    expect($validated)->toHaveCount(1)
        ->and($validated[0]->ingredientUri)->toEndWith('/c2pa.assertions/c2pa.ingredient.v3')
        ->and($validated[0]->url)->toBe('self#jumbf=/c2pa/adobe:urn:uuid:6a6b4dbe-5ef9-40ec-81ec-d8955db3e95c/c2pa.claim');
})->group('SPEC-021');

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
    // SPEC-070: the one about the active manifest stays a failure; the other is kept, marked acknowledged
    // the record is held by the active manifest, so it may speak for the ingredient below it (SPEC-070 amendment 3)
    $kept = (new IngredientManifestCheck)->acknowledge($mine, spec070Records($keys, ['urn:c2pa:ingredient']), $active, null);
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_values(array_filter($kept, static fn (ValidationStatus $s): bool => ! $s->acknowledged))))->toBe(['claimSignature.mismatch'])
        ->and(array_map(static fn (ValidationStatus $s): bool => $s->acknowledged, $kept))->toBe([false, true]);

    // and on the file: both recorded statuses name the active manifest, so neither is dropped
    $report = spec020Verify('ingredient-manifest/records-active-fault.png', SPEC021_VARIANT_SETTINGS);
    $oracle = spec020Oracle('ingredient-manifest/records-active-fault.json');
    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec021Failures($report))->toBe(['claimSignature.mismatch'])
        ->and(spec021Failures($report))->toBe(spec021OracleFailures('ingredient-manifest/records-active-fault.json'))
        ->and(array_column(spec020Deltas($report->toArray())[0]['validationDeltas']['informational'], 'code'))->toBe(['ingredient.unknownProvenance'])
        ->and(array_column(spec020Deltas($oracle)[0]['validationDeltas']['informational'], 'code'))->toBe(['ingredient.unknownProvenance']);
})->group('SPEC-021');

// AC6, amendment 5 (SPEC-035): the refusal is lifted; the redaction's own faults are reported instead
it('AC6: a redaction is judged by SPEC-035\'s rules, no longer refused', function (): void {
    $report = spec020Verify('ingredient-manifest/redacted.png', SPEC021_VARIANT_SETTINGS);
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(in_array('general.error', $codes, true))->toBeFalse()
        ->and(in_array('assertion.selfRedacted', $codes, true))->toBeTrue()
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

    expect($multi->result->checksPerformed)->toBe(['timestamp', 'signature', 'certificate', 'trust', 'revocation', 'hashedUris', 'actions', 'ingredients', 'dataHash'])
        ->and($single->result->checksPerformed)->toBe(['timestamp', 'signature', 'certificate', 'trust', 'revocation', 'hashedUris', 'actions', 'dataHash']);
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

// AC11, amendment 6 (step 149): the recorded set comes from the manifests the graph validates
/** @param  list<array<string, string>>  $recorded  the v1 validationStatus the assertion carries */
function spec021Recording(string $manifestLabel, string $label, string $referenced, Relationship $relationship, array $recorded): IngredientAssertion
{
    return new IngredientAssertion(
        $label, "self#jumbf=/c2pa/{$manifestLabel}/c2pa.assertions/{$label}", 1,
        $relationship, 'x', 'image/jpeg', null, 'xmp:iid:1',
        new HashedUri("self#jumbf=/c2pa/{$referenced}", new CborBytes(str_repeat("\x00", 32)), null),
        null, null, $recorded, null, null, [],
    );
}

it('AC11: an ingredient assertion of a manifest the graph never reaches records nothing', function (): void {
    // A (active) is parentOf B; S names B too, but nothing names S, so the walk never reaches it
    $fault = ['code' => 'assertion.hashedURI.mismatch', 'url' => 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.hash.data', 'explanation' => 'x'];
    $noted = ['code' => 'ingredient.unknownProvenance', 'url' => 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient', 'explanation' => 'x'];
    $graph = ManifestGraph::fromIngredients('A', [
        'A' => [spec021Recording('A', 'c2pa.ingredient', 'B', Relationship::ParentOf, [$noted])],
        'B' => [],
        'S' => [spec021Recording('S', 'c2pa.ingredient', 'B', Relationship::ComponentOf, [$fault])],
    ], []);

    expect($graph->unreferenced)->toBe(['S'])
        ->and(IngredientManifestCheck::recordedInStore($graph))->toBe(spec070Records(['ingredient.unknownProvenance self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient'], ['B']));
})->group('SPEC-021');

/**
 * @param  list<ValidationStatus>  $statuses
 * @return list<string> "code url" per status
 */
function spec021Keys(array $statuses): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value.' '.$s->url, $statuses);
}

/**
 * The keys of the statuses SPEC-070 left unmarked: what still counts.
 *
 * @param  list<ValidationStatus>  $statuses
 * @return list<string>
 */
function spec021Unmarked(array $statuses): array
{
    return spec021Keys(array_values(array_filter($statuses, static fn (ValidationStatus $s): bool => ! $s->acknowledged)));
}

it('AC11: a fault of the update manifest\'s binding manifest is never dropped; another manifest\'s still is', function (): void {
    $by = 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient';
    $statuses = [
        new ValidationStatus(StatusCode::AssertionHashedUriMismatch, 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.hash.data', 'x', $by),
        new ValidationStatus(StatusCode::IngredientUnknownProvenance, 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient', 'x', $by),
        new ValidationStatus(StatusCode::AssertionHashedUriMismatch, 'self#jumbf=/c2pa/C/c2pa.assertions/c2pa.hash.data', 'x', $by),
    ];
    $recorded = spec070Records([
        'assertion.hashedURI.mismatch self#jumbf=/c2pa/B/c2pa.assertions/c2pa.hash.data',
        'ingredient.unknownProvenance self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient',
        'assertion.hashedURI.mismatch self#jumbf=/c2pa/C/c2pa.assertions/c2pa.hash.data',
    ], ['B', 'C']);   // held by A, which has B and C below it (SPEC-070 amendment 3)
    $check = new IngredientManifestCheck;

    // B binds: its failure stays unmarked; its informational line and C's failure are acknowledged (SPEC-070; they went before)
    $unmarked = spec021Unmarked(...);
    expect($unmarked($check->acknowledge($statuses, $recorded, 'A', 'B')))->toBe(['assertion.hashedURI.mismatch self#jumbf=/c2pa/B/c2pa.assertions/c2pa.hash.data'])
        // without an update manifest there is no binding manifest to guard, and all three are acknowledged
        ->and($unmarked($check->acknowledge($statuses, $recorded, 'A', null)))->toBe([]);
})->group('SPEC-021');

// AC12, amendment 7 (step 408): every reference's hash, not only the first one's
const SPEC021_GRAPH_SETTINGS = 'graph-fuzz/graph-fuzz-root.settings.json';

/**
 * The hash statuses of a report, as "code under-assertion" pairs: the assertion label each was found under.
 *
 * @return list<string>
 */
function spec021HashStatuses(VerificationReport $report): array
{
    $hash = [StatusCode::IngredientManifestValidated, StatusCode::IngredientManifestMismatch, StatusCode::IngredientClaimSignatureValidated, StatusCode::IngredientClaimSignatureMismatch];
    $out = [];
    foreach ($report->result->statuses as $status) {
        if (in_array($status->code, $hash, true)) {
            $out[] = $status->code->value.' '.substr((string) $status->ingredientUri, (int) strrpos((string) $status->ingredientUri, '/') + 1);
        }
    }

    return $out;
}

it('AC12: a manifest named twice has the hash of each reference checked, under that reference', function (string $file, array $hashes): void {
    $report = spec020Verify("graph-fuzz/{$file}.png", SPEC021_GRAPH_SETTINGS);
    $oracle = spec020Oracle("graph-fuzz/{$file}.json");

    expect(spec021HashStatuses($report))->toBe($hashes)
        ->and($report->result->state->value)->toBe($oracle['validation_state'])
        ->and($report->result->state)->toBe(ValidationState::Invalid);
    // c2patool's failures, delta by delta
    foreach (spec020Deltas($oracle) as $delta) {
        $theirs = array_map(static fn (array $s): string => $s['code'], $delta['validationDeltas']['failure']);
        $ours = array_values(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter(
            spec021Scoped($report),
            static fn (ValidationStatus $s): bool => $s->ingredientUri === $delta['ingredientAssertionURI'] && $s->code->isFailure() && ! $s->acknowledged,
        )));
        expect($ours)->toBe($theirs, $delta['ingredientAssertionURI']);
    }
})->with([
    'the box hash' => ['ingredient-second-reference-wrong-hash', ['ingredient.manifest.validated c2pa.ingredient.v3', 'ingredient.manifest.mismatch c2pa.ingredient.v3__1']],
    'the claim-signature hash, for a manifest redacted from' => ['redacted-ingredient-second-reference-wrong-hash', ['ingredient.claimSignature.validated c2pa.ingredient.v3', 'ingredient.claimSignature.mismatch c2pa.ingredient.v3__1']],
])->group('SPEC-021');

it('AC12: the manifest itself is still validated once, under the first reference (AC9)', function (): void {
    $report = spec020Verify('graph-fuzz/ingredient-second-reference-wrong-hash.png', SPEC021_GRAPH_SETTINGS);
    $signature = array_values(array_filter(spec021Scoped($report), static fn (ValidationStatus $s): bool => $s->code === StatusCode::ClaimSignatureValidated));

    expect($signature)->toHaveCount(1)
        ->and((string) $signature[0]->ingredientUri)->toEndWith('/c2pa.assertions/c2pa.ingredient.v3');
})->group('SPEC-021');

it('AC12: the controls — one reference with a wrong hash, and no fault at all — are what c2patool says', function (string $file, string $state): void {
    $report = spec020Verify("graph-fuzz/{$file}.png", SPEC021_GRAPH_SETTINGS);

    expect($report->result->state->value)->toBe($state)
        ->and($state)->toBe(spec020Oracle("graph-fuzz/{$file}.json")['validation_state']);
})->with([
    ['one-manifest', 'Trusted'],
    ['ingredient-wrong-hash', 'Invalid'],
])->group('SPEC-021');
