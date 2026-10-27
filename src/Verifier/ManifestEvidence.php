<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Verifier;

use Provemark\C2paVerifier\Trust\AnchorEvidence;

/**
 * What one manifest's verdict rested on (SPEC-082): the time its signer was
 * judged at and where that time came from — a trusted time-stamp's genTime,
 * the caller's validation time, or the clock (C2PA 2.4 §15.8.2; SPEC-073) —
 * and what made its signer and its time-stamp authority trusted, or null
 * where they were not. Each manifest has its own: an ingredient manifest is
 * judged at its own time (SPEC-021).
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
     */
    public function __construct(
        public string $label,
        public int $judgedAt,
        public string $timeSource,
        public ?AnchorEvidence $signer,
        public ?AnchorEvidence $tsa,
    ) {}
}
