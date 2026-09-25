<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * ISOBMFF `uuid` box → manifest store bytes (SPEC-026; C2PA 2.4 §11.3, ISO/IEC 14496-12).
 *
 * Walks the top-level boxes and looks for the one `uuid` box whose first sixteen
 * content bytes are the C2PA UUID. Inside it, twenty-one bytes stand between that
 * UUID and the JUMBF superbox — four of version and flags, a null-terminated
 * purpose, and an eight-byte merkle offset — and those are read rather than
 * assumed (step 73 measured them on a signed MP4 and an AVIF alike). The walk
 * continues past the box so that a second one is seen and refused.
 *
 * A purpose this verifier does not read is an error, never silence. `merkle` is
 * the case that matters: treating a fragmented file's merkle data as a store, or
 * reporting it as a file with no credentials, would both be worse than saying so.
 *
 * Nothing here interprets the store, and nothing here checks the hash: the BMFF
 * hard binding (`c2pa.hash.bmff.v3`) is a different algorithm from SPEC-012's
 * byte ranges and is its own spec. Until it exists an ISOBMFF file reaches the
 * verifier with no hard binding it recognises, which SPEC-013 AC15 turns into
 * `claim.hardBindings.missing` and `Invalid` — the honest answer, not a silent one.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class IsobmffManifestStoreExtractor
{
    /** C2PA 2.4 §11.3.2: the UUID that marks a C2PA box in an ISOBMFF file. */
    public const C2PA_UUID = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";

    /** The only purpose this spec reads. `merkle` belongs to fragmented files. */
    public const PURPOSE_MANIFEST = 'manifest';

    /**
     * The longest purpose string read, NUL excluded (SPEC-043 AC2). The purposes C2PA defines are
     * `manifest`, `original` and `merkle`; without a bound, a box with no NUL was read a byte at a time
     * to its end.
     */
    public const MAX_PURPOSE_LENGTH = 64;

    /** SPEC-024: the same 16 MiB bound the other three containers carry. */
    public const DEFAULT_MAX_BOX_LENGTH = 16 * 1024 * 1024;

    /** As SPEC-001's piece limit: a file is not allowed to cost an unbounded walk. */
    public const DEFAULT_MAX_BOXES = 4096;

    /**
     * How deep `boxTree()` descends before refusing (SPEC-029).
     *
     * `/moov/trak/mdia/minf/stbl/stco` is six segments — the deepest path any
     * measured exclusion uses — and eight leaves room for a container this project
     * has not met. A file nested deeper is refused by name rather than read short:
     * a walk that stops early and reports what it found would leave bytes the
     * signer excluded inside the digest and call the result a match.
     */
    public const DEFAULT_MAX_BOX_DEPTH = 8;

    /**
     * The box types this walk descends into, and how many bytes of their own come
     * first. ISO/IEC 14496-12 gives `meta` a version and flags before its children;
     * the rest begin immediately.
     */
    private const CONTAINERS = [
        'moov' => 0, 'trak' => 0, 'mdia' => 0, 'minf' => 0, 'stbl' => 0,
        'moof' => 0, 'traf' => 0, 'mfra' => 0, 'edts' => 0, 'dinf' => 0,
        'udta' => 0, 'mvex' => 0, 'meta' => 4,
    ];

    private const TYPE_UUID = 'uuid';

    /** size (4) + type (4). */
    private const BOX_HEADER_LENGTH = 8;

    /** size (4) + type (4) + largesize (8), when size == 1. */
    private const LARGE_BOX_HEADER_LENGTH = 16;

    /** version and flags (4) + the shortest purpose ("\0") + merkle_offset (8). */
    private const MIN_PREAMBLE_LENGTH = 13;

    public function __construct(
        public int $maxBoxLength = self::DEFAULT_MAX_BOX_LENGTH,
        public int $maxBoxes = self::DEFAULT_MAX_BOXES,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * Every box to the configured depth, in file order, each with the path an
     * exclusion's `xpath` is matched against (SPEC-029).
     *
     * The path is built as the walk descends — `/moov/trak/mdia/minf/stbl/stco` —
     * so resolving an exclusion is a comparison rather than a second parse. This
     * lives here rather than in the hash check because nothing may parse a box in
     * two places (the maintainer's decision, 2026-09-22).
     *
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return list<array{offset: int, length: int, type: string, path: string}>
     *
     * @throws ContainerException on every malformed case, and when a box nests
     *                            deeper than DEFAULT_MAX_BOX_DEPTH
     */
    public function boxTree($stream): array
    {
        $reader = new StreamReader($stream, 'box');
        $end = $reader->end();
        rewind($stream);

        $boxes = [];
        $this->descend($reader, 0, $end, '', 1, $boxes);

        return $boxes;
    }

    /**
     * @param  list<array{offset: int, length: int, type: string, path: string}>  $boxes
     *
     * @throws ContainerException
     */
    private function descend(StreamReader $reader, int $from, int $to, string $parent, int $depth, array &$boxes): void
    {
        if ($depth > self::DEFAULT_MAX_BOX_DEPTH) {
            throw new ContainerException(sprintf(
                'boxes nest deeper than %d at offset %d; this verifier refuses rather than reading a file short',
                self::DEFAULT_MAX_BOX_DEPTH,
                $from,
            ));
        }

        $offset = $from;
        while ($offset + self::BOX_HEADER_LENGTH <= $to) {
            if (count($boxes) >= $this->maxBoxes) {
                throw new ContainerException(sprintf('more than %d boxes (offset %d)', $this->maxBoxes, $offset));
            }
            [$size, $header, $type] = $this->boxHeader($reader, $offset, $to);
            $path = $parent.'/'.$type;
            $boxes[] = ['offset' => $offset, 'length' => $size, 'type' => $type, 'path' => $path];

            $skip = array_key_exists($type, self::CONTAINERS) ? self::CONTAINERS[$type] : null;
            if ($skip !== null && $size > $header + $skip) {
                $reader->skip($skip, $offset);
                $this->descend($reader, $offset + $header + $skip, $offset + $size, $path, $depth + 1, $boxes);
            } else {
                $reader->skip($size - $header, $offset);
            }
            $offset += $size;
        }
    }

    /**
     * The CBOR of this file's C2PA box when its purpose is `merkle`, or null when it
     * has no C2PA box or one with another purpose (SPEC-028).
     *
     * A fragment of a fragmented stream carries a box of its own holding its leaf
     * index and the sibling hashes up to the root. `extract()` refuses that purpose,
     * and rightly: it is not a manifest store. This reads it for what it is.
     *
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     *
     * @throws ContainerException on every malformed case
     */
    public function merklePayload($stream): ?string
    {
        $reader = new StreamReader($stream, 'box');
        $end = $reader->end();
        $offset = 0;
        while ($offset + self::BOX_HEADER_LENGTH <= $end) {
            [$size, $header, $type] = $this->boxHeader($reader, $offset, $end);
            $read = $header;
            if ($type === self::TYPE_UUID && $size >= $header + 16) {
                $isC2pa = $reader->readExactly(16, $offset, 'the UUID') === self::C2PA_UUID;
                $read += 16;
                if ($isC2pa) {
                    $available = $size - $read;
                    if ($available < 5) {
                        throw new ContainerException(sprintf('C2PA box at offset %d holds %d bytes after its UUID', $offset, $available));
                    }
                    $reader->readExactly(4, $offset, 'version and flags');
                    $remaining = $available - 4;
                    $purpose = '';
                    while ($remaining > 0) {
                        if (strlen($purpose) >= self::MAX_PURPOSE_LENGTH) {
                            throw new ContainerException(sprintf('C2PA box at offset %d: the purpose string runs past %d bytes without a terminating NUL', $offset, self::MAX_PURPOSE_LENGTH));
                        }
                        $byte = $reader->readExactly(1, $offset, 'the purpose');
                        $remaining--;
                        if ($byte === "\x00") {
                            break;
                        }
                        $purpose .= $byte;
                    }

                    if ($purpose !== 'merkle' || $remaining <= 0) {
                        return null;
                    }
                    // SPEC-043 AC2: the store's bounds, before a byte of it is read
                    if ($remaining > $this->maxBoxLength) {
                        throw new ContainerException(sprintf('C2PA merkle box at offset %d holds %d bytes, over the limit of %d', $offset, $remaining, $this->maxBoxLength));
                    }
                    if (! $this->budget->allows($remaining)) {
                        throw new ContainerException(sprintf('C2PA merkle box at offset %d holds %d bytes, which does not fit this host: %d bytes of memory remain', $offset, $remaining, $this->budget->remainingBytes() ?? 0));
                    }

                    return $reader->readExactly($remaining, $offset, 'the merkle data');
                }
            }
            $reader->skip($size - $read, $offset);
            $offset += $size;
        }

        return null;
    }

    /**
     * The top-level boxes, in file order (SPEC-027 needs the same walk this class
     * already does, and duplicating it would be a second truth).
     *
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return list<array{offset: int, length: int, type: string}>
     *
     * @throws ContainerException on every malformed case
     */
    public function topLevelBoxes($stream): array
    {
        $reader = new StreamReader($stream, 'box');
        $end = $reader->end();

        $boxes = [];
        $offset = 0;
        while ($offset + self::BOX_HEADER_LENGTH <= $end) {
            if (count($boxes) >= $this->maxBoxes) {
                throw new ContainerException(sprintf('more than %d top-level boxes (offset %d)', $this->maxBoxes, $offset));
            }
            [$size, $header, $type] = $this->boxHeader($reader, $offset, $end);
            $boxes[] = ['offset' => $offset, 'length' => $size, 'type' => $type];
            $reader->skip($size - $header, $offset);
            $offset += $size;
        }

        return $boxes;
    }

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the file carries no C2PA box (AC2)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        $reader = new StreamReader($stream, 'box');
        $end = $reader->end();

        $store = null;
        $storeOffset = null;
        $storeLength = 0;
        $offset = 0;
        $boxes = 0;
        // The reader is sequential: $offset is where we are, and every read moves it.
        while ($offset + self::BOX_HEADER_LENGTH <= $end) {
            if (++$boxes > $this->maxBoxes) {
                throw new ContainerException(sprintf(
                    'more than %d top-level boxes (offset %d)',
                    $this->maxBoxes,
                    $offset,
                ));
            }
            [$size, $header, $type] = $this->boxHeader($reader, $offset, $end);
            $read = $header;   // bytes of this box already consumed

            $isC2pa = false;
            if ($type === self::TYPE_UUID) {
                if ($size < $header + 16) {
                    throw new ContainerException(sprintf(
                        'uuid box at offset %d declares %d bytes, too few for its 16-byte UUID',
                        $offset,
                        $size,
                    ));
                }
                $isC2pa = $reader->readExactly(16, $offset, 'the UUID') === self::C2PA_UUID;
                $read += 16;
            }

            if ($isC2pa) {
                if ($storeOffset !== null) {
                    throw new ContainerException(sprintf(
                        'two C2PA uuid boxes at offsets %d and %d; a file carries at most one manifest store',
                        $storeOffset,
                        $offset,
                    ));
                }
                $store = $this->readStore($reader, $offset, $size - $read);
                $storeOffset = $offset;
                $storeLength = $size;
                $read = $size;
            }

            $reader->skip($size - $read, $offset);
            $offset += $size;
        }

        if ($store === null || $storeOffset === null) {
            return null;
        }

        return new ManifestStoreBytes($store, [['start' => $storeOffset, 'length' => $storeLength]]);
    }

    /**
     * The declared size of the box at this offset, and the length of its header.
     *
     * `size == 1` means a 64-bit length follows the type; `size == 0` means the box
     * runs to the end of the file, which only the last box can honestly say
     * (ISO/IEC 14496-12 §4.2). `c2patool` reads a non-last one anyway; this refuses
     * it, because a box claiming everything after it while something follows is a
     * contradiction, and resolving it quietly would be choosing for the file.
     *
     * @return array{int, int, string} the size, the header length and the type
     */
    private function boxHeader(StreamReader $reader, int $offset, int $end): array
    {
        /** @var array{1: int} $sizeField */
        $sizeField = unpack('N', $reader->readExactly(4, $offset, 'the box size'));
        $size = $sizeField[1];
        $type = $reader->readExactly(4, $offset, 'the box type');
        $header = self::BOX_HEADER_LENGTH;

        if ($size === 1) {
            /** @var array{1: int} $large */
            $large = unpack('J', $reader->readExactly(8, $offset, 'the 64-bit box size'));
            $size = $large[1];
            $header = self::LARGE_BOX_HEADER_LENGTH;
        } elseif ($size === 0) {
            // SPEC-026 amendment 1: this declaration is what makes a box the last one,
            // so there is no "not last" case to refuse. A box that swallows what followed
            // it is caught by the hard binding, not here.
            $size = $end - $offset;
        }

        if ($size < $header) {
            throw new ContainerException(sprintf(
                'box %s at offset %d declares %d bytes, less than its %d-byte header',
                Bytes::printable($type),
                $offset,
                $size,
                $header,
            ));
        }
        if ($offset + $size > $end) {
            throw new ContainerException(sprintf(
                'box %s at offset %d declares %d bytes and runs past the end of the file at %d',
                Bytes::printable($type),
                $offset,
                $size,
                $end,
            ));
        }

        return [$size, $header, $type];
    }

    /** The JUMBF bytes of a C2PA box, after its twenty-one bytes of preamble. */
    private function readStore(StreamReader $reader, int $offset, int $available): string
    {
        if ($available < self::MIN_PREAMBLE_LENGTH) {
            throw new ContainerException(sprintf(
                'C2PA box at offset %d holds %d bytes after its UUID, too few for version, purpose and merkle offset',
                $offset,
                $available,
            ));
        }

        // version (1) and flags (3) are read and not interpreted: no version is defined
        // beyond 0, and refusing an unknown one would refuse files this reads correctly.
        $reader->readExactly(4, $offset, 'version and flags');
        $remaining = $available - 4;

        $purpose = '';
        while (true) {
            if ($remaining <= 0) {
                throw new ContainerException(sprintf(
                    'C2PA box at offset %d: the purpose string is not terminated inside the box',
                    $offset,
                ));
            }
            if (strlen($purpose) >= self::MAX_PURPOSE_LENGTH) {
                throw new ContainerException(sprintf(
                    'C2PA box at offset %d: the purpose string runs past %d bytes without a terminating NUL',
                    $offset,
                    self::MAX_PURPOSE_LENGTH,
                ));
            }
            $byte = $reader->readExactly(1, $offset, 'the purpose');
            $remaining--;
            if ($byte === "\x00") {
                break;
            }
            $purpose .= $byte;
        }

        if ($purpose !== self::PURPOSE_MANIFEST) {
            throw new ContainerException(sprintf(
                'C2PA box at offset %d has purpose %s; this verifier reads only %s, and a box it cannot read is not a file without credentials',
                $offset,
                Bytes::printableText($purpose),
                self::PURPOSE_MANIFEST,
            ));
        }

        if ($remaining < 8) {
            throw new ContainerException(sprintf(
                'C2PA box at offset %d: %d bytes left where the 8-byte merkle offset belongs',
                $offset,
                $remaining,
            ));
        }
        $reader->readExactly(8, $offset, 'the merkle offset');
        $remaining -= 8;

        if ($remaining > $this->maxBoxLength) {
            throw new ContainerException(sprintf(
                'C2PA box at offset %d holds a store of %d bytes, over the limit of %d',
                $offset,
                $remaining,
                $this->maxBoxLength,
            ));
        }
        if (! $this->budget->allows($remaining)) {
            throw new ContainerException(sprintf(
                'C2PA box at offset %d holds a store of %d bytes, which does not fit this host: %d bytes of memory remain. '
                .'The file was not examined, so this is not a judgement about it',
                $offset,
                $remaining,
                $this->budget->remainingBytes() ?? 0,
            ));
        }

        return $reader->readExactly($remaining, $offset, 'the manifest store');
    }
}
