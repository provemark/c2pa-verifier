<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/*
 * SPEC-008: COSE_Sign1, the structure and the headers, and the bytes that
 * were signed. The numbers are step 16's (notes/step-16-cose-signature.md);
 * the variants are tests/Fixtures/cose/ (notes/step-17-cose-variants.md).
 */

const SPEC008_PNG_SIG_STRUCTURE_SHA256 = '065a22daa4ffad6e5b83a830c4760dbc163842152562593e58cb44c286408504';

/** The active manifest of a fixture, through the M1 extractor, SPEC-005 and SPEC-007. */
function spec008Manifest(string $fixture): Manifest
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

    return ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active;
}

/** The active manifest of a variant store from tests/Fixtures/cose/. */
function spec008Variant(string $name): Manifest
{
    return ManifestStore::fromTree((new JumbfParser)->parse((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/cose/{$name}.bin")))->active;
}

function spec008Parse(Manifest $manifest): CoseSign1
{
    return CoseSign1::fromBytes($manifest->signatureBytes());
}

/** The CN of a DER certificate. */
function spec008Cn(CborBytes $der): string
{
    $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der->bytes), 64, "\n")."-----END CERTIFICATE-----\n";
    $parsed = openssl_x509_parse($pem);
    if (! is_array($parsed) || ! is_array($parsed['subject']) || ! is_string($parsed['subject']['CN'])) {
        throw new RuntimeException('cannot parse the certificate');
    }

    return $parsed['subject']['CN'];
}

/** A CBOR byte-string head, shortest form, for synthetic structures. */
function spec008BstrHead(int $length): string
{
    return match (true) {
        $length < 24 => pack('C', 0x40 | $length),
        $length < 256 => "\x58".pack('C', $length),
        $length < 65536 => "\x59".pack('n', $length),
        default => "\x5a".pack('N', $length),
    };
}

/** A tagged COSE_Sign1 with the given protected header bytes, an empty unprotected map, nil payload, a one-byte signature. */
function spec008Synthetic(string $protected): string
{
    return "\xd2\x84".spec008BstrHead(strlen($protected)).$protected."\xa0\xf6\x41\x00";
}

it('AC1: the PNG signature parses to its four parts', function (): void {
    $cose = spec008Parse(spec008Manifest('fixture-signed.png'));

    expect(strlen($cose->protectedBytes))->toBe(1285)
        ->and(bin2hex(substr($cose->protectedBytes, 0, 5)))->toBe('a201261821')
        ->and(array_keys($cose->protected))->toBe([1, 33])
        ->and($cose->alg)->toBe(-7)
        ->and(strlen($cose->signature))->toBe(64)
        ->and(array_keys($cose->unprotected))->toBe(['pad'])
        ->and($cose->timestamp)->toBeNull()
        ->and(array_keys($cose->otherHeaders))->toBe(['pad']);

    $pad = $cose->otherHeaders['pad'];
    expect($pad)->toBeInstanceOf(CborBytes::class);
    assert($pad instanceof CborBytes);
    expect(strlen($pad->bytes))->toBe(10932)
        ->and($pad->bytes)->toBe(str_repeat("\0", 10932));
})->group('SPEC-008');

it('AC2: the chain comes from the protected header, leaf first', function (): void {
    $cose = spec008Parse(spec008Manifest('fixture-signed.png'));

    expect($cose->chain)->toHaveCount(2)
        ->and($cose->chainProtected)->toBeTrue()
        ->and(strlen($cose->chain[0]->bytes))->toBe(651)
        ->and(strlen($cose->chain[1]->bytes))->toBe(622)
        ->and(spec008Cn($cose->chain[0]))->toBe('C2PA Signer')
        ->and(spec008Cn($cose->chain[1]))->toBe('Intermediate CA');
})->group('SPEC-008');

it('AC3: the JPEG and WebP signatures have the same shape as the PNG signature', function (): void {
    $shape = static fn (CoseSign1 $cose): array => [
        $cose->alg,
        array_keys($cose->protected),
        array_map(static fn (CborBytes $cert): int => strlen($cert->bytes), $cose->chain),
        $cose->chainProtected,
        array_keys($cose->unprotected),
        strlen($cose->signature),
        $cose->timestamp,
    ];
    $png = $shape(spec008Parse(spec008Manifest('fixture-signed.png')));

    foreach (['fixture-signed.jpg', 'fixture-signed.webp'] as $fixture) {
        expect($shape(spec008Parse(spec008Manifest($fixture))))->toBe($png, $fixture);
    }
})->group('SPEC-008');

it('AC4: a 2022 signature: PS256, the chain unprotected under the string label, a timestamp', function (): void {
    $cose = spec008Parse(spec008Manifest('public-testfiles/adobe-20220124-C.jpg'));

    expect(bin2hex($cose->protectedBytes))->toBe('a1013824')
        ->and($cose->alg)->toBe(-37)
        ->and(array_map(static fn (CborBytes $cert): int => strlen($cert->bytes), $cose->chain))->toBe([1716, 1685, 1663])
        ->and($cose->chainProtected)->toBeFalse()
        ->and(spec008Cn($cose->chain[0]))->toBe('C2PA Signer')
        ->and(strlen($cose->signature))->toBe(512)
        ->and(array_keys($cose->otherHeaders))->toBe(['x5chain', 'sigTst', 'pad']);

    $timestamp = $cose->timestamp;
    assert(is_array($timestamp) && is_array($timestamp['tstTokens']) && is_array($timestamp['tstTokens'][0]));
    $token = $timestamp['tstTokens'][0]['val'];
    expect($token)->toBeInstanceOf(CborBytes::class);
    assert($token instanceof CborBytes);
    expect(strlen($token->bytes))->toBe(5951);

    $pad = $cose->otherHeaders['pad'];
    assert($pad instanceof CborBytes);
    expect(strlen($pad->bytes))->toBe(6457);
})->group('SPEC-008');

it('AC5: the Sig_structure is byte-exact', function (): void {
    $expected = [
        'fixture-signed.png' => [1895, SPEC008_PNG_SIG_STRUCTURE_SHA256],
        'fixture-signed.jpg' => [1895, 'c80e74ebf7fa2f49a1353d2d5bdb3a8bf85762e862b46919e9ebddb4e031c165'],
        'fixture-signed.webp' => [1896, 'f47dba5fbe767874afebccfe9fc15b0f11218abfca379640c5147c740c72e652'],
        'public-testfiles/adobe-20220124-C.jpg' => [602, '1a33b3e75fe2e9047d78b225ca33f4a676792167e9c3af89133bf56ceec07932'],
    ];
    foreach ($expected as $fixture => [$length, $sha256]) {
        $manifest = spec008Manifest($fixture);
        $structure = spec008Parse($manifest)->sigStructure($manifest->claimBytes());

        expect(strlen($structure))->toBe($length, $fixture)
            ->and(hash('sha256', $structure))->toBe($sha256, $fixture)
            ->and(str_ends_with($structure, $manifest->claimBytes()))->toBeTrue($fixture);
    }

    $png = spec008Manifest('fixture-signed.png');
    expect(bin2hex(substr(spec008Parse($png)->sigStructure($png->claimBytes()), 0, 18)))
        ->toBe('846a5369676e6174757265315905'.'05a20126');
})->group('SPEC-008');

it('AC6: the encoder writes shortest-form lengths and the structure decodes back to four items', function (): void {
    $protected = "\xa1\x01\x26\x00";
    $cose = new CoseSign1($protected, [1 => -7], [], "\0", -7, [new CborBytes("\x30")], true, null, []);
    $heads = [0 => '40', 23 => '57', 24 => '5818', 255 => '58ff', 256 => '590100', 65535 => '59ffff', 65536 => '5a00010000', 70000 => '5a00011170'];

    foreach ($heads as $length => $head) {
        $payload = str_repeat('p', $length);
        $structure = $cose->sigStructure($payload);
        $prefix = "\x84\x6aSignature1\x44".$protected."\x40";

        expect(str_starts_with($structure, $prefix))->toBeTrue("payload of {$length}")
            ->and(bin2hex(substr($structure, strlen($prefix), strlen($head) / 2)))->toBe($head, "payload of {$length}")
            ->and((new CborDecoder)->decode($structure))->toEqual(['Signature1', new CborBytes($protected), new CborBytes(''), new CborBytes($payload)], "payload of {$length}");
    }
})->group('SPEC-008');

it('AC7: not a tagged COSE_Sign1 is an error', function (): void {
    expect(fn () => spec008Parse(spec008Variant('tag-19')))
        ->toThrow(CoseException::class, 'expected tag 18 (COSE_Sign1_Tagged), found tag 19');
    expect(fn () => spec008Parse(spec008Variant('no-tag')))
        ->toThrow(CoseException::class, 'expected tag 18 (COSE_Sign1_Tagged), found an untagged array');
    expect(fn () => spec008Parse(spec008Variant('three-items')))
        ->toThrow(CoseException::class, 'expected four items, found 3');
})->group('SPEC-008');

it('AC8: a present payload is an error', function (): void {
    expect(fn () => spec008Parse(spec008Variant('payload-present')))
        ->toThrow(CoseException::class, 'the payload must be detached (nil); an empty byte string does not count');
})->group('SPEC-008');

it('AC9: the protected header must be a map with an integer alg', function (): void {
    expect(fn () => spec008Parse(spec008Variant('protected-not-map')))
        ->toThrow(CoseException::class, 'the protected header is not a map');
    expect(fn () => spec008Parse(spec008Variant('alg-missing')))
        ->toThrow(CoseException::class, 'the protected header has no alg (label 1)');
    expect(fn () => spec008Parse(spec008Variant('alg-string-label')))
        ->toThrow(CoseException::class, 'alg under the string label "alg" is not allowed');
})->group('SPEC-008');

it('AC10: a missing or malformed chain is an error', function (): void {
    expect(fn () => spec008Parse(spec008Variant('x5chain-missing')))
        ->toThrow(CoseException::class, 'no x5chain in either header bucket');
    expect(fn () => spec008Parse(spec008Variant('leaf-der-broken')))
        ->toThrow(CoseException::class, 'the leaf certificate is not an X.509 certificate');
    expect(fn () => spec008Parse(spec008Variant('chain-empty')))
        ->toThrow(CoseException::class, 'x5chain is empty');
})->group('SPEC-008');

it('AC11: 33 wins over the string label', function (): void {
    $cose = spec008Parse(spec008Variant('double-label'));

    expect(array_keys($cose->protected))->toBe([1, 33, 'x5chain'])
        ->and($cose->chain)->toHaveCount(2)
        ->and(spec008Cn($cose->chain[0]))->toBe('C2PA Signer')
        ->and($cose->chainProtected)->toBeTrue()
        ->and(array_keys($cose->otherHeaders))->toBe(['x5chain', 'pad']);
})->group('SPEC-008');

it('AC12: limits are enforced before allocation', function (): void {
    expect(fn () => CoseSign1::fromBytes(spec008Manifest('public-testfiles/adobe-20220124-C.jpg')->signatureBytes(), maxChain: 2))
        ->toThrow(CoseException::class, 'chain of 3 certificates exceeds the limit of 2');

    $bigCert = str_repeat("\x30", 20000);
    $protected = "\xa2\x01\x26\x18\x21\x81".spec008BstrHead(strlen($bigCert)).$bigCert;
    expect(fn () => CoseSign1::fromBytes(spec008Synthetic($protected)))
        ->toThrow(CoseException::class, 'certificate of 20000 bytes exceeds the limit of 16384');

    expect(CoseSign1::DEFAULT_MAX_CHAIN)->toBe(16)
        ->and(CoseSign1::DEFAULT_MAX_CERTIFICATE_BYTES)->toBe(16384)
        ->and(CoseSign1::DEFAULT_MAX_PROTECTED_BYTES)->toBe(65536);
})->group('SPEC-008');
