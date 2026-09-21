<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;

/**
 * The claim, version 1 (`c2pa.claim`) or 2 (`c2pa.claim.v2`), typed from
 * its CBOR map per the CDDL of C2PA 2.4 §10.2.1 (SPEC-007). The version
 * comes from the box label; it is not in the CBOR.
 */
final readonly class Claim
{
    /**
     * @param  list<array<string, mixed>>|null  $claimGeneratorInfo  always a list, as c2patool renders it
     * @param  list<HashedUri>  $createdAssertions  v1: its one `assertions` list
     * @param  list<HashedUri>  $gatheredAssertions  v1: empty
     * @param  array<string, mixed>  $other  every field not modelled, as decoded
     */
    public function __construct(
        public int $version,
        public string $instanceId,
        public ?string $claimGenerator,
        public ?array $claimGeneratorInfo,
        public string $signatureUri,
        public array $createdAssertions,
        public array $gatheredAssertions,
        public ?string $title,
        public ?string $format,
        public ?string $alg,
        public array $other,
    ) {}

    /**
     * @param  array<int|string, mixed>  $map  the decoded claim
     */
    public static function fromMap(int $version, array $map): self
    {
        $required = $version === 2
            ? ['instanceID', 'claim_generator_info', 'signature', 'created_assertions']
            : ['claim_generator', 'signature', 'assertions', 'dc:format', 'instanceID'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $map)) {
                throw new ManifestException(sprintf('claim (version %d) is missing the required field %s', $version, $field));
            }
        }

        $text = static function (string $field, mixed $value) use ($version): string {
            if (! is_string($value)) {
                throw new ManifestException(sprintf('claim (version %d): %s is not text', $version, $field));
            }

            return $value;
        };
        $optionalText = static fn (string $field): ?string => array_key_exists($field, $map) ? $text($field, $map[$field]) : null;

        $info = null;
        if (array_key_exists('claim_generator_info', $map)) {
            $info = self::generatorInfo($map['claim_generator_info'], $version);
        }

        $modelled = $version === 2
            ? ['instanceID', 'claim_generator_info', 'signature', 'created_assertions', 'gathered_assertions', 'dc:title', 'alg']
            : ['instanceID', 'claim_generator', 'claim_generator_info', 'signature', 'assertions', 'dc:format', 'dc:title', 'alg'];
        $other = [];
        foreach ($map as $key => $value) {
            if (! in_array($key, $modelled, true)) {
                $other[(string) $key] = $value;
            }
        }

        return new self(
            $version,
            $text('instanceID', $map['instanceID']),
            $version === 1 ? $text('claim_generator', $map['claim_generator']) : null,
            $info,
            $text('signature', $map['signature']),
            self::hashedUris($version === 2 ? $map['created_assertions'] : $map['assertions'], $version === 2 ? 'created_assertions' : 'assertions'),
            array_key_exists('gathered_assertions', $map) ? self::hashedUris($map['gathered_assertions'], 'gathered_assertions') : [],
            $optionalText('dc:title'),
            $version === 1 ? $text('dc:format', $map['dc:format']) : null,
            $optionalText('alg'),
            $other,
        );
    }

    /**
     * v2: one generator-info-map; v1: a list of them. Always a list here,
     * each with a `name` (C2PA 2.4 §10.2.3.2).
     *
     * @return list<array<string, mixed>>
     */
    private static function generatorInfo(mixed $value, int $version): array
    {
        $expected = $version === 2 ? 'a map' : 'a non-empty list of maps';
        $entries = $version === 2 ? [$value] : $value;
        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw new ManifestException(sprintf('claim (version %d): claim_generator_info is not %s', $version, $expected));
        }
        $list = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new ManifestException(sprintf('claim (version %d): claim_generator_info entry is not a map', $version));
            }
            if (! isset($entry['name']) || ! is_string($entry['name'])) {
                throw new ManifestException('claim_generator_info is missing the required field name');
            }
            $map = [];
            foreach ($entry as $key => $item) {
                $map[(string) $key] = $item;
            }
            $list[] = $map;
        }

        return $list;
    }

    /** @return list<HashedUri> */
    private static function hashedUris(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new ManifestException(sprintf('claim: %s is not a non-empty list', $field));
        }
        $uris = [];
        foreach ($value as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['url']) || ! is_string($entry['url'])) {
                throw new ManifestException(sprintf('claim: %s[%d] is not a hashed URI with a url', $field, $i));
            }
            if (! array_key_exists('hash', $entry)) {
                throw new ManifestException(sprintf('hashed URI %s: hash is missing', $entry['url']));
            }
            if (! $entry['hash'] instanceof CborBytes) {
                throw new ManifestException(sprintf('hashed URI %s: hash is %s, not a byte string', $entry['url'], is_string($entry['hash']) ? 'text' : gettype($entry['hash'])));
            }
            $alg = $entry['alg'] ?? null;
            if ($alg !== null && ! is_string($alg)) {
                throw new ManifestException(sprintf('hashed URI %s: alg is not text', $entry['url']));
            }
            $uris[] = new HashedUri($entry['url'], $entry['hash'], $alg);
        }

        return $uris;
    }
}
