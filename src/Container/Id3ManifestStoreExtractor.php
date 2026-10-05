<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * An ID3v2 tag at offset 0 → the C2PA GEOB frame's object (SPEC-056; C2PA 2.4
 * §A.3.4). Written for MP3; FLAC carries the same tag and will reuse it.
 *
 * Reads the 10-byte header (version 3 or 4, a syncsafe size), skips an
 * extended header, then walks the frames to the end of the tag — v2.3 sizes
 * plain, v2.4 sizes syncsafe — until padding. Strict about the C2PA GEOB (a
 * GEOB whose MIME type is exactly `application/c2pa`): at most one, inside
 * the tag, not compressed, encrypted or unsynchronised, its object at least a
 * box header and its LBox equal to the object's length. As lenient as
 * c2patool about the rest: other frames are skipped unread, a frame other
 * than the C2PA GEOB that runs past the tag ends the walk, and what follows
 * the tag (audio, a footer, an ID3v1 tag) is left to the data hash, which
 * covers it. Every fault says whether a C2PA GEOB had been reached.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class Id3ManifestStoreExtractor
{
    // SPEC-024: the bound every extractor carries.
    public const DEFAULT_MAX_OBJECT_LENGTH = 16 * 1024 * 1024;

    /** A tag of more frames than this is refused (AC12). */
    public const MAX_FRAMES = 4096;

    /** The JUMBF media type (C2PA 2.4 §11.4), matched exactly, as c2patool matches it. */
    private const MIME = 'application/c2pa';

    private const HEADER_LENGTH = 10;

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    /** The GEOB's encoding byte, MIME type and two text fields are read within this many bytes. */
    private const MAX_TEXT_LENGTH = 4096;

    public function __construct(
        public int $maxObjectLength = self::DEFAULT_MAX_OBJECT_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the tag carries no C2PA GEOB (AC2)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        // whether a C2PA GEOB had been reached when a fault was thrown (SPEC-013 amendment 18)
        $reached = false;
        try {
            return $this->walk($stream, $reached);
        } catch (ContainerException $e) {
            throw $e->storeReached === $reached ? $e : new ContainerException($e->getMessage(), previous: $e, storeReached: $reached);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function walk($stream, bool &$reached): ?ManifestStoreBytes
    {
        $reader = new StreamReader($stream, 'frame');
        $header = $reader->readUpTo(self::HEADER_LENGTH);
        if (FormatDetector::isMpegFrame($header)) {
            return null;   // MPEG audio from the first byte: no tag, so no store (SPEC-056 AC13)
        }
        if (strlen($header) !== self::HEADER_LENGTH || ! str_starts_with($header, 'ID3')) {
            throw new ContainerException(sprintf('not an ID3v2 tag: expected ID3 at offset 0, found %s', Bytes::hex(substr($header, 0, 3))));
        }
        $version = ord($header[3]);
        if ($version !== 3 && $version !== 4) {
            throw new ContainerException(sprintf('ID3v2.%d is not read: only versions 3 and 4 (AC4)', $version));
        }
        $flags = ord($header[5]);
        $sizeBytes = substr($header, 6, 4);
        if (preg_match('/[\x80-\xFF]/', $sizeBytes) === 1) {
            throw new ContainerException(sprintf('the ID3 tag size %s at offset 6 is not syncsafe (AC4)', Bytes::hex($sizeBytes)));
        }
        $tagEnd = self::HEADER_LENGTH + self::syncsafe($sizeBytes);
        $fileEnd = $reader->end();
        if ($tagEnd > $fileEnd) {
            // a fault, but one that says whether the store was there (AC5, as SPEC-003 amendment 4)
            $reached = $this->scanForStore($reader, $version, min($tagEnd, $fileEnd));
            throw new ContainerException(sprintf('the ID3 tag ends at %d, past the end of the file at %d', $tagEnd, $fileEnd));
        }
        $offset = self::HEADER_LENGTH;
        if (($flags & 0x40) !== 0) {
            $offset += $this->extendedHeaderLength($reader, $version, $tagEnd);
        }
        $unsynchronised = ($flags & 0x80) !== 0;

        $store = null;
        $storeOffset = null;
        $storeFrame = null;
        $frames = 0;
        while ($offset + self::HEADER_LENGTH <= $tagEnd) {
            $frameHeader = $reader->readExactly(self::HEADER_LENGTH, $offset, 'the frame header');
            $id = substr($frameHeader, 0, 4);
            if ($id[0] === "\0") {
                break;   // padding: the frames have ended
            }
            if (++$frames > self::MAX_FRAMES) {
                throw new ContainerException(sprintf('more than %d frames in the ID3 tag (offset %d)', self::MAX_FRAMES, $offset));
            }
            $size = $version === 4 ? self::syncsafe(substr($frameHeader, 4, 4)) : self::uint32(substr($frameHeader, 4, 4));
            $bodyEnd = $offset + self::HEADER_LENGTH + $size;

            $prefix = '';
            $isStore = false;
            if ($id === 'GEOB' && $size > 0) {
                $prefix = $reader->readExactly(min($size, self::MAX_TEXT_LENGTH, $fileEnd - $offset - self::HEADER_LENGTH), $offset, 'the GEOB frame');
                $isStore = self::mime($prefix) === self::MIME;
            }
            if (! $isStore) {
                if ($bodyEnd > $tagEnd) {
                    return null;   // a frame other than the C2PA GEOB runs past the tag: the walk stops, as c2patool's does
                }
                $reader->skip($size - strlen($prefix), $offset);
                $offset = $bodyEnd;

                continue;
            }

            $reached = true;
            if ($storeOffset !== null) {
                throw new ContainerException(sprintf('two C2PA GEOB frames at offsets %d and %d; a tag carries at most one manifest store', $storeOffset, $offset));
            }
            if ($bodyEnd > $tagEnd) {
                throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d declares %d bytes, past the end of the tag at %d (AC8)', $offset, $size, $tagEnd));
            }
            $formatFlags = $version === 4 ? ord($frameHeader[9]) & 0x0F : ord($frameHeader[9]) & 0xE0;
            if ($formatFlags !== 0) {
                throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d has format flags %02X: compressed, encrypted, unsynchronised or with a data length, it is not read (AC10)', $offset, $formatFlags));
            }
            if ($unsynchronised) {
                throw new ContainerException(sprintf('the ID3 tag\'s unsynchronisation flag is set: the C2PA GEOB frame at offset %d is not read (AC10)', $offset));
            }

            $objectAt = self::objectStart($prefix, $offset);
            $length = $size - $objectAt;
            if ($length < self::BOX_HEADER_LENGTH) {
                throw new ContainerException(sprintf('the C2PA GEOB object length %d is shorter than the %d-byte box header (offset %d)', $length, self::BOX_HEADER_LENGTH, $offset));
            }
            if ($length > $this->maxObjectLength) {
                throw new ContainerException(sprintf('the C2PA GEOB object length %d exceeds the limit of %d bytes (offset %d)', $length, $this->maxObjectLength, $offset));
            }
            if (! $this->budget->allows($length)) {
                throw new ContainerException(sprintf(
                    'the C2PA GEOB object length %d does not fit this host: %d bytes of memory remain. '
                    .'The file was not examined, so this is not a judgement about it (offset %d)',
                    $length,
                    $this->budget->remainingBytes() ?? 0,
                    $offset,
                ));
            }
            $object = substr($prefix, $objectAt);
            if (strlen($object) < 4) {
                $object .= $reader->readExactly(4 - strlen($object), $offset, 'LBox');
            }
            /** @var array{1: int} $lBox */
            $lBox = unpack('N', $object);
            if ($lBox[1] !== $length) {
                throw new ContainerException(sprintf('LBox %d differs from the object length %d (C2PA GEOB frame at offset %d)', $lBox[1], $length, $offset));
            }
            $store = $object.$reader->readExactly($length - strlen($object), $offset, 'the GEOB object');
            $storeOffset = $offset;
            $storeFrame = $offset + self::HEADER_LENGTH + $objectAt;
            $offset = $bodyEnd;
        }

        if ($store === null || $storeFrame === null) {
            return null;
        }

        return new ManifestStoreBytes($store, [['start' => $storeFrame, 'length' => strlen($store)]]);   // the object alone, as c2patool excludes it
    }

    /** The length of an extended header, which is skipped (v2.4: syncsafe and counting itself; v2.3: plain, not counting its 4-byte size). */
    private function extendedHeaderLength(StreamReader $reader, int $version, int $tagEnd): int
    {
        $sizeBytes = $reader->readExactly(4, self::HEADER_LENGTH, 'the extended header size');
        $length = $version === 4 ? self::syncsafe($sizeBytes) : 4 + self::uint32($sizeBytes);
        if ($length < 4 || self::HEADER_LENGTH + $length > $tagEnd) {
            throw new ContainerException(sprintf('the ID3 extended header declares %d bytes, which do not fit the tag ending at %d', $length, $tagEnd));
        }
        $reader->skip($length - 4, self::HEADER_LENGTH);

        return $length;
    }

    /**
     * Whether a C2PA GEOB's header and MIME type lie between offset 10 and $end,
     * read frame header by frame header, never a frame's data (AC5).
     */
    private function scanForStore(StreamReader $reader, int $version, int $end): bool
    {
        try {
            $offset = self::HEADER_LENGTH;
            while ($offset + self::HEADER_LENGTH <= $end) {
                $frameHeader = $reader->readExactly(self::HEADER_LENGTH, $offset, 'the frame header');
                if ($frameHeader[0] === "\0") {
                    return false;
                }
                $size = $version === 4 ? self::syncsafe(substr($frameHeader, 4, 4)) : self::uint32(substr($frameHeader, 4, 4));
                $available = min($size, self::MAX_TEXT_LENGTH, $end - $offset - self::HEADER_LENGTH);
                $prefix = $available > 0 ? $reader->readExactly($available, $offset, 'the frame') : '';
                if (substr($frameHeader, 0, 4) === 'GEOB' && self::mime($prefix) === self::MIME) {
                    return true;
                }
                $next = $offset + self::HEADER_LENGTH + $size;
                if ($next >= $end) {
                    return false;
                }
                $reader->skip($size - strlen($prefix), $offset);
                $offset = $next;
            }
        } catch (ContainerException) {
            return false;
        }

        return false;
    }

    /** The MIME type of a GEOB body: ISO-8859-1 after the encoding byte, up to its NUL; null when it does not end inside $body. */
    private static function mime(string $body): ?string
    {
        $end = strpos($body, "\0", 1);

        return $end === false ? null : substr($body, 1, $end - 1);
    }

    /**
     * Where the object begins in a GEOB body: after the MIME type and the file name
     * and description in the body's text encoding (0 and 3: one NUL; 1 and 2: two,
     * on a two-byte boundary).
     */
    private static function objectStart(string $body, int $offset): int
    {
        $encoding = ord($body[0]);
        if ($encoding > 3) {
            throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d has text encoding %d; only 0 to 3 exist', $offset, $encoding));
        }
        $position = (int) strpos($body, "\0", 1) + 1;   // after the MIME type, which mime() found
        for ($field = 0; $field < 2; $field++) {
            $end = $encoding === 1 || $encoding === 2 ? self::wideNul($body, $position) : strpos($body, "\0", $position);
            if ($end === false) {
                throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d: its text fields do not end within %d bytes', $offset, self::MAX_TEXT_LENGTH));
            }
            $position = $end + ($encoding === 1 || $encoding === 2 ? 2 : 1);
        }

        return $position;
    }

    /** The first two-byte NUL at or after $from, on a two-byte boundary from $from. */
    private static function wideNul(string $body, int $from): int|false
    {
        for ($i = $from; $i + 1 < strlen($body); $i += 2) {
            if ($body[$i] === "\0" && $body[$i + 1] === "\0") {
                return $i;
            }
        }

        return false;
    }

    private static function syncsafe(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 7) | (ord($byte) & 0x7F);
        }

        return $value;
    }

    private static function uint32(string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', $bytes);

        return $value[1];
    }
}
