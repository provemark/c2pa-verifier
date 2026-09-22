<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\EmbeddedFile;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;

/*
 * SPEC-007: the claim and the manifest. The trees come from the M1
 * extractors and the SPEC-005 parser; the values were measured in steps 09,
 * 12 and 14; c2patool's JSON per fixture is recorded under
 * tests/Fixtures/c2patool/, the variants under tests/Fixtures/claim/
 * (notes/step-14-claim-variants.md).
 */

const SPEC007_PNG_LABEL = 'urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0';

const SPEC007_ADOBE_LABEL = 'contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc';

/** The box tree of a fixture's manifest store. */
function spec007Tree(string $fixture): Superbox
{
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/'.$fixture, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$fixture}");
    }
    $extractor = match (pathinfo($fixture, PATHINFO_EXTENSION)) {
        'jpg' => new JpegManifestStoreExtractor,
        'png' => new PngManifestStoreExtractor,
        'webp' => new WebpManifestStoreExtractor,
        default => throw new RuntimeException("no extractor for {$fixture}"),
    };
    $store = $extractor->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no manifest store in {$fixture}");
    }

    return (new JumbfParser)->parse($store->bytes);
}

function spec007Store(string $fixture): ManifestStore
{
    return ManifestStore::fromTree(spec007Tree($fixture));
}

/** A variant store from tests/Fixtures/claim/ (or jumbf/), parsed to a tree. */
function spec007Variant(string $name, string $dir = 'claim'): ManifestStore
{
    return ManifestStore::fromTree((new JumbfParser)->parse((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/{$dir}/{$name}.bin")));
}

function spec007C2patoolJson(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/c2patool/{$name}.json");
}

it('AC1: the PNG store has one manifest, active, with a v2 claim', function (): void {
    $store = spec007Store('fixture-signed.png');

    expect(array_keys($store->manifests))->toBe([SPEC007_PNG_LABEL])
        ->and($store->active->label)->toBe(SPEC007_PNG_LABEL);

    $claim = $store->active->claim;
    expect($claim->version)->toBe(2)
        ->and($claim->instanceId)->toBe('xmp:iid:abc42c63-7d76-437d-b2bb-b8e473a93dba')
        ->and($claim->claimGeneratorInfo)->toBe([['name' => 'c2pa-verifier fixtures', 'version' => '0.0.0', 'org.contentauth.c2pa_rs' => '0.90.22']])
        ->and($claim->claimGenerator)->toBeNull()
        ->and($claim->title)->toBe('fixture-signed.png')
        ->and($claim->format)->toBeNull()
        ->and($claim->alg)->toBe('sha256')
        ->and($claim->signatureUri)->toBe('self#jumbf=/c2pa/'.SPEC007_PNG_LABEL.'/c2pa.signature')
        ->and($claim->createdAssertions)->toHaveCount(1)
        ->and($claim->createdAssertions[0])->toBeInstanceOf(HashedUri::class)
        ->and($claim->createdAssertions[0]->url)->toBe('self#jumbf=c2pa.assertions/c2pa.hash.data')
        ->and(strlen($claim->createdAssertions[0]->hash->bytes))->toBe(32)
        ->and(array_map(static fn (HashedUri $u): string => $u->url, $claim->gatheredAssertions))
        ->toBe(['self#jumbf=c2pa.assertions/c2pa.thumbnail.claim', 'self#jumbf=c2pa.assertions/c2pa.actions.v2']);
})->group('SPEC-007');

it('AC2: the JPEG and WebP stores give the same claim shape', function (): void {
    $shape = static function (ManifestStore $store): array {
        $claim = $store->active->claim;

        return [
            count($store->manifests),
            $claim->version,
            $claim->claimGeneratorInfo,
            $claim->claimGenerator,
            $claim->format,
            $claim->alg,
            array_map(static fn (HashedUri $u): string => $u->url, $claim->createdAssertions),
            array_map(static fn (HashedUri $u): string => $u->url, $claim->gatheredAssertions),
            array_keys($store->active->assertions),
        ];
    };
    $png = spec007Store('fixture-signed.png');

    foreach (['fixture-signed.jpg' => 'urn:c2pa:4e936c4e-e4e0-42f5-ad23-22121bd90948', 'fixture-signed.webp' => 'urn:c2pa:233f5a78-e8f1-4c00-8e3b-8dd89f783bbc'] as $fixture => $label) {
        $store = spec007Store($fixture);
        expect($shape($store))->toBe($shape($png), $fixture)
            ->and($store->active->label)->toBe($label)
            ->and($store->active->claim->title)->toBe($fixture);
    }
})->group('SPEC-007');

it('AC3: assertions are decoded by content type', function (): void {
    $assertions = spec007Store('fixture-signed.png')->active->assertions;

    expect(array_keys($assertions))->toBe(['c2pa.thumbnail.claim', 'c2pa.actions.v2', 'c2pa.hash.data']);

    expect($assertions['c2pa.actions.v2']->data)->toBe([
        'actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia']],
    ]);

    $hashData = $assertions['c2pa.hash.data']->data;
    assert(is_array($hashData));
    $hash = $hashData['hash'];
    $pad = $hashData['pad'];
    expect($hashData['exclusions'])->toBe([['start' => 33, 'length' => 46037]])
        ->and($hashData['name'])->toBe('jumbf manifest')
        ->and($hashData['alg'])->toBe('sha256')
        ->and($hash)->toBeInstanceOf(CborBytes::class)
        ->and($pad)->toBeInstanceOf(CborBytes::class);
    assert($hash instanceof CborBytes && $pad instanceof CborBytes);
    expect(strlen($hash->bytes))->toBe(32)
        ->and(strlen($pad->bytes))->toBe(8);

    $thumbnail = $assertions['c2pa.thumbnail.claim']->data;
    expect($thumbnail)->toBeInstanceOf(EmbeddedFile::class);
    assert($thumbnail instanceof EmbeddedFile);
    expect($thumbnail->format)->toBe('image/jpeg')
        ->and(strlen($thumbnail->bytes))->toBe(32364);
})->group('SPEC-007');

it('AC4: URIs resolve to boxes, relative and absolute', function (): void {
    $manifest = spec007Store('fixture-signed.png')->active;

    expect($manifest->resolve($manifest->claim->signatureUri)->offset)->toBe(33672)
        ->and($manifest->resolve($manifest->claim->createdAssertions[0]->url)->offset)->toBe(32831)
        ->and(strlen($manifest->signatureBytes()))->toBe(12297)
        ->and(strlen($manifest->claimBytes()))->toBe(591)
        ->and(bin2hex(substr($manifest->signatureBytes(), 0, 2)))->toBe('d284');
})->group('SPEC-007');

it('AC5 (amendment 4): a v1 claim whose claim_generator_info is null is a claim without one', function (): void {
    // c2pa-rs's ocsp.jpg: its first (ingredient) manifest carries claim_generator_info: null in a v1 claim; c2patool reads it
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/c2pa-rs/ocsp.jpg', 'rb');
    assert($stream !== false);
    $store = (new JpegManifestStoreExtractor)->extract($stream);
    assert($store !== null);
    $manifestStore = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes));
    expect($manifestStore->manifests)->toHaveCount(2);
    $first = array_values($manifestStore->manifests)[0];
    expect($first->claim->version)->toBe(1)
        ->and($first->claim->claimGeneratorInfo)->toBeNull()
        ->and($first->claim->claimGenerator)->toContain('Adobe_Firefly');
})->group('SPEC-007');

it('AC5: the Adobe store gives a v1 claim without claim_generator_info', function (): void {
    $store = spec007Store('public-testfiles/adobe-20220124-C.jpg');
    $claim = $store->active->claim;
    $assertions = $store->active->assertions;

    expect($store->active->label)->toBe(SPEC007_ADOBE_LABEL)
        ->and($claim->version)->toBe(1)
        ->and($claim->claimGenerator)->toBe('make_test_images/0.16.1 c2pa-rs/0.16.1')
        ->and($claim->claimGeneratorInfo)->toBeNull()
        ->and($claim->format)->toBe('image/jpeg')
        ->and($claim->title)->toBe('C.jpg')
        ->and($claim->signatureUri)->toBe('self#jumbf=c2pa.signature')
        ->and($claim->createdAssertions)->toHaveCount(4)
        ->and($claim->gatheredAssertions)->toBe([])
        ->and(array_keys($assertions))->toBe(['c2pa.thumbnail.claim.jpeg', 'stds.schema-org.CreativeWork', 'c2pa.actions', 'c2pa.hash.data']);

    $thumbnail = $assertions['c2pa.thumbnail.claim.jpeg']->data;
    assert($thumbnail instanceof EmbeddedFile);
    expect($thumbnail->format)->toBe('image/jpeg')
        ->and(strlen($thumbnail->bytes))->toBe(31608);

    $creativeWork = $assertions['stds.schema-org.CreativeWork']->data;
    assert(is_array($creativeWork));
    expect(array_keys($creativeWork))->toBe(['@context', '@type', 'author']);

    $actions = $assertions['c2pa.actions']->data;
    assert(is_array($actions) && is_array($actions['actions']));
    expect(array_column($actions['actions'], 'action'))->toBe(['c2pa.created', 'c2pa.drawing']);
})->group('SPEC-007');

it('AC6: the JSON view is accepted by the sister library and agrees with c2patool', function (): void {
    $accessors = static fn (string $json): array => (static function ($report): array {
        return [
            'hasManifest' => $report->hasManifest(),
            'isAiGenerated' => $report->isAiGenerated(),
            'digitalSourceTypes' => $report->digitalSourceTypes(),
            'softwareAgents' => array_map(static fn ($agent) => $agent->toArray(), $report->softwareAgents()),
            'declaredSpecVersion' => $report->declaredSpecVersion(),
        ];
    })(ManifestStoreParser::fromJson($json));

    foreach (['png' => 'fixture-signed.png', 'jpg' => 'fixture-signed.jpg', 'webp' => 'fixture-signed.webp', 'adobe-20220124-C' => 'public-testfiles/adobe-20220124-C.jpg'] as $name => $fixture) {
        $ours = $accessors(spec007Store($fixture)->toJson());
        $theirs = $accessors(spec007C2patoolJson($name));

        expect($ours)->toBe($theirs, $name);
    }

    expect($accessors(spec007Store('fixture-signed.png')->toJson()))->toBe([
        'hasManifest' => true,
        'isAiGenerated' => false,
        'digitalSourceTypes' => ['http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia'],
        'softwareAgents' => [],
        'declaredSpecVersion' => null,
    ]);
})->group('SPEC-007');

it('AC7: the JSON view has c2patool\'s shape', function (): void {
    $png = spec007Store('fixture-signed.png')->toArray();
    assert(is_array($png['manifests']));
    $manifest = $png['manifests'][SPEC007_PNG_LABEL] ?? null;

    expect($png['active_manifest'])->toBe(SPEC007_PNG_LABEL)
        ->and($manifest)->toBeArray();
    assert(is_array($manifest));
    expect(array_keys($manifest))->toBe(['claim_generator_info', 'title', 'instance_id', 'thumbnail', 'assertions', 'label', 'claim_version'])
        ->and($manifest['claim_version'])->toBe(2)
        ->and($manifest['label'])->toBe(SPEC007_PNG_LABEL)
        ->and($manifest['title'])->toBe('fixture-signed.png')
        ->and($manifest['claim_generator_info'])->toBe([['name' => 'c2pa-verifier fixtures', 'version' => '0.0.0', 'org.contentauth.c2pa_rs' => '0.90.22']])
        ->and($manifest['thumbnail'])->toBe(['format' => 'image/jpeg', 'identifier' => 'self#jumbf=/c2pa/'.SPEC007_PNG_LABEL.'/c2pa.assertions/c2pa.thumbnail.claim'])
        ->and($manifest['assertions'])->toBe([[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia']]],
        ]]);

    $adobe = spec007Store('public-testfiles/adobe-20220124-C.jpg')->toArray();
    assert(is_array($adobe['manifests']));
    $manifest = $adobe['manifests'][SPEC007_ADOBE_LABEL] ?? null;
    assert(is_array($manifest) && is_array($manifest['assertions']));
    expect(array_keys($manifest))->toBe(['claim_generator', 'title', 'format', 'instance_id', 'thumbnail', 'assertions', 'label', 'claim_version'])
        ->and($manifest['claim_version'])->toBe(1)
        ->and($manifest['claim_generator'])->toBe('make_test_images/0.16.1 c2pa-rs/0.16.1')
        ->and($manifest['format'])->toBe('image/jpeg')
        ->and(array_column($manifest['assertions'], 'label'))->toBe(['stds.schema-org.CreativeWork', 'c2pa.actions']);

    // The JSON is what json_decode gives back from toJson().
    expect(json_decode(spec007Store('fixture-signed.png')->toJson(), true))->toBe($png);
})->group('SPEC-007');

it('AC8: a claim label that is neither v1 nor v2 is an error', function (): void {
    expect(fn () => spec007Variant('claim-label-v3'))
        ->toThrow(ManifestException::class, 'claim label c2pa.claim.v3 at offset 33034');
})->group('SPEC-007');

it('AC9: a claim missing a required field is an error naming the field and the version', function (): void {
    foreach (['signature', 'created_assertions', 'instanceID', 'claim_generator_info'] as $field) {
        $variant = 'claim-no-'.strtolower(str_replace('_', '-', $field));
        expect(fn () => spec007Variant($variant))
            ->toThrow(ManifestException::class, "claim (version 2) is missing the required field {$field}");
    }
})->group('SPEC-007');

it('AC10: a URI that resolves to nothing, to the wrong place, or to an unknown box is an error', function (): void {
    expect(fn () => spec007Variant('uri-not-found'))
        ->toThrow(ManifestException::class, 'self#jumbf=c2pa.assertions/c2pa.hash.datb does not resolve to a box');
    expect(fn () => spec007Variant('uri-wrong-place'))
        ->toThrow(ManifestException::class, 'self#jumbf=c2pa.claim.v2 is not in the assertion store');
    expect(fn () => spec007Variant('unknown-uuid', 'jumbf'))
        ->toThrow(ManifestException::class, 'self#jumbf=c2pa.assertions/c2pa.thumbnail.claim resolves to an unknown box (UUID ffffffff-ffff-ffff-ffff-ffffffffffff)');
})->group('SPEC-007');

it('AC11: a hashed URI without a byte-string hash is an error', function (): void {
    expect(fn () => spec007Variant('hash-as-text'))
        ->toThrow(ManifestException::class, 'hashed URI self#jumbf=c2pa.assertions/c2pa.hash.data: hash is text, not a byte string');
    expect(fn () => spec007Variant('hash-missing'))
        ->toThrow(ManifestException::class, 'hashed URI self#jumbf=c2pa.assertions/c2pa.hash.data: hash is missing');
})->group('SPEC-007');

it('AC12: structural faults in the manifest are errors', function (): void {
    expect(fn () => spec007Variant('second-claim'))
        ->toThrow(ManifestException::class, 'manifest '.SPEC007_PNG_LABEL.': 2 claim boxes, expected one');
    expect(fn () => spec007Variant('two-cbor-boxes'))
        ->toThrow(ManifestException::class, 'manifest '.SPEC007_PNG_LABEL.': the claim box holds 2 content boxes, expected one cbor box');
    expect(fn () => spec007Variant('assertion-store-label'))
        ->toThrow(ManifestException::class, 'manifest '.SPEC007_PNG_LABEL.': no assertion store (c2pa.assertions)');
    expect(fn () => spec007Variant('no-manifest'))
        ->toThrow(ManifestException::class, 'the store holds no manifest');
})->group('SPEC-007');

it('AC13: invalid JSON in a json box is an error naming the assertion, never the bytes', function (): void {
    expect(fn () => spec007Variant('json-broken'))
        ->toThrow(ManifestException::class, 'assertion stds.schema-org.CreativeWork: invalid JSON');
})->group('SPEC-007');

it('AC14: a claim_generator_info without a name is an error', function (): void {
    expect(fn () => spec007Variant('generator-info-no-name'))
        ->toThrow(ManifestException::class, 'claim_generator_info is missing the required field name');
})->group('SPEC-007');

// amendment 5 (2026-09-22, found by SPEC-019 AC11): a claim_generator_info that carries CBOR bytes
// (OpenAI's icon is a hashed URI with a byte-string hash) must render like every other value
it('amendment 5: claim_generator_info with a byte string renders as JSON, the bytes as base64', function (): void {
    $store = spec007Store('writers/openai-20260826-c2pa_2x.png');
    /** @var array{manifests: array<string, array{claim_generator_info: list<array{icon: array{hash: string}}>}>} $array */
    $array = $store->toArray();
    $hash = $array['manifests'][$store->active->label]['claim_generator_info'][0]['icon']['hash'];

    expect(strlen((string) base64_decode($hash, true)))->toBe(32) // strlen: toHaveLength() counts UTF-8 characters, not bytes
        ->and(json_decode($store->toJson(), true, 512, JSON_THROW_ON_ERROR))->toBeArray();
})->group('SPEC-007');
