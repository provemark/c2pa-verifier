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
 * plain, v2.4 sizes syncsafe unless a byte says otherwise (iTunes) — until
 * padding. The C2PA GEOB is a GEOB whose MIME type is exactly one of the two
 * c2patool accepts. Strict about it: at most one, inside the tag, not
 * compressed, encrypted or unsynchronised, its object at least a box header
 * and its LBox equal to the object's length. As lenient as c2patool about the
 * rest: other frames are skipped unread, a frame other than the C2PA GEOB that
 * runs past the tag ends the walk, and what follows the tag is left to the data
 * hash, which covers it. A frame id that is not four capitals or digits, and
 * unsynchronisation that changes bytes, are faults: the walk could no longer
 * be trusted to have seen every frame (SPEC-056 amendment 2). Every fault says
 * whether a C2PA GEOB had been reached; nothing escapes but ContainerException.
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

    /** The JUMBF media types c2patool accepts for the GEOB, exactly (C2PA 2.4 §11.4; the legacy one since amendment 2). */
    public const MIME_TYPES = ['application/c2pa', 'application/x-c2pa-manifest-store'];

    private const HEADER_LENGTH = 10;

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    /** The GEOB's first read: encoding, MIME type and, nearly always, both text fields. */
    private const FIRST_READ = 4096;

    /** Further reads while the text fields have not ended. */
    private const CHUNK = 65536;

    public function __construct(
        public int $maxObjectLength = self::DEFAULT_MAX_OBJECT_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * The shape of an ID3v2.3 or v2.4 tag header: its version, flags, where the
     * frames end, and where the tag ends with its footer (v2.4 only), both counted
     * from the tag's own first byte. Null for
     * anything else, a size that is not syncsafe included. Shared with
     * FormatDetector, so that the two never disagree about a tag's end; the
     * detector also passes version 2, whose header has the same shape and no
     * footer, so that an ID3v2.2 file reaches the extractor and is refused there
     * by name (SPEC-056 amendment 3, AC24).
     *
     * @return array{version: int, flags: int, end: int, next: int}|null
     */
    public static function header(string $bytes, bool $alsoVersion2 = false): ?array
    {
        if (strlen($bytes) < self::HEADER_LENGTH || ! str_starts_with($bytes, 'ID3')) {
            return null;
        }
        $version = ord($bytes[3]);
        $sizeBytes = substr($bytes, 6, 4);
        $known = $version === 3 || $version === 4 || ($alsoVersion2 && $version === 2);
        if (! $known || preg_match('/[\x80-\xFF]/', $sizeBytes) === 1) {
            return null;
        }
        $flags = ord($bytes[5]);
        $end = self::HEADER_LENGTH + self::syncsafe($sizeBytes);

        return ['version' => $version, 'flags' => $flags, 'end' => $end, 'next' => $end + ($version === 4 && ($flags & 0x10) !== 0 ? self::HEADER_LENGTH : 0)];
    }

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
            throw $e->withStoreReached($reached);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function walk($stream, bool &$reached): ?ManifestStoreBytes
    {
        $reader = new StreamReader($stream, 'frame');
        $first = $reader->readUpTo(self::HEADER_LENGTH);
        if (FormatDetector::isMpegFrame($first) || str_starts_with($first, 'fLaC')) {
            return null;   // MPEG audio or a FLAC stream from the first byte: no tag, so no store (AC13; SPEC-057 AC2)
        }
        if (strlen($first) !== self::HEADER_LENGTH || ! str_starts_with($first, 'ID3')) {
            throw new ContainerException(sprintf('not an ID3v2 tag: expected ID3 at offset 0, found %s', Bytes::hex(substr($first, 0, 3))));
        }
        $version = ord($first[3]);
        if ($version !== 3 && $version !== 4) {
            throw new ContainerException(sprintf('ID3v2.%d is not read: only versions 3 and 4 (AC4)', $version));
        }
        $header = self::header($first);
        if ($header === null) {
            throw new ContainerException(sprintf('the ID3 tag size %s at offset 6 is not syncsafe (AC4)', Bytes::hex(substr($first, 6, 4))));
        }
        $tagEnd = $header['end'];
        $fileEnd = $reader->end();
        if ($tagEnd > $fileEnd) {
            // a fault, but one that says whether the store was there (AC5, as SPEC-003 amendment 4)
            $reached = $this->scanForStore($reader, $version, $header['flags'], $fileEnd);
            throw new ContainerException(sprintf('the ID3 tag ends at %d, past the end of the file at %d', $tagEnd, $fileEnd));
        }
        $unsynchronised = ($header['flags'] & 0x80) !== 0;
        if ($unsynchronised && ($at = $this->unsynchronisedBytesAt($stream, $tagEnd)) !== null) {
            throw new ContainerException(sprintf('the ID3 tag has the unsynchronisation flag and FF 00 at offset %d: its frames cannot be read as they stand (AC19)', $at));
        }
        $offset = self::HEADER_LENGTH;
        if (($header['flags'] & 0x40) !== 0) {
            $offset += $this->extendedHeaderLength($reader, $version, $tagEnd);
        }

        $store = null;
        $storeOffset = null;
        $storeAt = null;
        $frames = 0;
        while ($offset + self::HEADER_LENGTH <= $tagEnd) {
            $frameHeader = $reader->readExactly(self::HEADER_LENGTH, $offset, 'the frame header');
            if ($frameHeader[0] === "\0") {
                break;   // padding: the frames have ended
            }
            if (++$frames > self::MAX_FRAMES) {
                throw new ContainerException(sprintf('more than %d frames in the ID3 tag (offset %d)', self::MAX_FRAMES, $offset));
            }
            ['id' => $id, 'size' => $size, 'flags' => $frameFlags] = self::frameHeader($frameHeader, $version, $offset);
            $bodyEnd = $offset + self::HEADER_LENGTH + $size;

            $body = '';
            $isStore = false;
            if ($id === 'GEOB') {
                $body = $reader->readExactly(max(0, min($size, self::FIRST_READ, $fileEnd - $offset - self::HEADER_LENGTH)), $offset, 'the GEOB frame');
                $isStore = in_array(self::mime($body), self::MIME_TYPES, true);
            }
            if (! $isStore) {
                if ($bodyEnd > $tagEnd) {
                    break;   // a frame other than the C2PA GEOB runs past the tag: the walk stops, as c2patool's does, keeping a store already read (step 245)
                }
                $reader->skip($size - strlen($body), $offset);
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
            // v2.4: data length 0x01, unsynchronisation 0x02, encryption 0x04, compression 0x08,
            // grouping 0x40; v2.3: compression 0x80, encryption 0x40, grouping 0x20. A GEOB whose
            // group byte is really there does not match the MIME type and never reaches here
            // (AC20); one that still reads as C2PA under the flag has bytes the flag says are
            // something else, and is refused (AC22).
            $formatFlags = $version === 4 ? $frameFlags & 0x4F : $frameFlags & 0xE0;
            if ($formatFlags !== 0) {
                throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d has format flags %02X: compressed, encrypted, unsynchronised or with a data length, it is not read (AC10)', $offset, $formatFlags));
            }
            if ($unsynchronised) {
                throw new ContainerException(sprintf('the ID3 tag\'s unsynchronisation flag is set: the C2PA GEOB frame at offset %d is not read (AC10)', $offset));
            }

            // the text fields may be of any length within the frame (AC20): read on until they end,
            // each search continuing where the last one stopped (amendment 3: linear, not quadratic)
            $scan = ['from' => (int) strpos($body, "\0", 1) + 1, 'field' => 0];
            while (($objectAt = self::objectStart($body, $offset, $scan)) === null) {
                if (strlen($body) >= $size || strlen($body) > $this->maxObjectLength) {
                    throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d: its text fields do not end within the frame', $offset));
                }
                $body .= $reader->readExactly(min(self::CHUNK, $size - strlen($body)), $offset, 'the GEOB text fields');
            }
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
            $object = substr($body, $objectAt);
            unset($body);   // the text fields are not needed beside the store (step 245)
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
            $storeAt = $offset + self::HEADER_LENGTH + $objectAt;
            $offset = $bodyEnd;
        }

        if ($store === null || $storeAt === null) {
            return null;
        }

        return new ManifestStoreBytes($store, [['start' => $storeAt, 'length' => strlen($store)]]);   // the object alone, as c2patool excludes it
    }

    /**
     * A frame header's id, body size and format-flags byte. The id must be four
     * capitals or digits (AC18): anything else means the walk has lost its place.
     * A v2.4 size with a byte above 7F is not syncsafe and is read as a plain
     * integer, as iTunes writes it and c2patool reads it (AC18).
     *
     * @return array{id: string, size: int, flags: int}
     */
    private static function frameHeader(string $bytes, int $version, int $offset): array
    {
        $id = substr($bytes, 0, 4);
        if (preg_match('/\A[A-Z0-9]{4}\z/', $id) !== 1) {
            throw new ContainerException(sprintf('the frame id %s at offset %d is not four capitals or digits (AC18)', Bytes::hex($id), $offset));
        }
        $sizeBytes = substr($bytes, 4, 4);
        $size = $version === 4 && preg_match('/[\x80-\xFF]/', $sizeBytes) !== 1 ? self::syncsafe($sizeBytes) : self::uint32($sizeBytes);

        return ['id' => $id, 'size' => $size, 'flags' => ord($bytes[9])];
    }

    /**
     * The offset of the first FF 00 between offset 10 and the tag's end, read
     * straight from the stream in chunks; null when there is none, in which case
     * unsynchronisation changed nothing (AC19). The stream is left at offset 10.
     *
     * @param  resource  $stream
     */
    private function unsynchronisedBytesAt($stream, int $tagEnd): ?int
    {
        $position = self::HEADER_LENGTH;
        $carry = '';
        while ($position < $tagEnd) {
            $wanted = min(self::CHUNK, $tagEnd - $position);
            $chunk = fseek($stream, $position) === 0 ? Read::upTo($stream, max(1, $wanted)) : '';
            if (strlen($chunk) !== $wanted) {
                throw new ContainerException(sprintf('the ID3 tag could not be read at offset %d to look for unsynchronised bytes', $position));
            }
            $found = strpos($carry.$chunk, "\xFF\x00");
            if ($found !== false) {
                fseek($stream, self::HEADER_LENGTH);

                return $position - strlen($carry) + $found;
            }
            $carry = substr($chunk, -1);
            $position += strlen($chunk);
        }
        fseek($stream, self::HEADER_LENGTH);

        return null;
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
     * Whether a C2PA GEOB's header and MIME type lie between offset 10 and
     * $end, read with the walk's own frame reading, never a frame's object (AC5).
     */
    private function scanForStore(StreamReader $reader, int $version, int $flags, int $end): bool
    {
        try {
            $offset = self::HEADER_LENGTH;
            if (($flags & 0x40) !== 0) {
                $offset += $this->extendedHeaderLength($reader, $version, $end);   // as the walk does (amendment 3)
            }
            $frames = 0;
            while ($offset + self::HEADER_LENGTH <= $end && ++$frames <= self::MAX_FRAMES) {
                $frameHeader = $reader->readExactly(self::HEADER_LENGTH, $offset, 'the frame header');
                if ($frameHeader[0] === "\0") {
                    return false;
                }
                ['id' => $id, 'size' => $size] = self::frameHeader($frameHeader, $version, $offset);
                $prefix = $reader->readExactly(max(0, min($size, self::FIRST_READ, $end - $offset - self::HEADER_LENGTH)), $offset, 'the frame');
                if ($id === 'GEOB' && in_array(self::mime($prefix), self::MIME_TYPES, true)) {
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
        if (strlen($body) < 2) {
            return null;   // no MIME type can end here (AC21: strpos() past the end raised a ValueError)
        }
        $end = strpos($body, "\0", 1);

        return $end === false ? null : substr($body, 1, $end - 1);
    }

    /**
     * Where the object begins in a GEOB body: after the MIME type and the file name
     * and description in the body's text encoding (0 and 3: one NUL; 1 and 2: two,
     * on a two-byte boundary from the field's start). Null when the text fields have
     * not ended within $body; $scan then holds where to go on, so that a body read
     * in chunks is searched once in all (amendment 3).
     *
     * @param  array{from: int, field: int}  $scan  the next field's start and how many fields have ended
     */
    private static function objectStart(string $body, int $offset, array &$scan): ?int
    {
        $encoding = ord($body[0]);
        if ($encoding > 3) {
            throw new ContainerException(sprintf('the C2PA GEOB frame at offset %d has text encoding %d; only 0 to 3 exist', $offset, $encoding));
        }
        $wide = $encoding === 1 || $encoding === 2;
        while ($scan['field'] < 2) {
            $end = $wide ? self::wideNul($body, $scan['from']) : strpos($body, "\0", $scan['from']);
            if ($end === false) {
                return null;
            }
            $scan = ['from' => $end + ($wide ? 2 : 1), 'field' => $scan['field'] + 1];
        }

        return $scan['from'];
    }

    /** The first two-byte NUL at or after $from, on a two-byte boundary from $from: strpos(), then the boundary checked. */
    private static function wideNul(string $body, int $from): int|false
    {
        $at = $from;
        while (($at = strpos($body, "\0\0", $at)) !== false) {
            if (($at - $from) % 2 === 0) {
                return $at;
            }
            $at++;
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
