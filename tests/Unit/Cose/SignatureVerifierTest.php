<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseException;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\EcdsaSignature;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/*
 * SPEC-009: verifying the claim signature. The four fixtures and the
 * tests/Fixtures/cose/ variants reach this layer through SPEC-005/007/008;
 * the synthetic vectors are tests/Fixtures/signatures/*.json
 * (notes/step-19-signature-vectors.md).
 */

function spec009Manifest(string $fixture): Manifest
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

function spec009Variant(string $name): Manifest
{
    return ManifestStore::fromTree((new JumbfParser)->parse((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/cose/{$name}.bin")))->active;
}

/** @return array{cose: CoseSign1, claim: string, expect: string, signature: string, protected: string} */
function spec009Vector(string $name): array
{
    /** @var array{expect: string, claim_hex: string, protected_hex: string, signature_hex: string} $v */
    $v = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/signatures/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    $protected = (string) hex2bin($v['protected_hex']);
    $signature = (string) hex2bin($v['signature_hex']);

    return [
        'cose' => CoseSign1::fromBytes(spec009CoseBytes($protected, $signature)),
        'claim' => (string) hex2bin($v['claim_hex']),
        'expect' => $v['expect'],
        'signature' => $signature,
        'protected' => $protected,
    ];
}

/** A COSE_Sign1_Tagged with an empty unprotected header and a detached payload, for SPEC-008 to parse. */
function spec009CoseBytes(string $protected, string $signature): string
{
    return "\xd2\x84".spec009Bstr($protected)."\xa0\xf6".spec009Bstr($signature);
}

function spec009Bstr(string $bytes): string
{
    $n = strlen($bytes);
    $head = match (true) {
        $n < 24 => pack('C', 0x40 | $n),
        $n < 256 => "\x58".pack('C', $n),
        $n < 65536 => "\x59".pack('n', $n),
        default => "\x5a".pack('N', $n),
    };

    return $head.$bytes;
}

function spec009Flip(string $claim): string
{
    $claim[20] = chr(ord($claim[20]) ^ 0x01);

    return $claim;
}

it('AC1: the four fixtures verify', function (): void {
    $verifier = new SignatureVerifier;
    foreach (['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp', 'public-testfiles/adobe-20220124-C.jpg'] as $fixture) {
        $manifest = spec009Manifest($fixture);

        expect($verifier->verify(CoseSign1::fromBytes($manifest->signatureBytes()), $manifest->claimBytes()))->toBeTrue($fixture);
    }
})->group('SPEC-009');

it('AC2: one changed byte of the claim is a mismatch', function (): void {
    $manifest = spec009Variant('claim-title-changed');

    expect((new SignatureVerifier)->verify(CoseSign1::fromBytes($manifest->signatureBytes()), $manifest->claimBytes()))->toBeFalse();
})->group('SPEC-009');

it('AC3: one changed bit of the signature is a mismatch', function (): void {
    $manifest = spec009Variant('signature-changed');

    expect((new SignatureVerifier)->verify(CoseSign1::fromBytes($manifest->signatureBytes()), $manifest->claimBytes()))->toBeFalse();
})->group('SPEC-009');

it('AC4: a claim from another manifest is a mismatch', function (): void {
    $png = spec009Manifest('fixture-signed.png');
    $jpg = spec009Manifest('fixture-signed.jpg');

    expect((new SignatureVerifier)->verify(CoseSign1::fromBytes($png->signatureBytes()), $jpg->claimBytes()))->toBeFalse();
})->group('SPEC-009');

it('AC5: a key that does not fit the algorithm cannot be verified', function (): void {
    $verifier = new SignatureVerifier;
    $manifest = spec009Variant('alg-eddsa-with-ec-key');
    expect(fn () => $verifier->verify(CoseSign1::fromBytes($manifest->signatureBytes()), $manifest->claimBytes()))
        ->toThrow(CoseException::class, 'key does not fit EdDSA (alg -8): EC key on prime256v1');

    foreach (['es256-p256k1' => 'key does not fit ES256 (alg -7): EC key on secp256k1', 'ps256-rsa1024' => 'key does not fit PS256 (alg -37): RSA key of 1024 bits', 'eddsa-rsa' => 'key does not fit EdDSA (alg -8): RSA key'] as $name => $message) {
        $v = spec009Vector($name);
        expect($v['expect'])->toBe('exception', $name);
        expect(fn () => $verifier->verify($v['cose'], $v['claim']))
            ->toThrow(CoseException::class, $message);
        expect(fn () => $verifier->verify($v['cose'], $v['claim']))
            ->toThrow(CoseException::class, '§13.2.1');
    }
})->group('SPEC-009');

it('AC6: ES384 and ES512 verify, and their curves cross', function (): void {
    $verifier = new SignatureVerifier;
    foreach (['es384-p384', 'es512-p521', 'es256-p384'] as $name) {
        $v = spec009Vector($name);
        expect($v['expect'])->toBe('true', $name)
            ->and($verifier->verify($v['cose'], $v['claim']))->toBeTrue($name)
            ->and($verifier->verify($v['cose'], spec009Flip($v['claim'])))->toBeFalse("{$name} flipped");
    }

    $v = spec009Vector('es384-p384');
    $cut = CoseSign1::fromBytes(spec009CoseBytes($v['protected'], substr($v['signature'], 0, 95)));
    expect($verifier->verify($cut, $v['claim']))->toBeFalse('95-byte signature');
})->group('SPEC-009');

it('AC7: PS256 under an ordinary RSA key verifies through EMSA-PSS, and PKCS#1 v1.5 does not pass', function (): void {
    $verifier = new SignatureVerifier;
    foreach (['ps256-rsa2048', 'ps384-rsa3072', 'ps512-rsa4096'] as $name) {
        $v = spec009Vector($name);
        expect($v['expect'])->toBe('true', $name)
            ->and($verifier->verify($v['cose'], $v['claim']))->toBeTrue($name)
            ->and($verifier->verify($v['cose'], spec009Flip($v['claim'])))->toBeFalse("{$name} flipped");
    }
    foreach (['ps256-rsa2048-v15', 'ps384-under-rsapss-sha256-key'] as $name) {
        $v = spec009Vector($name);
        expect($v['expect'])->toBe('false', $name)
            ->and($verifier->verify($v['cose'], $v['claim']))->toBeFalse($name);
    }
})->group('SPEC-009');

it('AC8: EdDSA verifies, through sodium or OpenSSL', function (): void {
    $v = spec009Vector('eddsa-ed25519');
    expect($v['expect'])->toBe('true');

    expect((new SignatureVerifier)->verify($v['cose'], $v['claim']))->toBeTrue('default')
        ->and((new SignatureVerifier)->verify($v['cose'], spec009Flip($v['claim'])))->toBeFalse('default, flipped')
        ->and((new SignatureVerifier(useSodium: true, useOpensslEd25519: false))->verify($v['cose'], $v['claim']))->toBeTrue('sodium only')
        ->and((new SignatureVerifier(useSodium: false, useOpensslEd25519: true))->verify($v['cose'], $v['claim']))->toBeTrue('openssl only')
        ->and((new SignatureVerifier(useSodium: false, useOpensslEd25519: true))->verify($v['cose'], spec009Flip($v['claim'])))->toBeFalse('openssl only, flipped');

    expect(fn () => (new SignatureVerifier(useSodium: false, useOpensslEd25519: false))->verify($v['cose'], $v['claim']))
        ->toThrow(CoseException::class, 'EdDSA cannot be verified: neither ext-sodium nor OpenSSL Ed25519');
})->group('SPEC-009');

it('AC9: an unsupported algorithm cannot be verified', function (): void {
    $v = spec009Vector('alg-unsupported');
    expect($v['expect'])->toBe('exception');

    expect(fn () => (new SignatureVerifier)->verify($v['cose'], $v['claim']))
        ->toThrow(CoseException::class, 'alg -65535 is not supported');
})->group('SPEC-009');

it('AC10: the R||S to DER conversion is exact', function (): void {
    $png = CoseSign1::fromBytes(spec009Manifest('fixture-signed.png')->signatureBytes());
    $der = EcdsaSignature::toDer($png->signature, 32);
    expect($der)->not->toBeNull();
    assert($der !== null);
    $r = substr($png->signature, 0, 32);
    $rHead = ord($r[0]) & 0x80 ? '022100' : '0220';
    expect(bin2hex(substr($der, 0, 1)))->toBe('30')
        ->and(bin2hex(substr($der, 2, strlen($rHead) / 2)))->toBe($rHead)
        ->and(strlen($der))->toBe(2 + ord($der[1]));

    $zeros = str_repeat("\0", 31);
    $one = str_repeat("\0", 32);
    expect(bin2hex(EcdsaSignature::toDer($zeros."\x05".$zeros."\x07", 32) ?? ''))->toBe('3006'.'020105'.'020107')
        ->and(bin2hex(substr(EcdsaSignature::toDer("\x80".$zeros.$zeros."\x07", 32) ?? '', 0, 5)))->toBe('3025022100')
        ->and(bin2hex(EcdsaSignature::toDer($one.$one, 32) ?? ''))->toBe('3006'.'020100'.'020100');

    $p521 = spec009Vector('es512-p521');
    $longForm = EcdsaSignature::toDer($p521['signature'], 66);
    expect($longForm)->not->toBeNull();
    assert($longForm !== null);
    expect(bin2hex(substr($longForm, 0, 2)))->toBe('3081')
        ->and(strlen($longForm))->toBe(3 + ord($longForm[2]));

    expect(EcdsaSignature::toDer(substr($png->signature, 0, 63), 32))->toBeNull();
    $cut = CoseSign1::fromBytes(spec009CoseBytes($png->protectedBytes, substr($png->signature, 0, 63)));
    expect((new SignatureVerifier)->verify($cut, spec009Manifest('fixture-signed.png')->claimBytes()))->toBeFalse();
})->group('SPEC-009');

it('AC11: the leaf key is read from chain[0], so a reversed chain is a mismatch, not an error', function (): void {
    $v = spec009Vector('chain-reversed');
    expect($v['expect'])->toBe('false')
        ->and((new SignatureVerifier)->verify($v['cose'], $v['claim']))->toBeFalse();
})->group('SPEC-009');
