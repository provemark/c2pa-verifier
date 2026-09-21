<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * One X.509 certificate, DER, with what the chain walk needs (SPEC-014;
 * ADR-0003): its SHA-256 for the allowed list, subject and issuer as
 * OpenSSL renders them (compared whole), whether it is a CA, and
 * signedBy() — openssl_x509_verify() on the issuer's public key. Nothing
 * is parsed by hand; what OpenSSL refuses is a TrustException.
 */
final readonly class Certificate
{
    public string $sha256;

    /** @var array<string, mixed> */
    public array $subject;

    /** @var array<string, mixed> */
    public array $issuer;

    public bool $isCa;

    private \OpenSSLCertificate $handle;

    public function __construct(public string $der)
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
        $parsed = openssl_x509_parse($handle);
        if ($parsed === false || ! is_array($parsed['subject'] ?? null) || ! is_array($parsed['issuer'] ?? null)) {
            throw new TrustException(sprintf('a certificate of %d bytes could not be parsed', strlen($der)));
        }
        $this->handle = $handle;
        $this->sha256 = hash('sha256', $der, true);
        $this->subject = self::name($parsed['subject']);
        $this->issuer = self::name($parsed['issuer']);
        $extensions = $parsed['extensions'] ?? [];
        $this->isCa = is_array($extensions) && is_string($extensions['basicConstraints'] ?? null) && str_contains($extensions['basicConstraints'], 'CA:TRUE');
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

    public static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
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
