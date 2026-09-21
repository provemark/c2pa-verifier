<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cbor\CborTag;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/*
 * SPEC-006: CBOR, the measured subset decoded, the rest refused. The
 * sixteen recorded values under tests/Fixtures/cbor/ come from step 12
 * (notes/step-12-cbor-vectors.md); the hex vectors of AC4–AC11 are copied
 * from RFC 8949 Appendix A and Appendix F.
 */

function spec006Bytes(string $hex): string
{
    return (string) hex2bin(str_replace(' ', '', $hex));
}

function spec006Decode(string $hex, ?CborDecoder $decoder = null): mixed
{
    return ($decoder ?? new CborDecoder)->decode(spec006Bytes($hex));
}

/** The manifest store of a fixture, through the M1 extractor of its container. */
function spec006Store(string $fixture): string
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

    return $store->bytes;
}

/**
 * A recorded box: its bytes (cut from the store at the recorded offset and
 * checked against the recorded hash) and its recorded value.
 *
 * @return array{bytes: string, value: mixed}
 */
function spec006Recorded(string $name): array
{
    /** @var array{fixture: string, data_offset: int, length: int, sha256: string, value: mixed} $record */
    $record = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/cbor/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    $bytes = substr(spec006Store($record['fixture']), $record['data_offset'], $record['length']);
    if (hash('sha256', $bytes) !== $record['sha256']) {
        throw new RuntimeException("{$name}: the store's bytes are not the recorded ones");
    }

    return ['bytes' => $bytes, 'value' => $record['value']];
}

/** The decoder's output in the JSON form of the recorded values, so one equality compares them. */
function spec006Render(mixed $value): mixed
{
    if ($value instanceof CborBytes) {
        return ['$bytes' => bin2hex($value->bytes)];
    }
    if ($value instanceof CborTag) {
        return ['$tag' => $value->number, '$value' => spec006Render($value->value)];
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map(spec006Render(...), $value);
        }
        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = [$key, spec006Render($item)];
        }

        return ['$map' => $pairs];
    }

    return $value;
}

const SPEC006_RECORDED = [
    'jpg--c2pa.actions.v2', 'jpg--c2pa.hash.data', 'jpg--c2pa.claim.v2', 'jpg--c2pa.signature',
    'png--c2pa.actions.v2', 'png--c2pa.hash.data', 'png--c2pa.claim.v2', 'png--c2pa.signature',
    'webp--c2pa.actions.v2', 'webp--c2pa.hash.data', 'webp--c2pa.claim.v2', 'webp--c2pa.signature',
    'adobe-20220124-C--c2pa.actions', 'adobe-20220124-C--c2pa.hash.data', 'adobe-20220124-C--c2pa.claim', 'adobe-20220124-C--c2pa.signature',
];

it('AC1: the PNG claim decodes to its seven keys', function (): void {
    $claim = (new CborDecoder)->decode(spec006Recorded('png--c2pa.claim.v2')['bytes']);

    expect($claim)->toBeArray();
    assert(is_array($claim));
    expect(array_keys($claim))->toBe(['instanceID', 'claim_generator_info', 'signature', 'created_assertions', 'gathered_assertions', 'dc:title', 'alg'])
        ->and($claim['instanceID'])->toBe('xmp:iid:abc42c63-7d76-437d-b2bb-b8e473a93dba')
        ->and($claim['claim_generator_info'])->toBe(['name' => 'c2pa-verifier fixtures', 'version' => '0.0.0', 'org.contentauth.c2pa_rs' => '0.90.22'])
        ->and($claim['alg'])->toBe('sha256')
        ->and($claim['created_assertions'])->toHaveCount(1)
        ->and(array_key_exists('claim_version', $claim))->toBeFalse();

    $created = $claim['created_assertions'];
    assert(is_array($created) && is_array($created[0]));
    $hash = $created[0]['hash'];
    expect($created[0]['url'])->toBe('self#jumbf=c2pa.assertions/c2pa.hash.data')
        ->and($hash)->toBeInstanceOf(CborBytes::class);
    assert($hash instanceof CborBytes);
    expect(base64_encode($hash->bytes))->toBe('Cxd9XpB7zgi4c3qUdT2c4DM6BpUCH16Yyn43iCeZ5LI=');
})->group('SPEC-006');

it('AC2: the sixteen measured blobs decode to their recorded values', function (): void {
    $decoder = new CborDecoder;
    foreach (SPEC006_RECORDED as $name) {
        $recorded = spec006Recorded($name);

        expect(spec006Render($decoder->decode($recorded['bytes'])))->toBe($recorded['value'], $name);
    }
})->group('SPEC-006');

it('AC2: the PNG signature is tag 18 over four items with a detached payload', function (): void {
    $signature = (new CborDecoder)->decode(spec006Recorded('png--c2pa.signature')['bytes']);

    expect($signature)->toBeInstanceOf(CborTag::class);
    assert($signature instanceof CborTag);
    expect($signature->number)->toBe(18)
        ->and($signature->value)->toBeArray()
        ->and($signature->value)->toHaveCount(4);
    /** @var array{0: mixed, 1: mixed, 2: mixed, 3: mixed} $parts */
    $parts = $signature->value;
    [$protected, $unprotected, $payload, $sig] = $parts;
    assert($protected instanceof CborBytes && is_array($unprotected) && $sig instanceof CborBytes);
    $pad = $unprotected['pad'];
    assert($pad instanceof CborBytes);
    expect(strlen($protected->bytes))->toBe(1285)
        ->and(array_keys($unprotected))->toBe(['pad'])
        ->and(strlen($pad->bytes))->toBe(10932)
        ->and($payload)->toBeNull()
        ->and(strlen($sig->bytes))->toBe(64);

    $adobe = (new CborDecoder)->decode(spec006Recorded('adobe-20220124-C--c2pa.signature')['bytes']);
    assert($adobe instanceof CborTag && is_array($adobe->value) && is_array($adobe->value[1]));
    expect(array_keys($adobe->value[1]))->toBe(['x5chain', 'sigTst', 'pad']);
})->group('SPEC-006');

it('AC3: byte strings and text strings are different types', function (): void {
    $hashData = (new CborDecoder)->decode(spec006Recorded('png--c2pa.hash.data')['bytes']);

    assert(is_array($hashData));
    $hash = $hashData['hash'];
    $pad = $hashData['pad'];
    expect($hash)->toBeInstanceOf(CborBytes::class)
        ->and($pad)->toBeInstanceOf(CborBytes::class);
    assert($hash instanceof CborBytes && $pad instanceof CborBytes);
    expect(strlen($hash->bytes))->toBe(32)
        ->and(strlen($pad->bytes))->toBe(8)
        ->and($hashData['name'])->toBe('jumbf manifest')
        ->and($hashData['alg'])->toBe('sha256')
        ->and($hashData['exclusions'])->toBe([['start' => 33, 'length' => 46037]]);
})->group('SPEC-006');

it('AC4: RFC 8949 Appendix A, the supported rows, decode as printed', function (): void {
    $rows = [
        '00' => 0, '01' => 1, '0a' => 10, '17' => 23, '1818' => 24, '1819' => 25, '1864' => 100,
        '1903e8' => 1000, '1a000f4240' => 1000000, '1b000000e8d4a51000' => 1000000000000,
        '20' => -1, '29' => -10, '3863' => -100, '3903e7' => -1000,
        '40' => new CborBytes(''), '4401020304' => new CborBytes("\x01\x02\x03\x04"),
        '60' => '', '6161' => 'a', '6449455446' => 'IETF', '62225c' => '"\\', '62c3bc' => 'ü', '63e6b0b4' => '水', '64f0908591' => "\u{10151}",
        '80' => [], '83010203' => [1, 2, 3], '8301820203820405' => [1, [2, 3], [4, 5]],
        '98190102030405060708090a0b0c0d0e0f101112131415161718181819' => range(1, 25),
        'a0' => [], 'a201020304' => [1 => 2, 3 => 4], 'a26161016162820203' => ['a' => 1, 'b' => [2, 3]],
        '826161a161626163' => ['a', ['b' => 'c']],
        'a56161614161626142616361436164614461656145' => ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E'],
        'f4' => false, 'f5' => true, 'f6' => null,
        'c074323031332d30332d32315432303a30343a30305a' => new CborTag(0, '2013-03-21T20:04:00Z'),
        'c11a514b67b0' => new CborTag(1, 1363896240),
        'd74401020304' => new CborTag(23, new CborBytes("\x01\x02\x03\x04")),
        'd818456449455446' => new CborTag(24, new CborBytes('dIETF')),
        'd82076687474703a2f2f7777772e6578616d706c652e636f6d' => new CborTag(32, 'http://www.example.com'),
        'c249010000000000000000' => new CborTag(2, new CborBytes("\x01\x00\x00\x00\x00\x00\x00\x00\x00")),
        'c349010000000000000000' => new CborTag(3, new CborBytes("\x01\x00\x00\x00\x00\x00\x00\x00\x00")),
    ];
    foreach ($rows as $hex => $expected) {
        $hex = (string) $hex; // PHP turns keys like '17' into ints
        expect(spec006Decode($hex))->toEqual($expected, $hex);
    }
})->group('SPEC-006');

it('AC5: integers beyond PHP\'s range are an error', function (): void {
    expect(fn () => spec006Decode('1bffffffffffffffff'))
        ->toThrow(CborException::class, 'integer at offset 0 does not fit a 64-bit signed integer');
    expect(fn () => spec006Decode('3bffffffffffffffff'))
        ->toThrow(CborException::class, 'integer at offset 0 does not fit a 64-bit signed integer');
})->group('SPEC-006');

it('AC6: indefinite lengths are an error naming the offset', function (): void {
    $rows = [
        '5f42010243030405ff' => 0, '7f657374726561646d696e67ff' => 0, '9fff' => 0, '9f018202039f0405ffff' => 0,
        '83018202039f0405ff' => 5, 'bf61610161629f0203ffff' => 0, 'bf6346756ef563416d7421ff' => 0,
    ];
    foreach ($rows as $hex => $offset) {
        $hex = (string) $hex;
        expect(fn () => spec006Decode($hex))
            ->toThrow(CborException::class, "indefinite length at offset {$offset} is not supported");
    }

    $claim = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/cbor/claim-indefinite-array.cbor');
    $offset = (int) strpos($claim, "\x72created_assertions") + 19;
    expect(fn () => (new CborDecoder)->decode($claim))
        ->toThrow(CborException::class, "indefinite length at offset {$offset} is not supported");
})->group('SPEC-006');

it('AC7: floats decode to PHP floats, all three widths', function (): void {
    // RFC 8949 Appendix A, plus a subnormal half and the two other infinities (amendment 2)
    foreach ([
        'f90000' => 0.0, 'f93c00' => 1.0, 'f93e00' => 1.5, 'f9c400' => -4.0, 'f97bff' => 65504.0, 'f90001' => 5.960464477539063e-8,
        'fa47c35000' => 100000.0, 'fa7f7fffff' => 3.4028234663852886e38,
        'fb3ff199999999999a' => 1.1, 'fb7e37e43c8800759c' => 1.0e300,
        'f97c00' => INF, 'f9fc00' => -INF, 'fa7f800000' => INF, 'fb7ff0000000000000' => INF,
    ] as $hex => $expected) {
        $value = spec006Decode($hex);
        expect($value)->toBeFloat($hex)
            ->and($value)->toBe($expected, $hex);
    }
    $nan = spec006Decode('f97e00');
    expect(is_float($nan) && is_nan($nan))->toBeTrue();

    $tagged = spec006Decode('c1fb41d452d9ec200000');
    expect($tagged)->toBeInstanceOf(CborTag::class);
    assert($tagged instanceof CborTag);
    expect($tagged->number)->toBe(1)
        ->and($tagged->value)->toBe(1363896240.5);

    foreach (['f93c' => 0, 'fa47c350' => 0, 'fb3ff19999999999' => 0, 'a161'.'66'.'f97c' => 3] as $hex => $offset) {
        expect(fn () => spec006Decode($hex))->toThrow(CborException::class, "offset {$offset}");
    }

    // the four camera files of the C2PA's own test set carry floats in their assertions
    foreach (['nikon-20221019-building.jpeg', 'truepic-20230212-camera.jpg', 'truepic-20230212-landscape.jpg', 'truepic-20230212-library.jpg'] as $file) {
        $stream = fopen(dirname(__DIR__, 2).'/Fixtures/public-testfiles/'.$file, 'rb');
        assert($stream !== false);
        $store = (new JpegManifestStoreExtractor)->extract($stream);
        assert($store !== null);
        $manifestStore = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes));
        expect($manifestStore->active->assertions)->not->toBe([], $file);
    }
})->group('SPEC-006');

it('AC8: unknown simple values and undefined are an error', function (): void {
    expect(fn () => spec006Decode('f7'))->toThrow(CborException::class, 'simple value 23 (undefined) at offset 0');
    expect(fn () => spec006Decode('f0'))->toThrow(CborException::class, 'simple value 16 at offset 0');
    expect(fn () => spec006Decode('f8ff'))->toThrow(CborException::class, 'simple value 255 at offset 0');
    expect(fn () => spec006Decode('f81f'))->toThrow(CborException::class, 'simple value 31 at offset 0');
})->group('SPEC-006');

it('AC9: reserved additional information and a stray break are an error', function (): void {
    foreach (['1c' => 28, '1d' => 29, '1e' => 30, '3c' => 28, '5c' => 28, '7c' => 28, '9c' => 28, 'bc' => 28, 'dc' => 28, 'fc' => 28] as $hex => $ai) {
        $hex = (string) $hex;
        expect(fn () => spec006Decode($hex))
            ->toThrow(CborException::class, "additional information {$ai} at offset 0 is reserved");
    }
    expect(fn () => spec006Decode('ff'))->toThrow(CborException::class, 'break at offset 0');
    expect(fn () => spec006Decode('8300ff02'))->toThrow(CborException::class, 'break at offset 2');
})->group('SPEC-006');

it('AC10: truncation is an error naming where the bytes ran out', function (): void {
    $rows = [
        '18' => 1, '19' => 1, '1a' => 1, '1b' => 1, '1901' => 1, '1a0102' => 1, '1b01020304050607' => 1,
        '38' => 1, '58' => 1, '78' => 1, '98' => 1, 'b8' => 1, 'd8' => 1, 'f8' => 1,
        '41' => 1, '61' => 1, '5affffffff00' => 5, '7affffffff00' => 5,
        '81' => 1, '8200' => 2, 'a1' => 1, 'a20102' => 3, 'a100' => 2, 'a2000000' => 4,
        'c0' => 1,
    ];
    foreach ($rows as $hex => $offset) {
        $hex = (string) $hex; // PHP turns keys like '18' into ints
        expect(fn () => spec006Decode($hex))
            ->toThrow(CborException::class, "unexpected end of input at offset {$offset}");
    }
})->group('SPEC-006');

it('AC11: bytes after the value are an error', function (): void {
    expect(fn () => spec006Decode('0000'))
        ->toThrow(CborException::class, '1 byte(s) remain after the value, which ended at offset 1');
    expect(fn () => spec006Decode('a0f6'))
        ->toThrow(CborException::class, '1 byte(s) remain after the value, which ended at offset 1');
})->group('SPEC-006');

it('AC12: text strings must be UTF-8, shown as hex when they are not', function (): void {
    expect(fn () => spec006Decode('62c328'))
        ->toThrow(CborException::class, 'text string at offset 0 is not valid UTF-8: C3 28');
})->group('SPEC-006');

it('AC13: map keys are int or string, and unique', function (): void {
    expect(fn () => spec006Decode('a1400a'))->toThrow(CborException::class, 'map key at offset 1 is a byte string');
    expect(fn () => spec006Decode('a1800a'))->toThrow(CborException::class, 'map key at offset 1 is an array');
    expect(fn () => spec006Decode('a201020103'))->toThrow(CborException::class, 'duplicate map key 1 at offset 3');
    expect(fn () => spec006Decode('a2616101616102'))->toThrow(CborException::class, 'duplicate map key "a" at offset 4');

    $claim = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/cbor/claim-duplicate-key.cbor');
    expect(fn () => (new CborDecoder)->decode($claim))
        ->toThrow(CborException::class, 'duplicate map key "dc:title"');
})->group('SPEC-006');

it('AC14: limits are enforced before memory is spent', function (): void {
    expect(fn () => spec006Decode(str_repeat('81', 33).'00'))
        ->toThrow(CborException::class, 'depth 33 exceeds the limit of 32');
    expect(fn () => spec006Decode('98190102030405060708090a0b0c0d0e0f101112131415161718181819', new CborDecoder(maxItems: 10)))
        ->toThrow(CborException::class, 'array at offset 0 declares 25 items, above the limit of 10');
    expect(fn () => spec006Decode('5b0000000100000000'))
        ->toThrow(CborException::class, 'byte string at offset 0 needs 4294967296 bytes, 0 available');
})->group('SPEC-006');

it('AC15: the default limits are 32 and 65536, and sufficient for the sixteen blobs', function (): void {
    $decoder = new CborDecoder;

    expect($decoder->maxDepth)->toBe(32)
        ->and($decoder->maxItems)->toBe(65536);
    foreach (SPEC006_RECORDED as $name) {
        expect($decoder->decode(spec006Recorded($name)['bytes']))->not->toBeNull($name);
    }
})->group('SPEC-006');
