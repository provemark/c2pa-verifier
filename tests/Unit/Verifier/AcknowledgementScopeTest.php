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
