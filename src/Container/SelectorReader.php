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
    /**
     * A selector's UTF-8: `U+FE00`–`U+FE0F` (`EF B8 80`–`8F`), `U+E0100`–`U+E01EF`
     * (`F3 A0 84 80` to `F3 A0 87 AF`).
     */
    private const SELECTOR = '\xEF\xB8[\x80-\x8F]|\xF3\xA0[\x84-\x86][\x80-\xBF]|\xF3\xA0\x87[\x80-\xAF]';

    /** The bytes whose selector is three bytes of UTF-8 (`U+FE00`–`U+FE0F`), as count_chars() keys. */
    private const LOW = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0];

    /** @var array<string, string>|null each selector's UTF-8 to its byte, built once */
    private static ?array $bytes = null;

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
        return $this->run($count, true)[1];
    }

    /** Passes over up to $max selectors; the number passed. Nothing is kept. */
    public function skipSelectors(int $max): int
    {
        return $this->run($max, false)[0];
    }

    /**
     * Consumes up to $max selectors from the current position, a stretch of the
     * buffer at a time: one anchored expression finds the stretch, one strtr()
     * translates it (SPEC-060 amendment 2 A; one selector at a time cost about
     * 0.5 µs). Returns how many selectors were consumed and, when $keep, their
     * bytes. A selector split over two pieces is completed from the stream
     * before it is judged.
     *
     * @return array{int, string}
     */
    private function run(int $max, bool $keep): array
    {
        $done = 0;
        $bytes = '';
        while ($done < $max) {
            $want = $max - $done;
            $this->fill(max(4, min(4 * $want, $this->piece)));   // at least one whole selector, whatever the piece
            // possessive: a long run as a backtracking repeat exhausts PCRE's JIT stack
            $found = preg_match('/\G(?:'.self::SELECTOR.')++/', $this->buffer, $match, 0, $this->at);
            if ($found === false) {
                throw new ContainerException(sprintf('the selectors at offset %d could not be read: %s', $this->offset(), preg_last_error_msg()));
            }
            if ($found === 0) {
                break;   // no selector here: the run has ended
            }
            $decoded = strtr($match[0], self::bytesOf());
            $length = strlen($match[0]);
            if (strlen($decoded) > $want) {
                // keep the first $want: bytes 0–15 are three bytes of UTF-8, the others four
                $decoded = substr($decoded, 0, $want);
                $length = 4 * $want - array_sum(array_intersect_key(count_chars($decoded, 1), self::LOW));
            }
            $this->at += $length;
            $done += strlen($decoded);
            if ($keep) {
                $bytes .= $decoded;
            }
            if ($done >= $max || $this->end || strlen($this->buffer) - $this->at >= 4) {
                break;   // enough, or the run ended inside what was buffered rather than at its edge
            }
        }

        return [$done, $bytes];
    }

    /** @return array<string, string> each selector's UTF-8 to its byte */
    private static function bytesOf(): array
    {
        if (self::$bytes === null) {
            $map = [];
            for ($b = 0; $b < 256; $b++) {
                $map[(string) mb_chr($b <= 15 ? 0xFE00 + $b : 0xE0100 + $b - 16, 'UTF-8')] = chr($b);
            }
            self::$bytes = $map;
        }

        return self::$bytes;
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
