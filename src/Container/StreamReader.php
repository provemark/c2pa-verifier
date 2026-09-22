<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * The one way the Container layer touches a stream (SPEC-004). Reads
 * exactly what is asked or fails naming the segment or chunk it was inside
 * of; skips without reading; never returns a partial result. Truncation is
 * decided by the file's end, not by probing: a file that ends inside the
 * skipped bytes is this reader's error, one that ends exactly after them
 * is the caller's next read to report.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class StreamReader
{
    /**
     * @param  resource  $stream  a readable, seekable stream
     * @param  string  $noun  what the containers call their unit: "segment", "chunk"
     */
    public function __construct(private mixed $stream, private string $noun)
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('StreamReader needs a stream resource');
        }
    }

    /**
     * Exactly $length bytes, or a ContainerException naming $what and the
     * segment or chunk at $offset. Zero bytes is '' without a read (fread
     * with a length of 0 throws in PHP 8).
     */
    public function readExactly(int $length, int $offset, string $what): string
    {
        if ($length === 0) {
            return '';
        }
        if ($length < 0) {
            throw new \LogicException(sprintf('negative read length %d', $length));
        }
        $bytes = fread($this->stream, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new ContainerException(sprintf(
                'unexpected end of file while reading %s of the %s at offset %d: wanted %d bytes, got %d',
                $what,
                $this->noun,
                $offset,
                $length,
                $bytes === false ? 0 : strlen($bytes),
            ));
        }

        return $bytes;
    }

    /**
     * Up to $length bytes — fewer at the end of the file, '' when nothing is
     * left. For callers that want to see a short read and name the fault
     * themselves (a header that is not there, a pad byte that is missing).
     */
    public function readUpTo(int $length): string
    {
        if ($length <= 0) {
            throw new \LogicException(sprintf('readUpTo needs a positive length, got %d', $length));
        }
        $bytes = fread($this->stream, $length);

        return $bytes === false ? '' : $bytes;
    }

    /**
     * Forward $length bytes without reading them. A seek past the end of a
     * file succeeds, so the end is looked up: inside the skipped bytes is an
     * error naming the segment or chunk at $offset; exactly at their end is
     * not — the caller's next read reports what is missing.
     */
    public function skip(int $length, int $offset): void
    {
        if ($length === 0) {
            return;
        }
        if ($length < 0) {
            throw new \LogicException(sprintf('negative skip length %d', $length));
        }
        $target = $this->tell() + $length;
        $end = $this->end();
        if ($end < $target) {
            throw new ContainerException(sprintf(
                'unexpected end of file inside the %s at offset %d: it ends at %d, the file at %d',
                $this->noun,
                $offset,
                $target,
                $end,
            ));
        }
        if (fseek($this->stream, $target, SEEK_SET) !== 0) {
            throw new ContainerException(sprintf('cannot skip %d bytes of the %s at offset %d', $length, $this->noun, $offset));
        }
    }

    public function tell(): int
    {
        $position = ftell($this->stream);
        if ($position === false) {
            throw new ContainerException('the stream is not seekable');
        }

        return $position;
    }

    /** The file's length; the position is unchanged afterwards. */
    public function end(): int
    {
        $position = $this->tell();
        if (fseek($this->stream, 0, SEEK_END) !== 0) {
            throw new ContainerException('cannot seek to the end of the stream');
        }
        $end = $this->tell();
        if (fseek($this->stream, $position, SEEK_SET) !== 0) {
            throw new ContainerException('cannot seek back after measuring the stream');
        }

        return $end;
    }
}
