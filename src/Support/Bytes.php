<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Support;

/**
 * How bytes from a file reach a message: never raw. File contents are
 * untrusted terminal output until proven otherwise (SPEC-004 AC5, SPEC-005
 * AC12). A leaf layer every other layer may use.
 */
final class Bytes
{
    /** Upper-case hex pairs separated by single spaces; '(nothing)' for ''. */
    public static function hex(string $bytes): string
    {
        return $bytes === '' ? '(nothing)' : trim(strtoupper(chunk_split(bin2hex($bytes), 2, ' ')));
    }

    /** A four-byte type as text when every byte is printable ASCII, otherwise as hex. */
    public static function printable(string $bytes): string
    {
        return preg_match('/\A[\x20-\x7E]{4}\z/', $bytes) === 1 ? $bytes : self::hex($bytes);
    }
}
