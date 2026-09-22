<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Support\Bytes;

/**
 * One X.509 certificate, DER, with what the chain walk (SPEC-014) and the
 * profile check (SPEC-015) need — everything as OpenSSL reports it through
 * openssl_x509_parse() and openssl_pkey_get_details(), nothing parsed by
 * hand: subject and issuer (compared whole), the SHA-256 for the allowed
 * list, version, validity, signature algorithm, key type/size/curve, KU,
 * EKU as OIDs, the two key identifiers, the O and CN, the serial in
 * decimal. signedBy() is openssl_x509_verify() on the issuer's key. What
 * OpenSSL refuses is a TrustException. fromParsed() takes the parse data
 * as given — the seam the SPEC-015 tests use for rules no re-signed file
 * can show.
 */
final readonly class Certificate
{
    /** OpenSSL's long names for the EKUs it knows, to the OIDs C2PA 2.4 §14.4.1 talks about. */
    private const EKU_NAMES = [
        'E-mail Protection' => '1.3.6.1.5.5.7.3.4',
        'Time Stamping' => '1.3.6.1.5.5.7.3.8',
        'OCSP Signing' => '1.3.6.1.5.5.7.3.9',
        'Code Signing' => '1.3.6.1.5.5.7.3.3',
        'TLS Web Server Authentication' => '1.3.6.1.5.5.7.3.1',
        'TLS Web Client Authentication' => '1.3.6.1.5.5.7.3.2',
        'Any Extended Key Usage' => '2.5.29.37.0',
        'Document Signing' => '1.3.6.1.5.5.7.3.36',
    ];

    public const EKU_ANY = '2.5.29.37.0';

    public string $sha256;

    /** @var array<string, mixed> */
    public array $subject;

    /** @var array<string, mixed> */
    public array $issuer;

    public bool $isCa;

    /** X.509 version, 1-based (OpenSSL reports 0-based). */
    public int $version;

    public int $validFrom;

    public int $validTo;

    /** OpenSSL's long name: ecdsa-with-SHA256, rsassaPss, sha256WithRSAEncryption, ED25519, … */
    public string $signatureAlgorithm;

    /** EC | RSA | Ed25519 | other */
    public string $keyType;

    public int $keyBits;

    public ?string $curve;

    /** @var list<string>|null OIDs (an OpenSSL name it knows mapped; an unknown one kept as given) — null when the extension is absent */
    public ?array $extendedKeyUsage;

    /** @var list<string>|null OpenSSL's names — null when the extension is absent */
    public ?array $keyUsage;

    public bool $hasAuthorityKeyIdentifier;

    public bool $hasSubjectKeyIdentifier;

    public ?string $organization;

    public string $serialDecimal;

    private \OpenSSLCertificate $handle;

    /**
     * @param  array<string, mixed>|null  $parsed  openssl_x509_parse()'s array, or null to parse the DER
     * @param  array<string, mixed>|null  $key  openssl_pkey_get_details()'s array, or null to read the DER's key
     */
    private function __construct(public string $der, ?array $parsed = null, ?array $key = null)
    {
        // OpenSSL reports a malformed certificate as a warning as well as a false
        // return; the return is the answer, the warning is noise here
        set_error_handler(static fn (): bool => true);
        try {
            $handle = openssl_x509_read(self::pem($der));
        } finally {
            restore_error_handler();
        }
        if ($handle === false) {
            throw new TrustException(sprintf('a certificate of %d bytes could not be read: %s', strlen($der), self::opensslError()));
        }
        $this->handle = $handle;
        $this->sha256 = hash('sha256', $der, true);

        if ($parsed === null) {
            $parsed = openssl_x509_parse($handle);
        }
        if ($parsed === false || ! is_array($parsed['subject'] ?? null) || ! is_array($parsed['issuer'] ?? null)) {
            throw new TrustException(sprintf('a certificate of %d bytes could not be parsed', strlen($der)));
        }
        if ($key === null) {
            $public = openssl_pkey_get_public($handle);
            $details = $public === false ? false : openssl_pkey_get_details($public);
            if ($details === false) {
                throw new TrustException(sprintf('the public key of a certificate of %d bytes could not be read', strlen($der)));
            }
            $key = [];
            foreach ($details as $field => $value) {
                $key[(string) $field] = $value;
            }
        }

        $this->subject = self::name($parsed['subject']);
        $this->issuer = self::name($parsed['issuer']);
        $extensions = is_array($parsed['extensions'] ?? null) ? $parsed['extensions'] : [];
        $this->isCa = is_string($extensions['basicConstraints'] ?? null) && str_contains($extensions['basicConstraints'], 'CA:TRUE');
        $this->version = (is_int($parsed['version'] ?? null) ? $parsed['version'] : 0) + 1;
        $this->validFrom = is_int($parsed['validFrom_time_t'] ?? null) ? $parsed['validFrom_time_t'] : 0;
        $this->validTo = is_int($parsed['validTo_time_t'] ?? null) ? $parsed['validTo_time_t'] : 0;
        $this->signatureAlgorithm = is_string($parsed['signatureTypeLN'] ?? null) ? $parsed['signatureTypeLN'] : '(unknown)';
        [$this->keyType, $this->keyBits, $this->curve] = self::keyFacts($key);
        $this->extendedKeyUsage = is_string($extensions['extendedKeyUsage'] ?? null) ? self::ekuOids($extensions['extendedKeyUsage']) : null;
        $this->keyUsage = is_string($extensions['keyUsage'] ?? null) ? self::names($extensions['keyUsage']) : null;
        $this->hasAuthorityKeyIdentifier = array_key_exists('authorityKeyIdentifier', $extensions);
        $this->hasSubjectKeyIdentifier = array_key_exists('subjectKeyIdentifier', $extensions);
        $o = $this->subject['O'] ?? null;
        $this->organization = is_string($o) ? $o : null;
        $this->serialDecimal = self::hexToDecimal(is_string($parsed['serialNumberHex'] ?? null) ? $parsed['serialNumberHex'] : '0');
    }

    public static function fromDer(string $der): self
    {
        return new self($der);
    }

    /**
     * The seam for tests: the DER (for signedBy) with parse data as given.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $key
     */
    public static function fromParsed(string $der, array $parsed, array $key): self
    {
        return new self($der, $parsed, $key);
    }

    /** Is this certificate's signature made by $issuer's key? openssl_x509_verify(): 1 yes, 0 no, -1 error — only 1 counts. */
    public function signedBy(self $issuer): bool
    {
        $key = openssl_pkey_get_public($issuer->handle);
        if ($key === false) {
            return false;
        }

        return openssl_x509_verify($this->handle, $key) === 1;
    }

    public function sameAs(self $other): bool
    {
        return hash_equals($this->der, $other->der);
    }

    /**
     * The EKUs as OpenSSL named them, for messages.
     *
     * @return list<string>
     */
    public function extendedKeyUsageNames(): array
    {
        $names = array_flip(self::EKU_NAMES);

        return array_map(static fn (string $oid): string => $names[$oid] ?? $oid, $this->extendedKeyUsage ?? []);
    }

    public function subjectCn(): string
    {
        $cn = $this->subject['CN'] ?? null;

        return is_string($cn) ? $cn : '(no CN)';
    }

    public function issuerCn(): string
    {
        $cn = $this->issuer['CN'] ?? null;

        return is_string($cn) ? $cn : '(no CN)';
    }

    public static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * A distinguished name as OpenSSL renders it, its keys as strings.
     *
     * @param  array<mixed, mixed>  $name
     * @return array<string, mixed>
     */
    private static function name(array $name): array
    {
        $typed = [];
        foreach ($name as $attribute => $value) {
            $typed[(string) $attribute] = $value;
        }

        return $typed;
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array{0: string, 1: int, 2: ?string}
     */
    private static function keyFacts(array $key): array
    {
        $bits = is_int($key['bits'] ?? null) ? $key['bits'] : 0;
        if (($key['type'] ?? null) === OPENSSL_KEYTYPE_EC || is_array($key['ec'] ?? null)) {
            $ec = is_array($key['ec'] ?? null) ? $key['ec'] : [];
            $curve = $ec['curve_name'] ?? null;

            return ['EC', $bits, is_string($curve) ? $curve : null];
        }
        if (($key['type'] ?? null) === OPENSSL_KEYTYPE_RSA || is_array($key['rsa'] ?? null)) {
            return ['RSA', $bits, null];
        }
        if (is_array($key['ed25519'] ?? null)) {
            return ['Ed25519', $bits, null];
        }
        // An RSASSA-PSS key (SPKI algorithm 1.2.840.113549.1.1.10) is RSA to PHP's
        // type -1: the algorithm OID in the public key's DER says what it is.
        if (is_string($key['key'] ?? null)) {
            $spki = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $key['key']) ?? '', true);
            if ($spki !== false && (str_contains($spki, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0a") || str_contains($spki, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"))) {
                return ['RSA', $bits, null];
            }
        }

        return ['other', $bits, null];
    }

    /** @return list<string> */
    private static function names(string $list): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $n): bool => $n !== ''));
    }

    /** @return list<string> */
    private static function ekuOids(string $list): array
    {
        return array_map(static fn (string $name): string => self::EKU_NAMES[$name] ?? $name, self::names($list));
    }

    /** Base 16 → base 10 on strings (SPEC-015); the routine lives in Support\Bytes since SPEC-016 shares it. */
    public static function hexToDecimal(string $hex): string
    {
        return Bytes::hexToDecimal($hex);
    }

    private static function opensslError(): string
    {
        $last = '';
        while (($message = openssl_error_string()) !== false) {
            $last = $message;
        }

        return $last === '' ? 'OpenSSL gave no reason' : $last;
    }
}
