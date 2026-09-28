<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Asn1\Asn1Exception;
use Provemark\C2paVerifier\Asn1\DerReader;
use Provemark\C2paVerifier\Asn1\TagClass;
use Provemark\C2paVerifier\Support\Bytes;

/**
 * An RSA key's public exponent, and whether it is one (SPEC-049). With e = 1 a signature is its own
 * public-key image, so a PS256 claim verifies without the private key; c2pa-rs 0.91.1 refuses e < 3,
 * an even e and a negative e (PR #2712), and RFC 8017 §3.1 defines e as odd and at least 3.
 *
 * The exponent is read from the key's own subjectPublicKeyInfo rather than from
 * openssl_pkey_get_details(), which reports no RSA details for an id-RSASSA-PSS key (SPEC-049
 * amendment 3): both algorithms carry RSAPublicKey ::= SEQUENCE { modulus, publicExponent }
 * (RFC 8017 appendix A.1.1; RFC 4055 §1.2).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class RsaExponent
{
    /** The publicExponent's content octets, or null when the key does not hold an RSAPublicKey. */
    public static function fromSubjectPublicKeyInfo(string $spki): ?string
    {
        try {
            $parts = (new DerReader)->read($spki)->sequence();
            if (count($parts) !== 2 || ! $parts[1]->is(TagClass::Universal, 3) || ($parts[1]->contents[0] ?? null) !== "\0") {
                return null;
            }
            $rsaPublicKey = (new DerReader)->read(substr($parts[1]->contents, 1))->sequence();
            if (count($rsaPublicKey) !== 2) {
                return null;
            }
            $rsaPublicKey[0]->integerBytes();

            return $rsaPublicKey[1]->integerBytes();
        } catch (Asn1Exception) {
            return null;
        }
    }

    /** Why $exponent (INTEGER content octets) is no RSA public exponent, or null when it is one. */
    public static function fault(?string $exponent): ?string
    {
        if ($exponent === null || $exponent === '') {
            return 'the RSA public exponent could not be read from the key (SPEC-049)';
        }
        if (ord($exponent[0]) >= 0x80) {
            return 'a negative RSA public exponent; RFC 8017 §3.1 requires an odd exponent of at least 3 (SPEC-049)';
        }
        $value = ltrim($exponent, "\0");
        $tooSmall = $value === '' || (strlen($value) === 1 && ord($value) < 3);
        $even = $value === '' || (ord($value[strlen($value) - 1]) & 1) === 0;
        if (! $tooSmall && ! $even) {
            return null;
        }
        $shown = $value === '' ? '0' : (strlen($value) <= 8 ? Bytes::hexToDecimal(bin2hex($value)) : '0x'.bin2hex($value));

        return sprintf('RSA public exponent %s; RFC 8017 §3.1 requires an odd exponent of at least 3 (SPEC-049)', $shown);
    }
}
