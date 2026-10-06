<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Cbor\CborTag;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Report\StatusCode;

/**
 * A claim signature as a structure (SPEC-008; RFC 8152 §4.2, C2PA 2.4
 * §13.2): tag 18 over [protected, unprotected, nil, signature]. Reads the
 * headers — alg under the integer label 1 in the protected bucket, the
 * certificate chain under 33 (or the deprecated "x5chain") in either
 * bucket, the timestamp for M6 — and builds the Sig_structure, the bytes
 * that were signed. No cryptography here: that is SPEC-009.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class CoseSign1
{
    public const TAG = 18;

    public const LABEL_ALG = 1;

    public const LABEL_X5CHAIN = 33;

    public const LABEL_X5CHAIN_DEPRECATED = 'x5chain';

    public const DEFAULT_MAX_CHAIN = 16;

    public const DEFAULT_MAX_CERTIFICATE_BYTES = 16384;

    public const DEFAULT_MAX_PROTECTED_BYTES = 65536;

    /** What the Sig_structure's context string is for a COSE_Sign1 (RFC 8152 §4.4; C2PA §13.2.3). */
    private const CONTEXT = 'Signature1';

    /**
     * @param  string  $protectedBytes  the protected header as stored — what the Sig_structure carries
     * @param  array<int|string, mixed>  $protected
     * @param  array<int|string, mixed>  $unprotected
     * @param  list<CborBytes>  $chain  DER certificates, leaf first
     * @param  mixed  $timestamp  the sigTst / sigTst2 value as decoded, or null (M6)
     * @param  array<int|string, mixed>  $otherHeaders  every header but alg and the chain's label, both buckets
     */
    public function __construct(
        public string $protectedBytes,
        public array $protected,
        public array $unprotected,
        public string $signature,
        public int $alg,
        public array $chain,
        public bool $chainProtected,
        public mixed $timestamp,
        public array $otherHeaders,
    ) {}

    /**
     * @param  string  $bytes  the signature box's cbor data (Manifest::signatureBytes())
     *
     * @throws CoseException
     */
    public static function fromBytes(
        string $bytes,
        int $maxChain = self::DEFAULT_MAX_CHAIN,
        int $maxCertificateBytes = self::DEFAULT_MAX_CERTIFICATE_BYTES,
        int $maxProtectedBytes = self::DEFAULT_MAX_PROTECTED_BYTES,
    ): self {
        $decoded = self::decode($bytes, 'the signature box');
        if (! $decoded instanceof CborTag) {
            throw new CoseException(sprintf('expected tag %d (COSE_Sign1_Tagged), found %s', self::TAG, is_array($decoded) ? 'an untagged array' : self::kind($decoded)));
        }
        if ($decoded->number !== self::TAG) {
            throw new CoseException(sprintf('expected tag %d (COSE_Sign1_Tagged), found tag %d', self::TAG, $decoded->number));
        }
        $items = $decoded->value;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new CoseException(sprintf('expected an array of four items under tag %d, found %s', self::TAG, self::kind($items)));
        }
        if (count($items) !== 4) {
            throw new CoseException(sprintf('expected four items, found %d', count($items)));
        }
        [$protectedItem, $unprotected, $payload, $signatureItem] = $items;

        if (! $protectedItem instanceof CborBytes) {
            throw new CoseException(sprintf('the protected header is not a byte string but %s', self::kind($protectedItem)));
        }
        if (strlen($protectedItem->bytes) > $maxProtectedBytes) {
            throw new CoseException(sprintf('protected header of %d bytes exceeds the limit of %d', strlen($protectedItem->bytes), $maxProtectedBytes));
        }
        if (! is_array($unprotected) || array_is_list($unprotected) && $unprotected !== []) {
            throw new CoseException(sprintf('the unprotected header is not a map but %s', self::kind($unprotected)));
        }
        if ($payload !== null) {
            throw new CoseException(sprintf(
                'the payload must be detached (nil); an empty byte string does not count (C2PA 2.4 §13.2.3) — found %s',
                self::kind($payload),
            ));
        }
        if (! $signatureItem instanceof CborBytes) {
            throw new CoseException(sprintf('the signature is not a byte string but %s', self::kind($signatureItem)));
        }

        // An empty protected byte string is an empty map (RFC 8152 §3).
        $protected = $protectedItem->bytes === '' ? [] : self::decode($protectedItem->bytes, 'the protected header');
        if (! is_array($protected) || array_is_list($protected) && $protected !== []) {
            throw new CoseException(sprintf('the protected header is not a map but %s', self::kind($protected)));
        }

        if (array_key_exists('alg', $protected) && ! array_key_exists(self::LABEL_ALG, $protected)) {
            throw new CoseException('alg under the string label "alg" is not allowed; C2PA 2.4 §13.2.3 requires the integer label 1');
        }
        if (! array_key_exists(self::LABEL_ALG, $protected)) {
            throw new CoseException('the protected header has no alg (label 1)');
        }
        $alg = $protected[self::LABEL_ALG];
        if (! is_int($alg)) {
            throw new CoseException(sprintf('alg is not an integer but %s', self::kind($alg)));
        }

        [$chainValue, $chainProtected] = self::findChain($protected, $unprotected);
        $chain = self::chain($chainValue, $maxChain, $maxCertificateBytes);

        $timestamp = $unprotected['sigTst2'] ?? $unprotected['sigTst'] ?? null;

        // Everything but alg (1) and the canonical chain label (33), both
        // buckets; a deprecated "x5chain" stays visible here, used or not.
        $otherHeaders = [];
        foreach ([$protected, $unprotected] as $bucket) {
            foreach ($bucket as $label => $value) {
                if ($label !== self::LABEL_ALG && $label !== self::LABEL_X5CHAIN) {
                    $otherHeaders[$label] = $value;
                }
            }
        }

        return new self($protectedItem->bytes, $protected, $unprotected, $signatureItem->bytes, $alg, $chain, $chainProtected, $timestamp, $otherHeaders);
    }

    /**
     * The bytes that were signed (RFC 8152 §4.4; C2PA 2.4 §13.2.3, §13.2.6):
     * ["Signature1", the protected header as stored, an empty external_aad,
     * the claim box's contents]. The only CBOR this verifier encodes:
     * definite, shortest-form lengths (RFC 8949 §4.2.1).
     */
    public function sigStructure(string $claimBytes): string
    {
        return "\x84"
            .self::head(3, strlen(self::CONTEXT)).self::CONTEXT
            .self::head(2, strlen($this->protectedBytes)).$this->protectedBytes
            .self::head(2, 0)
            .self::head(2, strlen($claimBytes)).$claimBytes;
    }

    /**
     * A manifest's signature, with the rule that depends on its claim (SPEC-047 scope item 3): in a
     * claim v2 or later the chain must be in the protected header, because the unprotected one is
     * not signed (RFC 9052 §3) and a chain there could be any certificate for the same key. A v1
     * claim may keep the older, unprotected form, as c2pa-rs reads it.
     *
     * @throws CoseException
     * @throws ManifestException when the manifest has no readable signature box
     */
    public static function ofManifest(Manifest $manifest): self
    {
        // Decoded once per manifest, which is immutable: the checks ask for it several times (step 255).
        // A WeakMap forgets it with the manifest; a fault is not kept, so it is thrown each time.
        /** @var \WeakMap<Manifest, self>|null $decoded */
        static $decoded = null;
        $decoded ??= new \WeakMap;
        $known = $decoded[$manifest] ?? null;
        if ($known instanceof self) {
            return $known;
        }

        $cose = self::fromBytes($manifest->signatureBytes());
        if ($manifest->claim->version >= 2 && ! $cose->chainProtected) {
            throw new CoseException(sprintf('the claim is v%d, and its signer\'s certificate chain is not in the protected header, so the signature does not cover which certificate signed (SPEC-047)', $manifest->claim->version), StatusCode::SigningCredentialInvalid);
        }

        return $decoded[$manifest] = $cose;
    }

    /**
     * Where the chain is, as c2pa-rs's cert_chain_from_sign1 looks (SPEC-047): protected 33, then
     * protected "x5chain" — 33 wins within the bucket (C2PA 2.4 §14.5) — and only when neither is
     * there, the unprotected "x5chain" of older C2PA versions. Label 33 is not read from the
     * unprotected header, and a chain in both headers is refused.
     *
     * @param  array<int|string, mixed>  $protected
     * @param  array<int|string, mixed>  $unprotected
     * @return array{0: mixed, 1: bool}
     */
    private static function findChain(array $protected, array $unprotected): array
    {
        foreach ([self::LABEL_X5CHAIN, self::LABEL_X5CHAIN_DEPRECATED] as $label) {
            if (array_key_exists($label, $protected)) {
                if (array_key_exists(self::LABEL_X5CHAIN_DEPRECATED, $unprotected)) {
                    throw new CoseException('an x5chain in both the protected and the unprotected header; which certificate signed is ambiguous (SPEC-047)', StatusCode::SigningCredentialInvalid);
                }

                return [$protected[$label], true];
            }
        }
        if (array_key_exists(self::LABEL_X5CHAIN_DEPRECATED, $unprotected)) {
            return [$unprotected[self::LABEL_X5CHAIN_DEPRECATED], false];
        }
        throw new CoseException('no x5chain in either header bucket: none in the protected header (label 33 or "x5chain"), none under "x5chain" in the unprotected one; label 33 is not read from the unprotected header (SPEC-047)', StatusCode::SigningCredentialInvalid);
    }

    /**
     * The chain as a list of DER certificates, the leaf checked to parse as
     * X.509; the limits before anything is looked at. One certificate may
     * come as a bare byte string instead of an array of one: RFC 9360, *"If
     * a single certificate is conveyed, it is placed in a CBOR byte
     * string"*, which c2pa-rs writes for a signer directly under a root
     * (SPEC-008 amendment 2). It is held to the same rules as an element.
     *
     * @return list<CborBytes>
     */
    private static function chain(mixed $value, int $maxChain, int $maxCertificateBytes): array
    {
        if ($value instanceof CborBytes) {
            if ($value->bytes === '') {
                throw new CoseException('x5chain is empty', StatusCode::SigningCredentialInvalid);
            }
            $value = [$value];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new CoseException(sprintf('x5chain is neither a byte string nor an array but %s', self::kind($value)), StatusCode::SigningCredentialInvalid);
        }
        if ($value === []) {
            throw new CoseException('x5chain is empty', StatusCode::SigningCredentialInvalid);
        }
        if (count($value) > $maxChain) {
            throw new CoseException(sprintf('chain of %d certificates exceeds the limit of %d', count($value), $maxChain), StatusCode::SigningCredentialInvalid);
        }
        $chain = [];
        foreach ($value as $i => $certificate) {
            if (! $certificate instanceof CborBytes) {
                throw new CoseException(sprintf('x5chain[%d] is not a byte string but %s', $i, self::kind($certificate)), StatusCode::SigningCredentialInvalid);
            }
            if ($certificate->bytes === '') {
                throw new CoseException(sprintf('x5chain[%d] is empty', $i), StatusCode::SigningCredentialInvalid);
            }
            if (strlen($certificate->bytes) > $maxCertificateBytes) {
                throw new CoseException(sprintf('certificate of %d bytes exceeds the limit of %d', strlen($certificate->bytes), $maxCertificateBytes), StatusCode::SigningCredentialInvalid);
            }
            $chain[] = $certificate;
        }
        if (! self::isX509($chain[0]->bytes)) {
            throw new CoseException('the leaf certificate is not an X.509 certificate', StatusCode::SigningCredentialInvalid);
        }

        return $chain;
    }

    /** Whether DER bytes parse as a certificate; OpenSSL's warning on failure is the answer, not noise. */
    private static function isX509(string $der): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $certificate = openssl_x509_read(self::pem($der));
        } finally {
            restore_error_handler();
            while (openssl_error_string() !== false) {
                // drain OpenSSL's error queue so a later call does not report this failure
            }
        }

        return $certificate !== false;
    }

    private static function decode(string $bytes, string $what): mixed
    {
        try {
            return (new CborDecoder)->decode($bytes);
        } catch (CborException $e) {
            throw new CoseException(sprintf('%s is not valid CBOR: %s', $what, $e->getMessage()), StatusCode::GeneralError, $e);
        }
    }

    /** A CBOR head for major type $majorType (2, 3 or 4) with argument $n ≥ 0, shortest form. */
    private static function head(int $majorType, int $n): string
    {
        if ($n < 0) {
            throw new \LogicException(sprintf('negative CBOR argument %d', $n));
        }
        $mt = $majorType << 5;

        return match (true) {
            $n < 24 => pack('C', $mt | $n),
            $n < 256 => pack('CC', $mt | 24, $n),
            $n < 65536 => pack('Cn', $mt | 25, $n),
            $n < 4294967296 => pack('CN', $mt | 26, $n),
            default => pack('CJ', $mt | 27, $n),
        };
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => 'a byte string',
            $value instanceof CborTag => sprintf('tag %d', $value->number),
            is_array($value) => array_is_list($value) ? 'an array' : 'a map',
            is_string($value) => 'text',
            is_int($value) => 'an integer',
            is_bool($value) => 'a boolean',
            $value === null => 'null',
            default => gettype($value),
        };
    }
}
