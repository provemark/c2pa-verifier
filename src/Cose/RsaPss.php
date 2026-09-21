<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

/**
 * RSASSA-PSS verification for an ordinary rsaEncryption key (SPEC-009):
 * `openssl_verify` would do PKCS#1 v1.5 for such a key, so the signature
 * is undone with a raw RSA operation and the encoded message checked with
 * EMSA-PSS-VERIFY (RFC 8017 §9.1.2), MGF1 over the same hash, salt length
 * = hash length (RFC 8230 §2). Read against cose-lib's PSSRSA.php.
 */
final class RsaPss
{
    public static function verify(string $message, string $signature, \OpenSSLAsymmetricKey $key, string $hash, int $modBits): bool
    {
        $k = intdiv($modBits + 7, 8);
        if (strlen($signature) !== $k) {
            return false;
        }
        $em = '';
        $ok = OpenSsl::quiet(static function () use ($signature, &$em, $key): bool {
            return openssl_public_decrypt($signature, $em, $key, OPENSSL_NO_PADDING);
        });
        if (! $ok || ! is_string($em)) {
            return false;
        }
        // RFC 8017 §8.1.2 step 2.c: emLen = ceil((modBits − 1) / 8); OpenSSL returns k octets.
        $emLen = intdiv($modBits - 1 + 7, 8);
        if ($emLen < strlen($em)) {
            if (ltrim(substr($em, 0, strlen($em) - $emLen), "\0") !== '') {
                return false;
            }
            $em = substr($em, -$emLen);
        }

        return self::emsaPssVerify($message, $em, $modBits - 1, $hash);
    }

    /** RFC 8017 §9.1.2 with sLen = hLen. */
    private static function emsaPssVerify(string $message, string $em, int $emBits, string $hash): bool
    {
        $mHash = hash($hash, $message, true);
        $hLen = strlen($mHash);
        $sLen = $hLen;
        $emLen = intdiv($emBits + 7, 8);
        if (strlen($em) !== $emLen || $emLen < $hLen + $sLen + 2) {
            return false;
        }
        if ($em[$emLen - 1] !== "\xbc") {
            return false;
        }
        $maskedDb = substr($em, 0, $emLen - $hLen - 1);
        $h = substr($em, $emLen - $hLen - 1, $hLen);
        $topBits = 8 * $emLen - $emBits;
        if ($topBits > 0 && (ord($maskedDb[0]) >> (8 - $topBits)) !== 0) {
            return false;
        }
        $db = $maskedDb ^ self::mgf1($h, $emLen - $hLen - 1, $hash);
        if ($topBits > 0) {
            $db[0] = chr(ord($db[0]) & (0xFF >> $topBits));
        }
        $psLen = $emLen - $hLen - $sLen - 2;
        if (substr($db, 0, $psLen) !== str_repeat("\0", $psLen) || $db[$psLen] !== "\x01") {
            return false;
        }
        $salt = substr($db, $psLen + 1, $sLen);
        $h2 = hash($hash, str_repeat("\0", 8).$mHash.$salt, true);

        return hash_equals($h, $h2);
    }

    private static function mgf1(string $seed, int $length, string $hash): string
    {
        $out = '';
        for ($counter = 0; strlen($out) < $length; $counter++) {
            $out .= hash($hash, $seed.pack('N', $counter), true);
        }

        return substr($out, 0, $length);
    }
}
