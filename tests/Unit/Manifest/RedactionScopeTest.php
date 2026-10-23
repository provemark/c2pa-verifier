<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Manifest\Relationship;

/*
 * SPEC-035 amendment 6: a redaction declared by manifest R applies only to
 * an assertion of a manifest below R in the ingredient graph (C2PA 2.4
 * §6.8): never to the active manifest, nor to one above or beside R.
 */

function spec035Takes(string $holder, string $names): IngredientAssertion
{
    return new IngredientAssertion(
        'c2pa.ingredient', "self#jumbf=/c2pa/{$holder}/c2pa.assertions/c2pa.ingredient", 1,
        Relationship::ParentOf, 'x', 'image/jpeg', null, 'xmp:iid:1',
        new HashedUri("self#jumbf=/c2pa/{$names}", new CborBytes(str_repeat("\x00", 32)), null),
        null, null, null, null, null, [],
    );
}

/** A takes B, B takes C. */
function spec035Graph(): ManifestGraph
{
    return ManifestGraph::fromIngredients('A', [
        'A' => [spec035Takes('A', 'B')],
        'B' => [spec035Takes('B', 'C')],
        'C' => [],
    ], []);
}

// AC10
it('AC10: a redaction reaches only below the manifest that declares it', function (string $by, string $of, bool $applies): void {
    $uri = "self#jumbf=/c2pa/{$of}/c2pa.assertions/c2pa.thumbnail.claim";
    $scoped = ManifestStore::scopedRedactions(spec035Graph(), [$by => [$uri]]);

    expect(in_array($uri, $scoped, true))->toBe($applies);
})->with([
    'A redacts in B, its ingredient' => ['A', 'B', true],
    'A redacts in C, below its ingredient' => ['A', 'C', true],
    'B redacts in C, its ingredient' => ['B', 'C', true],
    'B redacts in A, the active manifest above it' => ['B', 'A', false],
    'C redacts in B, its parent' => ['C', 'B', false],
    'C redacts in A, the active manifest' => ['C', 'A', false],
    'A redacts in A, itself' => ['A', 'A', false],
])->group('SPEC-035');

it('AC10: a redaction whose URI names no manifest, or a cycle back to the declaring one, applies to nothing', function (): void {
    $graph = ManifestGraph::fromIngredients('A', ['A' => [spec035Takes('A', 'B')], 'B' => [spec035Takes('B', 'B')]], []);

    expect(ManifestStore::scopedRedactions($graph, ['A' => ['self#jumbf=/c2pa'], 'B' => ['self#jumbf=/c2pa/B/c2pa.assertions/c2pa.actions']]))->toBe([]);
})->group('SPEC-035');
