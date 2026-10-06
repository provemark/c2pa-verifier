<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * GIF `C2PA_GIF` Application Extension → manifest store bytes (SPEC-059;
 * C2PA 2.4 §A.3.8).
 *
 * The blocks between the global colour table and the first image descriptor
 * are walked: each extension is an introducer `0x21`, a label, then sub-blocks
 * of a size byte and up to 255 bytes, to a `00` (GIF89a §15); the fixed block
 * of a graphic control, plain text or application extension is its first
 * sub-block, and a comment has none (SPEC-059 amendment 1 A). The walk stops at the first image descriptor (`0x2C`) or the trailer
 * (`0x3B`), as c2patool does not look further (AC9): image data is never
 * read. The store is the Application Extension with block size 11, identifier
 * `C2PA_GIF` and version `01 00 00`, its sub-blocks joined; its range is the
 * whole block, the exclusion c2patool writes (AC8). A block of another version
 * or identifier is skipped like any other extension (AC2, AC3).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class GifManifestStoreExtractor
{
    /** SPEC-024's bound on a manifest store, as every container's. */
    public const DEFAULT_MAX_STORE_LENGTH = 16 * 1024 * 1024;

    /** Blocks before the first image: more is a fault, as ISOBMFF's boxes and RIFF's chunks (AC10). */
    public const MAX_BLOCKS = 4096;

    /** Header (6) and logical screen descriptor (7). */
    private const HEAD_LENGTH = 13;

    private const APPLICATION = 0xFF;

    /** An Application Extension's block: identifier (8) and authentication code (3). */
    private const APPLICATION_BLOCK_SIZE = 11;

    private const IDENTIFIER = 'C2PA_GIF';

    /** The authentication code as the block's version: 1.0 (§A.3.8). */
    private const VERSION = "\x01\x00\x00";

    /** Sub-blocks are read in pieces of this size and walked in memory (amendment 1 B). */
    private const PIECE = 65536;

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    public function __construct(
        public int $maxStoreLength = self::DEFAULT_MAX_STORE_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when no `C2PA_GIF` block of version 1.0 precedes the first image
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        // whether a C2PA_GIF block of version 1.0 had been read when a fault was thrown
        // (SPEC-059 open question 3; SPEC-013 amendments 16 to 18)
        $reached = false;
        try {
            return $this->walk($stream, $reached);
        } catch (ContainerException $e) {
            throw $e->withStoreReached($reached);
        }
    }

    /** @param  resource  $stream */
    private function walk($stream, bool &$reached): ?ManifestStoreBytes
    {
        if (fseek($stream, 0) !== 0) {
            throw new ContainerException('cannot seek to the start of the GIF');
        }
        $reader = new StreamReader($stream, 'block');
        $head = $reader->readExactly(self::HEAD_LENGTH, 0, 'the header and screen descriptor');
        if (! str_starts_with($head, 'GIF87a') && ! str_starts_with($head, 'GIF89a')) {
            throw new ContainerException('not a GIF: the file does not start with GIF87a or GIF89a');
        }
        $flags = ord($head[10]);
        if (($flags & 0x80) !== 0) {
            $reader->skip(3 * (2 ** (($flags & 0x07) + 1)), 10);   // the global colour table
        }

        $store = null;
        $storeOffset = null;
        $storeEnd = null;
        $blockOffset = null;
        $blocks = 0;
        while (true) {
            $offset = $reader->tell();
            $introducer = $reader->readUpTo(1);
            if ($introducer === '' || $introducer === "\x2C" || $introducer === "\x3B") {
                break;   // the end of the file, the first image, or the trailer: nothing after it is read (AC9)
            }
            if ($introducer !== "\x21") {
                throw new ContainerException(sprintf('unexpected byte 0x%02x at offset %d: not an extension, an image or the trailer', ord($introducer), $offset));
            }
            if (++$blocks > self::MAX_BLOCKS) {
                throw new ContainerException(sprintf('more than %d blocks before the first image (offset %d)', self::MAX_BLOCKS, $offset));
            }
            $label = ord($reader->readExactly(1, $offset, 'the extension label'));
            if ($label !== self::APPLICATION) {
                $this->subBlocks($stream, $reader, $offset, false, $reached);   // every sub-block, the fixed one too (amendment 1 A)

                continue;
            }
            $size = ord($reader->readExactly(1, $offset, 'the block size'));
            if ($size !== self::APPLICATION_BLOCK_SIZE) {
                throw new ContainerException(sprintf('an Application Extension\'s block size is %d, not %d (offset %d)', $size, self::APPLICATION_BLOCK_SIZE, $offset));
            }
            $block = $reader->readExactly(self::APPLICATION_BLOCK_SIZE, $offset, 'the identifier and authentication code');
            if (substr($block, 0, 8) !== self::IDENTIFIER || substr($block, 8, 3) !== self::VERSION) {
                $this->subBlocks($stream, $reader, $offset, false, $reached);   // another application, or another version of this block (AC3)

                continue;
            }
            if ($blockOffset !== null) {
                $first = $reader->readUpTo(1);
                $reached = $reached || ($first !== '' && $first !== "\x00");   // the second block holds data (amendment 1 C)
                throw new ContainerException(sprintf('two C2PA_GIF blocks at offsets %d and %d; a GIF carries at most one manifest store', $blockOffset, $offset));
            }
            $blockOffset = $offset;   // an empty block counts as one too (amendment 1 C)
            $payload = $this->subBlocks($stream, $reader, $offset, true, $reached);
            if ($payload === '') {
                continue;   // a C2PA_GIF block that holds nothing is no store (AC2)
            }
            $store = $payload;
            $storeOffset = $offset;
            $storeEnd = $reader->tell();
        }

        if ($store === null || $storeOffset === null || $storeEnd === null) {
            return null;
        }
        if (strlen($store) < self::BOX_HEADER_LENGTH) {
            throw new ContainerException(sprintf('the C2PA_GIF store is %d bytes, shorter than the %d-byte box header (offset %d)', strlen($store), self::BOX_HEADER_LENGTH, $storeOffset));
        }
        /** @var array{1: int} $lBox */
        $lBox = unpack('N', $store);
        if ($lBox[1] !== strlen($store)) {
            throw new ContainerException(sprintf('LBox %d differs from the %d bytes of the C2PA_GIF store (offset %d)', $lBox[1], strlen($store), $storeOffset));
        }

        return new ManifestStoreBytes($store, [['start' => $storeOffset, 'length' => $storeEnd - $storeOffset]]);
    }

    /**
     * The data sub-blocks that follow, to their `00` terminator: joined when $keep,
     * within the store bound and the memory budget (AC10); passed over otherwise.
     * They are read in pieces of up to 64 KiB and walked in memory, so a run of
     * 1-byte sub-blocks costs neither a read each nor a string each (amendment 1
     * B); the stream is left just after the terminator. $reached turns true
     * at the first byte of data kept (amendment 1 C).
     *
     * @param  resource  $stream
     */
    private function subBlocks($stream, StreamReader $reader, int $offset, bool $keep, bool &$reached): string
    {
        $joined = '';
        $budgetFrom = 0;             // the budget is asked again once the store passes this length
        $buffer = '';
        $at = 0;                     // the next size byte in $buffer
        $base = $reader->tell();     // the file offset of $buffer[0]
        while (true) {
            if ($at >= strlen($buffer) || $at + 1 + ord($buffer[$at]) > strlen($buffer)) {
                $more = $reader->readUpTo(self::PIECE);
                if ($more === '') {
                    throw new ContainerException(sprintf('unexpected end of file inside the block at offset %d', $offset));
                }
                $base += $at;
                $buffer = substr($buffer, $at).$more;
                $at = 0;

                continue;
            }
            $size = ord($buffer[$at]);
            if ($size === 0) {
                if (fseek($stream, $base + $at + 1) !== 0) {
                    throw new ContainerException(sprintf('cannot seek past the block at offset %d', $offset));
                }

                return $joined;
            }
            if ($keep) {
                $reached = true;
                $total = strlen($joined) + $size;
                if ($total > $this->maxStoreLength) {
                    throw new ContainerException(sprintf('the C2PA_GIF store exceeds the limit of %d bytes (offset %d)', $this->maxStoreLength, $offset));
                }
                if ($total > $budgetFrom && ! $this->budget->allows($total + self::PIECE)) {
                    throw new ContainerException(sprintf(
                        'the C2PA_GIF store, %d bytes so far, does not fit this host: %d bytes of memory remain. '
                        .'The file was not examined, so this is not a judgement about it (offset %d)',
                        $total,
                        $this->budget->remainingBytes() ?? 0,
                        $offset,
                    ));
                }
                if ($total > $budgetFrom) {
                    $budgetFrom = $total + self::PIECE;   // asked once per 64 KiB of store, for that much ahead
                }
                $joined .= substr($buffer, $at + 1, $size);
            }
            $at += 1 + $size;
        }
    }
}
