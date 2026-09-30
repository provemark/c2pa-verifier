<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Tests\Support;

use RuntimeException;

/**
 * A stream wrapper for SPEC-050's tests: it serves a real file, but each
 * read returns at most a few bytes, as a stream that is not a plain file may
 * (PHP's fread() makes one read of up to the chunk size there). Seek, tell
 * and stat behave as the file does. With a stall offset it stops giving
 * bytes there without ever reporting the end of the file.
 *
 * Open with ShortReadStream::open($path) or ::stalling($path, $at).
 */
final class ShortReadStream
{
    public const string PROTOCOL = 'spec050short';

    /** A small odd number, so reads also end inside headers. */
    public const int CHUNK = 7;

    /** Reads that got nothing past the stall; a loop that never stops fails here instead of hanging. */
    private const int STALLED_READS_ALLOWED = 10_000;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $file;

    private ?int $stallAt = null;

    private int $stalledReads = 0;

    /** @return resource */
    public static function open(string $path)
    {
        return self::fopen($path, null);
    }

    /** @return resource a stream that gives nothing from $at on and never says it has ended */
    public static function stalling(string $path, int $at)
    {
        return self::fopen($path, $at);
    }

    /** @return resource */
    private static function fopen(string $path, ?int $stallAt)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $context = stream_context_create([self::PROTOCOL => ['stall' => $stallAt]]);
        $stream = fopen(self::PROTOCOL.'://'.$path, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException("cannot open {$path} through the short-read wrapper");
        }

        return $stream;
    }

    public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool
    {
        $file = fopen(substr($url, strlen(self::PROTOCOL) + 3), 'rb');
        if ($file === false) {
            return false;
        }
        $this->file = $file;
        $options = is_resource($this->context) ? stream_context_get_options($this->context) : [];
        $ours = $options[self::PROTOCOL] ?? null;
        $stall = is_array($ours) ? ($ours['stall'] ?? null) : null;
        $this->stallAt = is_int($stall) ? $stall : null;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (! is_resource($this->file)) {
            return false;
        }
        $length = min($count, self::CHUNK);
        if ($this->stallAt !== null) {
            $left = $this->stallAt - (int) ftell($this->file);
            if ($left <= 0) {
                if (++$this->stalledReads > self::STALLED_READS_ALLOWED) {
                    throw new RuntimeException('the reader kept asking a stalled stream for bytes');
                }

                return '';
            }
            $length = min($length, $left);
        }
        if ($length < 1) {
            return '';
        }

        return fread($this->file, $length);
    }

    public function stream_eof(): bool
    {
        if (! is_resource($this->file)) {
            return true;
        }

        return $this->stallAt === null && feof($this->file);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return is_resource($this->file) && fseek($this->file, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return is_resource($this->file) ? (int) ftell($this->file) : 0;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return is_resource($this->file) ? fstat($this->file) : false;
    }

    public function stream_close(): void
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
        $this->file = null;
    }
}
