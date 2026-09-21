<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\StreamReader;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The data-hash check as a list of statuses (SPEC-012; C2PA 2.4 §15.12.1,
 * §18.5): exactly one c2pa.hash.data, its shape read fail-closed, its
 * exclusions sorted and checked, every piece of the manifest store (what
 * the container layer measured, ManifestStoreBytes::$ranges) required to
 * lie inside an exclusion, then the asset hashed in chunks
 * with the exclusions skipped — never the whole file in memory — and
 * compared. The first check that reads the asset rather than the store.
 */
final readonly class DataHashCheck
{
    public const DEFAULT_MAX_EXCLUSIONS = 1024;

    public const DEFAULT_CHUNK_SIZE = 64 * 1024;

    public const LABEL = 'c2pa.hash.data';

    /** The algorithms C2PA 2.4 §13.1 allows, as PHP's hash() knows them, with their digest lengths. */
    private const ALGORITHMS = ['sha256' => 32, 'sha384' => 48, 'sha512' => 64];

    /** Hard-binding labels this verifier knows of but does not implement (M8 and later). */
    private const OTHER_HARD_BINDINGS = ['c2pa.hash.bmff', 'c2pa.hash.boxes', 'c2pa.hash.collection.data'];

    public function __construct(
        private int $maxExclusions = self::DEFAULT_MAX_EXCLUSIONS,
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {}

    /**
     * @param  resource  $stream  the asset, readable and seekable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, $stream, ManifestStoreBytes $store): array
    {
        $manifestUrl = sprintf('self#jumbf=/c2pa/%s', $manifest->label);

        $bindings = [];
        foreach ($manifest->assertionStore->superboxes() as $box) {
            $label = $box->description->label;
            if ($label === self::LABEL) {
                $bindings[] = $box;
            } elseif (self::isOtherHardBinding($label)) {
                return [new ValidationStatus(StatusCode::GeneralError, sprintf('%s/c2pa.assertions/%s', $manifestUrl, $label), sprintf('the hard binding %s is not supported yet: BMFF, box and collection hashes are M8 and later; only %s is verified today', $label, self::LABEL))];
            }
        }
        if ($bindings === []) {
            return [new ValidationStatus(StatusCode::ClaimHardBindingsMissing, $manifestUrl, sprintf('the manifest has no hard binding: no %s assertion in its store (C2PA 2.4 §15.10.1.2)', self::LABEL))];
        }
        if (count($bindings) > 1) {
            return [new ValidationStatus(StatusCode::AssertionMultipleHardBindings, $manifestUrl, sprintf('the manifest has %d %s assertions (at offsets %s); a standard manifest has exactly one (C2PA 2.4 §15.10.1.2)', count($bindings), self::LABEL, implode(', ', array_map(static fn (Superbox $b): int => $b->offset, $bindings))))];
        }

        $url = sprintf('%s/c2pa.assertions/%s', $manifestUrl, self::LABEL);
        $data = $manifest->assertions[self::LABEL]->data;
        if (! is_array($data) || array_is_list($data)) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s is not a CBOR map', self::LABEL))];
        }

        // ---- shape (§18.5), fail-closed ----
        if (array_key_exists('alg', $data) && ! is_string($data['alg'])) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: alg is %s, not text', self::LABEL, get_debug_type($data['alg'])))];
        }
        $exclusions = [];
        if (array_key_exists('exclusions', $data)) {
            if (! is_array($data['exclusions']) || ! array_is_list($data['exclusions'])) {
                return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: exclusions is %s, not a list', self::LABEL, is_array($data['exclusions']) ? 'a map' : get_debug_type($data['exclusions'])))];
            }
            if (count($data['exclusions']) > $this->maxExclusions) {
                return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: %d exclusions exceed the limit of %d', self::LABEL, count($data['exclusions']), $this->maxExclusions))];
            }
            foreach ($data['exclusions'] as $i => $range) {
                if (! is_array($range) || array_is_list($range)) {
                    return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: exclusions[%d] is not a map', self::LABEL, $i))];
                }
                foreach (['start', 'length'] as $field) {
                    if (! array_key_exists($field, $range)) {
                        return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: exclusions[%d] has no %s', self::LABEL, $i, $field))];
                    }
                }
                $start = $range['start'];
                $length = $range['length'];
                if (! is_int($start)) {
                    return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: exclusions[%d].start is %s, not an integer', self::LABEL, $i, is_string($start) ? 'text' : get_debug_type($start)))];
                }
                if (! is_int($length)) {
                    return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: exclusions[%d].length is %s, not an integer', self::LABEL, $i, is_string($length) ? 'text' : get_debug_type($length)))];
                }
                if ($start < 0 || $length < 0) {
                    return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: exclusions[%d] is [%d, %d] (start, length), negative', self::LABEL, $i, $start, $length))];
                }
                $exclusions[] = ['start' => $start, 'length' => $length];
            }
        }
        if (! array_key_exists('hash', $data)) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf('%s carries no hash; nothing to compare the asset with (C2PA 2.4 §15.12.1)', self::LABEL))];
        }
        if (! $data['hash'] instanceof CborBytes) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('%s: hash is %s, not a byte string', self::LABEL, is_string($data['hash']) ? 'text' : get_debug_type($data['hash'])))];
        }
        $expected = $data['hash']->bytes;

        // ---- algorithm (§15.4.2, §13.1) ----
        $alg = $data['alg'] ?? $manifest->claim->alg;
        if ($alg === null) {
            return [new ValidationStatus(StatusCode::AlgorithmUnsupported, $url, sprintf('no algorithm is specified for %s: neither the assertion nor the claim carries an alg (C2PA 2.4 §15.4.2)', self::LABEL))];
        }
        if (! array_key_exists($alg, self::ALGORITHMS)) {
            return [new ValidationStatus(StatusCode::AlgorithmUnsupported, $url, sprintf('the hash algorithm %s is not one of sha256, sha384, sha512 (C2PA 2.4 §13.1)', $alg))];
        }
        if (strlen($expected) !== self::ALGORITHMS[$alg]) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf('%s carries a %d-byte hash, but %s produces %d bytes', self::LABEL, strlen($expected), $alg, self::ALGORITHMS[$alg]))];
        }

        // ---- exclusions in order (§15.12.1) ----
        if (! is_resource($stream) || ! rewind($stream)) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, 'the asset stream cannot be rewound; the data hash needs a seekable stream')];
        }
        $reader = new StreamReader($stream, 'asset');
        $end = $reader->end();
        usort($exclusions, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $previous = null;
        foreach ($exclusions as $range) {
            if ($previous !== null && $range['start'] < $previous['start'] + $previous['length']) {
                return [new ValidationStatus(StatusCode::AssertionDataHashMalformed, $url, sprintf('exclusions overlap: [%d, %d] and [%d, %d] (start, length)', $previous['start'], $previous['length'], $range['start'], $range['length']))];
            }
            if ($end < $range['start'] + $range['length']) {
                return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf('exclusion [%d, %d] ends at %d, past the end of the file at %d', $range['start'], $range['length'], $range['start'] + $range['length'], $end))];
            }
            $previous = $range;
        }

        // ---- every piece of the store must lie inside an exclusion (§15.12.1; SPEC-012 amendment 5: cover, not equal) ----
        $covering = [];
        foreach ($store->ranges as $piece) {
            $pieceEnd = $piece['start'] + $piece['length'];
            $covered = null;
            foreach ($exclusions as $i => $range) {
                if ($range['start'] <= $piece['start'] && $pieceEnd <= $range['start'] + $range['length']) {
                    $covered = $i;
                    break;
                }
            }
            if ($covered === null) {
                $nearest = null;
                foreach ($exclusions as $range) {
                    if ($nearest === null || abs($range['start'] - $piece['start']) < abs($nearest['start'] - $piece['start'])) {
                        $nearest = $range;
                    }
                }

                return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf('no exclusion covers the manifest store%s at [%d, %d] of the file (start, length; ends at %d); %s', count($store->ranges) > 1 ? sprintf('\'s piece %s', implode(', ', array_map(static fn (array $r): string => sprintf('[%d, %d]', $r['start'], $r['length']), $store->ranges))) : '', $piece['start'], $piece['length'], $pieceEnd, $nearest === null ? 'the assertion has no exclusions' : sprintf('the nearest exclusion is [%d, %d], ending at %d', $nearest['start'], $nearest['length'], $nearest['start'] + $nearest['length'])))];
            }
            $covering[$covered] = true;
        }
        $others = [];
        foreach ($exclusions as $i => $range) {
            if (! isset($covering[$i])) {
                $others[] = $range;
            }
        }

        // ---- the hash, streamed ----
        try {
            $actual = $this->hashExcept($reader, $alg, $exclusions, $end);
        } catch (ContainerException $e) {
            return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf('the asset could not be read: %s', $e->getMessage()))];
        }
        $statuses = [hash_equals($expected, $actual)
            ? new ValidationStatus(StatusCode::AssertionDataHashMatch, $url, sprintf('data hash valid: %s over %d of %d bytes, %d exclusion(s)', $alg, $end - array_sum(array_column($exclusions, 'length')), $end, count($exclusions)))
            : new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf('data hash does not match: %s over %d of %d bytes gives %s, the assertion carries %s', $alg, $end - array_sum(array_column($exclusions, 'length')), $end, bin2hex($actual), bin2hex($expected))),
        ];
        if ($others !== []) {
            $statuses[] = new ValidationStatus(StatusCode::AssertionDataHashAdditionalExclusionsPresent, $url, sprintf('%d exclusion(s) beyond the manifest store, honoured as signed: %s', count($others), implode(', ', array_map(static fn (array $r): string => sprintf('[%d, %d]', $r['start'], $r['length']), $others))));
        }

        return $statuses;
    }

    /**
     * The hash of the file with the (sorted, non-overlapping, in-bounds)
     * ranges skipped, read in chunks; never the whole file at once.
     *
     * @param  list<array{start: int, length: int}>  $exclusions
     */
    private function hashExcept(StreamReader $reader, string $alg, array $exclusions, int $end): string
    {
        $context = hash_init($alg);
        $position = 0;
        foreach ([...$exclusions, ['start' => $end, 'length' => 0]] as $range) {
            $remaining = $range['start'] - $position;
            while ($remaining > 0) {
                $chunk = $reader->readExactly(min($remaining, $this->chunkSize), $position, 'asset data');
                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
                $position += strlen($chunk);
            }
            $reader->skip($range['length'], $range['start']);
            $position = $range['start'] + $range['length'];
        }

        return hash_final($context, true);
    }

    private static function isOtherHardBinding(string $label): bool
    {
        foreach (self::OTHER_HARD_BINDINGS as $prefix) {
            if ($label === $prefix || str_starts_with($label, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }
}
