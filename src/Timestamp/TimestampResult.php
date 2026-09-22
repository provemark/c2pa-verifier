<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * What the timestamp check found (SPEC-017): whether a header was there,
 * its timeStamp.* statuses in the order judged, the token's time when the
 * imprint matched (for signature_info.time), and whether the TSA was
 * trusted. `trustedTime()` is the one thing that reaches the verdict: the
 * epoch SPEC-015 judges the signer's validity at — only a validated
 * *and* trusted timestamp supplies it (C2PA 2.4 §14.6.1).
 */
final readonly class TimestampResult
{
    /**
     * @param  list<ValidationStatus>  $statuses
     * @param  int|null  $time  genTime when validated, else null
     * @param  string|null  $timeFraction  genTime's fractional-second digits, for signature_info.time (SPEC-017 amendment 2)
     */
    public function __construct(
        public bool $present,
        public array $statuses,
        public ?int $time,
        public bool $trusted,
        public ?string $timeFraction = null,
    ) {}

    /** The time as c2patool prints it: ISO 8601, UTC, the token's own fraction digits. */
    public function timeIso(): ?string
    {
        if ($this->time === null) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s', $this->time).($this->timeFraction === null ? '' : '.'.$this->timeFraction).'+00:00';
    }

    /** No sigTst / sigTst2 header at all. */
    public static function none(): self
    {
        return new self(false, [], null, false);
    }

    /** The epoch the signer's certificate validity is judged at: the timestamp's when validated and trusted, else null (= now). */
    public function trustedTime(): ?int
    {
        return $this->trusted ? $this->time : null;
    }
}
