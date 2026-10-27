<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * Where revocation data from outside the file comes from (SPEC-086, ADR-0007).
 *
 * The verifier asks a source only for a trusted signer whose stored OCSP
 * responses prove nothing (C2PA 2.4 §15.9.2), once per judged manifest. What
 * it returns is untrusted input: it is read and judged exactly as a stapled
 * response. This package ships no source that opens a connection; a caller
 * that wants online checks provides one, and chooses what it costs in privacy.
 */
interface RevocationSource
{
    /**
     * An OCSP response about $subject, or null.
     *
     * @param  string  $subject  DER of the signer's certificate
     * @param  string  $issuer  DER of its issuer
     * @param  list<string>  $urls  the OCSP responder urls of $subject's AIA (http or https, at most four)
     * @return string|null DER of an OCSP response
     */
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string;
}
