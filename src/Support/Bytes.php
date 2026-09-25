<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Support;

/**
 * How bytes from a file reach a message: never raw. File contents are
 * untrusted terminal output until proven otherwise (SPEC-004 AC5, SPEC-005
 * AC12). A leaf layer every other layer may use.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class Bytes
{
    /** Upper-case hex pairs separated by single spaces; '(nothing)' for ''. */
    public static function hex(string $bytes): string
    {
        return $bytes === '' ? '(nothing)' : trim(strtoupper(chunk_split(bin2hex($bytes), 2, ' ')));
    }

    /**
     * The longest INTEGER, in octets of magnitude, that is converted to decimal (SPEC-016 amendment 5,
     * SPEC-015 amendment 6). Real files carry at most 20 (RFC 5280); c2patool accepts a 200-octet
     * certificate serial. The conversion is quadratic, so a longer value is refused, not converted.
     */
    public const MAX_DECIMAL_OCTETS = 256;

    /** The octets of magnitude a hex string holds, leading zeros ignored. */
    public static function decimalOctets(string $hex): int
    {
        return intdiv(strlen(ltrim($hex, '0')) + 1, 2);
    }

    /**
     * Base 16 → base 10 on strings: no gmp, no bcmath. Seven hex digits at a time over limbs of 10^9
     * (16^7 · 10^9 stays inside a 64-bit integer); the callers check MAX_DECIMAL_OCTETS first, with
     * their own exception, and this guard only keeps an unchecked caller from hanging.
     *
     * @throws \LengthException past MAX_DECIMAL_OCTETS
     */
    public static function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        if (self::decimalOctets($hex) > self::MAX_DECIMAL_OCTETS) {
            throw new \LengthException(sprintf('an integer of %d octets; at most %d are converted to decimal', self::decimalOctets($hex), self::MAX_DECIMAL_OCTETS));
        }
        $head = strlen($hex) % 7;
        $chunks = $head > 0 ? [substr($hex, 0, $head), ...str_split(substr($hex, $head), 7)] : str_split($hex, 7);
        $limbs = [0];   // little-endian, base 10^9
        foreach ($chunks as $chunk) {
            $multiplier = 16 ** strlen($chunk);
            $carry = (int) hexdec($chunk);
            foreach ($limbs as $i => $limb) {
                $value = $limb * $multiplier + $carry;
                $limbs[$i] = $value % 1_000_000_000;
                $carry = intdiv($value, 1_000_000_000);
            }
            while ($carry > 0) {
                $limbs[] = $carry % 1_000_000_000;
                $carry = intdiv($carry, 1_000_000_000);
            }
        }
        $decimal = (string) array_pop($limbs);
        foreach (array_reverse($limbs) as $limb) {
            $decimal .= str_pad((string) $limb, 9, '0', STR_PAD_LEFT);
        }

        return $decimal;
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
