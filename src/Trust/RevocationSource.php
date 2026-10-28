<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * Where revocation data from outside the file comes from (SPEC-086, ADR-0007).
 *
 * The verifier asks a source about each CA between the anchor and the signer
 * on a path that reaches an anchor (SPEC-087; a revoked CA makes the signer
 * untrusted), and then about the signer when it is trusted and its stored
 * OCSP responses prove nothing (SPEC-086; C2PA 2.4 §15.9.2). It is asked at
 * most once for each certificate and issuer in one verification, whichever
 * manifest of the store needs the answer (SPEC-086 amendment 6). What it returns is untrusted input: it is read and judged
 * exactly as a stapled response. This package ships no source that opens a connection; a caller
 * that wants online checks provides one, and chooses what it costs in privacy.
 *
 * A caller that wants no source gives none: the `Verifier`'s default, NoRevocationSource, is never asked and
 * changes nothing. A source of the caller's that answers null was asked, and the signer's status says so
 * (`signingCredential.ocsp.inaccessible`; SPEC-086 AC4, amendment 5).
 */
interface RevocationSource
{
    /**
     * An OCSP response about $subject, or null.
     *
     * @param  string  $subject  DER of the certificate asked about: the signer, or a CA of its path
     * @param  string  $issuer  DER of the certificate above it in the path the chain walk verified — for the signer, the
     *                          anchor itself when the anchor issued it; never a certificate merely carried in x5chain
     * @param  list<string>  $urls  the OCSP responder urls of $subject's AIA (http or https, visible ASCII only, at most four)
     * @return string|null DER of an OCSP response
     */
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string;
}
