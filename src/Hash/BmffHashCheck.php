<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * `c2pa.hash.bmff.v3` — the hard binding for ISOBMFF (SPEC-027; C2PA 2.4 §11.3).
 *
 * The rule was measured in step 77, not reconstructed: c2pa-rs was instrumented
 * with two `eprintln!` lines and run against this repository's own fixtures, and
 * what it prints is
 *
 *     for each top-level box that no exclusion matches, in file order:
 *     hash the box's own offset as a big-endian uint64, then the box's bytes.
 *
 * Recomputing that by hand reproduces both stored digests exactly. The offsets
 * are the point: without them a box whose bytes are untouched could be moved
 * freely, and with them every included box is bound to where it sits as well as
 * to what it holds — which is what makes excluding the C2PA box safe.
 *
 * The exclusions are box paths, not byte ranges. The `data` form is how the
 * manifest excludes itself without naming an offset that would move: *the `uuid`
 * box whose bytes at offset 8 are the C2PA UUID*. Every other filter c2pa-rs
 * supports — `length`, `version`, `flags`, `subset`, and paths of more than one
 * segment — is refused by name, because no file this project holds exercises
 * them and ignoring one would hash the wrong bytes and call it a match.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class BmffHashCheck
{
    public const LABEL = 'c2pa.hash.bmff.v3';

    /** Read in 64 KiB pieces, as SPEC-012 does: a video is not held in memory. */
    public const DEFAULT_CHUNK_SIZE = 64 * 1024;

    /** The filters c2pa-rs honours and no fixture here carries (SPEC-027 AC5). */
    private const UNSUPPORTED_FILTERS = ['subset', 'length', 'version', 'flags', 'exact'];

    public function __construct(
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private IsobmffManifestStoreExtractor $boxes = new IsobmffManifestStoreExtractor,
    ) {}

    /**
     * @param  resource  $stream  the asset, readable and seekable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, $stream): array
    {
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, self::LABEL);

        try {
            $assertion = $this->assertionOf($manifest->assertions[self::LABEL]->data ?? null);
            // the checks before this one have read the stream to its end; the box walk
            // reads forward from wherever it is told to start
            rewind($stream);
            $boxes = $this->boxes->topLevelBoxes($stream);
            $included = self::included($boxes, $assertion['exclusions'], function (int $at, int $length) use ($stream): string {
                if ($length < 1) {
                    return '';
                }
                if (fseek($stream, $at) !== 0) {
                    throw new HashException(sprintf('cannot seek to %d while matching an exclusion', $at));
                }
                $bytes = fread($stream, $length);

                return $bytes === false ? '' : $bytes;
            });
        } catch (HashException|ContainerException $e) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $e->getMessage())];
        }

        $computed = $this->digest($stream, $included, $assertion['alg']);
        if (! hash_equals($assertion['hash'], $computed)) {
            return [new ValidationStatus(
                StatusCode::AssertionBmffHashMismatch,
                $url,
                sprintf(
                    'the %s digest over %d top-level box(es) is %s, the assertion says %s',
                    $assertion['alg'],
                    count($included),
                    substr(bin2hex($computed), 0, 16),
                    substr(bin2hex($assertion['hash']), 0, 16),
                ),
            )];
        }

        return [new ValidationStatus(
            StatusCode::AssertionBmffHashMatch,
            $url,
            sprintf('the %s hash of %d top-level box(es) matches, each bound to its own offset', $assertion['alg'], count($included)),
        )];
    }

    /**
     * The assertion, read whole before a byte of the asset is touched.
     *
     * @return array{alg: string, hash: string, exclusions: list<array<string, mixed>>}
     *
     * @throws HashException
     */
    public function assertionOf(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new HashException(self::LABEL.' is not a CBOR map');
        }
        if (array_key_exists('merkle', $data)) {
            throw new HashException(self::LABEL.' carries a merkle field: fragmented BMFF is not read by this verifier, and a merkle tree is not guessed at');
        }

        $alg = $data['alg'] ?? 'sha256';
        if (! is_string($alg) || ! in_array($alg, ['sha256', 'sha384', 'sha512'], true)) {
            throw new HashException(sprintf('%s: hash algorithm %s is not one this verifier implements', self::LABEL, is_string($alg) ? $alg : gettype($alg)));
        }

        $hash = $data['hash'] ?? null;
        if (! $hash instanceof CborBytes) {
            throw new HashException(self::LABEL.' has no hash, or one that is not a byte string');
        }

        $exclusions = $data['exclusions'] ?? [];
        if (! is_array($exclusions) || ! array_is_list($exclusions)) {
            throw new HashException(self::LABEL.': exclusions is not a list');
        }
        $checked = [];
        foreach ($exclusions as $exclusion) {
            if (! is_array($exclusion) || array_is_list($exclusion)) {
                throw new HashException(self::LABEL.': an exclusion is not a map');
            }
            /** @var array<string, mixed> $exclusion */
            $checked[] = $exclusion;
        }

        return ['alg' => $alg, 'hash' => $hash->bytes, 'exclusions' => $checked];
    }

    /**
     * The top-level boxes no exclusion matches, in file order.
     *
     * @param  list<array{offset: int, length: int, type: string}>  $boxes
     * @param  list<array<string, mixed>>  $exclusions
     * @param  callable(int, int): string  $readAt  the asset's bytes at an offset, for the data filter
     * @return list<array{offset: int, length: int}>
     *
     * @throws HashException on a filter this verifier does not implement
     */
    public static function included(array $boxes, array $exclusions, callable $readAt): array
    {
        $included = [];
        foreach ($boxes as $box) {
            $excluded = false;
            foreach ($exclusions as $exclusion) {
                if (self::matches($box, $exclusion, $readAt)) {
                    $excluded = true;
                    break;
                }
            }
            if (! $excluded) {
                $included[] = ['offset' => $box['offset'], 'length' => $box['length']];
            }
        }

        return $included;
    }

    /**
     * @param  array{offset: int, length: int, type: string}  $box
     * @param  array<string, mixed>  $exclusion
     * @param  callable(int, int): string  $readAt
     *
     * @throws HashException
     */
    private static function matches(array $box, array $exclusion, callable $readAt): bool
    {
        foreach (self::UNSUPPORTED_FILTERS as $filter) {
            if (array_key_exists($filter, $exclusion)) {
                throw new HashException(sprintf(
                    '%s: an exclusion carries a %s filter, which this verifier does not implement; ignoring it would hash the wrong bytes',
                    self::LABEL,
                    $filter,
                ));
            }
        }

        $xpath = $exclusion['xpath'] ?? null;
        if (! is_string($xpath) || $xpath === '' || $xpath[0] !== '/') {
            throw new HashException(self::LABEL.': an exclusion has no usable xpath');
        }
        if (substr_count($xpath, '/') > 1) {
            throw new HashException(sprintf(
                '%s: the exclusion path %s names a nested box, which this verifier does not resolve; only top-level paths are read',
                self::LABEL,
                $xpath,
            ));
        }
        if (substr($xpath, 1) !== $box['type']) {
            return false;
        }

        // The data filter: this is how the manifest excludes itself without naming an
        // offset that would move — the uuid box whose bytes at offset 8 are the C2PA UUID.
        $data = $exclusion['data'] ?? null;
        if ($data === null) {
            return true;
        }
        if (! is_array($data) || ! array_is_list($data)) {
            throw new HashException(self::LABEL.': an exclusion data filter is not a list');
        }
        foreach ($data as $match) {
            if (! is_array($match) || ! is_int($match['offset'] ?? null)) {
                throw new HashException(self::LABEL.': an exclusion data filter has no offset');
            }
            $value = $match['value'] ?? null;
            $wanted = $value instanceof CborBytes ? $value->bytes : $value;
            if (! is_string($wanted)) {
                throw new HashException(self::LABEL.': an exclusion data filter has no byte-string value');
            }
            if ($readAt($box['offset'] + $match['offset'], strlen($wanted)) !== $wanted) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  resource  $stream
     * @param  list<array{offset: int, length: int}>  $included
     */
    private function digest($stream, array $included, string $alg): string
    {
        $context = hash_init($alg);
        foreach ($included as $range) {
            // the offset first, as a big-endian uint64: this is what binds position
            hash_update($context, pack('J', $range['offset']));
            if (fseek($stream, $range['offset']) !== 0) {
                return '';
            }
            $left = $range['length'];
            while ($left > 0) {
                $want = min($left, $this->chunkSize);
                if ($want < 1) {
                    return '';
                }
                $chunk = fread($stream, $want);
                if ($chunk === false || $chunk === '') {
                    return '';
                }
                hash_update($context, $chunk);
                $left -= strlen($chunk);
            }
        }

        return hash_final($context, true);
    }
}
