<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * PNG `caBX` → manifest store bytes (SPEC-002; C2PA 2.4 §A.3).
 *
 * Walks the chunks from the eight-byte signature to IEND. Every chunk that
 * is not `caBX` is skipped with fseek, its CRC unread. The one `caBX` chunk
 * is checked before its data is read (limit, minimum length), then its LBox
 * against the chunk length, then its CRC-32 against the stored one. The
 * walk continues past it so that a second `caBX` is seen and refused, as
 * c2patool does. The chunk data is the JUMBF box, whole; nothing is
 * interpreted.
 */
final readonly class PngManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = 64 * 1024 * 1024;   // as SPEC-001's DEFAULT_MAX_LBOX

    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const TYPE_CABX = 'caBX';

    private const TYPE_IEND = 'IEND';

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the PNG has no caBX chunk (AC2)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        $signature = $this->readExactly($stream, 8, 0, 'the signature');
        if ($signature !== self::SIGNATURE) {
            throw new ContainerException(sprintf(
                'not a PNG: expected %s at offset 0, found %s',
                self::hex(self::SIGNATURE),
                self::hex($signature),
            ));
        }

        $store = null;
        $storeOffset = null;

        while (true) {
            $offset = $this->tell($stream);
            $header = fread($stream, 8);
            if ($header === false || strlen($header) !== 8) {
                throw new ContainerException(sprintf(
                    'unexpected end of file: a chunk header was expected at offset %d, got %d byte(s)',
                    $offset,
                    $header === false ? 0 : strlen($header),
                ));
            }
            /** @var array{length: int, type: string} $chunk */
            $chunk = unpack('Nlength/a4type', $header);

            if ($chunk['type'] === self::TYPE_IEND) {
                break;
            }
            if ($chunk['type'] !== self::TYPE_CABX) {
                // Data and CRC of a chunk we do not need: never read (AC8, AC9 —
                // where the chunk sits is not this layer's concern).
                $this->skip($stream, $chunk['length'] + 4, $offset);

                continue;
            }

            if ($storeOffset !== null) {
                throw new ContainerException(sprintf(
                    'two caBX chunks at offsets %d and %d; a PNG carries at most one manifest store',
                    $storeOffset,
                    $offset,
                ));
            }
            if ($chunk['length'] > $this->maxChunkLength) {
                throw new ContainerException(sprintf(
                    'caBX chunk length %d exceeds the limit of %d bytes (offset %d)',
                    $chunk['length'],
                    $this->maxChunkLength,
                    $offset,
                ));
            }
            if ($chunk['length'] < self::BOX_HEADER_LENGTH) {
                throw new ContainerException(sprintf(
                    'caBX chunk length %d is shorter than the %d-byte box header (offset %d)',
                    $chunk['length'],
                    self::BOX_HEADER_LENGTH,
                    $offset,
                ));
            }

            // LBox first, on its own: it is the first four bytes of the data and
            // must equal the chunk length before the rest is worth reading (AC7,
            // AC10) — and before the CRC, which a wrong length field also breaks.
            $lBoxBytes = $this->readExactly($stream, 4, $offset, 'LBox');
            /** @var array{1: int} $lBox */
            $lBox = unpack('N', $lBoxBytes);
            if ($lBox[1] !== $chunk['length']) {
                throw new ContainerException(sprintf(
                    'LBox %d differs from the chunk length %d (caBX chunk at offset %d)',
                    $lBox[1],
                    $chunk['length'],
                    $offset,
                ));
            }

            $data = $lBoxBytes.$this->readExactly($stream, $chunk['length'] - 4, $offset, 'the data');
            /** @var array{1: int} $stored */
            $stored = unpack('N', $this->readExactly($stream, 4, $offset, 'the CRC'));
            $computed = crc32(self::TYPE_CABX.$data);
            if ($stored[1] !== $computed) {
                throw new ContainerException(sprintf(
                    'caBX chunk at offset %d: stored CRC %08X, computed %08X',
                    $offset,
                    $stored[1],
                    $computed,
                ));
            }

            $store = $data;
            $storeOffset = $offset;
        }

        return $store === null ? null : new ManifestStoreBytes($store);
    }

    /**
     * Reads exactly $length bytes or throws; fewer bytes means the file ends
     * inside the caBX chunk that starts at $offset (AC4).
     *
     * @param  resource  $stream
     */
    private function readExactly($stream, int $length, int $offset, string $what): string
    {
        if ($length === 0) {
            return '';
        }
        if ($length < 0) {
            throw new \LogicException(sprintf('negative read length %d', $length));
        }
        $bytes = fread($stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new ContainerException(sprintf(
                'unexpected end of file while reading %s of the caBX chunk at offset %d: wanted %d bytes, got %d',
                $what,
                $offset,
                $length,
                $bytes === false ? 0 : strlen($bytes),
            ));
        }

        return $bytes;
    }

    /**
     * Skips a chunk's data and CRC without reading them. A seek past the end
     * of the file succeeds, so the end is looked up: a file that ends inside
     * the chunk is an error naming this chunk; one that ends exactly after
     * it is left to the loop, which then reports the missing next header
     * (AC14) — the two are different faults.
     *
     * @param  resource  $stream
     */
    private function skip($stream, int $length, int $offset): void
    {
        $target = $this->tell($stream) + $length;
        if (fseek($stream, 0, SEEK_END) !== 0) {
            throw new ContainerException(sprintf('cannot seek to the end of the file after the chunk at offset %d', $offset));
        }
        $end = $this->tell($stream);
        if ($end < $target) {
            throw new ContainerException(sprintf(
                'unexpected end of file inside the chunk at offset %d: it ends at %d, the file at %d',
                $offset,
                $target,
                $end,
            ));
        }
        if (fseek($stream, $target, SEEK_SET) !== 0) {
            throw new ContainerException(sprintf('cannot skip %d bytes of the chunk at offset %d', $length, $offset));
        }
    }

    /** @param resource $stream */
    private function tell($stream): int
    {
        $position = ftell($stream);
        if ($position === false) {
            throw new ContainerException('the stream is not seekable');
        }

        return $position;
    }

    /** Bytes as upper-case hex pairs — never raw: file contents are untrusted terminal output. */
    private static function hex(string $bytes): string
    {
        return trim(strtoupper(chunk_split(bin2hex($bytes), 2, ' ')));
    }
}
