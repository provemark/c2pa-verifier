<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Hash\DataHashCheck;
use Provemark\C2paVerifier\Hash\HashedUriCheck;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;

/*
 * SPEC-012: the data-hash check — c2pa.hash.data against the asset,
 * streamed. The oracle JSON is under tests/Fixtures/c2patool/ (fixtures,
 * step 14) and tests/Fixtures/c2patool/variants/ (steps 23 and 26); the
 * variant files under tests/Fixtures/binding/ (steps 23, 24, 26) and
 * tests/Fixtures/jpeg/ (step 02).
 */

const SPEC012_PNG = 'self#jumbf=/c2pa/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0';
const SPEC012_HEX64 = '/[0-9a-f]{64}/';

/**
 * The file opened, its store extracted (with the ranges SPEC-012 adds), its active manifest parsed.
 *
 * @return array{0: Manifest, 1: resource, 2: ManifestStoreBytes}
 */
function spec012Open(string $relative): array
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$relative;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $extractor = match (pathinfo($path, PATHINFO_EXTENSION)) {
        'jpg' => new JpegManifestStoreExtractor,
        'png' => new PngManifestStoreExtractor,
        'webp' => new WebpManifestStoreExtractor,
        default => throw new RuntimeException("no extractor for {$relative}"),
    };
    $store = $extractor->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no manifest store in {$relative}");
    }
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active;

    return [$manifest, $stream, $store];
}

/** @return list<ValidationStatus> */
function spec012Check(string $relative, ?DataHashCheck $check = null): array
{
    [$manifest, $stream, $store] = spec012Open($relative);

    return ($check ?? new DataHashCheck)->check($manifest, $stream, $store);
}

/** @return array<string, mixed> */
function spec012C2patool(string $name, bool $variant = false): array
{
    $file = dirname(__DIR__, 2).'/Fixtures/c2patool/'.($variant ? 'variants/' : '').$name.'.json';

    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The code/url pairs of a recorded c2patool list whose code starts with $prefix.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<array{code: string, url: string}>
 */
function spec012OraclePairs(array $oracle, string $kind, string $prefix): array
{
    $list = $kind === 'validation_status' ? $oracle['validation_status'] : (function () use ($oracle, $kind): mixed {
        $results = $oracle['validation_results'];
        assert(is_array($results) && is_array($results['activeManifest']));

        return $results['activeManifest'][$kind];
    })();
    assert(is_array($list));
    $pairs = [];
    foreach ($list as $status) {
        assert(is_array($status) && is_string($status['code']) && is_string($status['url']));
        if (str_starts_with($status['code'], $prefix)) {
            $pairs[] = ['code' => $status['code'], 'url' => $status['url']];
        }
    }

    return $pairs;
}

/** @return list<array{code: string, url: string}> */
function spec012Pairs(ValidationStatus ...$statuses): array
{
    return array_values(array_map(static fn (ValidationStatus $s): array => ['code' => $s->code->value, 'url' => $s->url], $statuses));
}

/**
 * @param  list<ValidationStatus>  $statuses
 * @return list<string>
 */
function spec012Codes(array $statuses): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $statuses);
}

/** @return resource */
function spec012Stream(string $path)
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return $stream;
}

it('AC1: the four fixtures: assertion.dataHash.match, and the words are c2patool\'s', function (): void {
    $expectedRanges = [
        'jpg' => ['fixture-signed.jpg', [['start' => 20, 'length' => 94772]]],
        'png' => ['fixture-signed.png', [['start' => 33, 'length' => 46037]]],
        'webp' => ['fixture-signed.webp', [['start' => 312, 'length' => 100643]]],
        'adobe-20220124-C' => ['public-testfiles/adobe-20220124-C.jpg', [['start' => 20, 'length' => 51130]]],
    ];
    foreach ($expectedRanges as $name => [$fixture, $ranges]) {
        [$manifest, $stream, $store] = spec012Open($fixture);
        expect($store->ranges)->toBe($ranges, $name);

        $statuses = (new DataHashCheck)->check($manifest, $stream, $store);
        expect($statuses)->toHaveCount(1, $name)
            ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMatch, $name)
            ->and($statuses[0]->url)->toBe("self#jumbf=/c2pa/{$manifest->label}/c2pa.assertions/c2pa.hash.data", $name);
        expect(spec012Pairs(...$statuses))->toBe(spec012OraclePairs(spec012C2patool($name), 'success', 'assertion.dataHash'), $name);

        $result = ValidationResult::fromStatuses($statuses, ['dataHash']);
        expect($result->state)->toBe(ValidationState::Valid, $name)
            ->and($result->checksPerformed)->toBe(['dataHash'], $name);
    }

    // the WebP pad byte (odd chunk size, offset 100,955) is outside the range and inside the hash: 100,956 − 100,643 = 313 bytes hashed
    [$manifest, $stream, $store] = spec012Open('fixture-signed.webp');
    expect(filesize(dirname(__DIR__, 2).'/Fixtures/fixture-signed.webp'))->toBe(100956)
        ->and($store->ranges[0]['start'] + $store->ranges[0]['length'])->toBe(100955);
    $statuses = (new DataHashCheck)->check($manifest, $stream, $store);
    expect($statuses[0]->explanation)->toContain('313 of 100956 bytes');
    // a non-zero pad byte never reaches this check: SPEC-003 refuses it in the extractor (amendment 3)
    $webp = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/fixture-signed.webp');
    $webp[100955] = "\x01";
    $tmp = tempnam(sys_get_temp_dir(), 'spec012-webp');
    file_put_contents($tmp, $webp);
    $stream = spec012Stream($tmp);
    try {
        expect(fn () => (new WebpManifestStoreExtractor)->extract($stream))->toThrow(ContainerException::class, 'pad byte at offset 100955 is 01, not 00');
    } finally {
        unlink($tmp);
    }
})->group('SPEC-012');

it('AC2: one changed pixel byte: assertion.dataHash.mismatch, as c2patool', function (): void {
    foreach (['binding/pixel-changed.png', 'binding/pixel-changed.jpg', 'binding/bytes-appended.png', 'binding/bytes-appended.jpg'] as $variant) {
        $statuses = spec012Check($variant);
        expect(spec012Codes($statuses))->toBe(['assertion.dataHash.mismatch'], $variant)
            ->and($statuses[0]->url)->toEndWith('/c2pa.assertions/c2pa.hash.data', $variant)
            ->and(preg_match_all(SPEC012_HEX64, $statuses[0]->explanation))->toBe(2, $variant)
            ->and(ValidationResult::fromStatuses($statuses, ['dataHash'])->state)->toBe(ValidationState::Invalid, $variant);
    }

    $statuses = spec012Check('binding/pixel-changed.png');
    expect(spec012Pairs(...$statuses))->toBe(spec012OraclePairs(spec012C2patool('pixel-changed', true), 'validation_status', 'assertion.dataHash'));
})->group('SPEC-012');

it('AC3: an exclusion must cover the store', function (): void {
    foreach (['binding/bytes-inserted-before-store.png', 'binding/exclusion-shifted.png', 'binding/exclusion-past-end.png'] as $variant) {
        $statuses = spec012Check($variant);
        expect(spec012Codes($statuses))->toBe(['assertion.dataHash.mismatch'], $variant)
            ->and($statuses[0]->explanation)->not->toMatch(SPEC012_HEX64, "{$variant}: not hashed")
            ->and($statuses[0]->explanation)->toContain('exclusion')
            ->and(ValidationResult::fromStatuses($statuses, ['dataHash'])->state)->toBe(ValidationState::Invalid, $variant);
    }
    // the store moved by 16 bytes: the message names where it is and where the exclusion is
    $moved = spec012Check('binding/bytes-inserted-before-store.png');
    expect($moved[0]->explanation)->toContain('49')->toContain('33');

    // the gap JPEG: two pieces, no single range can hold only the store
    [$manifest, $stream, $store] = spec012Open('jpeg/gap-between-pieces.jpg');
    expect($store->ranges)->toBe([['start' => 20, 'length' => 64012], ['start' => 64050, 'length' => 30760]]);
    $statuses = (new DataHashCheck)->check($manifest, $stream, $store);
    expect(spec012Codes($statuses))->toBe(['assertion.dataHash.mismatch'])
        ->and($statuses[0]->explanation)->toContain('64050')
        ->and($statuses[0]->explanation)->not->toMatch(SPEC012_HEX64);

    // amendment 5: Truepic excludes the whole file head with the store; the store is covered, the hash matches (c2patool: match)
    $statuses = spec012Check('public-testfiles/truepic-20230212-camera.jpg');
    expect(spec012Codes($statuses))->toBe(['assertion.dataHash.match']);
    $oracle = spec012C2patool('public-testfiles/truepic-20230212-camera');
    expect(spec012OraclePairs($oracle, 'success', 'assertion.dataHash'))->toHaveCount(1);
})->group('SPEC-012');

it('AC4: additional exclusions are honoured and reported', function (): void {
    $statuses = spec012Check('binding/exclusion-extra.png');
    expect(spec012Codes($statuses))->toBe(['assertion.dataHash.match', 'assertion.dataHash.additionalExclusionsPresent'])
        ->and($statuses[0]->url)->toBe(SPEC012_PNG.'/c2pa.assertions/c2pa.hash.data')
        ->and($statuses[1]->url)->toBe(SPEC012_PNG.'/c2pa.assertions/c2pa.hash.data')
        ->and($statuses[1]->explanation)->toContain('46500');
    foreach (StatusCode::cases() as $code) {
        if (str_starts_with($code->value, 'timeStamp.') || $code === StatusCode::IngredientUnknownProvenance) {
            continue;   // SPEC-017's four informational of its own, and SPEC-020's one
        }
        expect($code->isInformational())->toBe($code === StatusCode::AssertionDataHashAdditionalExclusionsPresent, $code->value);
    }

    $result = ValidationResult::fromStatuses($statuses, ['dataHash']);
    expect($result->state)->toBe(ValidationState::Valid);
    $array = $result->toArray();
    $oracle = spec012C2patool('exclusion-extra', true);
    // c2patool lists an informational under activeManifest.informational only, never under validation_status — and omits the key when it would be empty (measured, steps 26 and 30; SPEC-013 amendment 3)
    expect($array)->not->toHaveKey('validation_status')
        ->and(spec012OraclePairs($oracle, 'validation_status', 'assertion.dataHash'))->toBe([]);
    // our toArray() has c2patool's shape, so the same reader serves both sides
    expect(spec012OraclePairs($array, 'informational', 'assertion.dataHash'))->toBe(spec012OraclePairs($oracle, 'informational', 'assertion.dataHash'))
        ->and(spec012OraclePairs($array, 'success', 'assertion.dataHash'))->toBe(spec012OraclePairs($oracle, 'success', 'assertion.dataHash'));

    // the same file under SPEC-011 and SPEC-010: hashed URIs match, only the signature fails
    [$manifest] = spec012Open('binding/exclusion-extra.png');
    expect(spec012Codes((new HashedUriCheck)->check($manifest)))->each->toBe('assertion.hashedURI.match');
    expect((new ClaimSignatureCheck)->check($manifest)[0]->code)->toBe(StatusCode::ClaimSignatureMismatch);
})->group('SPEC-012');

it('AC5: overlapping exclusions: assertion.dataHash.malformed', function (): void {
    $statuses = spec012Check('binding/exclusions-overlap.png');
    expect(spec012Codes($statuses))->toBe(['assertion.dataHash.malformed'])
        ->and($statuses[0]->explanation)->toContain('256')->toContain('33')->toContain('overlap')
        ->and($statuses[0]->explanation)->not->toMatch(SPEC012_HEX64)
        ->and(ValidationResult::fromStatuses($statuses, ['dataHash'])->state)->toBe(ValidationState::Invalid);
    // c2patool: .mismatch plus the informational — the divergence step 23 recorded
    expect(spec012OraclePairs(spec012C2patool('exclusions-overlap', true), 'validation_status', 'assertion.dataHash'))
        ->toBe([['code' => 'assertion.dataHash.mismatch', 'url' => SPEC012_PNG.'/c2pa.assertions/c2pa.hash.data']]);

    $unsorted = spec012Check('binding/exclusions-unsorted.png');
    expect(spec012Codes($unsorted))->toBe(['assertion.dataHash.match', 'assertion.dataHash.additionalExclusionsPresent']);
})->group('SPEC-012');

it('AC6: shape faults: malformed, and a missing hash is a mismatch', function (): void {
    $missing = spec012Check('binding/hash-missing.png');
    expect(spec012Codes($missing))->toBe(['assertion.dataHash.mismatch'])
        ->and($missing[0]->explanation)->toContain('no hash')
        ->and($missing[0]->explanation)->not->toMatch(SPEC012_HEX64);

    foreach ([
        'binding/exclusions-not-list.png' => 'exclusions',
        'binding/exclusion-start-negative.png' => 'start',
        'binding/exclusion-length-text.png' => 'length',
        'binding/hash-as-text.png' => 'hash',
        'binding/exclusions-too-many.png' => '1025',
    ] as $variant => $word) {
        $statuses = spec012Check($variant);
        expect(spec012Codes($statuses))->toBe(['assertion.dataHash.malformed'], $variant)
            ->and($statuses[0]->explanation)->toContain($word)
            ->and($statuses[0]->explanation)->not->toMatch(SPEC012_HEX64, $variant)
            ->and(ValidationResult::fromStatuses($statuses, ['dataHash'])->state)->toBe(ValidationState::Invalid, $variant);
    }
    // the bound is the check's, not the file's: with a wider one the 1,025 ranges are read, and the store's exclusion is then found missing
    expect(spec012Codes(spec012Check('binding/exclusions-too-many.png', new DataHashCheck(maxExclusions: 2048))))
        ->toBe(['assertion.dataHash.mismatch']);
})->group('SPEC-012');

it('AC7: the algorithm: the assertion\'s, else the claim\'s, else unsupported', function (): void {
    $sha1 = spec012Check('binding/alg-sha1.png');
    expect(spec012Codes($sha1))->toBe(['algorithm.unsupported'])
        ->and($sha1[0]->explanation)->toContain('sha1')
        ->and($sha1[0]->explanation)->not->toMatch(SPEC012_HEX64);

    [$manifest, $stream, $store] = spec012Open('binding/alg-missing.png');
    expect($manifest->assertions['c2pa.hash.data']->data)->not->toHaveKey('alg')
        ->and($manifest->claim->alg)->toBe('sha256');
    expect(spec012Codes((new DataHashCheck)->check($manifest, $stream, $store)))->toBe(['assertion.dataHash.match']);

    [$manifest, $stream, $store] = spec012Open('binding/alg-sha384.png');
    assert(is_array($manifest->assertions['c2pa.hash.data']->data));
    expect($manifest->assertions['c2pa.hash.data']->data['alg'])->toBe('sha384');
    expect(spec012Codes((new DataHashCheck)->check($manifest, $stream, $store)))->toBe(['assertion.dataHash.match']);
    expect(spec012OraclePairs(spec012C2patool('alg-sha384', true), 'success', 'assertion.dataHash'))
        ->toBe([['code' => 'assertion.dataHash.match', 'url' => SPEC012_PNG.'/c2pa.assertions/c2pa.hash.data']]);
})->group('SPEC-012');

it('AC8: exactly one hard binding', function (): void {
    $none = spec012Check('binding/hard-binding-missing.png');
    expect(spec012Codes($none))->toBe(['claim.hardBindings.missing'])
        ->and($none[0]->url)->toBe(SPEC012_PNG);

    $bmff = spec012Check('binding/hard-binding-bmff.png');
    // SPEC-012 amendment 6: M8 is finished and this binding is verified, by
    // BmffHashCheck — Verifier routes a manifest carrying one there and never here.
    // Asked directly, this check still answers rather than falling silent.
    expect(spec012Codes($bmff))->toBe(['general.error'])
        ->and($bmff[0]->explanation)->toContain('c2pa.hash.bmff.v2')->toContain('BmffHashCheck');

    $two = spec012Check('binding/hard-bindings-two.png');
    expect(spec012Codes($two))->toBe(['assertion.multipleHardBindings'])
        ->and($two[0]->url)->toBe(SPEC012_PNG)
        ->and(spec012Pairs(...$two))->toBe(spec012OraclePairs(spec012C2patool('hard-bindings-two', true), 'validation_status', 'assertion.multiple'));

    foreach ([$none, $bmff, $two] as $statuses) {
        expect($statuses[0]->explanation)->not->toMatch(SPEC012_HEX64)
            ->and(ValidationResult::fromStatuses($statuses, ['dataHash'])->state)->toBe(ValidationState::Invalid);
    }
})->group('SPEC-012');

it('AC9: streamed, not slurped', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'spec012-big');
    $out = fopen($tmp, 'wb');
    assert($out !== false);
    fwrite($out, (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/fixture-signed.png'));
    $chunk = str_repeat("\xa5", 1024 * 1024);
    for ($i = 0; $i < 48; $i++) {
        fwrite($out, $chunk);
    }
    fclose($out);
    unset($chunk);
    expect(filesize($tmp))->toBeGreaterThan(48 * 1024 * 1024);

    $stream = spec012Stream($tmp);
    $store = (new PngManifestStoreExtractor)->extract($stream);
    assert($store !== null);
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active;

    $before = memory_get_peak_usage();
    $statuses = (new DataHashCheck)->check($manifest, $stream, $store);
    $grown = memory_get_peak_usage() - $before;
    unlink($tmp);

    expect(spec012Codes($statuses))->toBe(['assertion.dataHash.mismatch'])
        ->and($grown)->toBeLessThan(4 * 1024 * 1024, sprintf('peak memory grew by %d bytes', $grown));
})->group('SPEC-012');

it('AC10: the codes are verbatim, and informational is a third kind', function (): void {
    // SPEC-012's twenty-one; SPEC-014 added the two of the signing credential's trust (its AC10 asserts the twenty-three)
    $values = array_map(static fn (StatusCode $c): string => $c->value, StatusCode::cases());
    foreach ([
        'algorithm.unsupported',
        'assertion.dataHash.additionalExclusionsPresent', 'assertion.dataHash.malformed', 'assertion.dataHash.match', 'assertion.dataHash.mismatch',
        'assertion.hashedURI.match', 'assertion.hashedURI.mismatch',
        'assertion.json.invalid', 'assertion.missing', 'assertion.multipleHardBindings', 'assertion.undeclared',
        'claim.cbor.invalid', 'claim.hardBindings.missing', 'claim.malformed', 'claim.missing', 'claim.multiple',
        'claimSignature.mismatch', 'claimSignature.missing', 'claimSignature.validated',
        'general.error', 'signingCredential.invalid',
    ] as $value) {
        expect($values)->toContain($value);
    }
    $successes = [StatusCode::ClaimSignatureValidated, StatusCode::AssertionHashedUriMatch, StatusCode::AssertionDataHashMatch, StatusCode::AssertionBmffHashMatch];   // the last added by SPEC-027
    foreach (StatusCode::cases() as $code) {
        if (in_array($code, [StatusCode::SigningCredentialTrusted, StatusCode::SigningCredentialUntrusted], true) || str_starts_with($code->value, 'timeStamp.') || str_starts_with($code->value, 'ingredient.')) {
            continue;   // SPEC-014's, SPEC-017's and SPEC-020's
        }
        $informational = $code === StatusCode::AssertionDataHashAdditionalExclusionsPresent;
        expect($code->isInformational())->toBe($informational, $code->value)
            ->and($code->isSuccess())->toBe(in_array($code, $successes, true), $code->value)
            ->and($code->isFailure())->toBe(! $informational && ! in_array($code, $successes, true), $code->value);
    }

    $url = SPEC012_PNG.'/c2pa.assertions/c2pa.hash.data';
    $match = new ValidationStatus(StatusCode::AssertionDataHashMatch, $url, 'ok');
    $info = new ValidationStatus(StatusCode::AssertionDataHashAdditionalExclusionsPresent, $url, 'extra');
    expect(ValidationResult::fromStatuses([$match, $info], ['dataHash'])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$info], ['dataHash'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-012');
