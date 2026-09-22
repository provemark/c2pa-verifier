<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Manifest\Relationship;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-020 AC1, AC2, AC4: the ingredient assertion as a value object and
 * its malformed rules. Oracles: c2patool 0.27.22's recorded JSON on the
 * corpora (tests/Fixtures/c2patool/) and on the signed ingredient variants
 * (tests/Fixtures/c2patool/ingredient/, six of them a hard exit with no
 * JSON — their stderr recorded).
 */

function spec020Store(string $relative): ManifestStore
{
    return Corpus::manifestStore($relative) ?? throw new RuntimeException("no store in {$relative}");
}

/** @return array<string, mixed> */
function spec020Oracle(string $relative): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/'.$relative), true, 512, JSON_THROW_ON_ERROR);
}

function spec020Verify(string $relative, ?string $settings = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $trust = $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings));

    return (new Verifier)->verify($stream, $trust);
}

/**
 * The ingredient assertions of a manifest, decoded, in claim order.
 *
 * @return list<IngredientAssertion>
 */
function spec020Ingredients(ManifestStore $store, ?string $label = null): array
{
    $manifest = $label === null ? $store->active : $store->manifests[$label];
    $out = [];
    foreach ([...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions] as $uri) {
        $assertionLabel = substr($uri->url, strlen('self#jumbf=c2pa.assertions/'));
        if (IngredientAssertion::isIngredientLabel($assertionLabel)) {
            $out[] = IngredientAssertion::fromAssertion($manifest->label, $manifest->assertions[$assertionLabel]);
        }
    }

    return $out;
}

/** @return array<string, mixed> the oracle's rendering of the manifest's ingredients, by index */
function spec020RenderedIngredient(string $oracle, string $manifestLabel, int $index): array
{
    $ingredients = spec020OracleManifest($oracle, $manifestLabel)['ingredients'];
    assert(is_array($ingredients) && is_array($ingredients[$index]));

    /** @var array<string, mixed> */
    return $ingredients[$index];
}

/**
 * The ingredient deltas of a report or an oracle, typed: the shape c2patool prints.
 *
 * @param  array<string, mixed>  $array
 * @return list<array{ingredientAssertionURI: string, validationDeltas: array{success: list<array<string, string>>, informational: list<array<string, string>>, failure: list<array<string, string>>}}>
 */
function spec020Deltas(array $array): array
{
    $results = $array['validation_results'] ?? [];
    assert(is_array($results));
    $deltas = $results['ingredientDeltas'] ?? [];
    assert(is_array($deltas));

    /** @var list<array{ingredientAssertionURI: string, validationDeltas: array{success: list<array<string, string>>, informational: list<array<string, string>>, failure: list<array<string, string>>}}> */
    return array_values($deltas);
}

/**
 * The report's flat failure list, typed.
 *
 * @param  array<string, mixed>  $array
 * @return list<array<string, string>>
 */
function spec020Failures(array $array): array
{
    /** @var list<array<string, string>> */
    return array_values((array) ($array['validation_status'] ?? []));
}

/**
 * One manifest of an oracle, typed.
 *
 * @return array<string, mixed>
 */
function spec020OracleManifest(string $oracle, string $label): array
{
    $json = spec020Oracle($oracle);
    assert(is_array($json['manifests']));
    $manifest = $json['manifests'][$label];
    assert(is_array($manifest));

    /** @var array<string, mixed> */
    return $manifest;
}

// AC1
it('AC1: a v1 ingredient decodes: relationship, title, format, ids, no manifest', function (): void {
    $store = spec020Store('public-testfiles/adobe-20220124-CA.jpg');
    $ingredients = spec020Ingredients($store);
    $rendered = spec020RenderedIngredient('public-testfiles/adobe-20220124-CA.json', $store->active->label, 0);

    expect($ingredients)->toHaveCount(1);
    $i = $ingredients[0];
    expect($i->label)->toBe('c2pa.ingredient')
        ->and($i->version)->toBe(1)
        ->and($i->url)->toBe('self#jumbf=/c2pa/'.$store->active->label.'/c2pa.assertions/c2pa.ingredient')
        ->and($i->relationship)->toBe(Relationship::ParentOf)
        ->and($i->relationship->value)->toBe($rendered['relationship'])
        ->and($i->title)->toBe($rendered['title'])
        ->and($i->format)->toBe($rendered['format'])
        ->and($i->instanceId)->toBe($rendered['instance_id'])
        ->and($i->documentId)->toBe($rendered['document_id'])
        ->and($i->manifest)->toBeNull()
        ->and($i->manifestLabel())->toBeNull()
        ->and($i->claimSignature)->toBeNull()
        ->and($i->thumbnail?->url)->toBe('self#jumbf=c2pa.assertions/c2pa.thumbnail.ingredient.jpeg')
        ->and($i->validationStatus)->toBeNull()
        ->and($i->validationResults)->toBeNull();
})->group('SPEC-020');

it('AC1: a v2 (Lightroom), the v3 without a reference (c2pa-rs) and two v1 in claim order', function (): void {
    $lightroom = spec020Store('writers/adobe-20260425-lightroom-classic-church.jpg');
    [$v2] = spec020Ingredients($lightroom);
    $rendered = spec020RenderedIngredient('writers/adobe-20260425-lightroom-classic-church.json', $lightroom->active->label, 0);
    expect($v2->label)->toBe('c2pa.ingredient.v2')
        ->and($v2->version)->toBe(2)
        ->and($v2->relationship->value)->toBe($rendered['relationship'])
        ->and($v2->title)->toBe($rendered['title'])
        ->and($v2->format)->toBe($rendered['format'])
        ->and($v2->manifest)->toBeNull();

    // SPEC-020 amendment 1: the Photoshop file the approved AC1 named declares its manifest by URL and
    // carries no store; a v3 assertion without a reference comes from c2pa-rs's ingredient manifest instead
    $caca = spec020Store('c2pa-rs/CACA.jpg');
    $ingredientManifest = array_key_first($caca->manifests);
    [$v3] = spec020Ingredients($caca, $ingredientManifest);
    expect($v3->label)->toBe('c2pa.ingredient.v3')
        ->and($v3->version)->toBe(3)
        ->and($v3->relationship)->toBe(Relationship::ParentOf)
        ->and($v3->manifest)->toBeNull()
        ->and($v3->validationResults)->toBeNull()
        ->and($v3->title)->toBe('A.jpg');

    // two v1 assertions of one manifest, in claim order, as c2patool renders them
    $two = spec020Store('public-testfiles/adobe-20220124-CAI.jpg');
    $labels = array_map(static fn (IngredientAssertion $i): string => $i->label, spec020Ingredients($two));
    expect($labels)->toBe(['c2pa.ingredient', 'c2pa.ingredient__1']);
    foreach ($labels as $n => $label) {
        $rendered = spec020RenderedIngredient('public-testfiles/adobe-20220124-CAI.json', $two->active->label, $n);
        expect($rendered['label'])->toBe($label);
    }
})->group('SPEC-020');

it('AC1: a v3 ingredient with a manifest carries both hashed URIs and the recorded validationResults', function (): void {
    $store = spec020Store('c2pa-rs/CACA.jpg');
    [$i] = spec020Ingredients($store);
    $referenced = 'urn:c2pa:5259041e-8171-49c9-acc2-915f1a48e3a5:contentauth';

    expect($i->version)->toBe(3)
        ->and($i->relationship)->toBe(Relationship::ParentOf)
        ->and($i->manifest?->url)->toBe("self#jumbf=/c2pa/{$referenced}")
        ->and(strlen((string) $i->manifest?->hash->bytes))->toBe(32)
        ->and($i->manifest?->alg)->toBe('sha256')   // measured: this writer names the algorithm (amendment 2)
        ->and($i->manifestLabel())->toBe($referenced)
        ->and($i->claimSignature?->url)->toBe("self#jumbf=/c2pa/{$referenced}/c2pa.signature")
        ->and(strlen((string) $i->claimSignature?->hash->bytes))->toBe(32)
        ->and($i->validationStatus)->toBeNull()
        ->and(array_keys((array) $i->validationResults))->toBe(['activeManifest', 'ingredientDeltas'])
        ->and($i->digitalSourceType)->toBeNull()
        ->and($i->data['dc:title'] ?? null)->toBe('CA.jpg');
})->group('SPEC-020');

// AC2
const SPEC020_MALFORMED = [
    'no-relationship' => 'relationship',
    'relationship-childof' => 'childOf',
    'relationship-int' => 'relationship',
    'v4' => 'version 4',
    'v1-no-title' => 'dc:title',
    'manifest-no-results' => 'validationResults',
    'manifest-and-dst' => 'digitalSourceType',
    'hash-text' => 'hash',
    'data-array' => 'map',
];

it('AC2: nine malformed ingredient assertions throw assertion.ingredient.malformed with the assertion url', function (): void {
    foreach (SPEC020_MALFORMED as $name => $needle) {
        $store = spec020Store("ingredient/{$name}.png");
        $label = $name === 'v4' ? 'c2pa.ingredient.v4' : ($name === 'v1-no-title' ? 'c2pa.ingredient' : 'c2pa.ingredient.v3');
        $assertion = $store->active->assertions[$label] ?? throw new RuntimeException("{$name}: no {$label}");
        try {
            IngredientAssertion::fromAssertion($store->active->label, $assertion);
            expect(false)->toBeTrue("{$name}: no exception");
        } catch (ManifestException $e) {
            expect($e->status)->toBe(StatusCode::AssertionIngredientMalformed, $name)
                ->and($e->url)->toBe("self#jumbf=/c2pa/{$store->active->label}/c2pa.assertions/{$label}", $name)
                ->and(str_contains($e->getMessage(), $needle))->toBeTrue("{$name}: {$e->getMessage()}");
        }
    }
})->group('SPEC-020');

it('AC2: through the Verifier each malformed variant is Invalid with the failure under ingredientDeltas', function (): void {
    foreach (SPEC020_MALFORMED as $name => $needle) {
        $report = spec020Verify("ingredient/{$name}.png");
        $label = $name === 'v4' ? 'c2pa.ingredient.v4' : ($name === 'v1-no-title' ? 'c2pa.ingredient' : 'c2pa.ingredient.v3');
        $url = "self#jumbf=/c2pa/{$report->store?->active->label}/c2pa.assertions/{$label}";
        $malformed = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionIngredientMalformed));

        expect($report->result->state)->toBe(ValidationState::Invalid, $name)
            ->and($malformed)->toHaveCount(1, $name)
            ->and($malformed[0]->url)->toBe($url, $name)
            ->and($malformed[0]->ingredientUri)->toBe($url, $name);
        $array = $report->toArray();
        $deltas = spec020Deltas($array);
        expect($deltas)->toHaveCount(1, $name)
            ->and($deltas[0]['ingredientAssertionURI'])->toBe($url, $name)
            ->and($deltas[0]['validationDeltas']['failure'][0]['code'])->toBe('assertion.ingredient.malformed', $name)
            ->and(array_column(spec020Failures($array), 'code'))->toContain('assertion.ingredient.malformed');
        // a malformed reference is not followed: no ingredient.manifest.missing next to it (c2patool reports both on manifest-no-results)
        expect(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientManifestMissing))->toBe([], $name);
    }
    // the one variant c2patool judges with JSON: the same code and url in its delta
    $delta = spec020Deltas(spec020Oracle('ingredient/manifest-no-results.json'))[0];
    $ours = spec020Deltas(spec020Verify('ingredient/manifest-no-results.png')->toArray())[0];
    expect($ours['ingredientAssertionURI'])->toBe($delta['ingredientAssertionURI'])
        ->and($ours['validationDeltas']['failure'][0]['url'])->toBe($delta['validationDeltas']['failure'][0]['url'])
        ->and($delta['validationDeltas']['failure'][0]['code'])->toBe('assertion.ingredient.malformed');
})->group('SPEC-020');

// AC4
it('AC4: an inputTo ingredient without a manifest is not unknown provenance', function (): void {
    $report = spec020Verify('ingredient/inputto.png');
    $array = $report->toArray();
    $oracle = spec020Oracle('ingredient/inputto.json');

    expect($report->result->state)->toBe(ValidationState::Valid)
        ->and(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientUnknownProvenance))->toBe([])
        ->and(spec020Deltas($array))->toBe([])
        ->and(spec020Deltas($oracle))->toBe([])
        ->and($oracle['validation_state'])->toBe('Valid');

    // the control: componentOf is unknown provenance, as c2patool says, with c2patool's explanation
    $control = spec020Verify('ingredient/componentof.png')->toArray();
    expect(spec020Deltas($control))->toBe(spec020Deltas(spec020Oracle('ingredient/componentof.json')))
        ->and(spec020Deltas($control))->toHaveCount(1)
        ->and($control['validation_state'])->toBe('Valid');
})->group('SPEC-020');
