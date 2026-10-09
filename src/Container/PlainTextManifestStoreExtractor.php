<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

use Provemark\C2paVerifier\Support\MemoryBudget;

/**
 * Plain text `C2PATextManifestWrapper` → manifest store bytes (SPEC-060;
 * C2PA 2.4 §A.8). Opt-in: the verifier uses it only when the caller turns
 * text on, and only for a stream no other format claims (SPEC-060 open
 * questions 1 and 2).
 *
 * The wrapper is `U+FEFF` followed by a run of variation selectors, one per
 * byte: 0–15 as `U+FE00`–`U+FE0F`, 16–255 as `U+E0100`–`U+E01EF`. The run
 * decodes to the magic `C2PATXT\0`, a version, a big-endian 32-bit length,
 * the store and padding. Every marker is a candidate; one whose run holds
 * the magic and version 1 is a wrapper, any other is text, as c2pa-rs reads
 * it (AC3, AC4, AC7). A wrapper's store must fit its run and its LBox must
 * equal the length field, and a text carries at most one wrapper: both
 * stricter than c2pa-rs, named (AC5, AC6). The padding is not judged; it is
 * inside the range, which runs from the marker to the end of the run, the
 * exclusion c2pa-rs writes (AC9). Nothing is normalised (open question 5):
 * the bytes are hashed as they are.
 *
 * The stream is read in pieces of 64 KiB and selectors are decoded from the
 * UTF-8 bytes, so a long text costs one piece of memory, and a store costs
 * its own length, within the bound and the budget (AC12).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class PlainTextManifestStoreExtractor
{
    /** SPEC-024's bound on a manifest store, as every container's. */
    public const DEFAULT_MAX_STORE_LENGTH = 16 * 1024 * 1024;

    /** The stream is read in pieces of this size. */
    public const PIECE = 65536;

    /** `U+FEFF` in UTF-8: the marker in front of a wrapper. */
    private const MARKER = "\xEF\xBB\xBF";

    private const MAGIC = "C2PATXT\0";

    private const VERSION = 1;

    /** The status codes of C2PA 2.4 A.8.7.1, verbatim (amendment 3). */
    private const CORRUPTED_WRAPPER = 'manifest.text.corruptedWrapper';

    private const MULTIPLE_WRAPPERS = 'manifest.text.multipleWrappers';

    /** Magic (8), version (1), length (4). */
    private const HEADER_LENGTH = 13;

    /** LBox (4) + TBox (4): the least a JUMBF box can be. */
    private const BOX_HEADER_LENGTH = 8;

    public function __construct(
        public int $maxStoreLength = self::DEFAULT_MAX_STORE_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * Whether the whole stream is valid UTF-8 (AC10), read in pieces; a sequence
     * split over two pieces is judged whole.
     *
     * @param  resource  $stream  a readable, seekable stream
     */
    public function isText($stream): bool
    {
        if (fseek($stream, 0) !== 0) {
            return false;
        }
        $carry = '';
        while (true) {
            $piece = fread($stream, self::PIECE);
            if ($piece === false) {
                return false;
            }
            if ($piece === '') {
                return $carry === '';
            }
            $data = $carry.$piece;
            $cut = self::completeLength($data);
            if (! mb_check_encoding(substr($data, 0, $cut), 'UTF-8')) {
                return false;
            }
            $carry = substr($data, $cut);
        }
    }

    /**
     * @param  resource  $stream  a readable, seekable stream; whether it is UTF-8 is isText()'s to say
     * @return ManifestStoreBytes|null null when the text holds no wrapper of version 1
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes
    {
        // whether a wrapper of version 1 had been found when a fault was thrown (SPEC-060 Scope)
        $reached = false;
        try {
            return $this->scan($stream, $reached);
        } catch (ContainerException $e) {
            throw $e->withStoreReached($reached);
        }
    }

    /** @param  resource  $stream */
    private function scan($stream, bool &$reached): ?ManifestStoreBytes
    {
        if (fseek($stream, 0) !== 0) {
            throw new ContainerException('cannot seek to the start of the text');
        }
        $text = new SelectorReader($stream, self::PIECE);
        $found = null;
        while (($marker = $text->nextMarker(self::MARKER)) !== null) {
            $header = $text->selectors(self::HEADER_LENGTH);
            if (strlen($header) < self::HEADER_LENGTH || ! str_starts_with($header, self::MAGIC)) {
                continue;   // a lone mark, an emoji's selector, another magic: text (AC3)
            }
            if (ord($header[8]) !== self::VERSION) {
                // the magic with another version: a wrapper, corrupted (C2PA 2.4 §15.12.1.3; amendment 3, AC4, AC7)
                $reached = true;
                throw self::corrupted(sprintf('a C2PA text wrapper of version %d at offset %d; this verifier reads version %d', ord($header[8]), $marker, self::VERSION));
            }
            if ($found !== null) {
                $reached = true;
                throw new ContainerException(sprintf('two C2PA text wrappers at offsets %d and %d; a text carries at most one manifest store', $found->ranges[0]['start'], $marker), statusCode: self::MULTIPLE_WRAPPERS);
            }
            $reached = true;
            $found = $this->wrapper($text, $marker, $header);
        }
        if ($found === null) {
            return null;
        }
        /** @var array{1: int} $lBox */
        $lBox = unpack('N', $found->bytes);
        if ($lBox[1] !== strlen($found->bytes)) {
            throw self::corrupted(sprintf('the wrapper\'s length field declares %d bytes, but its store\'s LBox is %d (offset %d)', strlen($found->bytes), $lBox[1], $found->ranges[0]['start']));
        }

        return $found;
    }

    /** The store after a header of the magic and version 1, and the range from the marker to the end of its run. */
    private function wrapper(SelectorReader $text, int $marker, string $header): ManifestStoreBytes
    {
        /** @var array{1: int} $length */
        $length = unpack('N', substr($header, 9, 4));
        $declared = $length[1];
        if ($declared > $this->maxStoreLength) {
            throw new ContainerException(sprintf('the wrapper declares a store of %d bytes, over the limit of %d bytes (offset %d)', $declared, $this->maxStoreLength, $marker));
        }
        if ($declared < self::BOX_HEADER_LENGTH) {
            throw self::corrupted(sprintf('the wrapper declares a store of %d bytes, shorter than the %d-byte box header (offset %d)', $declared, self::BOX_HEADER_LENGTH, $marker));
        }
        if (! $this->budget->allows($declared + self::PIECE)) {
            throw new ContainerException(sprintf(
                'the C2PA text store, %d bytes, does not fit this host: %d bytes of memory remain. '
                .'The file was not examined, so this is not a judgement about it (offset %d)',
                $declared,
                $this->budget->remainingBytes() ?? 0,
                $marker,
            ));
        }
        $store = $text->selectors($declared);
        if (strlen($store) < $declared) {
            throw self::corrupted(sprintf('the wrapper declares a store of %d bytes, but its run holds %d after the header (offset %d)', $declared, strlen($store), $marker));
        }
        // the padding: not judged, but counted, so a run cannot grow without end (AC12)
        $limit = self::HEADER_LENGTH + $this->maxStoreLength;
        $padding = $text->skipSelectors($limit + 1);
        if ($padding > $limit) {
            throw new ContainerException(sprintf('the wrapper\'s padding runs past %d selectors, the limit for a store of at most %d bytes (offset %d)', $limit, $this->maxStoreLength, $marker));
        }

        return new ManifestStoreBytes($store, [['start' => $marker, 'length' => $text->offset() - $marker]]);
    }

    /**
     * A wrapper found but corrupted: its version or length (C2PA 2.4 §15.12.1.3, A.8.7.1; amendment 3). A limit of
     * this verifier's own (store size, memory, padding) is no corruption and stays `general.error`.
     */
    private static function corrupted(string $message): ContainerException
    {
        return new ContainerException($message, statusCode: self::CORRUPTED_WRAPPER);
    }

    /** The length of $data up to the last complete UTF-8 sequence; what follows may continue in the next piece. */
    private static function completeLength(string $data): int
    {
        $length = strlen($data);
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($data[$length - $back]);
            if ($byte < 0x80) {
                return $length;   // ASCII: complete
            }
            if ($byte >= 0xC0) {
                $needs = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $back < $needs ? $length - $back : $length;
            }
        }

        return $length;   // continuation bytes only: mb_check_encoding judges them
    }
}
