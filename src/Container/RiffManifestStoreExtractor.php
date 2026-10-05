<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\Bytes;
use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * RIFF `C2PA` → manifest store bytes: the walk shared by every RIFF form this
 * verifier reads (SPEC-003 for WebP, SPEC-055 for WAV; C2PA 2.4 §A.3.7 puts
 * every RIFF form's store in the same chunk). The form type and its name are
 * given by the caller.
 *
 * Strict about the `C2PA` chunk, as lenient as c2patool about the rest
 * (SPEC-003 amendment 3). A header size larger than the file is refused
 * before any chunk is read; a smaller one ends the walk where the RIFF chunk
 * ends, and the bytes after it are left to the data hash, which covers them.
 * Every chunk but `C2PA` is skipped unread, its pad byte too, unchecked, and
 * missing where the RIFF chunk ends. The one `C2PA` chunk is checked before
 * its data is read (limit, minimum, overrun), then its LBox against the chunk
 * length, then its own pad byte, which must be there and zero; the pad byte
 * is not part of the store. The walk continues past it so that a second
 * `C2PA` is seen and refused — c2patool takes the first silently; this
 * verifier does not choose. Every fault says whether a `C2PA` chunk header
 * had been read (`ContainerException::$storeReached`).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class RiffManifestStoreExtractor
{
    // SPEC-024: 16 MiB, not 64. Measured in step 66 over 212 corpus stores: median
    // 45 kB, p90 241 kB, largest ever met 3.36 MB. A store at this bound peaks at
    // 38 MB (step 67b), which a 64 MB host survives; the old 64 MiB needed 132 MB and
    // ended a 128 MB host with a fatal error. Same figure in SPEC-001 and SPEC-002.
    public const DEFAULT_MAX_CHUNK_LENGTH = 16 * 1024 * 1024;

    private const RIFF = 'RIFF';

    private const TYPE_C2PA = 'C2PA';

    /** RIFF (4) + size (4) + form type (4). */
    private const HEADER_LENGTH = 12;

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    /**
     * @param  string  $form  the form type at offset 8, four bytes (`WEBP`)
     * @param  string  $name  the format's name in messages (`WebP`)
     */
    public function __construct(
        private string $form,
        private string $name,
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the file has no C2PA chunk (SPEC-003 AC2)
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
            ), storeReached: false);
        }
        if (strlen($header) !== self::HEADER_LENGTH) {
            throw new ContainerException(sprintf('unexpected end of file inside the %d-byte RIFF header', self::HEADER_LENGTH), storeReached: false);
        }
        $form = substr($header, 8, 4);
        if ($form !== $this->form) {
            throw new ContainerException(sprintf(
                'not a %s: expected form type %s at offset 8, found %s',
                $this->name,
                $this->form,
                Bytes::printable($form),
            ), storeReached: false);
        }

        // The size field says where the RIFF chunk ends. A size that promises more
        // than the file holds is a truncated file: one error naming both numbers,
        // before any chunk is read (SPEC-003 AC5, AC16). A size that promises less
        // is not the container's concern: the walk ends where the RIFF chunk ends,
        // and the bytes after it are left to the data hash, which covers them
        // (SPEC-003 amendment 3, AC17). The length comes from a seek, not a read.
        /** @var array{1: int} $size */
        $size = unpack('V', $header, 4);
        try {
            $fileEnd = $reader->end();
        } catch (ContainerException $e) {
            // a stream that cannot be measured is a fault before any C2PA chunk (SPEC-003 AC18, step 217)
            throw new ContainerException($e->getMessage(), previous: $e, storeReached: false);
        }
        if ($size[1] > $fileEnd - 8) {
            throw new ContainerException(sprintf(
                'RIFF size %d in the header, %d bytes in the file after it',
                $size[1],
                $fileEnd - 8,
            ), storeReached: false);
        }
        $end = 8 + $size[1];
        $where = $end === $fileEnd ? 'the file' : 'the RIFF chunk';

        $store = null;
        $storeOffset = null;
        // whether a C2PA chunk header has been read: a fault before it is not a fault
        // in a manifest (SPEC-003 AC18, SPEC-013 amendment 16)
        $reached = false;

        // The walk's own faults, and StreamReader's inside it, are thrown with the
        // default flag; this one place sets it from what the walk had seen.
        try {
            $this->walk($reader, $end, $where, $store, $storeOffset, $reached);
        } catch (ContainerException $e) {
            throw $e->storeReached === $reached ? $e : new ContainerException($e->getMessage(), previous: $e, storeReached: $reached);
        }

        if ($store === null || $storeOffset === null) {
            return null;
        }

        return new ManifestStoreBytes($store, [['start' => $storeOffset, 'length' => 8 + strlen($store)]]);   // FourCC, size, data; the pad byte is hashed (step 23)
    }

    /**
     * The chunks from offset 12 to $end, the end of the RIFF chunk.
     *
     * @param-out string|null $store
     * @param-out int|null $storeOffset
     */
    private function walk(StreamReader $reader, int $end, string $where, ?string &$store, ?int &$storeOffset, bool &$reached): void
    {
        while ($reader->tell() < $end) {
            $offset = $reader->tell();
            if ($end - $offset < 8) {
                throw new ContainerException(sprintf(
                    'a chunk header at offset %d runs past the end of %s at %d',
                    $offset,
                    $where,
                    $end,
                ));
            }
            $chunkHeader = $reader->readExactly(8, $offset, 'the chunk header');
            /** @var array{type: string, length: int} $chunk */
            $chunk = unpack('a4type/Vlength', $chunkHeader);
            $padded = $chunk['length'] & 1;
            $isStore = $chunk['type'] === self::TYPE_C2PA;
            $reached = $reached || $isStore;

            if ($offset + 8 + $chunk['length'] > $end) {
                throw new ContainerException(sprintf(
                    '%s chunk at offset %d declares %d bytes, past the end of %s at %d',
                    Bytes::printable($chunk['type']),
                    $offset,
                    $chunk['length'],
                    $where,
                    $end,
                ));
            }

            if (! $isStore) {
                // Where the chunk sits is not this layer's concern (SPEC-003 AC8). Its
                // pad byte is skipped unchecked, and may be missing where the RIFF chunk
                // ends, as c2patool reads it (SPEC-003 amendment 3, AC12).
                $reader->skip($chunk['length'], $offset);
                if ($padded === 1 && $offset + 8 + $chunk['length'] < $end) {
                    $reader->skip(1, $offset);
                }

                continue;
            }

            if ($storeOffset !== null) {
                throw new ContainerException(sprintf(
                    'two C2PA chunks at offsets %d and %d; a %s carries at most one manifest store',
                    $storeOffset,
                    $offset,
                    $this->name,
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
            // inside the box although RIFF is little-endian around it (SPEC-003 AC9, AC10).
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

            if ($padded === 1) {
                $this->readPad($reader, $offset + 8 + $chunk['length'], $end, $where);
            }
        }
    }

    /**
     * The pad byte after the odd-length C2PA chunk: present inside the RIFF
     * chunk and zero, as RIFF requires (SPEC-003 AC12, narrowed to the store's
     * own chunk by amendment 3). Not part of any chunk's data.
     */
    private function readPad(StreamReader $reader, int $offset, int $end, string $where): void
    {
        $pad = $offset < $end ? $reader->readUpTo(1) : '';
        if ($pad === '') {
            throw new ContainerException(sprintf('pad byte expected at offset %d, but %s ends there', $offset, $where));
        }
        if ($pad !== "\0") {
            throw new ContainerException(sprintf('pad byte at offset %d is %s, not 00', $offset, Bytes::hex($pad)));
        }
    }
}
