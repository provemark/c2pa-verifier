<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\Claim;
use Provemark\C2paVerifier\Manifest\EmbeddedFile;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Verifier\Verifier;
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

it('AC9: an empty assertion list, a null claim_generator_info and a name that is not text are malformed (C2PA 2.4 §10.2.2, §10.2.3; step 436)', function (): void {
    $entry = ['url' => 'self#jumbf=c2pa.assertions/c2pa.hash.data', 'hash' => new CborBytes(str_repeat("\0", 32))];
    $claim = ['instanceID' => 'xmp:iid:step-436', 'claim_generator_info' => ['name' => 'step 436'], 'signature' => 'self#jumbf=c2pa.signature', 'created_assertions' => [$entry]];
    expect(Claim::fromMap(2, $claim)->createdAssertions)->toHaveCount(1);
    foreach ([
        'created empty' => [[...$claim, 'created_assertions' => []], 'claim: created_assertions is not a non-empty list'],
        'gathered empty' => [[...$claim, 'gathered_assertions' => []], 'claim: gathered_assertions is not a non-empty list'],
        'generator info null' => [[...$claim, 'claim_generator_info' => null], 'claim (version 2) is missing the required field claim_generator_info'],
        'generator name a number' => [[...$claim, 'claim_generator_info' => ['name' => 5]], 'claim_generator_info is missing the required field name'],
    ] as $name => [$map, $message]) {
        expect(fn () => Claim::fromMap(2, $map))->toThrow(ManifestException::class, $message, $name);
    }

    // and in a file: tests/Fixtures/absence/created-empty.png, every entry gathered and none created
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/absence/created-empty.png', 'rb');
    assert(is_resource($stream));
    $report = (new Verifier)->verify($stream);
    $malformed = array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code === StatusCode::ClaimMalformed));
    expect($report->result->state->value)->toBe('Invalid')
        ->and($malformed)->toHaveCount(1)
        ->and($malformed[0]->explanation)->toContain('created_assertions is not a non-empty list');
})->group('SPEC-007');

it('AC12: a manifest holds one assertion store and one claim signature, never two (C2PA 2.4 §11.1.4.2; step 437)', function (): void {
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/fixture-signed.png', 'rb');
    assert(is_resource($stream));
    $extracted = (new PngManifestStoreExtractor)->extract($stream);
    assert($extracted !== null);
    $bytes = $extracted->bytes;
    $root = (new JumbfParser)->parse($bytes);
    $manifests = $root->superboxes();
    $manifest = end($manifests);
    assert($manifest instanceof Superbox);
    foreach (['c2pa.assertions' => 'assertion store', 'c2pa.signature' => 'signature box'] as $label => $what) {
        $box = array_values(array_filter($manifest->superboxes(), static fn (Superbox $b): bool => $b->description->label === $label))[0];
        // the box once more after itself; the manifest's and the store's LBox grow by its length
        $copy = substr($bytes, $box->offset, $box->length);
        $grown = substr_replace($bytes, $copy, $box->offset + $box->length, 0);
        foreach ([0, $manifest->offset] as $at) {
            $length = unpack('N', substr($bytes, $at, 4));
            assert(is_array($length) && is_int($length[1]));
            $grown = substr_replace($grown, pack('N', $length[1] + $box->length), $at, 4);
        }
        expect(fn () => ManifestStore::fromTree((new JumbfParser)->parse($grown)))
            ->toThrow(ManifestException::class, "2 {$what}");   // the plural is written \"{$what}es\"; the count is what matters
    }
})->group('SPEC-007');

it('AC10: a URI that resolves to nothing, to the wrong place, or to an unknown box is an error', function (): void {
    expect(fn () => spec007Variant('uri-not-found'))
        ->toThrow(ManifestException::class, 'self#jumbf=c2pa.assertions/c2pa.hash.datb does not resolve to a box');
    expect(fn () => spec007Variant('uri-wrong-place'))
        ->toThrow(ManifestException::class, 'self#jumbf=c2pa.claim.v2 is not in the assertion store');
    // step 431: the claim's signature URI resolves, but not to the signature box (C2PA 2.4 §15.7)
    try {
        spec007Variant('signature-uri-other-box');
        throw new RuntimeException('no exception');
    } catch (ManifestException $e) {
        expect($e->status)->toBe(StatusCode::ClaimSignatureMissing)
            ->and($e->getMessage())->toContain('signature URI self#jumbf=c2pa.assertions does not name the signature box');
    }
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

it('AC15: a float JSON cannot hold is rendered as its name, and the report encodes (amendment 6, step 247)', function (): void {
    $stream = fopen('php://memory', 'w+b');
    assert($stream !== false);
    fwrite($stream, spec247NonFiniteJpeg());
    rewind($stream);
    $report = (new Verifier)->verify($stream);

    $lists = [];
    $walk = static function (mixed $node) use (&$walk, &$lists): void {
        if (is_array($node)) {
            if (array_is_list($node)) {
                $lists[] = $node;
            }
            array_map($walk, $node);
        }
    };
    $walk(json_decode($report->toJson(), true, 512, JSON_THROW_ON_ERROR));

    expect($lists)->toContain(['NaN', 'Infinity', '-Infinity', 'NaN']);
})->group('SPEC-007');

// AC21 (amendment 9)
it('AC21: of two assertion boxes with one label, every check reads the one the claim\'s reference resolves to', function (): void {
    $stream = fopen(dirname(__DIR__, 2).'/Fixtures/fixture-signed.png', 'rb');
    assert($stream !== false);
    $extracted = (new PngManifestStoreExtractor)->extract($stream);
    assert($extracted !== null);
    $bytes = $extracted->bytes;
    $root = (new JumbfParser)->parse($bytes);
    $manifest = $root->superboxes()[0];
    $store = array_values(array_filter($manifest->superboxes(), static fn (Superbox $b): bool => $b->description->label === 'c2pa.assertions'))[0];
    $actions = array_values(array_filter($store->superboxes(), static fn (Superbox $b): bool => $b->description->label === 'c2pa.actions.v2'))[0];
    // a second c2pa.actions.v2 after the store's last box, its CBOR changed (created → opened, same length)
    $copy = str_replace('c2pa.created', 'c2pa.opened_', substr($bytes, $actions->offset, $actions->length));
    expect($copy)->not->toBe(substr($bytes, $actions->offset, $actions->length));
    $end = $store->offset + $store->length;
    $forged = substr($bytes, 0, $end).$copy.substr($bytes, $end);
    foreach ([0, $manifest->offset, $store->offset] as $at) {   // each enclosing box grows by the copy
        $size = unpack('N', substr($forged, $at, 4));
        assert(is_array($size) && is_int($size[1]));
        $forged = substr_replace($forged, pack('N', $size[1] + strlen($copy)), $at, 4);
    }
    $active = ManifestStore::fromTree((new JumbfParser)->parse($forged))->active;
    $resolved = $active->resolve('self#jumbf=c2pa.assertions/c2pa.actions.v2');

    expect($active->assertions['c2pa.actions.v2']->box)->toBe($resolved)
        ->and(json_encode($active->assertions['c2pa.actions.v2']->data))->toContain('c2pa.created')
        ->and(json_encode($active->assertions['c2pa.actions.v2']->data))->not->toContain('c2pa.opened_')
        // the second box is still reported, so the file stays Invalid
        ->and(array_map(static fn ($s): string => $s->code->value, (new HashedUriCheck)->check($active)))->toContain('assertion.undeclared');
})->group('SPEC-007');

it('AC4: a URI resolves only as self#jumbf, only into its own manifest, and without a leading slash only relative to it (C2PA 2.4 §8.4.1, §8.4.2.1; step 441)', function (): void {
    $manifest = spec007Store('fixture-signed.png')->active;
    $url = $manifest->claim->createdAssertions[0]->url;
    $label = substr($url, (int) strrpos($url, '/') + 1);
    $offset = $manifest->resolve($url)->offset;

    expect($manifest->resolve("self#jumbf=/c2pa/{$manifest->label}/c2pa.assertions/{$label}")->offset)->toBe($offset)
        // the prefix has the length of self#jumbf= and is not it
        ->and(fn () => $manifest->resolve("http://x.y/c2pa.assertions/{$label}"))->toThrow(ManifestException::class, 'does not start with self#jumbf=')
        ->and(fn () => $manifest->resolve("self#jumbf=/c2pa/urn:c2pa:00000000-0000-4000-8000-00000000000b/c2pa.assertions/{$label}"))->toThrow(ManifestException::class, 'refers to another manifest')
        // no leading slash: relative, so its first segment is a child named c2pa, which there is not (c2pa-rs reads it as absolute)
        ->and(fn () => $manifest->resolve("self#jumbf=c2pa/{$manifest->label}/c2pa.assertions/{$label}"))->toThrow(ManifestException::class, 'does not resolve to a box (no c2pa)');
})->group('SPEC-007');
