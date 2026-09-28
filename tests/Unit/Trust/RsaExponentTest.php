<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\Certificate;
use Provemark\C2paVerifier\Trust\CertificateProfileCheck;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Trust\RsaExponent;
use Provemark\C2paVerifier\Trust\TrustSettings;

/*
 * SPEC-049: an RSA certificate needs a real public exponent. Amendment 2: the rule is tested on the
 * exponent's bytes, with the values of c2pa-rs 0.91.1's own test (PR #2712), and each call site on a
 * real certificate from the corpus whose reported key has one field altered, the exponent (amendment 3:
 * read from the subjectPublicKeyInfo, which also covers id-RSASSA-PSS keys). The real key still verifies
 * every signature, so the exponent is the only fault the check can see.
 */

/**
 * The chain of a corpus file's active manifest, leaf first.
 *
 * @return non-empty-list<Certificate>
 */
function spec049Chain(string $relative): array
{
    $store = Corpus::manifestStore($relative);
    assert($store !== null);
    $chain = array_map(static fn ($c): Certificate => Certificate::fromDer($c->bytes), CoseSign1::fromBytes($store->active->signatureBytes())->chain);
    assert($chain !== []);

    return $chain;
}

/** @param  int<0, 255>  $tag */
function spec049Tlv(int $tag, string $contents): string
{
    $n = strlen($contents);
    $length = $n < 0x80 ? chr($n) : ($n < 0x100 ? "\x81".chr($n) : "\x82".pack('n', $n));

    return chr($tag).$length.$contents;
}

/**
 * $certificate with the publicExponent in its key's subjectPublicKeyInfo replaced by $exponent (content
 * octets), or, when null, with an RSAPublicKey that holds the modulus alone: a key that is not the
 * structure RFC 8017 A.1.1 defines. Only the key details change; the certificate's DER, and the real key
 * that verifies every signature, stay as they are (amendment 3).
 */
function spec049WithExponent(Certificate $certificate, ?string $exponent): Certificate
{
    $pem = Certificate::pem($certificate->der);
    $parsed = openssl_x509_parse($pem);
    $public = openssl_pkey_get_public($pem);
    assert(is_array($parsed) && $public !== false);
    $key = openssl_pkey_get_details($public);
    assert(is_array($key) && is_string($key['key'] ?? null));

    $spki = (new DerReader)->read((string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $key['key']) ?? '', true));
    [$algorithm, $bits] = $spki->sequence();
    $rsaPublicKey = (new DerReader)->read(substr($bits->contents, 1));
    $modulus = $rsaPublicKey->element(0)->encoded();
    $inner = $exponent === null ? $modulus : $modulus.spec049Tlv(0x02, $exponent);
    $altered = spec049Tlv(0x30, $algorithm->encoded().spec049Tlv(0x03, "\0".spec049Tlv(0x30, $inner)));
    $key['key'] = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($altered), 64, "\n")."-----END PUBLIC KEY-----\n";

    $typed = static function (array $a): array {
        $t = [];
        foreach ($a as $k => $v) {
            $t[(string) $k] = $v;
        }

        return $t;
    };

    return Certificate::fromParsed($certificate->der, $typed($parsed), $typed($key));
}

/** @param list<ValidationStatus> $statuses */
function spec049Mentions(array $statuses, StatusCode $code): string
{
    return implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, array_values(array_filter($statuses, static fn (ValidationStatus $s): bool => $s->code === $code))));
}

function spec049Settings(string $relative): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$relative));
}

it('AC1: an exponent of 1 is refused', function (): void {
    expect(RsaExponent::fault("\x01"))->toContain('exponent 1');
})->group('SPEC-049');

it('AC2: an even exponent is refused', function (string $bytes, string $shown): void {
    expect(RsaExponent::fault($bytes))->toContain("exponent {$shown}");
})->with([
    '2' => ["\x02", '2'],
    '4' => ["\x04", '4'],
    '65536' => ["\x01\x00\x00", '65536'],
])->group('SPEC-049');

it('AC3: an exponent that cannot be read is refused', function (?string $bytes): void {
    expect(RsaExponent::fault($bytes))->toContain('exponent');
})->with([
    'absent' => [null],
    'empty' => [''],
    'zero' => ["\x00"],
])->group('SPEC-049');

it('AC4: real exponents are accepted', function (string $bytes): void {
    expect(RsaExponent::fault($bytes))->toBeNull();
})->with([
    '3' => ["\x03"],
    '65537' => ["\x01\x00\x01"],
    '65537 with a leading zero byte' => ["\x00\x01\x00\x01"],
])->group('SPEC-049');

it('AC1: the leaf profile consults the rule', function (): void {
    $leaf = spec049Chain('matrix/ps256.jpg')[0];
    expect($leaf->keyType)->toBe('RSA');
    $check = new CertificateProfileCheck;

    $refused = $check->checkLeaf(spec049WithExponent($leaf, "\x01"), null, null, 'self#jumbf=/probe');
    expect(spec049Mentions($refused, StatusCode::SigningCredentialInvalid))->toContain('exponent 1');

    // the same leaf with its own exponent: nothing about the exponent
    $kept = $check->checkLeaf($leaf, null, null, 'self#jumbf=/probe');
    expect(spec049Mentions($kept, StatusCode::SigningCredentialInvalid))->not->toContain('exponent');
})->group('SPEC-049');

it('AC3: the leaf profile refuses an RSA key that holds no exponent', function (): void {
    $leaf = spec049Chain('matrix/ps256.jpg')[0];
    $statuses = (new CertificateProfileCheck)->checkLeaf(spec049WithExponent($leaf, null), null, null, 'self#jumbf=/probe');
    expect(spec049Mentions($statuses, StatusCode::SigningCredentialInvalid))->toContain('exponent');
})->group('SPEC-049');

it('AC6: an intermediate with a bad exponent is untrusted', function (string $bytes, string $shown): void {
    $chain = spec049Chain('chain-constraints/rsa1024-intermediate.png');
    expect($chain)->toHaveCount(2)
        ->and($chain[1]->keyType)->toBe('RSA');
    $settings = spec049Settings('chain-constraints/root.settings.json');

    $statuses = (new ChainCheck)->checkCertificates([$chain[0], spec049WithExponent($chain[1], $bytes)], $settings, 'self#jumbf=/probe');
    expect(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))->toBe([StatusCode::SigningCredentialUntrusted])
        ->and(spec049Mentions($statuses, StatusCode::SigningCredentialUntrusted))->toContain("exponent {$shown}")
        ->and(spec049Mentions($statuses, StatusCode::SigningCredentialUntrusted))->toContain('Intermediate with an RSA-1024 key');
})->with([
    '1' => ["\x01", '1'],
    '2' => ["\x02", '2'],
])->group('SPEC-049');

it('AC6: the same intermediate with its own exponent is trusted', function (): void {
    $chain = spec049Chain('chain-constraints/rsa1024-intermediate.png');
    $statuses = (new ChainCheck)->checkCertificates($chain, spec049Settings('chain-constraints/root.settings.json'), 'self#jumbf=/probe');
    expect(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))->toBe([StatusCode::SigningCredentialTrusted]);
})->group('SPEC-049');

it('AC4: real RSA files keep their verdicts', function (): void {
    expect(spec020Verify('matrix/ps256.jpg', 'trust/full.settings.json')->result->state->value)->toBe('Trusted')
        ->and(spec020Verify('chain-constraints/rsa1024-intermediate.png', 'chain-constraints/root.settings.json')->result->state->value)->toBe('Trusted');
    // an id-RSASSA-PSS leaf, for which PHP reports no RSA details (amendment 3): Valid, only untrusted
    $report = spec020Verify('c2pa-rs/CA_ct.jpg');
    $failures = array_map(static fn (ValidationStatus $s): string => $s->code->value, array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null && $s->code->isFailure())));
    expect($report->result->state->value)->toBe('Valid')
        ->and($failures)->toBe(['signingCredential.untrusted']);
})->group('SPEC-049');
