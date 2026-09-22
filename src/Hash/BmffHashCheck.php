<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
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

    /**
     * @param  iterable<string, resource>  $fragments  a name and an open stream, one at
     *                                                 a time (SPEC-028). Empty for a whole file, which is every caller but
     *                                                 FragmentedVerifier; a merkle assertion with no fragments offered is
     *                                                 refused rather than passed.
     */
    public function __construct(
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private IsobmffManifestStoreExtractor $boxes = new IsobmffManifestStoreExtractor,
        private iterable $fragments = [],
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

        if ($assertion['merkle'] !== null) {
            return $this->checkMerkle($url, $stream, $included, $assertion);
        }

        $expected = $assertion['hash'];
        $computed = $this->digest($stream, $included, $assertion['alg']);
        if ($expected === null || ! hash_equals($expected, $computed)) {
            return [new ValidationStatus(
                StatusCode::AssertionBmffHashMismatch,
                $url,
                sprintf(
                    'the %s digest over %d top-level box(es) is %s, the assertion says %s',
                    $assertion['alg'],
                    count($included),
                    substr(bin2hex($computed), 0, 16),
                    $expected === null ? 'nothing' : substr(bin2hex($expected), 0, 16),
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
     * A fragmented stream: the init segment bound by `initHash`, and every fragment
     * bound by a Merkle proof up to the root (SPEC-028).
     *
     * Measured in step 82: `initHash` and each leaf are this same digest applied to
     * another file, and the tree puts the largest power of two smaller than the leaf
     * count on the left, with sha256(left ‖ right). Two streams were needed to pin
     * that: the obvious reading of `location` is right four times out of five.
     *
     * @param  resource  $stream  the init segment
     * @param  list<array{offset: int, length: int}>  $included
     * @param  array{alg: string, hash: string|null, merkle: mixed, exclusions: list<array<string, mixed>>}  $assertion
     * @return list<ValidationStatus>
     */
    private function checkMerkle(string $url, $stream, array $included, array $assertion): array
    {
        try {
            $map = self::merkleMapOf($assertion['merkle']);
        } catch (HashException $e) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $e->getMessage())];
        }
        $alg = is_string($map['alg'] ?? null) ? $map['alg'] : $assertion['alg'];

        $initHash = $map['initHash'] ?? null;
        if (! $initHash instanceof CborBytes) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, self::LABEL.': the merkle map has no initHash')];
        }
        $computed = $this->digest($stream, $included, $alg);
        if (! hash_equals($initHash->bytes, $computed)) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, sprintf(
                'the init segment does not match its initHash: computed %s, the assertion says %s',
                substr(bin2hex($computed), 0, 16),
                substr(bin2hex($initHash->bytes), 0, 16),
            ))];
        }

        $hashes = $map['hashes'] ?? null;
        $root = is_array($hashes) ? ($hashes[0] ?? null) : null;
        if (! $root instanceof CborBytes) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, self::LABEL.': the merkle map has no root hash')];
        }
        $count = is_int($map['count'] ?? null) ? $map['count'] : 0;

        $seen = [];
        foreach ($this->fragments as $name => $fragment) {
            $fault = $this->checkFragment($name, $fragment, $assertion['exclusions'], $alg, $root->bytes, $count, $seen);
            if ($fault !== null) {
                return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $fault)];
            }
        }

        if (count($seen) !== $count) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, sprintf(
                'the assertion declares %d fragment(s) and %d were offered; a tree whose leaves are not all present has not been verified',
                $count,
                count($seen),
            ))];
        }

        return [new ValidationStatus(StatusCode::AssertionBmffHashMatch, $url, sprintf(
            'the init segment matches its initHash and all %d fragment(s) reach the merkle root (%s)',
            $count,
            $alg,
        ))];
    }

    /**
     * One fragment against the root, or a sentence saying why not.
     *
     * @param  resource  $fragment
     * @param  list<array<string, mixed>>  $exclusions
     * @param  array<int, true>  $seen
     */
    private function checkFragment(string $name, $fragment, array $exclusions, string $alg, string $root, int $count, array &$seen): ?string
    {
        try {
            $payload = $this->boxes->merklePayload($fragment);
            if ($payload === null) {
                return sprintf('%s carries no C2PA box with purpose merkle, so it cannot be placed in the tree', $name);
            }
            $proof = (new CborDecoder)->decode($payload);
            rewind($fragment);
            $leaf = $this->digest($fragment, self::included($this->boxes->topLevelBoxes($fragment), $exclusions, function (int $at, int $length) use ($fragment): string {
                if ($length < 1 || fseek($fragment, $at) !== 0) {
                    return '';
                }
                $bytes = fread($fragment, $length);

                return $bytes === false ? '' : $bytes;
            }), $alg);
        } catch (HashException|CborException|ContainerException $e) {
            return sprintf('%s: %s', $name, $e->getMessage());
        }

        if (! is_array($proof) || ! is_int($proof['location'] ?? null)) {
            return sprintf('%s: its merkle box has no location', $name);
        }
        $location = $proof['location'];
        if (array_key_exists($location, $seen)) {
            return sprintf('%s claims location %d, which another fragment already filled', $name, $location);
        }
        $seen[$location] = true;

        $siblings = $proof['hashes'] ?? [];
        if (! is_array($siblings)) {
            return sprintf('%s: its merkle box has no hashes', $name);
        }

        $climbed = $leaf;
        foreach (self::path($location, $count) as $depth => $left) {
            $sibling = $siblings[$depth] ?? null;
            if (! $sibling instanceof CborBytes) {
                return sprintf('%s: its proof is %d hash(es) long, the tree needs more', $name, count($siblings));
            }
            $climbed = $left
                ? hash($alg, $sibling->bytes.$climbed, true)
                : hash($alg, $climbed.$sibling->bytes, true);
        }

        return hash_equals($root, $climbed)
            ? null
            : sprintf('%s does not reach the merkle root: climbed to %s from location %d', $name, substr(bin2hex($climbed), 0, 16), $location);
    }

    /**
     * Exactly one merkle map, or a refusal by name.
     *
     * The field is a list because a stream can carry several renditions. What
     * `uniqueId` and `localId` select among them is unmeasured (step 82), and a
     * guess would pick a tree and call the result a match.
     *
     * @return array<string, mixed>
     *
     * @throws HashException
     */
    public static function merkleMapOf(mixed $merkle): array
    {
        if (! is_array($merkle) || ! array_is_list($merkle) || $merkle === []) {
            throw new HashException(self::LABEL.': merkle is not a non-empty list of maps');
        }
        if (count($merkle) > 1) {
            throw new HashException(sprintf(
                '%s carries %d merkle maps; this verifier reads one, and what uniqueId and localId select among several is unmeasured',
                self::LABEL,
                count($merkle),
            ));
        }
        $map = $merkle[0];
        if (! is_array($map) || array_is_list($map)) {
            throw new HashException(self::LABEL.': the merkle map is not a CBOR map');
        }

        /** @var array<string, mixed> */
        return $map;
    }

    /**
     * Which side the sibling is on at each level, from leaf to root.
     *
     * The tree is unbalanced in one specific way (measured, step 82): the left
     * subtree holds the largest power of two smaller than the leaf count, the right
     * holds the rest. Reading the bits of `location` from the least significant end
     * is right four times out of five and wrong on the lone leaf one level up.
     *
     * @return list<bool> true where the sibling is on the left
     */
    public static function path(int $location, int $count): array
    {
        $low = 0;
        $high = $count;
        $out = [];
        while ($high - $low > 1) {
            $left = 1;
            while ($left * 2 < $high - $low) {
                $left *= 2;
            }
            $middle = $low + $left;
            if ($location < $middle) {
                $out[] = false;
                $high = $middle;
            } else {
                $out[] = true;
                $low = $middle;
            }
        }

        return array_reverse($out);
    }

    /**
     * The assertion, read whole before a byte of the asset is touched.
     *
     * @return array{alg: string, hash: string|null, merkle: mixed, exclusions: list<array<string, mixed>>}
     *
     * @throws HashException
     */
    public function assertionOf(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new HashException(self::LABEL.' is not a CBOR map');
        }
        $alg = $data['alg'] ?? 'sha256';
        if (! is_string($alg) || ! in_array($alg, ['sha256', 'sha384', 'sha512'], true)) {
            throw new HashException(sprintf('%s: hash algorithm %s is not one this verifier implements', self::LABEL, is_string($alg) ? $alg : gettype($alg)));
        }

        $merkle = $data['merkle'] ?? null;
        $hash = $data['hash'] ?? null;
        if ($merkle === null && ! $hash instanceof CborBytes) {
            throw new HashException(self::LABEL.' has no hash and no merkle, or a hash that is not a byte string');
        }
        if ($merkle !== null && $hash instanceof CborBytes) {
            throw new HashException(self::LABEL.' carries both a hash and a merkle list; a binding is one or the other');
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

        return [
            'alg' => $alg,
            'hash' => $hash instanceof CborBytes ? $hash->bytes : null,
            'merkle' => $merkle,
            'exclusions' => $checked,
        ];
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
