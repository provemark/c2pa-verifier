<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\StreamReader;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The general box hash, c2pa.hash.boxes, for JPEG (SPEC-090; C2PA 2.4 §15.12.3, §15.12.3.1, §18.7).
 *
 * In order: the box-map's structure (assertion.boxesHash.malformed), the algorithm of every hashed
 * box-hash-map (algorithm.unsupported), the asset's boxes against the list (assertion.boxesHash.unknownBox
 * for a box the list does not name, mismatch for one out of order or absent), then each box-hash-map's
 * hash over its range with its exclusions left out (mismatch). The C2PA box's hash is never computed;
 * pad and pad2 are ignored. An exclusion may lie only inside an Exif or XMP APP1 payload (SPEC-069);
 * one there, or a non-C2PA box excluded whole, adds assertion.boxesHash.additionalExclusionsPresent.
 *
 * Bytes after EOI are a c2pa.after box, refused whether listed or not: the specification allows one
 * only for a multi-part asset (§18.7.2), which is not read here (SPEC-090 open question 4).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class BoxHashCheck
{
    public const LABEL = 'c2pa.hash.boxes';

    public const DEFAULT_MAX_MAPS = 65536;

    public const DEFAULT_CHUNK_SIZE = 64 * 1024;

    /** The algorithms C2PA 2.4 §13.1 allows, as PHP's hash() knows them, with their digest lengths. */
    private const ALGORITHMS = ['sha256' => 32, 'sha384' => 48, 'sha512' => 64];

    public function __construct(
        private JpegBoxMap $boxMap = new JpegBoxMap,
        private ExclusionAllowance $allowance = new JpegExclusionAllowance,
        private int $maxMaps = self::DEFAULT_MAX_MAPS,
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {}

    /** The label of the manifest's c2pa.hash.boxes assertion, instance number included, or null. */
    public static function labelOf(Manifest $manifest): ?string
    {
        foreach (HardBindings::in($manifest) as $box) {
            if (HardBindings::baseLabel($box->description->label) === self::LABEL) {
                return $box->description->label;
            }
        }

        return null;
    }

    /**
     * @param  resource  $stream  the asset, readable and seekable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, $stream, ManifestStoreBytes $store): array
    {
        $label = self::labelOf($manifest) ?? self::LABEL;
        $url = sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $manifest->label, $label);
        $fail = static fn (StatusCode $code, string $why): array => [new ValidationStatus($code, $url, $why)];
        $malformed = static fn (string $why): array => $fail(StatusCode::AssertionBoxesHashMalformed, $why.' (C2PA 2.4 §15.12.3)');

        // ---- the box-map's structure ----
        $data = ($manifest->assertions[$label] ?? null)?->data;
        if (! is_array($data) || ! is_array($data['boxes'] ?? null) || $data['boxes'] === [] || ! array_is_list($data['boxes'])) {
            return $malformed(sprintf('%s has no boxes array', $label));
        }
        if (count($data['boxes']) > $this->maxMaps) {
            return $malformed(sprintf('%s lists %d box-hash-maps, more than %d', $label, count($data['boxes']), $this->maxMaps));
        }
        if (array_key_exists('alg', $data) && ! is_string($data['alg'])) {
            return $malformed(sprintf('%s: alg is %s, not text', $label, get_debug_type($data['alg'])));
        }
        /** @var list<array{names: list<string>, alg: ?string, hash: ?string, excluded: bool, exclusions: list<array{start: int, length: int, box: int}>}> $maps */
        $maps = [];
        foreach ($data['boxes'] as $i => $map) {
            if (! is_array($map)) {
                return $malformed(sprintf('box-hash-map %d is %s, not a map', $i, get_debug_type($map)));
            }
            $names = $map['names'] ?? null;
            if (! is_array($names) || $names === [] || ! array_is_list($names) || array_filter($names, static fn (mixed $n): bool => ! is_string($n) || $n === '') !== []) {
                return $malformed(sprintf('box-hash-map %d has no names array of box names', $i));
            }
            /** @var list<string> $names */
            if (in_array(JpegBoxMap::C2PA, $names, true) && count($names) > 1) {
                return $malformed(sprintf('box-hash-map %d lists C2PA with other boxes; the manifest store is listed in a map of its own (C2PA 2.4 §18.7.1)', $i));
            }
            if (array_key_exists('alg', $map) && ! is_string($map['alg'])) {
                return $malformed(sprintf('box-hash-map %d: alg is %s, not text', $i, get_debug_type($map['alg'])));
            }
            if (array_key_exists('excluded', $map) && ! is_bool($map['excluded'])) {
                return $malformed(sprintf('box-hash-map %d: excluded is %s, not a boolean', $i, get_debug_type($map['excluded'])));
            }
            if (array_key_exists('hash', $map) && ! $map['hash'] instanceof CborBytes) {
                return $malformed(sprintf('box-hash-map %d: hash is %s, not a byte string', $i, get_debug_type($map['hash'])));
            }
            $exclusions = [];
            if (array_key_exists('exclusions', $map)) {
                if (! is_array($map['exclusions']) || $map['exclusions'] === [] || ! array_is_list($map['exclusions'])) {
                    return $malformed(sprintf('box-hash-map %d: exclusions is not a non-empty array', $i));
                }
                foreach ($map['exclusions'] as $j => $exclusion) {
                    $start = is_array($exclusion) ? ($exclusion['start'] ?? null) : null;
                    $length = is_array($exclusion) ? ($exclusion['length'] ?? null) : null;
                    $box = is_array($exclusion) ? ($exclusion['boxIndex'] ?? (count($names) === 1 ? 0 : null)) : null;
                    if (! is_int($start) || ! is_int($length) || $start < 0 || $length < 0) {
                        return $malformed(sprintf('box-hash-map %d, exclusion %d: start and length are not non-negative integers', $i, $j));
                    }
                    if (! is_int($box)) {
                        return $malformed(sprintf('box-hash-map %d, exclusion %d: no boxIndex, and the map names %d boxes', $i, $j, count($names)));
                    }
                    if ($box < 0 || $box >= count($names)) {
                        return $malformed(sprintf('box-hash-map %d, exclusion %d: boxIndex %d names no box of the %d listed', $i, $j, $box, count($names)));
                    }
                    $exclusions[] = ['start' => $start, 'length' => $length, 'box' => $box];
                }
            }
            $maps[] = [
                'names' => $names,
                'alg' => $map['alg'] ?? null,
                'hash' => isset($map['hash']) ? $map['hash']->bytes : null,
                'excluded' => ($map['excluded'] ?? false) === true,
                'exclusions' => $exclusions,
            ];
        }

        // ---- the algorithm of every map that is hashed ----
        foreach ($maps as $i => $map) {
            if ($map['names'] === [JpegBoxMap::C2PA] || $map['excluded']) {
                continue;
            }
            $alg = $map['alg'] ?? $data['alg'] ?? $manifest->claim->alg;
            if ($alg === null) {
                return $fail(StatusCode::AlgorithmUnsupported, sprintf('no algorithm for box-hash-map %d: no alg in the box-hash-map, the box-map or the claim (C2PA 2.4 §15.12.3)', $i));
            }
            if (! array_key_exists($alg, self::ALGORITHMS)) {
                return $fail(StatusCode::AlgorithmUnsupported, sprintf('the hash algorithm %s of box-hash-map %d is not one of sha256, sha384, sha512 (C2PA 2.4 §13.1)', $alg, $i));
            }
        }

        // ---- the asset's boxes against the list ----
        if (! is_resource($stream) || ! rewind($stream)) {
            return $fail(StatusCode::AssertionBoxesHashMismatch, 'the asset stream cannot be rewound; the box hash needs a seekable stream');
        }
        try {
            $boxes = $this->boxMap->boxes($stream, $store);
        } catch (ContainerException $e) {
            return $fail(StatusCode::AssertionBoxesHashMismatch, sprintf('the JPEG\'s boxes cannot be walked: %s', $e->getMessage()));
        }
        $listed = array_merge(...array_column($maps, 'names'));
        foreach ($boxes as $box) {
            if ($box->name === JpegBoxMap::AFTER) {
                return $fail(StatusCode::AssertionBoxesHashUnknownBox, sprintf('%d bytes follow EOI at offset %d (%s); %s', $box->length, $box->start, JpegBoxMap::AFTER, in_array(JpegBoxMap::AFTER, $listed, true)
                    ? 'the list names them, but a c2pa.after box belongs to a multi-part asset (C2PA 2.4 §18.7.2), which is not read here'
                    : 'the list does not name them (C2PA 2.4 §15.12.3)'));
            }
        }
        $inAsset = array_count_values(array_map(static fn (AssetBox $b): string => $b->name, $boxes));
        $inList = array_count_values($listed);
        foreach ($boxes as $box) {
            if (($inList[$box->name] ?? 0) < $inAsset[$box->name]) {
                return $fail(StatusCode::AssertionBoxesHashUnknownBox, sprintf('the asset has %d %s box(es), the first at offset %d, and the list names %d (C2PA 2.4 §15.12.3%s)', $inAsset[$box->name], $box->name, $box->start, $inList[$box->name] ?? 0, $box->name === 'APP11' ? ', §15.12.3.1' : ''));
            }
        }
        foreach ($listed as $k => $name) {
            if (($boxes[$k]->name ?? null) !== $name) {
                return $fail(StatusCode::AssertionBoxesHashMismatch, isset($boxes[$k])
                    ? sprintf('box %d of the list is %s, but the asset has %s at offset %d: the boxes appear out of order (C2PA 2.4 §15.12.3)', $k, $name, $boxes[$k]->name, $boxes[$k]->start)
                    : sprintf('box %d of the list is %s, but the asset has only %d boxes', $k, $name, count($boxes)));
            }
        }

        // ---- each map's hash ----
        $regions = null;
        $additional = false;
        $reader = new StreamReader($stream, 'asset');
        $k = 0;
        foreach ($maps as $i => $map) {
            $own = array_slice($boxes, $k, count($map['names']));
            $k += count($map['names']);
            if ($map['names'] === [JpegBoxMap::C2PA]) {
                continue;
            }
            if ($map['excluded']) {
                $additional = true;

                continue;
            }
            $first = $own[0];
            $last = $own[count($own) - 1];
            $ranges = [];
            $previousEnd = null;
            foreach ($map['exclusions'] as $j => $exclusion) {
                $box = $own[$exclusion['box']];
                $start = $box->start + $exclusion['start'];
                if ($previousEnd !== null && $start < $previousEnd) {
                    return $malformed(sprintf('box-hash-map %d: exclusion %d starts at offset %d, before the previous one ends at %d', $i, $j, $start, $previousEnd));
                }
                if ($box->length < $exclusion['start'] + $exclusion['length']) {
                    return $fail(StatusCode::AssertionBoxesHashMismatch, sprintf('box-hash-map %d: exclusion %d [%d, %d] reaches past the end of its %s box (%d bytes) (C2PA 2.4 §15.12.3)', $i, $j, $exclusion['start'], $exclusion['length'], $box->name, $box->length));
                }
                try {
                    $regions ??= $this->allowance->regions($stream);
                } catch (ContainerException) {
                    $regions = [];
                }
                $allowed = array_filter($regions, static fn (ExclusionRegion $r): bool => $r->allowed && $start >= $r->start && $start + $exclusion['length'] <= $r->start + $r->length);
                if ($allowed === []) {
                    return $fail(StatusCode::AssertionBoxesHashMismatch, sprintf('box-hash-map %d: exclusion %d [%d, %d] of the %s box at offset %d is not inside the store or a payload %s (C2PA 2.4 §15.12.3)', $i, $j, $exclusion['start'], $exclusion['length'], $box->name, $box->start, $this->allowance->rule()));
                }
                $additional = true;
                $ranges[] = ['start' => $start, 'length' => $exclusion['length']];
                $previousEnd = $start + $exclusion['length'];
            }
            if ($map['hash'] === null) {
                return $fail(StatusCode::AssertionBoxesHashMismatch, sprintf('box-hash-map %d (%s) has no hash (C2PA 2.4 §15.12.3)', $i, implode(', ', $map['names'])));
            }
            /** @var string $alg checked above */
            $alg = $map['alg'] ?? $data['alg'] ?? $manifest->claim->alg;
            try {
                $actual = $this->hash($reader, $alg, $first->start, $last->start + $last->length, $ranges);
            } catch (ContainerException $e) {
                return $fail(StatusCode::AssertionBoxesHashMismatch, sprintf('box-hash-map %d cannot be hashed: %s', $i, $e->getMessage()));
            }
            if (! hash_equals($actual, $map['hash'])) {
                return $fail(StatusCode::AssertionBoxesHashMismatch, sprintf('box hash does not match: %s over %s [%d, %d) gives %s, the assertion carries %s', $alg, implode(', ', $map['names']), $first->start, $last->start + $last->length, bin2hex($actual), bin2hex($map['hash'])));
            }
        }

        $statuses = [new ValidationStatus(StatusCode::AssertionBoxesHashMatch, $url, sprintf('box hash valid: %d box-hash-maps over %d boxes', count($maps), count($boxes)))];
        if ($additional) {
            $statuses[] = new ValidationStatus(StatusCode::AssertionBoxesHashAdditionalExclusionsPresent, $url, sprintf('the box hash leaves out more than the manifest store: a box excluded whole, or a range inside a payload %s', $this->allowance->rule()));
        }

        return $statuses;
    }

    /**
     * The hash of [$from, $to) with the (ordered, non-overlapping, in-range) ranges left out, read in chunks.
     *
     * @param  list<array{start: int, length: int}>  $skip
     */
    private function hash(StreamReader $reader, string $alg, int $from, int $to, array $skip): string
    {
        $context = hash_init($alg);
        $position = $from;
        foreach ([...$skip, ['start' => $to, 'length' => 0]] as $range) {
            $reader->seek($position);
            while ($position < $range['start']) {
                $chunk = $reader->readExactly(min($range['start'] - $position, $this->chunkSize), $position, 'asset data');
                hash_update($context, $chunk);
                $position += strlen($chunk);
            }
            $position = $range['start'] + $range['length'];
        }

        return hash_final($context, true);
    }
}
