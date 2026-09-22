<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

/**
 * COSE carries an ECDSA signature as R‖S (RFC 8152 §8.1); OpenSSL wants
 * DER `SEQUENCE { INTEGER r, INTEGER s }` (RFC 3279 §2.2.3). The
 * conversion, with the two places it goes wrong: an INTEGER is minimal —
 * leading zeros stripped, one added back when the high bit is set — and a
 * SEQUENCE above 127 bytes needs the long-form length (P-521; SPEC-009
 * step 19).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class EcdsaSignature
{
    /** DER for an R‖S of exactly 2 × $curveBytes; null for any other length. */
    public static function toDer(string $rs, int $curveBytes): ?string
    {
        if (strlen($rs) !== 2 * $curveBytes) {
            return null;
        }
        $body = self::integer(substr($rs, 0, $curveBytes)).self::integer(substr($rs, $curveBytes));

        return "\x30".self::length(strlen($body)).$body;
    }

    private static function integer(string $bytes): string
    {
        $minimal = ltrim($bytes, "\0");
        if ($minimal === '') {
            $minimal = "\0";
        }
        if ((ord($minimal[0]) & 0x80) !== 0) {
            $minimal = "\0".$minimal;
        }

        return "\x02".self::length(strlen($minimal)).$minimal;
    }

    /** A DER length: one byte up to 127, else 0x80 | n followed by n big-endian bytes. */
    private static function length(int $n): string
    {
        if ($n < 128) {
            return pack('C', $n);
        }
        $bytes = ltrim(pack('N', $n), "\0");

        return pack('C', 0x80 | strlen($bytes)).$bytes;
    }
}
