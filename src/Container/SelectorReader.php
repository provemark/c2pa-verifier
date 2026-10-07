<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * Reads a text stream in pieces for SPEC-060: finds the next `U+FEFF` and
 * decodes the variation selectors after it, one byte each (C2PA 2.4 §A.8),
 * straight from their UTF-8: `U+FE00`–`U+FE0F` are `EF B8 80`–`EF B8 8F`
 * (bytes 0–15), `U+E0100`–`U+E01EF` are `F3 A0 84 80`–`F3 A0 87 AF`
 * (bytes 16–255). Anything else ends a run and is not consumed. At most one
 * piece and the few bytes of a split sequence are held.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class SelectorReader
{
    private string $buffer = '';

    /** The next byte to read in $buffer. */
    private int $at = 0;

    /** The file offset of $buffer[0]. */
    private int $base = 0;

    private bool $end = false;

    /**
     * @param  resource  $stream  positioned at 0
     * @param  positive-int  $piece
     */
    public function __construct(
        private $stream,
        private readonly int $piece,
    ) {}

    /** The file offset of the next byte. */
    public function offset(): int
    {
        return $this->base + $this->at;
    }

    /** The offset of the next $marker, positioned after it; null at the end of the stream. */
    public function nextMarker(string $marker): ?int
    {
        while (true) {
            $found = strpos($this->buffer, $marker, $this->at);
            if ($found !== false) {
                $this->at = $found + strlen($marker);

                return $this->base + $found;
            }
            if ($this->end) {
                $this->at = strlen($this->buffer);

                return null;
            }
            // keep what could be the start of a marker split over two pieces
            $this->at = max($this->at, strlen($this->buffer) - (strlen($marker) - 1));
            $this->fill(strlen($marker));
        }
    }

    /** Up to $count bytes decoded from the selectors that follow; fewer when the run ends first. */
    public function selectors(int $count): string
    {
        $bytes = '';
        while (strlen($bytes) < $count && ($byte = $this->selector()) !== null) {
            $bytes .= chr($byte);
        }

        return $bytes;
    }

    /** Passes over up to $max selectors; the number passed. */
    public function skipSelectors(int $max): int
    {
        $count = 0;
        while ($count < $max && $this->selector() !== null) {
            $count++;
        }

        return $count;
    }

    /**
     * The byte of the selector at the current position, consumed; null, and nothing consumed, when there is none.
     *
     * @return int<0, 255>|null
     */
    private function selector(): ?int
    {
        $this->fill(4);
        $b = $this->buffer;
        $i = $this->at;
        $left = strlen($b) - $i;
        if ($left >= 3 && $b[$i] === "\xEF" && $b[$i + 1] === "\xB8" && ord($b[$i + 2]) >= 0x80 && ord($b[$i + 2]) <= 0x8F) {
            $this->at += 3;

            return ord($b[$i + 2]) - 0x80;
        }
        if ($left >= 4 && $b[$i] === "\xF3" && $b[$i + 1] === "\xA0") {
            $third = ord($b[$i + 2]);
            $fourth = ord($b[$i + 3]);
            if ($third >= 0x84 && $third <= 0x87 && $fourth >= 0x80 && $fourth <= 0xBF) {
                $codePoint = 0xE0000 | (($third & 0x3F) << 6) | ($fourth & 0x3F);
                if ($codePoint >= 0xE0100 && $codePoint <= 0xE01EF) {
                    $this->at += 4;

                    return $codePoint - 0xE0100 + 16;
                }
            }
        }

        return null;
    }

    /** Reads pieces until $need bytes are buffered after the position or the stream ends; drops what was read. */
    private function fill(int $need): void
    {
        while (! $this->end && strlen($this->buffer) - $this->at < $need) {
            $more = fread($this->stream, $this->piece);
            if ($more === false || $more === '') {
                $this->end = true;

                break;
            }
            $this->base += $this->at;
            $this->buffer = substr($this->buffer, $this->at).$more;
            $this->at = 0;
        }
    }
}
