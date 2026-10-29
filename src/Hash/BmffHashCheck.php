<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Cbor\CborException;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Container\Read;
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
 * supports — `length`, `version` and `flags` — is refused by name, because no
 * file this project holds exercises them and ignoring one would hash the wrong
 * bytes and call it a match. Nested paths and `subset` are read (SPEC-029), and
 * SPEC-038 added the shape c2pa-rs refuses before hashing and its offset-marker
 * rule for boxes a `subset` touches.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class BmffHashCheck
{
    public const LABEL = 'c2pa.hash.bmff.v3';

    /**
     * The hard bindings this check answers to (SPEC-029).
     *
     * v2 and v3 share the digest exactly; what differs is the exclusion list, and
     * v2's is the precise one — nested paths and `subset` where v3 names whole
     * top-level boxes. Measured in step 86 by instrumenting c2pa-rs.
     */
    public const LABELS = [self::LABEL, 'c2pa.hash.bmff.v2'];

    /** The hash algorithms C2PA 2.4 §13.1 allows, for the assertion, its merkle map and the digest (SPEC-051). */
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /** Read in 64 KiB pieces, as SPEC-012 does: a video is not held in memory. */
    public const DEFAULT_CHUNK_SIZE = 64 * 1024;

    /**
     * SPEC-053: the plan costs boxes × boxes × exclusions, and the specification sets no limit.
     * Real files use at most 8 exclusions and 2 subsets (the 31 in tests/Fixtures, measured).
     */
    public const DEFAULT_MAX_EXCLUSIONS = 64;

    public const DEFAULT_MAX_SUBSETS = 64;

    /** The excluded ranges one plan may collect: the product that makes the cost. */
    public const DEFAULT_MAX_RANGES = 4096;

    /** The filters c2pa-rs honours and no fixture here carries (SPEC-027 AC5). */
    /** `subset` was here until SPEC-029 implemented it; the rest await a file that uses them. */
    private const UNSUPPORTED_FILTERS = ['length', 'version', 'flags', 'exact'];

    /** SPEC-071: the boxes an exclusion may take whole, as c2pa-rs writes them. */
    private const ALLOWED_WHOLE = ['/ftyp', '/mfra', '/free', '/skip', '/mfra/tfra', '/moov/free', '/moov/udta', '/moov/meta'];   // the last three: Pixel Camera (SPEC-072)

    /** SPEC-080: where the leaves of a Merkle tree over the mdat begin, from the box's start, whatever its header (as c2pa-rs). */
    private const MDAT_LEAVES_FROM = 16;

    /** The smallest merkle block read: each block is a read and a hash of its own, and c2patool writes whole KiB (SPEC-080 amendment 4). */
    private const MIN_BLOCK_SIZE = 1024;

    /** SPEC-072: a track excluded by index, allowed only when its handler is timed metadata (checked after the walk). */
    private const INDEXED_TRACK = '#^/moov\[1\]/trak\[([1-9][0-9]{0,8})\]$#';

    /** SPEC-072: one xpath step, a box type with an optional 1-based index. */
    private const XPATH_STEP = '#^([^\[\]/]+)(?:\[([1-9][0-9]{0,8})\])?$#';

    /** SPEC-072: exclusions whose boxes can hold metadata worth saying is not signed. */
    private const UNSIGNED_METADATA = ['/moov/udta', '/moov/meta'];

    /**
     * SPEC-071 amendment 1: the offset tables an older c2pa-rs excluded, now refused. With them excluded,
     * the bytes read as samples can be changed without touching the hash; C2PA 2.4 §18.6.2 hashes every
     * offset at its post-embed value, and today's c2pa-rs no longer excludes them.
     */
    private const OFFSET_TABLES = ['/moov/trak/mdia/minf/stbl/stco', '/moov/trak/mdia/minf/stbl/co64', '/meta/iloc', '/moof/traf/tfhd', '/moof/traf/trun'];

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
        private int $maxExclusions = self::DEFAULT_MAX_EXCLUSIONS,
        private int $maxSubsets = self::DEFAULT_MAX_SUBSETS,
        private int $maxRanges = self::DEFAULT_MAX_RANGES,
    ) {}

    /**
     * @param  resource  $stream  the asset, readable and seekable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, $stream): array
    {
        $label = self::labelOf($manifest) ?? self::LABEL;
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $label);

        $data = $manifest->assertions[$label]->data ?? null;
        $shape = self::shapeFault($data);
        if ($shape !== null) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMalformed, $url, $shape)];
        }
        try {
            $assertion = $this->assertionOf($data, $manifest->claim->alg);
            if ($assertion['alg'] === null) {
                return [new ValidationStatus(StatusCode::AlgorithmUnsupported, $url, sprintf('no algorithm is specified for %s: neither the assertion nor the claim carries an alg (C2PA 2.4 §15.4.1)', $label))];
            }
            $this->bound($assertion['exclusions']);
            // SPEC-080: a hash beside a merkle list binds the mdat through the tree, so /mdat may be narrowed
            $mdatMerkle = $assertion['merkle'] !== null && $assertion['hash'] !== null;
            // SPEC-071: only what c2pa-rs writes may be excluded, judged by path before a byte is hashed
            foreach ($assertion['exclusions'] as $exclusion) {
                $refused = self::refusedExclusion($exclusion, $mdatMerkle);
                if ($refused !== null) {
                    $xpath = $exclusion['xpath'] ?? null;
                    throw new HashException(sprintf('%s: the exclusion %s is refused: %s', $label, is_string($xpath) ? $xpath : '(no xpath)', $refused));
                }
            }
            // the checks before this one have read the stream to its end; the box walk
            // reads forward from wherever it is told to start
            rewind($stream);
            $boxes = $this->boxes->boxTree($stream);
            $readTrack = static function (int $at, int $length) use ($stream): string {
                if (fseek($stream, $at) !== 0) {
                    throw new HashException(sprintf('cannot seek to %d while reading a track handler', $at));
                }

                return Read::upTo($stream, $length);
            };
            foreach ($assertion['exclusions'] as $exclusion) {
                $xpath = $exclusion['xpath'] ?? null;
                $refused = is_string($xpath) ? self::trackRefusal($xpath, $boxes, $readTrack) : null;
                if ($refused !== null) {
                    throw new HashException(sprintf('%s: the exclusion %s is refused: %s', $label, $xpath, $refused));
                }
            }
            $included = self::plan($boxes, $assertion['exclusions'], function (int $at, int $length) use ($stream): string {
                if ($length < 1) {
                    return '';
                }
                if (fseek($stream, $at) !== 0) {
                    throw new HashException(sprintf('cannot seek to %d while matching an exclusion', $at));
                }

                return Read::upTo($stream, $length);
            }, $this->maxRanges);
            $included = self::withTail($included, array_values(array_filter($boxes, static fn (array $box): bool => substr_count($box['path'], '/') === 1)), $stream);
        } catch (BmffLimitException $e) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMalformed, $url, $e->getMessage())];
        } catch (HashException|ContainerException $e) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $e->getMessage())];
        }

        // SPEC-038: informational, beside whatever the hash says, as c2patool 0.28.0 reports it
        $unsigned = array_filter($assertion['exclusions'], static fn (array $e): bool => in_array($e['xpath'] ?? null, self::UNSIGNED_METADATA, true) || (is_string($e['xpath'] ?? null) && preg_match(self::INDEXED_TRACK, $e['xpath']) === 1)) !== [];
        $notes = self::hasAdditionalExclusions($assertion['exclusions'])
            ? [new ValidationStatus(StatusCode::AssertionBmffHashAdditionalExclusionsPresent, $url, 'extra BMFF hash exclusion(s) found: beyond the C2PA uuid box, ftyp and mfra'.($unsigned ? '; these boxes are not covered by the signature: what they hold (user data, metadata, a timed-metadata track, such as a location or a capture time) is not verified' : ''))]   // SPEC-072
            : [];
        if ($assertion['merkle'] !== null && $assertion['hash'] === null) {
            return [...$notes, ...$this->checkMerkle($url, $stream, $included, $assertion)];
        }

        $expected = $assertion['hash'];
        try {
            $computed = $this->digest($stream, $included, $assertion['alg']);
        } catch (HashException $e) {
            return [...$notes, new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $e->getMessage())];
        }
        if ($expected === null || ! hash_equals($expected, $computed)) {
            return [...$notes, new ValidationStatus(
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

        $matched = sprintf('the %s hash of %d top-level box(es) matches, each bound to its own offset', $assertion['alg'], count($included));
        if ($assertion['merkle'] !== null) {
            $topLevel = array_values(array_filter($boxes, static fn (array $box): bool => substr_count($box['path'], '/') === 1));

            return [...$notes, $this->checkMdatMerkle($url, $stream, $topLevel, $assertion, $matched)];
        }

        return [...$notes, new ValidationStatus(StatusCode::AssertionBmffHashMatch, $url, $matched)];
    }

    /**
     * SPEC-080: the Merkle tree over the mdat of a non-fragmented file, once the file `hash` has matched.
     *
     * As c2pa-rs 0.91 writes it under `core.merkle_tree_chunk_size_in_kb` (step 443): one map, one mdat,
     * blocks of `fixedBlockSize` from the mdat's start + 16 to its end, each leaf the digest of its block
     * alone; `hashes` a row of the tree, and optionally one top-level `merkle` box per block holding its
     * location and the sibling hashes up to that row. The tree is step 82's (the largest power of two on
     * the left), which step 446 showed to be the same tree c2pa-rs's layer-by-layer walk builds, for every
     * count from 1 to 1,999. The blocks are streamed: memory holds the stored row, the proofs and a stack
     * of one hash per level, never the leaves.
     *
     * @param  resource  $stream
     * @param  list<array{offset: int, length: int, type: string, path: string}>  $topLevel
     * @param  array{alg: string|null, hash: string|null, merkle: mixed, exclusions: list<array<string, mixed>>}  $assertion
     */
    private function checkMdatMerkle(string $url, $stream, array $topLevel, array $assertion, string $matched): ValidationStatus
    {
        $mismatch = static fn (string $why): ValidationStatus => new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, self::LABEL.': '.$why);
        $merkle = $assertion['merkle'];

        if (! (is_array($this->fragments) && $this->fragments === []) || in_array('moof', array_column($topLevel, 'type'), true)) {
            return $mismatch('a hash beside a merkle list is the form of a non-fragmented file, and this one is fragmented');
        }
        if (is_array($merkle) && array_is_list($merkle) && count($merkle) > 1) {
            return $mismatch(sprintf('the assertion carries %d merkle maps, one per mdat; this verifier does not read more than one (SPEC-080)', count($merkle)));
        }
        try {
            $map = self::merkleMapOf($merkle);
        } catch (HashException $e) {
            return new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $e->getMessage());
        }
        // uniqueId and localId select among several maps; with one map and one mdat they select nothing
        // (c2patool 0.28.3 and 0.28.1 trust a map whose localId is 1, measured in step 448; ADR-0005)
        if (array_key_exists('initHash', $map)) {
            return $mismatch('the merkle map carries an initHash, which a non-fragmented file must not (C2PA 2.4 §15.12.2.1)');
        }
        $fixed = $map['fixedBlockSize'] ?? null;
        $variable = array_key_exists('variableBlockSizes', $map);
        if ($fixed !== null && $variable) {
            return $mismatch('the merkle map carries both fixedBlockSize and variableBlockSizes; a map has one or neither');
        }
        if ($variable) {
            return $mismatch('the merkle map has variableBlockSizes; this verifier does not read variable block sizes, only fixedBlockSize (SPEC-080)');
        }
        if ($fixed === null) {
            return $mismatch('the merkle map has neither fixedBlockSize nor variableBlockSizes, one leaf over the whole mdat; this verifier does not read that form (SPEC-080)');
        }
        if (! is_int($fixed)) {
            return new ValidationStatus(StatusCode::AssertionBmffHashMalformed, $url, sprintf('%s: the merkle map\'s fixedBlockSize is %s, not an integer', self::LABEL, get_debug_type($fixed)));
        }
        if ($fixed < 2) {
            return $mismatch(sprintf('the merkle map\'s fixedBlockSize is %d; a block is at least 2 bytes', $fixed));
        }
        if ($fixed < self::MIN_BLOCK_SIZE) {
            return $mismatch(sprintf('the merkle map\'s fixedBlockSize is %d; this verifier reads blocks of %s bytes or more (SPEC-080 amendment 4)', $fixed, number_format(self::MIN_BLOCK_SIZE)));
        }
        // merkleMapOf() has held a present alg to the three (SPEC-051); check() has refused an assertion without one
        $alg = is_string($map['alg'] ?? null) ? $map['alg'] : (string) $assertion['alg'];

        $mdats = array_values(array_filter($topLevel, static fn (array $box): bool => $box['type'] === 'mdat'));
        if (count($mdats) !== 1) {
            return $mismatch(sprintf('the file has %d mdat boxes; this verifier does not read a merkle tree over other than one (SPEC-080)', count($mdats)));
        }
        $start = $mdats[0]['offset'] + self::MDAT_LEAVES_FROM;
        $size = $mdats[0]['length'] - self::MDAT_LEAVES_FROM;
        if ($size < 1) {
            return $mismatch('the mdat holds nothing after its first 16 bytes, so no block');
        }
        // the blocks rounded up, without size + fixed - 1, which passes PHP_INT_MAX for a huge block (SPEC-080 amendment 3)
        $blocks = intdiv($size - 1, $fixed) + 1;
        $count = $map['count'] ?? null;
        if ($count !== $blocks) {
            return $mismatch(sprintf('the merkle map says count %s, and the mdat holds %d block(s) of %d bytes', is_int($count) ? (string) $count : get_debug_type($count), $blocks, $fixed));
        }

        $width = strlen(hash($alg, '', true));
        $hashes = $map['hashes'] ?? null;
        if (! is_array($hashes) || ! array_is_list($hashes) || $hashes === []) {
            return $mismatch('the merkle map has no hashes');
        }
        $row = [];
        foreach ($hashes as $h) {
            if (! $h instanceof CborBytes || strlen($h->bytes) !== $width) {
                return $mismatch(sprintf('a stored hash is not a %d-byte %s digest', $width, $alg));
            }
            $row[] = $h->bytes;
        }
        $widths = [$blocks];
        for ($w = $blocks; $w > 1;) {
            $w = intdiv($w + 1, 2);
            $widths[] = $w;
        }
        $layer = array_search(count($row), $widths, true);
        if (! is_int($layer)) {
            return $mismatch(sprintf('the stored hashes are a row of %d, and a tree of %d leaves has rows of %s only', count($row), $blocks, implode(', ', $widths)));
        }
        // without proofs c2pa-rs reads a row as the root or as the leaves, and calls one between them a mismatch
        // (row-of-three, step 448); with proofs no file was measured. The verdict means what c2patool's means
        if (count($row) !== 1 && count($row) !== $blocks) {
            return $mismatch(sprintf('the stored hashes are the row of %d between the root and the %d leaves; this verifier does not read a row other than those two (SPEC-080)', count($row), $blocks));
        }

        $proofs = [];
        try {
            rewind($stream);
            foreach ($this->boxes->merkleProofs($stream) as $box) {
                // c2pa-rs writes every proof box at one size, a shorter proof followed by zero bytes, and reads
                // the proof alone: other bytes after it are Trusted in c2patool 0.28.3 and 0.28.1 too (measured,
                // step 448). No signature covers the box and the proof is checked, so they bind nothing (ADR-0005)
                [$proof] = (new CborDecoder)->decodePrefix($box['payload']);
                $proofs[] = ['offset' => $box['offset'], 'proof' => $proof];
            }
        } catch (ContainerException|CborException $e) {
            return $mismatch('a merkle proof box cannot be read: '.$e->getMessage());
        }
        $byLocation = [];
        if ($proofs !== [] && count($proofs) !== $blocks) {
            return $mismatch(sprintf('the file holds %d proof boxes for %d blocks', count($proofs), $blocks));
        }
        foreach ($proofs as ['offset' => $at, 'proof' => $proof]) {
            $location = is_array($proof) ? ($proof['location'] ?? null) : null;
            $siblings = is_array($proof) ? ($proof['hashes'] ?? null) : null;
            // uniqueId and localId are not read: with one map there is nothing to select, and a proof is
            // checked against the signed row whatever it says it belongs to (c2patool 0.28.3 and 0.28.1 trust
            // a proof whose uniqueId is 1, measured in step 448; ADR-0005)
            if (! is_array($proof) || ! is_int($location) || ! is_array($siblings) || ! array_is_list($siblings)) {
                return $mismatch(sprintf('the proof box at offset %d is not a proof: a map with a location and a list of hashes', $at));
            }
            if ($location < 0 || $location >= $blocks) {
                return $mismatch(sprintf('the proof box at offset %d claims location %d, outside the %d blocks', $at, $location, $blocks));
            }
            if (array_key_exists($location, $byLocation)) {
                return $mismatch(sprintf('location %d appears in two proof boxes, at offsets %d and %d', $location, $byLocation[$location]['offset'], $at));
            }
            $list = [];
            foreach ($siblings as $s) {
                if (! $s instanceof CborBytes || strlen($s->bytes) !== $width) {
                    return $mismatch(sprintf('the proof box at offset %d holds a hash that is not a %d-byte %s digest', $at, $width, $alg));
                }
                $list[] = $s->bytes;
            }
            $byLocation[$location] = ['offset' => $at, 'siblings' => $list];
        }

        // the blocks in order: each against its proof, and each run of 2^layer leaves folded into its stored node
        $span = 1 << $layer;
        /** @var list<array{int, string}> $stack a height and a node, the heights falling from the bottom */
        $stack = [];
        /** @var list<string> $computed */
        $computed = [];
        for ($i = 0; $i < $blocks; $i++) {
            try {
                $leaf = $this->digest($stream, [['offset' => $start + $i * $fixed, 'length' => min($fixed, $size - $i * $fixed), 'marker' => false]], $alg);
            } catch (HashException $e) {
                return $mismatch($e->getMessage());
            }
            if (isset($byLocation[$i])) {
                $node = self::climb($alg, $leaf, $i, $blocks, $layer, $byLocation[$i]['siblings']);
                if ($node === null || ! hash_equals($row[$i >> $layer], $node)) {
                    return $mismatch(sprintf('block %d does not reach the stored row through the proof box at offset %d (location %d)', $i, $byLocation[$i]['offset'], $i));
                }
            }
            $stack[] = [0, $leaf];
            while (count($stack) > 1 && $stack[count($stack) - 1][0] === $stack[count($stack) - 2][0]) {
                [$height, $right] = array_pop($stack);
                [, $left] = array_pop($stack);
                $stack[] = [$height + 1, hash($alg, $left.$right, true)];
            }
            if (($i + 1) % $span === 0 || $i === $blocks - 1) {
                // fold from the top: the largest power of two ends up on the left (step 82)
                $node = null;
                while ($stack !== []) {
                    [, $part] = array_pop($stack);
                    $node = $node === null ? $part : hash($alg, $part.$node, true);
                }
                $computed[] = (string) $node;
            }
        }
        foreach ($computed as $j => $node) {
            if (! hash_equals($row[$j], $node)) {
                $first = $j * $span;
                $last = min(($j + 1) * $span, $blocks) - 1;

                return $mismatch($first === $last
                    ? sprintf('block %d does not match the stored row', $first)
                    : sprintf('blocks %d to %d do not match the stored row', $first, $last));
            }
        }

        return new ValidationStatus(StatusCode::AssertionBmffHashMatch, $url, sprintf(
            '%s; the merkle tree over the mdat, %d block%s of %d bytes, matches its stored %s%s',
            $matched,
            $blocks,
            $blocks === 1 ? '' : 's',
            $fixed,
            count($row) === 1 ? 'root' : 'row of '.count($row),
            $proofs === [] ? '' : sprintf(', and all %d proof boxes lead their block to it', count($proofs)),
        ));
    }

    /**
     * A leaf climbed `$layers` levels with its proof, layer by layer as c2pa-rs's check_merkle_tree() walks
     * (SPEC-080): an odd index takes its sibling from the left, an even one from the right, and the last node
     * of an odd-width layer moves up alone, consuming nothing. Null when the proof is too short. Hashes past
     * the climb are not read, as c2pa-rs does not read them (Trusted in c2patool 0.28.3 and 0.28.1, step 448):
     * the node reached is compared with the signed row either way.
     *
     * @param  list<string>  $siblings
     */
    private static function climb(string $alg, string $leaf, int $index, int $width, int $layers, array $siblings): ?string
    {
        $node = $leaf;
        $used = 0;
        for ($k = 0; $k < $layers; $k++) {
            if ($index % 2 === 1) {
                $sibling = $siblings[$used++] ?? null;
                if ($sibling === null) {
                    return null;
                }
                $node = hash($alg, $sibling.$node, true);
            } elseif ($index + 1 < $width) {
                $sibling = $siblings[$used++] ?? null;
                if ($sibling === null) {
                    return null;
                }
                $node = hash($alg, $node.$sibling, true);
            }
            $index = intdiv($index, 2);
            $width = intdiv($width + 1, 2);
        }

        return $node;
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
        // merkleMapOf() has held a present alg to the three (SPEC-051)
        $alg = is_string($map['alg'] ?? null) ? $map['alg'] : $assertion['alg'];

        $initHash = $map['initHash'] ?? null;
        if (! $initHash instanceof CborBytes) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, self::LABEL.': the merkle map has no initHash')];
        }
        try {
            $computed = $this->digest($stream, $included, $alg);
        } catch (HashException $e) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMismatch, $url, $e->getMessage())];
        }
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
        // a tree of no leaves binds nothing: the count is required and at least 1 (SPEC-028 amendment 2; c2pa-rs
        // cannot decode a map without it and refuses a count of 0)
        $count = $map['count'] ?? null;
        if (! is_int($count) || $count < 1) {
            return [new ValidationStatus(StatusCode::AssertionBmffHashMalformed, $url, sprintf(
                '%s: the merkle map has %s; a tree needs at least one fragment',
                self::LABEL,
                $count === null ? 'no count' : (is_int($count) ? 'a count of '.$count : 'a count that is not an integer'),
            ))];
        }

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
     * The ranges to hash, with whatever follows the last top-level box appended (SPEC-027 amendment 4).
     * Fewer than eight bytes make no box header, so the walk never lists them. Measured against the
     * hash c2pa-rs writes: they come last, after every included range, with no offset marker of their
     * own, and they count even when the last box is excluded.
     *
     * @param  list<array{offset: int, length: int, marker?: bool}>  $included
     * @param  list<array{offset: int, length: int, type: string, path?: string, ipath?: string}>  $topLevel
     * @param  resource  $stream
     * @return list<array{offset: int, length: int, marker?: bool}>
     *
     * @throws HashException when the stream's size cannot be read
     */
    private static function withTail(array $included, array $topLevel, $stream): array
    {
        $end = 0;
        foreach ($topLevel as $box) {
            $end = max($end, $box['offset'] + $box['length']);
        }
        $stat = fstat($stream);
        if ($stat === false) {
            throw new HashException('cannot read the size of the stream to find the bytes after its last box');
        }
        $tail = $stat['size'] - $end;

        return $tail > 0 ? [...$included, ['offset' => $end, 'length' => $tail, 'marker' => false]] : $included;
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
            $top = $this->boxes->topLevelBoxes($fragment);
            $leaf = $this->digest($fragment, self::withTail(self::included($top, $exclusions, function (int $at, int $length) use ($fragment): string {
                if ($length < 1 || fseek($fragment, $at) !== 0) {
                    return '';
                }

                return Read::upTo($fragment, $length);
            }), $top, $fragment), $alg);
        } catch (HashException|CborException|ContainerException $e) {
            return sprintf('%s: %s', $name, $e->getMessage());
        }

        if (! is_array($proof) || ! is_int($proof['location'] ?? null)) {
            return sprintf('%s: its merkle box has no location', $name);
        }
        $location = $proof['location'];
        // SPEC-028 amendment 1: a leaf's place in a tree of $count leaves. path() does not check it, and
        // the merkle box is not in the leaf hash, so a copy with another number would climb as a real leaf
        if ($location < 0 || $location >= $count) {
            return sprintf('%s claims location %d, outside the tree of %d fragment(s) the assertion declares', $name, $location, $count);
        }
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
        // SPEC-051: absent means the assertion's alg; present, it is text and one of the three
        if (array_key_exists('alg', $map)) {
            $alg = $map['alg'];
            if (! is_string($alg)) {
                throw new HashException(sprintf('%s: the merkle map alg is %s, not text', self::LABEL, get_debug_type($alg)));
            }
            if (! in_array($alg, self::ALGORITHMS, true)) {
                throw new HashException(sprintf('%s: the merkle map names the hash algorithm %s, which is not one this verifier implements', self::LABEL, $alg));
            }
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
     * SPEC-038: the shape c2pa-rs refuses before it hashes, as `assertion.bmffHash.malformed` —
     * `exclusions` present and not empty, and every `subset` list ordered by offset without overlap
     * (C2PA 2.4: *"shall be ordered by increasing offset value and shall not overlap"*). A length of 0
     * runs to the end of the box, so only the last entry may carry it (open question 3). Anything
     * else about the assertion is assertionOf()'s to judge.
     */
    public static function shapeFault(mixed $data): ?string
    {
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        $exclusions = $data['exclusions'] ?? null;
        if ($exclusions === null) {
            return self::LABEL.': the assertion has no exclusions; a BMFF hash must exclude at least the C2PA box';
        }
        if ($exclusions === []) {
            return self::LABEL.': the exclusions list is empty; a BMFF hash must exclude at least the C2PA box';
        }
        foreach (is_array($exclusions) ? $exclusions : [] as $exclusion) {
            // SPEC-072: an indexed step is type[n], n a decimal integer from 1; anything else in brackets is malformed
            $xpath = is_array($exclusion) ? ($exclusion['xpath'] ?? null) : null;
            if (is_string($xpath) && $xpath !== '' && $xpath[0] === '/' && (str_contains($xpath, '[') || str_contains($xpath, ']'))) {
                foreach (explode('/', substr($xpath, 1)) as $step) {
                    if (preg_match(self::XPATH_STEP, $step) !== 1) {
                        return sprintf('%s: the xpath %s has a malformed step %s: an index is [n] with n a whole number from 1', self::LABEL, $xpath, self::printable($step));
                    }
                }
            }
        }
        foreach (is_array($exclusions) ? $exclusions : [] as $i => $exclusion) {
            $subsets = is_array($exclusion) ? ($exclusion['subset'] ?? null) : null;
            if (! is_array($subsets) || ! array_is_list($subsets)) {
                continue;
            }
            $end = null;
            foreach ($subsets as $k => $subset) {
                $offset = is_array($subset) ? ($subset['offset'] ?? null) : null;
                $length = is_array($subset) ? ($subset['length'] ?? null) : null;
                if (! is_int($offset) || ! is_int($length) || $offset < 0 || $length < 0) {
                    continue;   // not a range at all: ranges() refuses it
                }
                if ($end !== null && $offset < $end) {
                    return sprintf('%s: exclusion %s: subset %d starts at %d, before the previous one ends at %d; subsets must be ordered by offset and must not overlap', self::LABEL, (string) $i, $k, $offset, $end);
                }
                $end = $length === 0 ? PHP_INT_MAX : $offset + $length;
            }
        }

        return null;
    }

    /**
     * Whether an xpath names this box (SPEC-072): step by step, the type equal and, where the
     * xpath gives an index, the box's 1-based index among same-type siblings equal too. A step
     * without an index matches every sibling of that type, as before. A box handed over without
     * an indexed path (SPEC-027's top-level callers) matches unindexed xpaths only.
     */
    private static function pathMatches(string $xpath, string $path, ?string $ipath): bool
    {
        if (! str_contains($xpath, '[')) {
            return $path === $xpath;
        }
        if ($ipath === null) {
            return false;
        }
        $pattern = '';
        foreach (explode('/', substr($xpath, 1)) as $step) {
            if (preg_match(self::XPATH_STEP, $step, $m) !== 1) {
                return false;   // shapeFault() has refused it already
            }
            $pattern .= '/'.preg_quote($m[1], '#').'\['.(($m[2] ?? '') !== '' ? $m[2] : '[0-9]+').'\]';
        }

        return preg_match('#^'.$pattern.'$#', $ipath) === 1;
    }

    /**
     * SPEC-072: why an indexed track exclusion is refused once the boxes are known, or null. The
     * track it resolves to must hold mdia/hdlr with handler type `meta` (timed metadata, what Pixel
     * Camera excludes); a video or audio track's sample table says which mdat bytes are frames, so
     * excluding it could unbind the picture.
     *
     * @param  list<array{offset: int, length: int, type: string, path: string, ipath?: string}>  $boxes
     * @param  callable(int, int): string  $readAt
     */
    private static function trackRefusal(string $xpath, array $boxes, callable $readAt): ?string
    {
        if (preg_match(self::INDEXED_TRACK, $xpath, $m) !== 1) {
            return null;
        }
        $track = sprintf('/moov[1]/trak[%s]', $m[1]);
        $found = false;
        $handler = null;
        foreach ($boxes as $box) {
            $ipath = $box['ipath'] ?? null;
            if ($ipath === $track) {
                $found = true;
            }
            if ($ipath === $track.'/mdia[1]/hdlr[1]') {
                // the handler type follows version and flags and pre_defined, after a header of 8 bytes
                // or of 16 when size is 1 (ISO/IEC 14496-12 §4.2; SPEC-072 amendment 1)
                $header = $box['length'] >= 16 && $readAt($box['offset'], 4) === "\x00\x00\x00\x01" ? 16 : 8;
                if ($box['length'] >= $header + 12) {
                    $handler = $readAt($box['offset'] + $header + 8, 4);
                }
            }
        }
        if ($handler === 'meta') {
            return null;
        }

        return sprintf('a track may be excluded only when its handler is meta (timed metadata); %s', match (true) {
            ! $found => 'it resolves to no track',
            $handler === null => 'this one has no handler',
            default => sprintf("this one's is %s", self::printable($handler)),
        });
    }

    /** Bytes from the file, as they may appear in an explanation: themselves when printable ASCII, else hex. */
    private static function printable(string $bytes): string
    {
        return preg_match('/^[\x20-\x7e]*$/', $bytes) === 1 ? $bytes : '0x'.bin2hex($bytes);
    }

    /**
     * SPEC-071: why this exclusion is not one a BMFF hash may carry, or null when it is. The
     * allow-list is what c2pa-rs writes (36 assertions measured in step 356; v2's list in SPEC-029):
     * the C2PA uuid box by its data filter, ftyp, mfra, free, skip, meta/iloc and mfra/tfra, whole
     * or narrowed; the offset fields of stco, co64, tfhd and trun only through a subset that starts
     * at offset 16 or later, so that the box header, version, flags and count stay hashed. Anything
     * else, a path that matches no box included, would let the media out of the hash.
     *
     * @param  array<string, mixed>  $exclusion
     */
    public static function refusedExclusion(array $exclusion, bool $mdatMerkle = false): ?string
    {
        $xpath = $exclusion['xpath'] ?? null;
        if ($xpath === '/uuid') {
            $data = $exclusion['data'] ?? null;
            $map = is_array($data) && count($data) === 1 ? ($data[0] ?? null) : null;
            $value = is_array($map) ? ($map['value'] ?? null) : null;
            if (is_array($map) && ($map['offset'] ?? null) === 8 && $value instanceof CborBytes && $value->bytes === IsobmffManifestStoreExtractor::C2PA_UUID) {
                return null;
            }
        } elseif (in_array($xpath, self::ALLOWED_WHOLE, true) || (is_string($xpath) && preg_match(self::INDEXED_TRACK, $xpath) === 1)) {
            return null;   // an indexed track: its handler is checked after the box walk (SPEC-072)
        } elseif ($xpath === '/mdat' && $mdatMerkle) {
            return self::mdatRefusal($exclusion);
        } elseif (in_array($xpath, self::OFFSET_TABLES, true)) {
            return 'an offset table is hashed, never excluded: with it excluded, which bytes are read as the media can change without changing the hash (C2PA 2.4 §18.6.2 hashes every offset at its post-embed value; an older c2pa-rs excluded these, today\'s does not)';
        }

        return 'a BMFF hash may exclude only the C2PA uuid box, ftyp, mfra, free, skip and mfra/tfra, which is what c2pa-rs writes, and moov/free, moov/udta, moov/meta and a timed-metadata track, which is what Pixel Camera writes; any other box could hold the media';
    }

    /**
     * SPEC-080 amendment 1: an `/mdat` exclusion beside a merkle tree over the mdat may leave out only what
     * the leaves bind, from offset 16 on. c2pa-rs writes `subset [{offset: 16, length: 0}]`; a subset that
     * starts later leaves no byte unbound either. The whole box, or a subset from before 16, would leave
     * bytes between the header and the first leaf under neither the hash nor a leaf.
     *
     * @param  array<string, mixed>  $exclusion
     */
    private static function mdatRefusal(array $exclusion): ?string
    {
        $subsets = $exclusion['subset'] ?? null;
        if ($subsets === null) {
            return 'the whole mdat is excluded, and the merkle tree binds it only from offset 16: the bytes between its header and the first leaf would be under neither the hash nor a leaf (SPEC-080)';
        }
        if (! is_array($subsets) || ! array_is_list($subsets) || $subsets === []) {
            return 'an /mdat exclusion beside a merkle tree is a list of subsets, each from offset 16 on (SPEC-080)';
        }
        foreach ($subsets as $subset) {
            $offset = is_array($subset) ? ($subset['offset'] ?? null) : null;
            if (! is_int($offset) || $offset < self::MDAT_LEAVES_FROM) {
                return sprintf('an /mdat subset from offset %s leaves bytes before 16, where the leaves begin, under neither the hash nor a leaf (SPEC-080)', is_int($offset) ? (string) $offset : 'that is not an integer');
            }
        }

        return null;
    }

    /**
     * SPEC-038: whether an exclusion goes beyond the ones every writer needs — the C2PA `uuid` box
     * (one data map, the C2PA UUID at offset 8), `ftyp` and `mfra` — as c2pa-rs's `verify_internal`
     * decides it. `c2pa-rs`'s own writer adds `/free` and `/skip`, so nearly every file carries one.
     *
     * @param  list<array<string, mixed>>  $exclusions
     */
    public static function hasAdditionalExclusions(array $exclusions): bool
    {
        foreach ($exclusions as $exclusion) {
            $xpath = $exclusion['xpath'] ?? null;
            if ($xpath === '/ftyp' || $xpath === '/mfra') {
                continue;
            }
            $data = $exclusion['data'] ?? null;
            $map = is_array($data) && count($data) === 1 ? ($data[0] ?? null) : null;
            $value = is_array($map) ? ($map['value'] ?? null) : null;
            if ($xpath === '/uuid' && is_array($map) && ($map['offset'] ?? null) === 8 && $value instanceof CborBytes && $value->bytes === IsobmffManifestStoreExtractor::C2PA_UUID) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The assertion, read whole before a byte of the asset is touched. Without its own `alg`, the
     * claim's applies, as for a data hash and as c2pa-rs does (C2PA 2.4 §13.1, §15.4.1;
     * SPEC-027 amendment 8). With neither, `alg` is null: no algorithm is assumed, and check()
     * reports algorithm.unsupported (SPEC-027 amendment 9).
     *
     * @return array{alg: string|null, hash: string|null, merkle: mixed, exclusions: list<array<string, mixed>>}
     *
     * @throws HashException
     */
    public function assertionOf(mixed $data, ?string $claimAlg = null): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new HashException(self::LABEL.' is not a CBOR map');
        }
        $alg = $data['alg'] ?? $claimAlg;
        if ($alg !== null && (! is_string($alg) || ! in_array($alg, self::ALGORITHMS, true))) {
            throw new HashException(sprintf('%s: hash algorithm %s is not one this verifier implements', self::LABEL, is_string($alg) ? $alg : gettype($alg)));
        }

        $merkle = $data['merkle'] ?? null;
        $hash = $data['hash'] ?? null;
        if ($merkle === null && ! $hash instanceof CborBytes) {
            throw new HashException(self::LABEL.' has no hash and no merkle, or a hash that is not a byte string');
        }
        // both together: SPEC-080's Merkle tree over the mdat of a non-fragmented file, judged in check()

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
     * SPEC-053: the exclusion list's size, checked before a box is read. The fragments share the
     * init segment's exclusions, so this bounds them too.
     *
     * @param  list<array<string, mixed>>  $exclusions
     *
     * @throws BmffLimitException
     */
    private function bound(array $exclusions): void
    {
        if (count($exclusions) > $this->maxExclusions) {
            throw new BmffLimitException(sprintf('%s: %d exclusions exceed the limit of %d', self::LABEL, count($exclusions), $this->maxExclusions));
        }
        foreach ($exclusions as $exclusion) {
            $subsets = $exclusion['subset'] ?? null;
            if (is_array($subsets) && count($subsets) > $this->maxSubsets) {
                throw new BmffLimitException(sprintf('%s: an exclusion of %s has %d subsets, beyond the limit of %d', self::LABEL, is_string($exclusion['xpath'] ?? null) ? $exclusion['xpath'] : 'no xpath', count($subsets), $this->maxSubsets));
            }
        }
    }

    /** Which BMFF binding this manifest carries, newest first, or null for none. */
    public static function labelOf(Manifest $manifest): ?string
    {
        foreach (self::LABELS as $kind) {
            foreach (array_keys($manifest->assertions) as $label) {
                if (HardBindings::baseLabel((string) $label) === $kind) {   // an instance label too (SPEC-012 amendment 9)
                    return (string) $label;
                }
            }
        }

        return null;
    }

    /**
     * The ranges the digest covers, in file order, each saying whether an offset
     * marker precedes it (SPEC-029).
     *
     * A marker belongs to a top-level box, not to a range: a nested exclusion
     * punches a hole inside a box and the pieces on either side share the one
     * marker. Measured, step 86 — `moov` comes back as three ranges and one
     * marker, because two `stco` boxes are excluded from their offset 16 onward.
     *
     * @param  list<array{offset: int, length: int, type: string, path: string}>  $tree  as the extractor gives it
     * @param  list<array<string, mixed>>  $exclusions
     * @param  callable(int, int): string  $readAt
     * @return list<array{offset: int, length: int, marker: bool}>
     *
     * @throws HashException on a filter this verifier does not implement
     * @throws BmffLimitException past $maxRanges excluded ranges (SPEC-053), before remaining() sees them
     */
    public static function plan(array $tree, array $exclusions, callable $readAt, int $maxRanges = self::DEFAULT_MAX_RANGES): array
    {
        $excluded = [];
        foreach ($tree as $box) {
            foreach ($exclusions as $exclusion) {
                if (! self::matches($box, $exclusion, $readAt)) {
                    continue;
                }
                foreach (self::ranges($box, $exclusion) as $range) {
                    if (count($excluded) >= $maxRanges) {
                        throw new BmffLimitException(sprintf('%s: the exclusions exclude more than %d ranges, the limit', self::LABEL, $maxRanges));
                    }
                    $excluded[] = $range;
                }
            }
        }

        // SPEC-038: markers as c2pa-rs places them. Every top-level box keeps one, holding its own
        // start offset, unless an exclusion without `subset` takes the box out — a box with a subset
        // is not excluded "in its entirety", even when the subsets cover every byte. The marker comes
        // first; where the box's head is included it rides on the first range, as SPEC-029 had it.
        // Where the head is not, it stands alone, and c2pa-rs keeps it only strictly between the
        // first and the last included byte of the file (hash_utils.rs, hash_stream_by_alg).
        $boxes = [];
        foreach ($tree as $box) {
            if ($box['path'] !== '/'.$box['type']) {
                continue;   // markers and ranges are per top-level box
            }
            $whole = false;
            foreach ($exclusions as $exclusion) {
                $whole = $whole || (! array_key_exists('subset', $exclusion) && self::matches($box, $exclusion, $readAt));
            }
            $boxes[] = [$box, $whole, self::remaining($box, $excluded)];
        }
        $first = null;
        $last = null;
        foreach ($boxes as [, , $spans]) {
            foreach ($spans as $span) {
                $first = $first === null ? $span['offset'] : min($first, $span['offset']);
                $last = $last === null ? $span['offset'] + $span['length'] - 1 : max($last, $span['offset'] + $span['length'] - 1);
            }
        }

        $plan = [];
        foreach ($boxes as [$box, $whole, $spans]) {
            if ($whole) {
                continue;
            }
            $headIncluded = $spans !== [] && $spans[0]['offset'] === $box['offset'];
            if (! $headIncluded && $first !== null && $last !== null && $box['offset'] > $first && $box['offset'] < $last) {
                $plan[] = ['offset' => $box['offset'], 'length' => 0, 'marker' => true];
            }
            foreach ($spans as $k => $span) {
                $plan[] = ['offset' => $span['offset'], 'length' => $span['length'], 'marker' => $headIncluded && $k === 0];
            }
        }

        return $plan;
    }

    /**
     * What an exclusion takes out of a box: the whole of it, or the subsets it names.
     *
     * `length: 0` means to the end of the box, and an explicit length is clipped to
     * it — measured on this file's two 40-byte `stco` boxes, excluded from offset
     * 16 (step 86).
     *
     * @param  array{offset: int, length: int, type: string, path: string, ipath?: string}  $box
     * @param  array<string, mixed>  $exclusion
     * @return list<array{offset: int, length: int}>
     */
    private static function ranges(array $box, array $exclusion): array
    {
        $subsets = $exclusion['subset'] ?? null;
        if ($subsets === null) {
            return [['offset' => $box['offset'], 'length' => $box['length']]];
        }
        if (! is_array($subsets) || ! array_is_list($subsets)) {
            throw new HashException(self::LABEL.': an exclusion subset is not a list');
        }

        $out = [];
        foreach ($subsets as $subset) {
            if (! is_array($subset) || ! is_int($subset['offset'] ?? null) || ! is_int($subset['length'] ?? null)) {
                throw new HashException(self::LABEL.': a subset has no integer offset and length');
            }
            if ($subset['offset'] > $box['length']) {
                continue;
            }
            $length = $subset['length'] === 0
                ? $box['length'] - $subset['offset']
                : min($subset['length'], $box['length'] - $subset['offset']);
            $out[] = ['offset' => $box['offset'] + $subset['offset'], 'length' => $length];
        }

        return $out;
    }

    /**
     * A box minus everything excluded inside it, in file order.
     *
     * @param  array{offset: int, length: int, type: string, path: string, ipath?: string}  $box
     * @param  list<array{offset: int, length: int}>  $excluded
     * @return list<array{offset: int, length: int}>
     */
    private static function remaining(array $box, array $excluded): array
    {
        $inside = [];
        foreach ($excluded as $range) {
            $start = max($range['offset'], $box['offset']);
            $stop = min($range['offset'] + $range['length'], $box['offset'] + $box['length']);
            if ($stop > $start) {
                $inside[] = ['offset' => $start, 'length' => $stop - $start];
            }
        }
        usort($inside, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $out = [];
        $at = $box['offset'];
        $end = $box['offset'] + $box['length'];
        foreach ($inside as $range) {
            if ($range['offset'] > $at) {
                $out[] = ['offset' => $at, 'length' => $range['offset'] - $at];
            }
            $at = max($at, $range['offset'] + $range['length']);
        }
        if ($at < $end) {
            $out[] = ['offset' => $at, 'length' => $end - $at];
        }

        return $out;
    }

    /**
     * The top-level boxes no exclusion matches, in file order.
     *
     * @param  list<array{offset: int, length: int, type: string, path?: string, ipath?: string}>  $boxes
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
     * @param  array{offset: int, length: int, type: string, path?: string, ipath?: string}  $box
     * @param  array<string, mixed>  $exclusion
     * @param  callable(int, int): string  $readAt
     *
     * @throws HashException
     */
    private static function matches(array $box, array $exclusion, callable $readAt): bool
    {
        $xpath = $exclusion['xpath'] ?? null;
        if (! is_string($xpath) || $xpath === '' || $xpath[0] !== '/') {
            throw new HashException(self::LABEL.': an exclusion has no usable xpath');
        }

        // The path first, and a refusal only after it resolves. video1.mp4 carries
        // two `flags` exclusions on /moof paths and has no moof at all; refusing
        // while reading the list would make a file fail on exclusions that touch
        // nothing (SPEC-029 AC6).
        // SPEC-027's callers hand over top-level boxes without a path; a box that
        // has none is at the top level and its path is its type.
        $path = $box['path'] ?? '/'.$box['type'];
        if (! self::pathMatches($xpath, $path, $box['ipath'] ?? null)) {
            return false;
        }

        foreach (self::UNSUPPORTED_FILTERS as $filter) {
            if (($exclusion[$filter] ?? null) !== null) {
                throw new HashException(sprintf(
                    '%s: the exclusion %s carries a %s filter, which this verifier does not implement; ignoring it would hash the wrong bytes',
                    self::LABEL,
                    $xpath,
                    $filter,
                ));
            }
        }

        // The data filter: this is how the manifest excludes itself without naming an
        // offset that would move — the uuid box whose bytes at offset 8 are the C2PA
        // UUID. video1.mp4 proves it does real work: it holds a second uuid box that
        // is hashed (SPEC-029 AC5).
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
     * @param  list<array{offset: int, length: int, marker?: bool}>  $included
     *
     * @throws HashException for an algorithm outside the three (SPEC-051)
     */
    private function digest($stream, array $included, string $alg): string
    {
        // SPEC-051: a second guard, so no path that forgets to check can crash here or hash weakly
        if (! in_array($alg, self::ALGORITHMS, true)) {
            throw new HashException(sprintf('%s: hash algorithm %s is not one this verifier implements', self::LABEL, $alg));
        }
        $context = hash_init($alg);
        foreach ($included as $range) {
            // the offset first, as a big-endian uint64: this is what binds position.
            // One marker per top-level box, not per range — a nested exclusion splits
            // a box and the pieces share its marker (SPEC-029).
            if ($range['marker'] ?? true) {
                hash_update($context, pack('J', $range['offset']));
            }
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
