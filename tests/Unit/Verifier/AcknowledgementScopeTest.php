<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\Relationship;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\IngredientManifestCheck;

/*
 * SPEC-070 amendment 3: a recorded status acknowledges a status about
 * manifest X only when the manifest holding the record is a proper
 * ancestor of X in the ingredient graph (C2PA 2.4 §18.16.12.4: a statement
 * by the manifest that takes the ingredient, about that ingredient).
 */

/**
 * An ingredient assertion in manifest $holder that names manifest $names and records $recorded.
 *
 * @param  list<array{code: string, url: string}>  $recorded
 */
function spec070Ingredient(string $holder, string $names, array $recorded): IngredientAssertion
{
    return new IngredientAssertion(
        'c2pa.ingredient', "self#jumbf=/c2pa/{$holder}/c2pa.assertions/c2pa.ingredient", 1,
        Relationship::ParentOf, 'x', 'image/jpeg', null, 'xmp:iid:1',
        new HashedUri("self#jumbf=/c2pa/{$names}", new CborBytes(str_repeat("\x00", 32)), null),
        null, null, $recorded, null, null, [],
    );
}

/**
 * The statuses acknowledged after the whole pipeline: the graph, the pooled records, acknowledge().
 *
 * @param  array<string, list<IngredientAssertion>>  $ingredients
 * @param  list<ValidationStatus>  $statuses
 * @return list<ValidationStatus>
 */
function spec070Acknowledged(array $ingredients, array $statuses): array
{
    $graph = ManifestGraph::fromIngredients('A', $ingredients, []);

    return (new IngredientManifestCheck)->acknowledge($statuses, IngredientManifestCheck::recordedInStore($graph), 'A', null);
}

// AC9
it('AC9: a manifest cannot acknowledge its own faults, nor its parent\'s hash of it', function (): void {
    $faults = [
        ['code' => 'claimSignature.mismatch', 'url' => 'self#jumbf=/c2pa/B/c2pa.signature'],
        ['code' => 'ingredient.manifest.mismatch', 'url' => 'self#jumbf=/c2pa/B'],
    ];
    $by = 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient';
    $statuses = [
        new ValidationStatus(StatusCode::ClaimSignatureValidated, 'self#jumbf=/c2pa/A/c2pa.signature', 'ok'),
        new ValidationStatus(StatusCode::IngredientManifestMismatch, 'self#jumbf=/c2pa/B', 'x', $by),
        new ValidationStatus(StatusCode::ClaimSignatureMismatch, 'self#jumbf=/c2pa/B/c2pa.signature', 'x', $by),
    ];
    // B, altered, records its own faults in an ingredient assertion of its own (about some C)
    $out = spec070Acknowledged([
        'A' => [spec070Ingredient('A', 'B', [])],
        'B' => [spec070Ingredient('B', 'C', $faults)],
    ], $statuses);

    expect(array_map(static fn (ValidationStatus $s): bool => $s->acknowledged, $out))->toBe([false, false, false])
        ->and(ValidationResult::fromStatuses($out, ['ingredients'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-070');

it('AC9: a cycle does not put a manifest below itself', function (bool $throughC): void {
    $fault = ['code' => 'claimSignature.mismatch', 'url' => 'self#jumbf=/c2pa/B/c2pa.signature'];
    $status = new ValidationStatus(StatusCode::ClaimSignatureMismatch, 'self#jumbf=/c2pa/B/c2pa.signature', 'x', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient');
    $ingredients = $throughC
        ? ['A' => [spec070Ingredient('A', 'B', [])], 'B' => [spec070Ingredient('B', 'C', [$fault])], 'C' => [spec070Ingredient('C', 'B', [])]]
        : ['A' => [spec070Ingredient('A', 'B', [])], 'B' => [spec070Ingredient('B', 'B', [$fault])]];

    expect(spec070Acknowledged($ingredients, [$status])[0]->acknowledged)->toBeFalse();
})->with([
    'B names itself' => [false],
    'B names C, C names B' => [true],
])->group('SPEC-070');

// AC10
it('AC10: a record speaks for what lies below it, and for nothing above or beside it', function (string $holder, string $about, bool $acknowledged): void {
    // A takes B, B takes C; the record is held by $holder and names a fault of $about
    $fault = ['code' => 'claimSignature.mismatch', 'url' => "self#jumbf=/c2pa/{$about}/c2pa.signature"];
    $ingredients = [
        'A' => [spec070Ingredient('A', 'B', $holder === 'A' ? [$fault] : [])],
        'B' => [spec070Ingredient('B', 'C', $holder === 'B' ? [$fault] : [])],
        'C' => [spec070Ingredient('C', 'D', $holder === 'C' ? [$fault] : [])],
    ];
    $status = new ValidationStatus(StatusCode::ClaimSignatureMismatch, "self#jumbf=/c2pa/{$about}/c2pa.signature", 'x', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient');

    expect(spec070Acknowledged($ingredients, [$status])[0]->acknowledged)->toBe($acknowledged);
})->with([
    'A about B, its ingredient' => ['A', 'B', true],
    'A about C, below its ingredient' => ['A', 'C', true],
    'B about C, its ingredient' => ['B', 'C', true],
    'B about B, itself' => ['B', 'B', false],
    'C about B, its parent' => ['C', 'B', false],
    'C about C, itself' => ['C', 'C', false],
])->group('SPEC-070');

it('AC10: a status whose url names no manifest is never acknowledged', function (): void {
    $status = new ValidationStatus(StatusCode::ClaimSignatureMismatch, 'self#jumbf=/c2pa', 'x', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient');
    $out = spec070Acknowledged(['A' => [spec070Ingredient('A', 'B', [['code' => 'claimSignature.mismatch', 'url' => 'self#jumbf=/c2pa']])]], [$status]);

    expect($out[0]->acknowledged)->toBeFalse();
})->group('SPEC-070');

// AC12, amendment 4 (step 410): no record speaks for a reference that closes a cycle

/** The cyclic status of a graph: the `assertion.ingredient.malformed` the walk reported for the reference back into the path. */
function spec070Cyclic(ManifestGraph $graph): ValidationStatus
{
    $cyclic = array_values(array_filter($graph->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionIngredientMalformed));
    expect($cyclic)->toHaveCount(1);

    return $cyclic[0];
}

it('AC12: the graph names every reference that closes a cycle', function (): void {
    $short = ManifestGraph::fromIngredients('A', ['A' => [spec070Ingredient('A', 'B', [])], 'B' => [spec070Ingredient('B', 'A', [])]], []);
    $long = ManifestGraph::fromIngredients('A', ['A' => [spec070Ingredient('A', 'B', [])], 'B' => [spec070Ingredient('B', 'C', [])], 'C' => [spec070Ingredient('C', 'B', [])]], []);
    $none = ManifestGraph::fromIngredients('A', ['A' => [spec070Ingredient('A', 'B', [])], 'B' => [spec070Ingredient('B', 'C', [])], 'C' => []], []);

    expect($short->cyclic)->toBe([spec070Cyclic($short)->url])
        ->and($short->cyclic)->toBe(['self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient'])
        ->and($long->cyclic)->toBe(['self#jumbf=/c2pa/C/c2pa.assertions/c2pa.ingredient'])
        ->and($none->cyclic)->toBe([]);
})->group('SPEC-070');

it('AC12: a record of a cyclic reference acknowledges nothing, whoever above it holds it', function (bool $long): void {
    $closer = $long ? 'C' : 'B';
    $record = ['code' => 'assertion.ingredient.malformed', 'url' => "self#jumbf=/c2pa/{$closer}/c2pa.assertions/c2pa.ingredient"];
    $a = [spec070Ingredient('A', 'B', [$record])];   // the active manifest records it
    $graph = ManifestGraph::fromIngredients('A', $long
        ? ['A' => $a, 'B' => [spec070Ingredient('B', 'C', [])], 'C' => [spec070Ingredient('C', 'B', [])]]
        : ['A' => $a, 'B' => [spec070Ingredient('B', 'A', [])]], []);
    $out = (new IngredientManifestCheck)->acknowledge([spec070Cyclic($graph)], IngredientManifestCheck::recordedInStore($graph), 'A', null);

    expect($out[0]->url)->toBe($record['url'])
        ->and($out[0]->acknowledged)->toBeFalse()
        ->and(ValidationResult::fromStatuses($out, [])->state)->toBe(ValidationState::Invalid);
})->with([
    'B takes A back' => [false],
    'C takes B back, below B' => [true],
])->group('SPEC-070');

it('AC12: in a graph with a cycle, a record still acknowledges another fault below it (amendment 3)', function (): void {
    $record = ['code' => 'claimSignature.mismatch', 'url' => 'self#jumbf=/c2pa/B/c2pa.signature'];
    $out = spec070Acknowledged(
        ['A' => [spec070Ingredient('A', 'B', [$record])], 'B' => [spec070Ingredient('B', 'A', [])]],
        [new ValidationStatus(StatusCode::ClaimSignatureMismatch, $record['url'], 'x', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient')],
    );

    expect($out[0]->acknowledged)->toBeTrue();
})->group('SPEC-070');

it('AC12: a malformed ingredient assertion that closes no cycle is still acknowledged by a record above it', function (): void {
    $url = 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient__1';
    $malformed = new ValidationStatus(StatusCode::AssertionIngredientMalformed, $url, 'x', $url);
    $graph = ManifestGraph::fromIngredients('A', [
        'A' => [spec070Ingredient('A', 'B', [['code' => 'assertion.ingredient.malformed', 'url' => $url]])],
        'B' => [],
    ], [], ['B' => [$malformed]]);
    $out = (new IngredientManifestCheck)->acknowledge($graph->statuses, IngredientManifestCheck::recordedInStore($graph), 'A', null);

    expect($graph->cyclic)->toBe([])
        ->and($out)->toHaveCount(1)
        ->and($out[0]->acknowledged)->toBeTrue();
})->group('SPEC-070');

it('AC12: end to end — a cycle acknowledged by the active manifest is Invalid; a chain without one stays Trusted', function (string $file, string $state): void {
    $report = spec020Verify("graph-fuzz-cycle-acknowledged/{$file}.png", 'graph-fuzz-cycle-acknowledged/graph-fuzz-root.settings.json');

    expect($report->result->state->value)->toBe($state);
    if ($state === 'Invalid') {
        expect(array_values(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure() && ! $s->acknowledged))))
            ->toBe(['assertion.ingredient.malformed']);
    }
})->with([
    ['cycle-back-to-the-active-acknowledged', 'Invalid'],
    ['cycle-below-the-active-acknowledged', 'Invalid'],
    ['a-chain-of-three', 'Trusted'],
])->group('SPEC-070');

it('AC4: a record never acknowledges a failure of the active manifest, even one that names it as below (C2PA 2.4 §18.16.12.4; step 438)', function (): void {
    // at the seam: a scoped failure about the active manifest, and a record whose holder has the active manifest
    // below it, as only a cycle could make it. The other guards stop such a record earlier; this one stands alone
    $active = 'urn:c2pa:00000000-0000-4000-8000-00000000000a';
    $url = "self#jumbf=/c2pa/{$active}/c2pa.signature";
    $status = new ValidationStatus(StatusCode::ClaimSignatureMismatch, $url, 'the claim signature does not verify', 'self#jumbf=/c2pa/urn:c2pa:00000000-0000-4000-8000-00000000000b/c2pa.assertions/c2pa.ingredient.v3');
    $recorded = [['key' => StatusCode::ClaimSignatureMismatch->value.' '.$url, 'below' => [$active]]];

    $out = (new IngredientManifestCheck)->acknowledge([$status], $recorded, $active, null);
    expect($out)->toHaveCount(1)
        ->and($out[0]->acknowledged)->toBeFalse()
        ->and($out[0]->code->isFailure())->toBeTrue();
    // and the same record for another manifest below is acknowledged: the record itself is well-formed
    $other = 'urn:c2pa:00000000-0000-4000-8000-00000000000c';
    $below = new ValidationStatus(StatusCode::ClaimSignatureMismatch, "self#jumbf=/c2pa/{$other}/c2pa.signature", 'the claim signature does not verify', $status->ingredientUri);
    $out = (new IngredientManifestCheck)->acknowledge([$below], [['key' => StatusCode::ClaimSignatureMismatch->value." self#jumbf=/c2pa/{$other}/c2pa.signature", 'below' => [$other]]], $active, null);
    expect($out[0]->acknowledged)->toBeTrue();
})->group('SPEC-070');
