<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * The trust settings in the format c2patool, the sister library and this
 * verifier share (SPEC-014): `trust.trust_anchors`, `trust.allowed_list`,
 * `trust.trust_config`, `verify.verify_trust` — every value the *contents*
 * of a file, never a path. Read whole or not at all: any field of the wrong
 * type, any unknown key, any PEM block OpenSSL refuses, any block that is
 * not a certificate, is a TrustException, and no partial object exists.
 */
final readonly class TrustSettings
{
    public const DEFAULT_MAX_CERTIFICATES = 256;

    /**
     * @param  list<Certificate>  $trustAnchors
     * @param  list<Certificate>  $allowedList
     * @param  list<string>  $trustConfig  EKU OIDs, in addition to the built-in list (ADR-0003 item 4; used by SPEC-015)
     */
    public function __construct(
        public array $trustAnchors,
        public array $allowedList,
        public array $trustConfig = [],
        public bool $verifyTrust = true,
    ) {}

    public static function fromJson(string $json, int $maxCertificates = self::DEFAULT_MAX_CERTIFICATES): self
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TrustException(sprintf('the trust settings are not valid JSON: %s', $e->getMessage()), 0, $e);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new TrustException('the trust settings must be a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded, $maxCertificates);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings, int $maxCertificates = self::DEFAULT_MAX_CERTIFICATES): self
    {
        foreach (array_keys($settings) as $key) {
            if (! in_array($key, ['trust', 'verify'], true)) {
                throw new TrustException(sprintf('unknown top-level key %s in the trust settings (known: trust, verify)', $key));
            }
        }
        $trust = self::section($settings, 'trust', ['trust_anchors', 'allowed_list', 'trust_config']);
        $verify = self::section($settings, 'verify', ['verify_trust']);

        $verifyTrust = $verify['verify_trust'] ?? true;
        if (! is_bool($verifyTrust)) {
            throw new TrustException(sprintf('verify.verify_trust is %s, not a boolean', get_debug_type($verifyTrust)));
        }

        return new self(
            self::certificatesFromPem(self::text($trust, 'trust_anchors'), 'trust.trust_anchors', $maxCertificates),
            self::certificatesFromPem(self::text($trust, 'allowed_list'), 'trust.allowed_list', $maxCertificates),
            self::ekusFromConfig(self::text($trust, 'trust_config')),
            $verifyTrust,
        );
    }

    /**
     * Every `CERTIFICATE` block of a PEM string as a Certificate, in order.
     * A block of any other kind (a key, a request) is refused without
     * echoing its contents; a block OpenSSL refuses names its position.
     *
     * @return list<Certificate>
     */
    public static function certificatesFromPem(string $pem, string $what, int $max): array
    {
        if (trim($pem) === '') {
            return [];
        }
        if (preg_match_all('/-----BEGIN ([A-Z0-9 ]+)-----\s*(.*?)\s*-----END ([A-Z0-9 ]+)-----/s', $pem, $blocks, PREG_SET_ORDER) === 0) {
            throw new TrustException(sprintf('%s holds no PEM block', $what));
        }
        $certificates = [];
        foreach ($blocks as $i => [, $begin, $body, $end]) {
            if ($begin !== 'CERTIFICATE' || $end !== 'CERTIFICATE') {
                throw new TrustException(sprintf('%s: block %d is a %s, not a CERTIFICATE', $what, $i + 1, $begin === $end ? $begin : "{$begin}/{$end}"));
            }
            if (count($certificates) >= $max) {
                throw new TrustException(sprintf('%s holds more than %d certificates', $what, $max));
            }
            $der = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
            if ($der === false || $der === '') {
                throw new TrustException(sprintf('%s: block %d is not valid base64', $what, $i + 1));
            }
            try {
                $certificates[] = Certificate::fromDer($der);
            } catch (TrustException $e) {
                throw new TrustException(sprintf('%s: block %d: %s', $what, $i + 1, $e->getMessage()), 0, $e);
            }
        }

        return $certificates;
    }

    /**
     * The OIDs of a `store.cfg`-style config: one per line, `//` comments
     * and blank lines dropped; anything else on a line is refused.
     *
     * @return list<string>
     */
    public static function ekusFromConfig(string $config): array
    {
        $oids = [];
        foreach (preg_split('/\R/', $config) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            if (preg_match('/\A[0-2](\.\d+)+\z/', $line) !== 1) {
                throw new TrustException(sprintf('trust.trust_config line %d is not an OID: %s', $n + 1, preg_replace('/[^\x20-\x7E]/', '?', $line) ?? ''));
            }
            $oids[] = $line;
        }

        return $oids;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $known
     * @return array<string, mixed>
     */
    private static function section(array $settings, string $name, array $known): array
    {
        $section = $settings[$name] ?? [];
        if (! is_array($section) || array_is_list($section) && $section !== []) {
            throw new TrustException(sprintf('%s is %s, not an object', $name, get_debug_type($section)));
        }
        foreach (array_keys($section) as $key) {
            if (! in_array($key, $known, true)) {
                throw new TrustException(sprintf('unknown key %s.%s in the trust settings (known: %s)', $name, $key, implode(', ', $known)));
            }
        }

        /** @var array<string, mixed> */
        return $section;
    }

    /** @param  array<string, mixed>  $section */
    private static function text(array $section, string $key): string
    {
        $value = $section[$key] ?? '';
        if (! is_string($value)) {
            throw new TrustException(sprintf('trust.%s is %s, not a string of file contents', $key, get_debug_type($value)));
        }

        return $value;
    }
}
