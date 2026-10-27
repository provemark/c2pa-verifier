<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Report\StatusCode;

/**
 * One OCSP response offered for a manifest's signer, and what became of it
 * (SPEC-085): stapled in the claim signature's unprotected `rVals` header, or
 * held by a `c2pa.certificate-status` assertion (C2PA 2.4 §14, §15.9, §18.19),
 * or fetched from the caller's revocation source (SPEC-086, ADR-0007). The
 * response is the bytes the file or the source gave. It was chosen when the signer was
 * judged by it, not chosen when it was usable and another was, unusable when
 * it could not be read, was about another certificate or did not verify under
 * a responder tied to the signer's issuer, and not tried when a revoked answer
 * before it had ended the check. What it says — the status and its times
 * (RFC 6960 §4.2) — is read only from a usable one.
 */
final readonly class OcspEvidence
{
    public const RVALS = 'rVals';

    public const ASSERTION = 'assertion';

    public const FETCHED = 'fetched';

    public const CHOSEN = 'chosen';

    public const NOT_CHOSEN = 'notChosen';

    public const UNUSABLE = 'unusable';

    public const NOT_TRIED = 'notTried';

    /**
     * @param  string  $source  self::RVALS, self::ASSERTION or self::FETCHED
     * @param  string  $url  the claim signature's for a stapled or fetched response, the assertion's for an assertion's
     * @param  string  $response  DER, as the file carries it or the source returned it
     * @param  string  $outcome  self::CHOSEN, self::NOT_CHOSEN, self::UNUSABLE or self::NOT_TRIED
     * @param  StatusCode|null  $code  the signingCredential.ocsp.* code the chosen response gave; null for every other
     * @param  string|null  $certStatus  'good', 'revoked' or 'unknown', for a chosen or not-chosen response
     * @param  int|null  $producedAt  seconds since the epoch, for a chosen or not-chosen response
     * @param  int|null  $thisUpdate  seconds since the epoch, for a chosen or not-chosen response
     * @param  int|null  $nextUpdate  seconds since the epoch; null also when the response has none
     */
    public function __construct(
        public string $source,
        public string $url,
        public string $response,
        public string $outcome,
        public ?StatusCode $code,
        public ?string $certStatus,
        public ?int $producedAt,
        public ?int $thisUpdate,
        public ?int $nextUpdate,
    ) {}
}
