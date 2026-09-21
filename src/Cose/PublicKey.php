<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

/**
 * The public key of a DER certificate, classified by the algorithm
 * identifier of its SubjectPublicKeyInfo — not by PHP's key-type constants,
 * which do not name RSA-PSS and, before PHP 8.4, not Ed25519 either
 * (SPEC-009).
 */
final readonly class PublicKey
{
    public const KIND_EC = 'ec';

    public const KIND_RSA = 'rsa';

    public const KIND_RSA_PSS = 'rsa-pss';

    public const KIND_ED25519 = 'ed25519';

    private const OID_EC = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";           // 1.2.840.10045.2.1

    private const OID_RSA = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";  // 1.2.840.113549.1.1.1

    private const OID_RSA_PSS = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0a";  // 1.2.840.113549.1.1.10

    private const OID_ED25519 = "\x06\x03\x2b\x65\x70";                       // 1.3.101.112

    private function __construct(
        public string $kind,
        public ?string $curve,
        public int $bits,
        public \OpenSSLAsymmetricKey $key,
        public string $spki,
    ) {}

    /** @throws CoseException when the certificate or its key cannot be read, or the key is of no known kind */
    public static function fromCertificateDer(string $der): self
    {
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
        $key = OpenSsl::quiet(static fn () => openssl_pkey_get_public($pem));
        if ($key === false) {
            throw new CoseException('the leaf certificate\'s public key cannot be read');
        }
        $details = OpenSsl::quiet(static fn () => openssl_pkey_get_details($key));
        if ($details === false || ! is_string($details['key']) || ! is_int($details['bits'])) {
            throw new CoseException('the leaf certificate\'s public key has no readable details');
        }
        $spki = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $details['key']), true);
        if ($spki === false) {
            throw new CoseException('the leaf certificate\'s public key is not DER');
        }
        // The algorithm identifier sits at the start of the SPKI, inside its first 32 bytes.
        $head = substr($spki, 0, 32);
        if (str_contains($head, self::OID_RSA_PSS)) {
            return new self(self::KIND_RSA_PSS, null, $details['bits'], $key, $spki);
        }
        if (str_contains($head, self::OID_RSA)) {
            return new self(self::KIND_RSA, null, $details['bits'], $key, $spki);
        }
        if (str_contains($head, self::OID_ED25519)) {
            return new self(self::KIND_ED25519, null, $details['bits'], $key, $spki);
        }
        if (str_contains($head, self::OID_EC)) {
            $ec = $details['ec'] ?? null;
            $curve = is_array($ec) ? ($ec['curve_name'] ?? null) : null;
            if (! is_string($curve)) {
                throw new CoseException('the leaf certificate\'s EC key names no curve');
            }

            return new self(self::KIND_EC, $curve, $details['bits'], $key, $spki);
        }
        throw new CoseException('the leaf certificate\'s public key is of no kind this verifier knows (not EC, RSA, RSA-PSS or Ed25519)');
    }

    /**
     * The raw 32-byte Ed25519 key: the last 32 bytes of the 44-byte SPKI (RFC 8410).
     *
     * @return non-empty-string
     */
    public function rawEd25519(): string
    {
        $raw = substr($this->spki, -32);
        if (strlen($raw) !== 32) {
            throw new CoseException(sprintf('the Ed25519 SubjectPublicKeyInfo is %d bytes, expected 44', strlen($this->spki)));
        }

        return $raw;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::KIND_EC => sprintf('EC key on %s', (string) $this->curve),
            self::KIND_RSA => sprintf('RSA key of %d bits', $this->bits),
            self::KIND_RSA_PSS => sprintf('RSA-PSS key of %d bits', $this->bits),
            default => 'Ed25519 key',
        };
    }
}
