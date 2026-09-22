<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestGraph;
use Provemark\C2paVerifier\Manifest\Relationship;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;

/*
 * SPEC-020 AC5, AC7: the manifest graph — the walk from the active
 * manifest over the eighteen multi-manifest corpus files (measured in step
 * 53 and against c2patool's ingredientDeltas), and the bounds and cycles
 * on synthetic graphs through the fromIngredients() seam.
 */

/** @return array<string, string> corpus name => fixture-relative path, the seventeen `_MULTI` files this verifier's JUMBF parser reads */
function spec020Multi(): array
{
    $files = [];
    foreach ([['public-testfiles', SPEC013_PUBLIC_MULTI], ['c2pa-rs', SPEC013_RS_MULTI], ['writers', SPEC013_WRITERS_MULTI]] as [$dir, $names]) {
        foreach ($names as $name) {
            if ($name === 'update_manifest') {
                continue;   // a c2um box: refused by SPEC-005 AC13 until SPEC-022
            }
            $path = glob(Corpus::fixtures()."/{$dir}/{$name}.*")[0] ?? throw new RuntimeException("no file for {$name}");
            $files["{$dir}/{$name}"] = "{$dir}/".basename($path);
        }
    }

    return $files;
}

function spec020Synthetic(string $manifestLabel, string $label, ?string $referenced, Relationship $relationship = Relationship::ComponentOf): IngredientAssertion
{
    $manifest = $referenced === null ? null : new HashedUri("self#jumbf=/c2pa/{$referenced}", new CborBytes(str_repeat("\0", 32)), null);

    return new IngredientAssertion($label, "self#jumbf=/c2pa/{$manifestLabel}/c2pa.assertions/{$label}", 3, $relationship, 'x', 'image/png', null, null, $manifest, null, null, null, $manifest === null ? null : [], null, []);
}

// AC5
it('AC5: the graph of the multi-manifest files — root, references, missing, unreferenced, redactions, order', function (): void {
    $expectedReferenced = [
        'public-testfiles/adobe-20220124-CACAICAICICA' => 3,
        'public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA' => 5,
        'c2pa-rs/exp-test1' => 5,
        'c2pa-rs/CACAE-uri-CA' => 2,
        'c2pa-rs/ocsp_with_assertion' => 2,
        'public-testfiles/adobe-20220124-E-clm-CAICAI' => 0,
        'c2pa-rs/adobe-20220124-E-clm-CAICAI' => 0,
    ];
    $checked = 0;
    foreach (spec020Multi() as $name => $relative) {
        $store = Corpus::manifestStore($relative) ?? throw new RuntimeException($relative);
        $graph = ManifestGraph::fromStore($store);
        $oracle = spec020Oracle((string) preg_replace('/\.[a-z]+$/', '.json', $relative));

        expect($graph->active)->toBe($oracle['active_manifest'], $name)
            ->and($graph->active)->toBe(array_key_last($store->manifests), $name)
            ->and(count($graph->referenced))->toBe($expectedReferenced[$name] ?? 1, $name)
            // the two E-clm copies keep a manifest nobody reaches: their reference names contentbeef:… (amendment 2)
            ->and($graph->unreferenced)->toBe(str_contains($name, 'E-clm') ? ['contentauth:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019'] : [], $name)
            ->and($graph->redactedAssertions)->toBe([], $name);
        // every referenced label is a manifest c2patool rendered an ingredient for — a reference to a
        // label that is not in the store counts as missing here, and c2patool renders it all the same
        $rendered = [];
        assert(is_array($oracle['manifests']));
        foreach (array_keys($oracle['manifests']) as $manifestLabel) {
            $manifest = spec020OracleManifest((string) preg_replace('/\.[a-z]+$/', '.json', $relative), (string) $manifestLabel);
            foreach ((array) ($manifest['ingredients'] ?? []) as $ingredient) {
                assert(is_array($ingredient));
                $referenced = $ingredient['active_manifest'] ?? null;
                if (is_string($referenced) && array_key_exists($referenced, $store->manifests)) {
                    $rendered[] = $referenced;
                }
            }
        }
        expect(array_keys($graph->referenced))->toEqualCanonicalizing(array_values(array_unique($rendered)), $name);
        // missing: the two E-clm copies only
        if (str_contains($name, 'E-clm')) {
            expect($graph->missing)->toBe([['label' => 'contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019', 'by' => 'self#jumbf=/c2pa/contentauth:urn:uuid:a4ec0a2e-2a4a-4652-bede-762c0362b236/c2pa.assertions/c2pa.ingredient__1']], $name);
            $missing = array_values(array_filter($graph->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientManifestMissing));
            expect($missing)->toHaveCount(1, $name)
                ->and($missing[0]->url)->toBe('contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019', $name)
                ->and($missing[0]->ingredientUri)->toBe($graph->missing[0]['by'], $name);
        } else {
            expect($graph->missing)->toBe([], $name);
        }
        // the walk order is c2patool's delta order. c2patool's list can be shorter: a status an
        // ingredient assertion already recorded is dropped from the top-level report, and an
        // assertion whose every status was dropped leaves no delta at all (c2pa-rs
        // ValidationResults::from_store; the dropping itself is SPEC-021). So the deltas must be a
        // *subsequence* of the walk — same URIs, same order, gaps allowed (amendment 3)
        $deltaUris = array_column(spec020Deltas($oracle), 'ingredientAssertionURI');
        $remaining = $graph->walk;
        foreach ($deltaUris as $uri) {
            $at = array_search($uri, $remaining, true);
            expect($at)->not->toBeFalse("{$name}: {$uri} is not in the walk after the previous delta");
            $remaining = array_slice($remaining, (int) $at + 1);
        }
        // and where no ingredient assertion recorded anything, nothing can be dropped: the two are equal
        $recorded = false;
        foreach ($graph->ingredients as $list) {
            foreach ($list as $ingredient) {
                $recorded = $recorded || $ingredient->validationStatus !== null || $ingredient->validationResults !== null;
            }
        }
        if (! $recorded) {
            expect($graph->walk)->toBe($deltaUris, $name);
        }
        $checked++;
    }
    expect($checked)->toBe(17);
})->group('SPEC-020');

// AC7
it('AC7: a cycle is assertion.ingredient.malformed on the assertion that closes it', function (): void {
    $graph = ManifestGraph::fromIngredients('A', [
        'B' => [spec020Synthetic('B', 'c2pa.ingredient.v3', 'A')],
        'A' => [spec020Synthetic('A', 'c2pa.ingredient.v3', 'B')],
    ], []);
    $malformed = array_values(array_filter($graph->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionIngredientMalformed));

    expect($malformed)->toHaveCount(1)
        ->and($malformed[0]->url)->toBe('self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient.v3')
        ->and($malformed[0]->ingredientUri)->toBe('self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient.v3')
        ->and(str_contains($malformed[0]->explanation, 'cyclic'))->toBeTrue($malformed[0]->explanation)
        ->and(array_keys($graph->referenced))->toBe(['B'])
        ->and($graph->walk)->toBe(['self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient.v3', 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient.v3']);
})->group('SPEC-020');

it('AC7: a cycle stops that branch, not the walk: the assertions after it are still followed', function (): void {
    // Step 65b. Mutation testing found that turning the `continue` after a cyclic
    // reference into `break` breaks no test: every cyclic fixture had the cycle in
    // its last assertion, so there was nothing behind it to lose. B names A, which
    // is in the path, and then names C, which is not.
    $graph = ManifestGraph::fromIngredients('A', [
        'A' => [spec020Synthetic('A', 'c2pa.ingredient.v3', 'B')],
        'B' => [
            spec020Synthetic('B', 'c2pa.ingredient.v3', 'A'),
            spec020Synthetic('B', 'c2pa.ingredient.v3__1', 'C'),
        ],
        'C' => [],
    ], []);
    $malformed = array_values(array_filter($graph->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionIngredientMalformed));

    expect($malformed)->toHaveCount(1)
        ->and(str_contains($malformed[0]->explanation, 'cyclic'))->toBeTrue($malformed[0]->explanation)
        // the assertion behind the cyclic one was read: C is reached, and the walk names it
        ->and(array_keys($graph->referenced))->toBe(['B', 'C'])
        ->and($graph->walk)->toBe([
            'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient.v3',
            'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient.v3',
            'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient.v3__1',
        ]);
})->group('SPEC-020');

it('AC7: a chain deeper than 32 and a manifest with more than 256 ingredient assertions are general.error', function (): void {
    $chain = [];
    for ($n = 0; $n <= 33; $n++) {
        $chain["m{$n}"] = $n === 0 ? [] : [spec020Synthetic("m{$n}", 'c2pa.ingredient.v3', 'm'.($n - 1))];
    }
    try {
        ManifestGraph::fromIngredients('m33', $chain, []);
        expect(false)->toBeTrue('no exception on depth 33');
    } catch (ManifestException $e) {
        expect($e->status)->toBe(StatusCode::GeneralError)
            ->and(str_contains($e->getMessage(), '32'))->toBeTrue($e->getMessage());
    }
    // depth 32 itself is fine
    unset($chain['m33']);
    expect(count(ManifestGraph::fromIngredients('m32', $chain, [])->referenced))->toBe(32);

    $many = [];
    for ($n = 0; $n < 257; $n++) {
        $many[] = spec020Synthetic('A', 'c2pa.ingredient.v3'.($n === 0 ? '' : "__{$n}"), null);
    }
    try {
        ManifestGraph::fromIngredients('A', ['A' => $many], []);
        expect(false)->toBeTrue('no exception on 257 assertions');
    } catch (ManifestException $e) {
        expect($e->status)->toBe(StatusCode::GeneralError)
            ->and(str_contains($e->getMessage(), '256'))->toBeTrue($e->getMessage());
    }
})->group('SPEC-020');
