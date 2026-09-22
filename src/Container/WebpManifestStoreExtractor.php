<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * WebP RIFF `C2PA` → manifest store bytes (SPEC-003; C2PA 2.4 §A.3).
 *
 * Reads the twelve-byte header, checks the RIFF size against the file
 * length before anything else, then walks the chunks to that end. Every
 * chunk but `C2PA` is skipped unread; after an odd-length chunk the pad
 * byte is read and must be zero. The one `C2PA` chunk is checked before its
 * data is read (limit, minimum, overrun), then its LBox against the chunk
 * length; the pad byte is not part of the store. The walk continues past
 * it so that a second `C2PA` is seen and refused — c2patool takes the
 * first silently; this verifier does not choose.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class WebpManifestStoreExtractor
{
    // SPEC-024: 16 MiB, not 64. Measured in step 66 over 212 corpus stores: median
    // 45 kB, p90 241 kB, largest ever met 3.36 MB. A store at this bound peaks at
    // 38 MB (step 67b), which a 64 MB host survives; the old 64 MiB needed 132 MB and
    // ended a 128 MB host with a fatal error. Same figure in SPEC-001 and SPEC-002.
    public const DEFAULT_MAX_CHUNK_LENGTH = 16 * 1024 * 1024;

    private const RIFF = 'RIFF';

    private const FORM_WEBP = 'WEBP';

    private const TYPE_C2PA = 'C2PA';

    /** RIFF (4) + size (4) + form type (4). */
    private const HEADER_LENGTH = 12;

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the WebP has no C2PA chunk (AC2)
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        $reader = new StreamReader($stream, 'chunk');
        $header = $reader->readUpTo(self::HEADER_LENGTH);
        if (strlen($header) < 4 || substr($header, 0, 4) !== self::RIFF) {
            throw new ContainerException(sprintf(
                'not a RIFF file: expected RIFF at offset 0, found %s',
                Bytes::hex(substr($header, 0, 4)),
            ));
        }
        if (strlen($header) !== self::HEADER_LENGTH) {
            throw new ContainerException(sprintf('unexpected end of file inside the %d-byte RIFF header', self::HEADER_LENGTH));
        }
        $form = substr($header, 8, 4);
        if ($form !== self::FORM_WEBP) {
            throw new ContainerException(sprintf(
                'not a WebP: expected form type WEBP at offset 8, found %s',
                Bytes::printable($form),
            ));
        }

        // The size field promises the file length minus 8: check it first, so
        // a truncated or padded file is one error naming both numbers (AC5,
        // AC16). The length comes from a seek, not a read.
        /** @var array{1: int} $size */
        $size = unpack('V', $header, 4);
        $end = $reader->end();
        if ($size[1] !== $end - 8) {
            throw new ContainerException(sprintf(
                'RIFF size %d in the header, %d bytes in the file after it',
                $size[1],
                $end - 8,
            ));
        }

        $store = null;
        $storeOffset = null;

        while ($reader->tell() < $end) {
            $offset = $reader->tell();
            $chunkHeader = $reader->readExactly(8, $offset, 'the chunk header');
            /** @var array{type: string, length: int} $chunk */
            $chunk = unpack('a4type/Vlength', $chunkHeader);
            $padded = $chunk['length'] & 1;

            if ($offset + 8 + $chunk['length'] > $end) {
                throw new ContainerException(sprintf(
                    '%s chunk at offset %d declares %d bytes, past the end of the file at %d',
                    Bytes::printable($chunk['type']),
                    $offset,
                    $chunk['length'],
                    $end,
                ));
            }

            if ($chunk['type'] !== self::TYPE_C2PA) {
                // Where the chunk sits is not this layer's concern (AC8).
                $reader->skip($chunk['length'], $offset);
            } else {
                if ($storeOffset !== null) {
                    throw new ContainerException(sprintf(
                        'two C2PA chunks at offsets %d and %d; a WebP carries at most one manifest store',
                        $storeOffset,
                        $offset,
                    ));
                }
                if ($chunk['length'] <= $this->maxChunkLength && ! $this->budget->allows($chunk['length'])) {
                    throw new ContainerException(sprintf(
                        'C2PA chunk length %d does not fit this host: %d bytes of memory remain. '
                        .'The file was not examined, so this is not a judgement about it (offset %d)',
                        $chunk['length'],
                        $this->budget->remainingBytes() ?? 0,
                        $offset,
                    ));
                }
                if ($chunk['length'] > $this->maxChunkLength) {
                    throw new ContainerException(sprintf(
                        'C2PA chunk length %d exceeds the limit of %d bytes (offset %d)',
                        $chunk['length'],
                        $this->maxChunkLength,
                        $offset,
                    ));
                }
                if ($chunk['length'] < self::BOX_HEADER_LENGTH) {
                    throw new ContainerException(sprintf(
                        'C2PA chunk length %d is shorter than the %d-byte box header (offset %d)',
                        $chunk['length'],
                        self::BOX_HEADER_LENGTH,
                        $offset,
                    ));
                }

                // LBox first, on its own: the first four bytes of the data, big-endian
                // inside the box although RIFF is little-endian around it (AC9, AC10).
                $lBoxBytes = $reader->readExactly(4, $offset, 'LBox');
                /** @var array{1: int} $lBox */
                $lBox = unpack('N', $lBoxBytes);
                if ($lBox[1] !== $chunk['length']) {
                    throw new ContainerException(sprintf(
                        'LBox %d differs from the chunk length %d (C2PA chunk at offset %d)',
                        $lBox[1],
                        $chunk['length'],
                        $offset,
                    ));
                }

                $store = $lBoxBytes.$reader->readExactly($chunk['length'] - 4, $offset, 'the data');
                $storeOffset = $offset;
            }

            if ($padded === 1) {
                $this->readPad($reader, $offset + 8 + $chunk['length']);
            }
        }

        if ($store === null || $storeOffset === null) {
            return null;
        }

        return new ManifestStoreBytes($store, [['start' => $storeOffset, 'length' => 8 + strlen($store)]]);   // FourCC, size, data; the pad byte is hashed (step 23)
    }

    /**
     * The pad byte after an odd-length chunk: present and zero, as RIFF
     * requires (AC12). Not part of any chunk's data.
     */
    private function readPad(StreamReader $reader, int $offset): void
    {
        $pad = $reader->readUpTo(1);
        if ($pad === '') {
            throw new ContainerException(sprintf('pad byte expected at offset %d, but the file ends there', $offset));
        }
        if ($pad !== "\0") {
            throw new ContainerException(sprintf('pad byte at offset %d is %s, not 00', $offset, Bytes::hex($pad)));
        }
    }
}
