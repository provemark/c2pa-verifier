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

    /** Base 16 → base 10 on strings: no gmp, no bcmath — a serial may be 20 bytes (SPEC-015; shared with the DER reader, SPEC-016). */
    public static function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        $digits = [0];   // little-endian base-10 digits
        foreach (str_split($hex) as $char) {
            $carry = (int) hexdec($char);
            foreach ($digits as $i => $d) {
                $value = $d * 16 + $carry;
                $digits[$i] = $value % 10;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }

        return implode('', array_reverse($digits));
    }

    /** Short text (a time string, an OID) as itself when every byte is printable ASCII and it is short, otherwise as hex. */
    public static function printableText(string $bytes): string
    {
        return preg_match('/\A[\x20-\x7E]{0,64}\z/', $bytes) === 1 ? $bytes : self::hex($bytes);
    }

    /** A four-byte type as text when every byte is printable ASCII, otherwise as hex. */
    public static function printable(string $bytes): string
    {
        return preg_match('/\A[\x20-\x7E]{4}\z/', $bytes) === 1 ? $bytes : self::hex($bytes);
    }
}
