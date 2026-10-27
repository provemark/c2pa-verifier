<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Timestamp\TimestampEvidence;
use Provemark\C2paVerifier\Trust\AnchorEvidence;

/**
 * What one manifest's verdict rested on (SPEC-082): the time its signer was
 * judged at and where that time came from — a trusted time-stamp's genTime,
 * the caller's validation time, or the clock (C2PA 2.4 §15.8.2; SPEC-073) —
 * and what made its signer and its time-stamp authority trusted, or null
 * where they were not. Each manifest has its own: an ingredient manifest is
 * judged at its own time (SPEC-021). Its certificates are the signer's
 * `x5chain` exactly as the file carries them, leaf first (C2PA 2.4 §14.5;
 * SPEC-083), whether or not X.509 could read them; its time-stamps every
 * token offered for it, and what became of each (SPEC-084).
 */
final readonly class ManifestEvidence
{
    public const TIMESTAMP = 'timestamp';

    public const GIVEN = 'given';

    public const CLOCK = 'clock';

    /**
     * @param  int  $judgedAt  seconds since the epoch
     * @param  string  $timeSource  self::TIMESTAMP, self::GIVEN or self::CLOCK
     * @param  AnchorEvidence|null  $signer  set exactly when the signature has signingCredential.trusted
     * @param  AnchorEvidence|null  $tsa  set exactly when the time-stamp was trusted and gave the time
     * @param  list<string>  $certificates  DER, x5chain's order, the leaf first; empty when the COSE reader refused the signature
     * @param  list<TimestampEvidence>  $timestamps  the header's tokens, then the assertions', in the order tried
     */
    public function __construct(
        public string $label,
        public int $judgedAt,
        public string $timeSource,
        public ?AnchorEvidence $signer,
        public ?AnchorEvidence $tsa,
        public array $certificates = [],
        public array $timestamps = [],
    ) {}
}
