<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * The trust settings in the format c2patool, the sister library and this
 * verifier share (SPEC-014, SPEC-031): `trust.trust_anchors` (the legacy
 * string, which anchors signers and time-stamping authorities both),
 * `trust.anchors` (a list of entries, each counting only for its own kind),
 * `trust.trust_config`, `verify.verify_trust` — every value the *contents*
 * of a file, never a path. A top-level `trust.allowed_list` is refused: it
 * lives inside a "manifest" entry now, and c2pa 0.91.0 drops a loose one
 * without a word. Read whole or not at all: any field of the wrong type,
 * any unknown key, any PEM block OpenSSL refuses, any block that is not a
 * certificate, is a TrustException, and no partial object exists.
 */
final readonly class TrustSettings
{
    public const DEFAULT_MAX_CERTIFICATES = 256;

    public const MAX_ANCHOR_ENTRIES = 32;

    private const ENTRY_KEYS = ['trust_anchors', 'trust_kind', 'trust_uri', 'trust_config', 'allowed_list', 'trusted_ica_issuers'];

    /**
     * @param  list<Certificate>  $trustAnchors
     * @param  list<Certificate>  $allowedList
     * @param  list<string>  $trustConfig  EKU OIDs, in addition to the built-in list (ADR-0003 item 4; used by SPEC-015)
     * @param  list<TrustAnchorSet>  $anchorSets  the `trust.anchors` entries (SPEC-031)
     */
    public function __construct(
        public array $trustAnchors,
        public array $allowedList,
        public array $trustConfig = [],
        public bool $verifyTrust = true,
        public array $anchorSets = [],
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
        $trustSection = $settings['trust'] ?? [];
        if (is_array($trustSection) && array_key_exists('allowed_list', $trustSection)) {
            throw new TrustException('trust.allowed_list is not read at the top level: it belongs inside an entry, trust.anchors[].allowed_list, of kind "manifest" (c2pa 0.91.0 moved it there and drops a loose one without a word; this verifier refuses it instead — SPEC-031)');
        }
        $trust = self::section($settings, 'trust', ['trust_anchors', 'trust_config', 'anchors']);
        $verify = self::section($settings, 'verify', ['verify_trust']);

        $verifyTrust = $verify['verify_trust'] ?? true;
        if (! is_bool($verifyTrust)) {
            throw new TrustException(sprintf('verify.verify_trust is %s, not a boolean', get_debug_type($verifyTrust)));
        }

        $anchors = self::certificatesFromPem(self::text($trust, 'trust_anchors'), 'trust.trust_anchors', $maxCertificates);
        $sets = self::anchorSets($trust['anchors'] ?? [], $maxCertificates);
        $total = count($anchors) + array_sum(array_map(static fn (TrustAnchorSet $set): int => count($set->anchors) + count($set->allowedList), $sets));
        if ($total > $maxCertificates) {
            throw new TrustException(sprintf('the trust settings hold more than %d certificates in all (%d)', $maxCertificates, $total));
        }

        return new self($anchors, [], self::ekusFromConfig(self::text($trust, 'trust_config')), $verifyTrust, $sets);
    }

    /**
     * `trust.anchors`, entry by entry (SPEC-031 AC5): a list of at most
     * MAX_ANCHOR_ENTRIES objects, each with `trust_anchors` and `trust_kind`
     * and nothing but the keys c2pa 0.91.0 defines.
     *
     * @return list<TrustAnchorSet>
     */
    private static function anchorSets(mixed $anchors, int $maxCertificates): array
    {
        if (! is_array($anchors) || ! array_is_list($anchors)) {
            throw new TrustException(sprintf('trust.anchors is %s, not a list', is_array($anchors) ? 'an object' : get_debug_type($anchors)));
        }
        if (count($anchors) > self::MAX_ANCHOR_ENTRIES) {
            throw new TrustException(sprintf('trust.anchors holds more than %d entries (%d)', self::MAX_ANCHOR_ENTRIES, count($anchors)));
        }
        $sets = [];
        foreach ($anchors as $i => $entry) {
            $at = sprintf('trust.anchors[%d]', $i);
            if (! is_array($entry) || (array_is_list($entry) && $entry !== [])) {
                throw new TrustException(sprintf('%s is %s, not an object', $at, get_debug_type($entry)));
            }
            foreach (array_keys($entry) as $key) {
                if (! in_array($key, self::ENTRY_KEYS, true)) {
                    throw new TrustException(sprintf('%s: unknown key %s (known: %s)', $at, $key, implode(', ', self::ENTRY_KEYS)));
                }
            }
            foreach (['trust_anchors', 'trust_kind'] as $required) {
                if (! array_key_exists($required, $entry)) {
                    throw new TrustException(sprintf('%s.%s is missing', $at, $required));
                }
            }
            $text = static function (string $key) use ($entry, $at): string {
                $value = $entry[$key] ?? '';
                if (! is_string($value)) {
                    throw new TrustException(sprintf('%s.%s is %s, not a string', $at, $key, get_debug_type($value)));
                }

                return $value;
            };
            $kind = $text('trust_kind');
            if (! in_array($kind, TrustAnchorSet::KINDS, true)) {
                throw new TrustException(sprintf('%s.trust_kind %s is not one of %s', $at, preg_replace('/[^\x20-\x7E]/', '?', $kind) ?? '', implode(', ', TrustAnchorSet::KINDS)));
            }
            $issuers = $entry['trusted_ica_issuers'] ?? [];
            if (! is_array($issuers) || ! array_is_list($issuers) || array_filter($issuers, static fn (mixed $v): bool => ! is_string($v)) !== []) {
                throw new TrustException(sprintf('%s.trusted_ica_issuers is not a list of strings', $at));
            }
            $uri = array_key_exists('trust_uri', $entry) ? $text('trust_uri') : null;
            try {
                $sets[] = new TrustAnchorSet(
                    $kind,
                    self::certificatesFromPem($text('trust_anchors'), "{$at}.trust_anchors", $maxCertificates),
                    self::certificatesFromPem($text('allowed_list'), "{$at}.allowed_list", $maxCertificates),
                    self::ekusFromConfig($text('trust_config'), "{$at}.trust_config"),
                    $uri,
                );
            } catch (TrustException $e) {
                throw str_starts_with($e->getMessage(), $at) ? $e : new TrustException(sprintf('%s: %s', $at, $e->getMessage()), 0, $e);
            }
        }

        return $sets;
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
    public static function ekusFromConfig(string $config, string $what = 'trust.trust_config'): array
    {
        $oids = [];
        foreach (preg_split('/\R/', $config) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            if (preg_match('/\A[0-2](\.\d+)+\z/', $line) !== 1) {
                throw new TrustException(sprintf('%s line %d is not an OID: %s', $what, $n + 1, preg_replace('/[^\x20-\x7E]/', '?', $line) ?? ''));
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
