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

// AC12, amendment 7 (step 409): the active manifest stays out of reach through a cycle too

/** A takes B and B takes A back, or A takes B, B takes C and C takes A back. */
function spec035Cycle(bool $long): ManifestGraph
{
    return ManifestGraph::fromIngredients('A', $long
        ? ['A' => [spec035Takes('A', 'B')], 'B' => [spec035Takes('B', 'C')], 'C' => [spec035Takes('C', 'A')]]
        : ['A' => [spec035Takes('A', 'B')], 'B' => [spec035Takes('B', 'A')]], []);
}

it('AC12: a redaction of the active manifest never applies, even where a cycle puts it below the declaring manifest', function (bool $long, string $by): void {
    $graph = spec035Cycle($long);
    $uri = 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.thumbnail.claim';

    expect($graph->below($by))->toContain('A')   // the cycle does put A below the declaring manifest
        ->and(ManifestStore::scopedRedactions($graph, [$by => [$uri]]))->toBe([]);
})->with([
    'B takes A back' => [false, 'B'],
    'C takes A back, below B' => [true, 'C'],
    'C takes A back, and B redacts' => [true, 'B'],
])->group('SPEC-035');

it('AC12: through a cycle, a manifest still redacts below itself what is not the active manifest', function (): void {
    $graph = spec035Cycle(true);
    $uri = 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.thumbnail.claim';

    // C reaches B only through the cycle back to A; A is above B, so the rule of amendment 6 lets it stand
    expect(ManifestStore::scopedRedactions($graph, ['C' => [$uri]]))->toBe([$uri]);
})->group('SPEC-035');

it('AC12: end to end — the active manifest redacted through a cycle is Invalid; a parent redacting its ingredient stays Trusted', function (string $file, string $state): void {
    $report = spec020Verify("graph-fuzz-cycle/{$file}.png", 'graph-fuzz-cycle/graph-fuzz-root.settings.json');

    expect($report->result->state->value)->toBe($state);
    if ($state === 'Invalid') {
        expect(array_map(static fn ($s): string => $s->code->value.' '.$s->url, array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code->isFailure() && ! $s->acknowledged))))
            ->toContain('assertion.missing self#jumbf=/c2pa');
    }
})->with([
    ['redaction-of-the-active-through-a-cycle', 'Invalid'],
    ['redaction-by-the-parent', 'Trusted'],
])->group('SPEC-035');
