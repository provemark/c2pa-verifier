<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\Bytes;

/**
 * PNG `caBX` → manifest store bytes (SPEC-002; C2PA 2.4 §A.3).
 *
 * Walks the chunks from the eight-byte signature to IEND. Every chunk that
 * is not `caBX` is skipped unread, CRC included. The one `caBX` chunk
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
        $reader = new StreamReader($stream, 'chunk');
        $signature = $reader->readExactly(8, 0, 'the signature');
        if ($signature !== self::SIGNATURE) {
            throw new ContainerException(sprintf(
                'not a PNG: expected %s at offset 0, found %s',
                Bytes::hex(self::SIGNATURE),
                Bytes::hex($signature),
            ));
        }

        $store = null;
        $storeOffset = null;

        while (true) {
            $offset = $reader->tell();
            $header = $reader->readUpTo(8);
            if (strlen($header) !== 8) {
                throw new ContainerException(sprintf(
                    'unexpected end of file: a chunk header was expected at offset %d, got %d byte(s)',
                    $offset,
                    strlen($header),
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
                $reader->skip($chunk['length'] + 4, $offset);

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
            $lBoxBytes = $reader->readExactly(4, $offset, 'LBox');
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

            $data = $lBoxBytes.$reader->readExactly($chunk['length'] - 4, $offset, 'the data');
            /** @var array{1: int} $stored */
            $stored = unpack('N', $reader->readExactly(4, $offset, 'the CRC'));
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

        if ($store === null || $storeOffset === null) {
            return null;
        }

        return new ManifestStoreBytes($store, [['start' => $storeOffset, 'length' => 12 + strlen($store)]]);   // length, type, data, CRC
    }
}
