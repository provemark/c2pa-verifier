<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Timestamp\TimestampException;
use Provemark\C2paVerifier\Timestamp\TimestampHeader;
use Provemark\C2paVerifier\Timestamp\TimeStampToken;
use Provemark\C2paVerifier\Trust\Certificate;

/*
 * SPEC-016, AC3–AC10: the timestamp token as data. No new fixtures: the
 * tokens are cut out of the corpus files through CoseSign1, as step 40's
 * scratch script did. Every literal below is what `openssl ts -reply
 * -token_in -text`, `openssl cms -cmsout -print` and `openssl asn1parse`
 * printed for these tokens on 2026-09-22 (step 40 and the SPEC-016 draft's
 * AI-log entry).
 */

const SPEC016_OID_TSTINFO = '1.2.840.113549.1.9.16.1.4';
const SPEC016_OID_SHA256 = '2.16.840.1.101.3.4.2.1';
const SPEC016_OID_SHA384 = '2.16.840.1.101.3.4.2.2';
const SPEC016_OID_RSA = '1.2.840.113549.1.1.1';
const SPEC016_OID_SHA384_RSA = '1.2.840.113549.1.1.12';
const SPEC016_OID_SIGNING_CERT = '1.2.840.113549.1.9.16.2.12';
const SPEC016_OID_SIGNING_CERT_V2 = '1.2.840.113549.1.9.16.2.47';
const SPEC016_OID_CMS_ALG_PROTECTION = '1.2.840.113549.1.9.52';
// the bounds every corpus token must fit (step 41a: `openssl asn1parse` shows d=18 and 311 elements at most, on the Truepic tokens; the defaults are 32 / 65 536)
const SPEC016_CORPUS_MAX_DEPTH = 20;
const SPEC016_CORPUS_MAX_ELEMENTS = 512;

/**
 * The five tokens of AC3–AC5: file => the measured expectation.
 *
 * @return array<string, array{header: string, policy: string, alg: string, imprint: string, serialHex: string, genTime: string, nonceHex: ?string, millis: ?int, tsa: bool, eContentLength: int, eContentHead: string, signerCn: string, digestAlg: string, sigAlg: string, otherAttributes: list<string>}>
 */
function spec016Five(): array
{
    $digicert = ['policy' => '2.16.840.1.114412.7.1', 'alg' => SPEC016_OID_SHA256, 'millis' => null, 'tsa' => false, 'digestAlg' => SPEC016_OID_SHA256, 'sigAlg' => SPEC016_OID_RSA, 'otherAttributes' => [SPEC016_OID_SIGNING_CERT, SPEC016_OID_SIGNING_CERT_V2]];

    return [
        'c2pa-rs/C.jpg' => $digicert + ['header' => 'sigTst', 'imprint' => '64d055da', 'serialHex' => '0637D446C68635796BE29941AE68F7A9', 'genTime' => '2024-08-06T21:53:37+00:00', 'nonceHex' => '59A4EE7623CEFC36', 'eContentLength' => 112, 'eContentHead' => '306e', 'signerCn' => 'DigiCert Timestamp 2023'],
        'public-testfiles/adobe-20220124-C.jpg' => $digicert + ['header' => 'sigTst', 'imprint' => '0432594d', 'serialHex' => '86B091F8B163977B2B98CDC2A02D7B0F', 'genTime' => '2023-01-24T14:48:56+00:00', 'nonceHex' => '90C0FF57DE1168F8', 'eContentLength' => 114, 'eContentHead' => '3070', 'signerCn' => 'DigiCert Timestamp 2022 - 2'],
        'public-testfiles/truepic-20230212-camera.jpg' => ['header' => 'sigTst', 'policy' => '1.3.6.1.4.1.22408.1.2.3.45', 'alg' => SPEC016_OID_SHA384, 'imprint' => 'd8160464', 'serialHex' => '3972A07AC1F439E50BB0DB3EB17B20D9', 'genTime' => '2023-02-12T18:44:26+00:00', 'nonceHex' => null, 'millis' => 500, 'tsa' => true, 'eContentLength' => 227, 'eContentHead' => '3081e0', 'signerCn' => 'Truepic Lens Time-Stamping Authority', 'digestAlg' => SPEC016_OID_SHA384, 'sigAlg' => SPEC016_OID_SHA384_RSA, 'otherAttributes' => [SPEC016_OID_CMS_ALG_PROTECTION, SPEC016_OID_SIGNING_CERT_V2]],
        'c2pa-rs/C_with_CAWG_data.jpg' => $digicert + ['header' => 'sigTst2', 'imprint' => '37ab305c', 'serialHex' => '8EFC58636572B0EB9637E2DC718C93E6', 'genTime' => '2025-07-29T23:13:49+00:00', 'nonceHex' => '16DA33EF5E896BE0', 'eContentLength' => 113, 'eContentHead' => '306f', 'signerCn' => 'DigiCert SHA256 RSA4096 Timestamp Responder 2025 1'],
        'c2pa-rs/CACA.jpg' => $digicert + ['header' => 'sigTst2', 'imprint' => '4b2fa959', 'serialHex' => 'B7EAF41E6D4CF3ACDF7116816B9C3272', 'genTime' => '2025-10-16T17:31:55+00:00', 'nonceHex' => '3A1E7AA9E21E8526', 'eContentLength' => 113, 'eContentHead' => '306f', 'signerCn' => 'DigiCert SHA256 RSA4096 Timestamp Responder 2025 1'],
    ];
}

function spec016Fixtures(): string
{
    return dirname(__DIR__, 2).'/Fixtures';
}

/** The active manifest's COSE_Sign1 of a corpus file, or null when the file has no manifest store. */
function spec016Cose(string $relative): ?CoseSign1
{
    $stream = fopen(spec016Fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $format = (new FormatDetector)->detect($stream);
    $store = match ($format) {
        'jpeg' => (new JpegManifestStoreExtractor)->extract($stream),
        'png' => (new PngManifestStoreExtractor)->extract($stream),
        'webp' => (new WebpManifestStoreExtractor)->extract($stream),
        default => null,
    };
    fclose($stream);
    if ($store === null) {
        return null;
    }
    $manifests = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes));

    return CoseSign1::fromBytes($manifests->active->signatureBytes());
}

/** The raw sigTst / sigTst2 value (tstTokens[0].val) of the active manifest. */
function spec016HeaderValue(string $relative): string
{
    $cose = spec016Cose($relative);
    if ($cose === null) {
        throw new RuntimeException("{$relative} has no manifest");
    }
    $header = $cose->unprotected['sigTst2'] ?? $cose->unprotected['sigTst'] ?? null;
    $first = is_array($header) && is_array($header['tstTokens'] ?? null) && is_array($header['tstTokens'][0] ?? null) ? $header['tstTokens'][0] : [];
    $val = $first['val'] ?? null;
    if (! $val instanceof CborBytes) {
        throw new RuntimeException("{$relative} carries no timestamp header");
    }

    return $val->bytes;
}

/**
 * A test-side DER walker for the patches of AC7: the identifier, header
 * length and content length of the element at $offset. Independent of the
 * reader under test on purpose — a patch must not depend on the code it
 * is meant to exercise.
 *
 * @return array{tag: int, headerLength: int, length: int}
 */
function spec016Element(string $bytes, int $offset): array
{
    $tag = ord($bytes[$offset]);
    $first = ord($bytes[$offset + 1]);
    if ($first < 0x80) {
        return ['tag' => $tag, 'headerLength' => 2, 'length' => $first];
    }
    $n = $first & 0x7F;
    $length = 0;
    for ($i = 0; $i < $n; $i++) {
        $length = ($length << 8) | ord($bytes[$offset + 2 + $i]);
    }

    return ['tag' => $tag, 'headerLength' => 2 + $n, 'length' => $length];
}

function spec016Length(int $length): string
{
    if ($length < 0x80) {
        return pack('C', $length);
    }
    $bytes = ltrim(pack('N', $length), "\0");

    return pack('C', 0x80 | strlen($bytes)).$bytes;
}

/**
 * Replace $oldLength bytes at $at by $new and re-encode the length of every
 * enclosing element (DER-minimal), walking from the root. An OCTET STRING
 * whose contents enclose $at is descended into as if it were constructed
 * (the eContent holds the TSTInfo's DER; a digest is not descended into).
 * $oldLength 0 inserts at $at; an
 * insertion at the end of nested elements is ambiguous, so $inside names
 * the element (by offset) whose contents receive it — the walk stops there.
 */
function spec016Splice(string $bytes, int $at, int $oldLength, string $new, ?int $inside = null): string
{
    $path = [];
    $offset = 0;
    while (true) {
        $element = spec016Element($bytes, $offset);
        $contentsStart = $offset + $element['headerLength'];
        $contentsEnd = $contentsStart + $element['length'];
        // constructed, or an OCTET STRING that wraps a SEQUENCE (the eContent) — never one that holds a digest
        $descendable = ($element['tag'] & 0x20) !== 0 || ($element['tag'] === 0x04 && $element['length'] > 0 && ord($bytes[$contentsStart]) === 0x30);
        $enclosesStrictly = $at >= $contentsStart && $at + $oldLength <= $contentsEnd && ! ($at === $offset && $oldLength === $element['headerLength'] + $element['length']);
        if (! $enclosesStrictly) {
            break;
        }
        $path[] = $offset; // its length changes even when it is a primitive holding the target (the imprint's OCTET STRING)
        if (! $descendable || $offset === $inside) {
            break;
        }
        $child = $contentsStart;
        $next = null;
        while ($child < $contentsEnd) {
            $c = spec016Element($bytes, $child);
            $childEnd = $child + $c['headerLength'] + $c['length'];
            if ($at >= $child && $at + $oldLength <= $childEnd) {
                $next = $child;
                break;
            }
            $child = $childEnd;
        }
        if ($next === null) {
            break; // $at is between children (an insertion point) or at the very end
        }
        $offset = $next;
    }
    if ($path === []) {
        throw new LogicException("no element encloses offset {$at}");
    }
    if ($inside !== null && ! in_array($inside, $path, true)) {
        throw new LogicException("the walk to offset {$at} did not pass the element at {$inside}: ".implode(',', $path));
    }
    $bytes = substr_replace($bytes, $new, $at, $oldLength);
    $delta = strlen($new) - $oldLength;
    foreach (array_reverse($path) as $p) {
        $element = spec016Element($bytes, $p);
        $header = pack('C', $element['tag']).spec016Length($element['length'] + $delta);
        $bytes = substr_replace($bytes, $header, $p, $element['headerLength']);
        $delta += strlen($header) - $element['headerLength'];
    }

    return $bytes;
}

/** Offsets in C.jpg's sigTst value (a TimeStampResp of 5951 bytes): the token starts at 9; the rest as `openssl asn1parse` prints them on the token, plus 9. */
function spec016C(): string
{
    return spec016HeaderValue('c2pa-rs/C.jpg');
}

foreach (spec016Five() as $file => $expected) {
    test('SPEC-016 AC3: the five tokens\' TSTInfo, field by field as openssl ts prints it — '.$file, function () use ($file, $expected): void {
        $token = TimeStampToken::fromHeaderValue(spec016HeaderValue($file));
        $tst = $token->tstInfo;
        expect($tst->version)->toBe(1)
            ->and($tst->policy)->toBe($expected['policy'])
            ->and($tst->hashAlgorithm)->toBe($expected['alg'])
            ->and(strlen($tst->hashedMessage))->toBe($expected['alg'] === SPEC016_OID_SHA256 ? 32 : 48)
            ->and(bin2hex(substr($tst->hashedMessage, 0, 4)))->toBe($expected['imprint'])
            ->and($tst->serialNumber)->toBe(Certificate::hexToDecimal($expected['serialHex']))
            ->and(gmdate('c', $tst->genTime))->toBe($expected['genTime'])
            ->and($tst->nonce)->toBe($expected['nonceHex'] === null ? null : Certificate::hexToDecimal($expected['nonceHex']))
            ->and($tst->accuracy?->millis)->toBe($expected['millis'])
            ->and($tst->accuracy?->seconds)->toBeNull()
            ->and($tst->ordering)->toBeFalse()
            ->and($tst->tsa !== null)->toBe($expected['tsa'])
            ->and($tst->extensions)->toBeNull()
            ->and($token->responseStatus)->toBe($expected['header'] === 'sigTst' ? 0 : null);
    })->group('SPEC-016');
}

foreach (spec016Five() as $file => $expected) {
    test('SPEC-016 AC4: the SignedData and the one SignerInfo — '.$file, function () use ($file, $expected): void {
        $token = TimeStampToken::fromHeaderValue(spec016HeaderValue($file));
        $sd = $token->signedData;
        $si = $sd->signerInfo;
        expect($sd->version)->toBe(3)
            ->and($sd->eContentType)->toBe(SPEC016_OID_TSTINFO)
            ->and(strlen($sd->eContent))->toBe($expected['eContentLength'])
            ->and(bin2hex(substr($sd->eContent, 0, intdiv(strlen($expected['eContentHead']), 2))))->toBe($expected['eContentHead'])
            ->and($sd->digestAlgorithms)->toContain($expected['digestAlg'])
            ->and(count($sd->certificates))->toBe(3);
        foreach ($sd->certificates as $der) {
            Certificate::fromDer($der); // every one is a certificate ext-openssl accepts
        }
        // the signer is the certificate the sid names — first in DigiCert's tokens, last in Truepic's (amendment 2)
        $signer = $sd->signerCertificate();
        expect($signer)->not->toBeNull();
        assert($signer !== null);
        $leaf = Certificate::fromDer($signer);
        // the sid: issuerAndSerialNumber, equal to the signer's issuer Name (TBSCertificate's fourth element) and serial
        $tbs = (new DerReader)->read($signer)->child(0);
        $issuerDer = $tbs->child(3)->encoded();
        expect($leaf->subjectCn())->toBe($expected['signerCn'])
            ->and($si->version)->toBe(1)
            ->and($si->sidSubjectKeyId)->toBeNull()
            ->and($si->sidSerial)->toBe($leaf->serialDecimal)
            ->and($si->sidIssuer)->toBe($issuerDer)
            ->and($si->digestAlgorithm)->toBe($expected['digestAlg'])
            ->and($si->signatureAlgorithm)->toBe($expected['sigAlg'])
            ->and($si->signatureParameters)->toBeNull()
            ->and(strlen($si->signature))->toBe(512)
            ->and($si->messageDigest)->toBe(hash($expected['digestAlg'] === SPEC016_OID_SHA256 ? 'sha256' : 'sha384', $sd->eContent, true))
            ->and($si->signingTime)->toBe($token->tstInfo->genTime)
            ->and($si->contentTypeAttribute)->toBe(SPEC016_OID_TSTINFO)
            ->and(array_keys($si->otherAttributes))->toBe($expected['otherAttributes']);
        foreach ($si->otherAttributes as $oid => $attribute) {
            expect(substr($attribute, 0, 1))->toBe("\x30", "attribute {$oid} is kept as its whole SEQUENCE");
        }
    })->group('SPEC-016');
}

foreach (spec016Five() as $file => $expected) {
    test('SPEC-016 AC5: the cut is right — the re-tagged signed attributes verify — '.$file, function () use ($file, $expected): void {
        $token = TimeStampToken::fromHeaderValue(spec016HeaderValue($file));
        $si = $token->signedData->signerInfo;
        $signer = $token->signedData->signerCertificate();
        assert($signer !== null);
        $key = openssl_pkey_get_public(Certificate::pem($signer));
        expect($key)->not->toBeFalse();
        assert($key !== false);
        $algo = $expected['digestAlg'] === SPEC016_OID_SHA256 ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA384;
        $tbs = $si->signedAttributesForVerification();
        expect(openssl_verify($tbs, $si->signature, $key, $algo))->toBe(1);
        $flipped = $tbs;
        $flipped[10] = chr(ord($flipped[10]) ^ 0x01);
        expect(openssl_verify($flipped, $si->signature, $key, $algo))->toBe(0);
        // exactly the first byte differs: A0 → 31
        expect(strlen($tbs))->toBe(strlen($si->signedAttributes))
            ->and(substr($si->signedAttributes, 0, 1))->toBe("\xa0")
            ->and(substr($tbs, 0, 1))->toBe("\x31")
            ->and(substr($tbs, 1))->toBe(substr($si->signedAttributes, 1));
    })->group('SPEC-016');
}

test('SPEC-016 AC6: both wrappers, either header — a TimeStampResp (sigTst) and a bare ContentInfo (sigTst2) parse, and so do each other\'s shape', function (): void {
    $response = spec016C();
    $bare = spec016HeaderValue('c2pa-rs/C_with_CAWG_data.jpg');
    expect(strlen($response))->toBe(5951)->and(bin2hex(substr($response, 0, 9)))->toBe('3082173b3003020100')
        ->and(strlen($bare))->toBe(5998)->and(bin2hex(substr($bare, 0, 15)))->toBe('3082176a06092a864886f70d010702');

    $fromResponse = TimeStampToken::fromHeaderValue($response);
    $fromToken = TimeStampToken::fromHeaderValue(substr($response, 9)); // the 5942-byte ContentInfo `openssl ts -token_out` writes
    expect($fromToken->tstInfo)->toEqual($fromResponse->tstInfo)
        ->and($fromResponse->responseStatus)->toBe(0)
        ->and($fromToken->responseStatus)->toBeNull();

    $fromBare = TimeStampToken::fromHeaderValue($bare);
    $wrapped = "\x30\x82".pack('n', 5 + strlen($bare))."\x30\x03\x02\x01\x00".$bare; // TimeStampResp { status granted, token }
    $fromWrapped = TimeStampToken::fromHeaderValue($wrapped);
    expect($fromWrapped->tstInfo)->toEqual($fromBare->tstInfo)
        ->and($fromWrapped->responseStatus)->toBe(0);
})->group('SPEC-016');

test('SPEC-016 AC6: both wrappers, either header — status 1 (grantedWithMods) is accepted; status 2 with a statusString is refused naming both; a granted response without a token is refused', function (): void {
    $response = spec016C();
    $withMods = substr_replace($response, "\x01", 8, 1);
    expect(TimeStampToken::fromHeaderValue($withMods)->responseStatus)->toBe(1);

    // PKIStatusInfo { status 2, statusString { "bad request" } } — the SEQUENCE grows from 3 to 20 bytes
    $statusInfo = "\x02\x01\x02"."\x30\x0d\x0c\x0b".'bad request';
    $rejected = spec016Splice($response, 4, 5, "\x30".chr(strlen($statusInfo)).$statusInfo);
    expect(fn () => TimeStampToken::fromHeaderValue($rejected))->toThrow(TimestampException::class, 'rejection');
    try {
        TimeStampToken::fromHeaderValue($rejected);
    } catch (TimestampException $e) {
        expect($e->getMessage())->toContain('2')->toContain('bad request');
    }

    $noToken = "\x30\x05\x30\x03\x02\x01\x00";
    expect(fn () => TimeStampToken::fromHeaderValue($noToken))->toThrow(TimestampException::class, 'no token');
})->group('SPEC-016');

test('SPEC-016 AC6: both wrappers, either header — a ContentInfo whose OID is not signedData is refused naming the OID', function (): void {
    $bare = spec016HeaderValue('c2pa-rs/C_with_CAWG_data.jpg');
    // 1.2.840.113549.1.7.2 (signedData) → 1.2.840.113549.1.7.1 (data): the last OID byte
    expect(bin2hex(substr($bare, 4, 11)))->toBe('06092a864886f70d010702');
    $data = substr_replace($bare, "\x01", 14, 1);
    expect(fn () => TimeStampToken::fromHeaderValue($data))->toThrow(TimestampException::class, '1.2.840.113549.1.7.1');
})->group('SPEC-016');

/*
 * Offsets are `openssl asn1parse -inform DER` on the C.jpg token, plus 9
 * for the TimeStampResp wrapper (step 41a measured every patch below
 * with asn1parse on the patched bytes: notes/step-41-timestamp-tests.md).
 *
 *   token   +9    what
 *      43   52    SEQUENCE encapContentInfo (l=129)
 *      46   55    OID id-ct-TSTInfo (11 bytes)
 *      59   68    [0] eContent
 *      61   70    OCTET STRING (112 bytes: the TSTInfo, contents at 72)
 *     175  184    [0] certificates (l=4873, contents at 188)
 *    5052 5061    SET OF SignerInfo (l=884? — read by the walker)
 *    5199 5208    [0] signedAttrs (l=209, contents at 5211)
 */
$c = static fn (): string => spec016C();

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — the unpatched token parses (every helper below changes only what it claims)', function () use ($c): void {
    expect(TimeStampToken::fromHeaderValue($c())->tstInfo->version)->toBe(1);
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — eContentType that is not id-ct-TSTInfo', function () use ($c): void {
    $bytes = $c();
    expect(bin2hex(substr($bytes, 55, 13)))->toBe('060b2a864886f70d0109100104');
    $patched = substr_replace($bytes, "\x05", 67, 1); // …16.1.4 → …16.1.5
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, '1.2.840.113549.1.9.16.1.5');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — two SignerInfos', function () use ($c): void {
    $bytes = $c();
    $set = spec016SignerInfoSet($bytes);
    $signerInfo = substr($bytes, $set['contents'], $set['length']);
    $patched = spec016Splice($bytes, $set['contents'] + $set['length'], 0, $signerInfo, inside: $set['offset']);
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'exactly one SignerInfo, found 2');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — zero SignerInfos', function () use ($c): void {
    $bytes = $c();
    $set = spec016SignerInfoSet($bytes);
    $patched = spec016Splice($bytes, $set['contents'], $set['length'], '');
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'exactly one SignerInfo, found 0');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — TSTInfo version 2', function () use ($c): void {
    $bytes = $c();
    expect(bin2hex(substr($bytes, 72, 5)))->toBe('306e020101');
    $patched = substr_replace($bytes, "\x02", 76, 1);
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'version 2');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — a messageImprint of 31 bytes for sha256', function () use ($c): void {
    $bytes = $c();
    // TSTInfo at 72: 30 6e | 02 01 01 | 06 09 policy | 30 31 { 30 0d { 06 09 sha256, 05 00 }, 04 20 <32> }
    expect(bin2hex(substr($bytes, 72 + 33, 2)))->toBe('0420');
    $patched = spec016Splice($bytes, 72 + 35 + 31, 1, '');
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, '31 bytes');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — a SignerInfo without signedAttrs', function () use ($c): void {
    $bytes = $c();
    $attrs = spec016SignedAttrs($bytes);
    $patched = spec016Splice($bytes, $attrs['offset'], $attrs['headerLength'] + $attrs['length'], '');
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'signedAttrs');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — signedAttrs without messageDigest', function () use ($c): void {
    $bytes = $c();
    $attribute = spec016Attribute($bytes, '2a864886f70d010904'); // messageDigest 1.2.840.113549.1.9.4
    $patched = spec016Splice($bytes, $attribute['offset'], $attribute['headerLength'] + $attribute['length'], '');
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'messageDigest');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — a contentType attribute that is not the eContentType', function () use ($c): void {
    $bytes = $c();
    $attribute = spec016Attribute($bytes, '2a864886f70d010903'); // contentType 1.2.840.113549.1.9.3
    // its value: SET { OID id-ct-TSTInfo } — the OID's last byte
    $valueOid = $attribute['offset'] + $attribute['headerLength'] + 11 + 2 + 2;
    expect(bin2hex(substr($bytes, $valueOid, 11)))->toBe('2a864886f70d0109100104');
    $patched = substr_replace($bytes, "\x05", $valueOid + 10, 1);
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'contentType');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — a certificates choice that is not certificate ([1] extraCert)', function () use ($c): void {
    $bytes = $c();
    expect(bin2hex(substr($bytes, 184, 4)))->toBe('a0821309')->and(substr($bytes, 188, 1))->toBe("\x30");
    $patched = substr_replace($bytes, "\xa1", 188, 1);
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, '[1]');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — no certificates at all', function () use ($c): void {
    $bytes = $c();
    $patched = spec016Splice($bytes, 184, 4 + 4873, '');
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'certificates');
})->group('SPEC-016');

test('SPEC-016 AC7: the token\'s own rules are enforced, one refusal each — a TSTInfo with a critical extension', function () use ($c): void {
    $bytes = $c();
    // extensions [1] IMPLICIT Extensions { Extension { OID 1.2.3.4, critical TRUE, OCTET STRING "" } } appended to the TSTInfo
    $extension = "\x30\x0a"."\x06\x03\x2a\x03\x04"."\x01\x01\xff"."\x04\x00";
    $extensions = "\xa1".chr(strlen($extension)).$extension; // IMPLICIT: the [1] is the SEQUENCE OF itself
    $patched = spec016Splice($bytes, 72 + 2 + 110, 0, $extensions, inside: 72);
    expect(fn () => TimeStampToken::fromHeaderValue($patched))->toThrow(TimestampException::class, 'critical');
})->group('SPEC-016');

/**
 * The SET OF SignerInfo of a sigTst value: its offset, header length, contents offset and length. Found as the last child of SignedData.
 *
 * @return array{offset: int, headerLength: int, contents: int, length: int, tag: int}
 */
function spec016SignerInfoSet(string $bytes): array
{
    $signedData = 9 + 19; // the SEQUENCE inside [0] inside ContentInfo, on the C.jpg token: offset 19, plus the wrapper
    $sd = spec016Element($bytes, $signedData);
    $child = $signedData + $sd['headerLength'];
    $end = $child + $sd['length'];
    $last = null;
    while ($child < $end) {
        $e = spec016Element($bytes, $child);
        $last = ['offset' => $child, 'headerLength' => $e['headerLength'], 'contents' => $child + $e['headerLength'], 'length' => $e['length'], 'tag' => $e['tag']];
        $child += $e['headerLength'] + $e['length'];
    }
    if ($last === null || $last['tag'] !== 0x31) {
        throw new LogicException('the last child of SignedData is not a SET');
    }

    return $last;
}

/**
 * The [0] signedAttrs of the one SignerInfo.
 *
 * @return array{offset: int, headerLength: int, length: int}
 */
function spec016SignedAttrs(string $bytes): array
{
    $set = spec016SignerInfoSet($bytes);
    $si = spec016Element($bytes, $set['contents']);
    $child = $set['contents'] + $si['headerLength'];
    $end = $child + $si['length'];
    while ($child < $end) {
        $e = spec016Element($bytes, $child);
        if ($e['tag'] === 0xA0) {
            return ['offset' => $child, 'headerLength' => $e['headerLength'], 'length' => $e['length']];
        }
        $child += $e['headerLength'] + $e['length'];
    }
    throw new LogicException('no signedAttrs');
}

/**
 * The Attribute SEQUENCE inside signedAttrs whose OID has the given hex contents.
 *
 * @return array{offset: int, headerLength: int, length: int}
 */
function spec016Attribute(string $bytes, string $oidHex): array
{
    $attrs = spec016SignedAttrs($bytes);
    $child = $attrs['offset'] + $attrs['headerLength'];
    $end = $child + $attrs['length'];
    while ($child < $end) {
        $e = spec016Element($bytes, $child);
        $oid = spec016Element($bytes, $child + $e['headerLength']);
        if (bin2hex(substr($bytes, $child + $e['headerLength'] + $oid['headerLength'], $oid['length'])) === $oidHex) {
            return ['offset' => $child, 'headerLength' => $e['headerLength'], 'length' => $e['length']];
        }
        $child += $e['headerLength'] + $e['length'];
    }
    throw new LogicException("no attribute {$oidHex}");
}

test('SPEC-016 AC8: bounded — a token is at most maxBytes, a header at most maxTokens — a header with neither sigTst nor sigTst2 is null; one with both is refused', function (): void {
    expect(TimestampHeader::fromUnprotected([]))->toBeNull()
        ->and(TimestampHeader::fromUnprotected(['x5chain' => [new CborBytes('x')]]))->toBeNull();
    $one = ['tstTokens' => [['val' => new CborBytes('x')]]];
    expect(fn () => TimestampHeader::fromUnprotected(['sigTst' => $one, 'sigTst2' => $one]))->toThrow(TimestampException::class, 'both');
})->group('SPEC-016');

test('SPEC-016 AC8: bounded — a token is at most maxBytes, a header at most maxTokens — the shape: tstTokens, each with a byte-string val', function (): void {
    $header = TimestampHeader::fromUnprotected(['sigTst2' => ['tstTokens' => [['val' => new CborBytes('abc')], ['val' => new CborBytes('def')]]]]);
    expect($header)->not->toBeNull();
    assert($header !== null);
    expect($header->header)->toBe('sigTst2')->and($header->tokens)->toBe(['abc', 'def']);

    expect(fn () => TimestampHeader::fromUnprotected(['sigTst' => ['tstTokens' => [['val' => 'text']]]]))->toThrow(TimestampException::class, 'val')
        ->and(fn () => TimestampHeader::fromUnprotected(['sigTst' => ['tstTokens' => [[]]]]))->toThrow(TimestampException::class, 'val')
        ->and(fn () => TimestampHeader::fromUnprotected(['sigTst' => ['tstTokens' => 'x']]))->toThrow(TimestampException::class, 'tstTokens')
        ->and(fn () => TimestampHeader::fromUnprotected(['sigTst' => 'x']))->toThrow(TimestampException::class, 'sigTst')
        ->and(fn () => TimestampHeader::fromUnprotected(['sigTst' => ['tstTokens' => []]]))->toThrow(TimestampException::class, 'empty');
})->group('SPEC-016');

test('SPEC-016 AC8: bounded — a token is at most maxBytes, a header at most maxTokens — nine tokens exceed maxTokens 8', function (): void {
    $tokens = array_fill(0, 9, ['val' => new CborBytes('x')]);
    expect(fn () => TimestampHeader::fromUnprotected(['sigTst' => ['tstTokens' => $tokens]]))->toThrow(TimestampException::class, '8');
    $eight = TimestampHeader::fromUnprotected(['sigTst' => ['tstTokens' => array_slice($tokens, 0, 8)]]);
    expect($eight?->tokens)->toHaveCount(8);
})->group('SPEC-016');

test('SPEC-016 AC8: bounded — a token is at most maxBytes, a header at most maxTokens — a value of maxBytes + 1 is refused before it is read (the default is 1 MiB; the test lowers it to keep the bytes small)', function (): void {
    expect(DerReader::DEFAULT_MAX_BYTES)->toBe(1048576);
    $reader = new DerReader(maxBytes: 64);
    $value = "\x30\x3f".str_repeat("\x05\x00", 31).'x'; // 65 bytes
    expect(strlen($value))->toBe(65);
    expect(fn () => TimeStampToken::fromHeaderValue($value, $reader))->toThrow(TimestampException::class, '65 bytes');
})->group('SPEC-016');

test('SPEC-016 AC8: bounded — a token is at most maxBytes, a header at most maxTokens — the claim version is not this spec\'s rule: sigTst2 on a v1 claim and sigTst on a v2 claim pass here', function (): void {
    // C.jpg is a v1 claim carrying sigTst; the header reader does not know the claim, and must not refuse the other name either
    $value = spec016C();
    $v2Name = TimestampHeader::fromUnprotected(['sigTst2' => ['tstTokens' => [['val' => new CborBytes($value)]]]]);
    expect($v2Name)->not->toBeNull();
    assert($v2Name !== null);
    expect($v2Name->header)->toBe('sigTst2')
        ->and(TimeStampToken::fromHeaderValue($v2Name->tokens[0])->tstInfo->version)->toBe(1);
})->group('SPEC-016');

test('SPEC-016 AC9: CA_ct.jpg is the corpus\'s malformed token, and the message says why — genTime 20240806216337Z: minute 63 at offset 86 of the TSTInfo', function (): void {
    $value = spec016HeaderValue('c2pa-rs/CA_ct.jpg');
    $thrown = null;
    try {
        TimeStampToken::fromHeaderValue($value);
    } catch (TimestampException $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(TimestampException::class, 'CA_ct.jpg parsed');
    assert($thrown !== null);
    expect($thrown->getMessage())->toContain('genTime')->toContain('63')->toContain('offset 86');
    $oracle = json_decode((string) file_get_contents(spec016Fixtures().'/c2patool/c2pa-rs/CA_ct.json'), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($oracle));
    $codes = [];
    array_walk_recursive($oracle, function (mixed $value, mixed $key) use (&$codes): void {
        if ($key === 'code' && is_string($value) && str_starts_with($value, 'timeStamp')) {
            $codes[] = $value;
        }
    });
    expect(array_unique($codes))->toBe(['timeStamp.malformed']);
})->group('SPEC-016');

test('SPEC-016 AC10: every corpus token parses, and none takes the reader past its bounds — 38 timestamped files, 37 parse; one token, one SignerInfo, version 1, two or three certificates with the signer among them', function (): void {
    $files = [];
    foreach (['public-testfiles', 'c2pa-rs', 'binding'] as $dir) {
        foreach (glob(spec016Fixtures()."/{$dir}/*.{jpg,png,webp}", GLOB_BRACE) ?: [] as $path) {
            $files[] = $dir.'/'.basename($path);
        }
    }
    $timestamped = [];
    $parsed = 0;
    $malformed = [];
    foreach ($files as $relative) {
        try {
            $cose = spec016Cose($relative);
        } catch (Throwable) {
            continue; // no manifest, or a store this verifier refuses for other reasons — not this spec's concern
        }
        if ($cose === null) {
            continue;
        }
        $header = TimestampHeader::fromUnprotected($cose->unprotected);
        if ($header === null) {
            continue;
        }
        $timestamped[] = $relative;
        expect($header->tokens)->toHaveCount(1, "{$relative}: one token");
        try {
            $token = TimeStampToken::fromHeaderValue($header->tokens[0]);
        } catch (TimestampException) {
            $malformed[] = $relative;

            continue;
        }
        $parsed++;
        expect($token->tstInfo->version)->toBe(1, $relative)
            ->and(count($token->signedData->certificates))->toBeGreaterThanOrEqual(2, $relative)
            ->and($token->signedData->signerCertificate())->not->toBeNull($relative);
        // the bounds the defaults leave room for: measured in the tests-first step and pinned here
        (new DerReader(maxDepth: SPEC016_CORPUS_MAX_DEPTH, maxElements: SPEC016_CORPUS_MAX_ELEMENTS))->read($header->tokens[0]);
    }
    expect(count($timestamped))->toBe(38, implode(', ', $timestamped))
        ->and($parsed)->toBe(37)
        ->and($malformed)->toBe(['c2pa-rs/CA_ct.jpg']);
})->group('SPEC-016');
