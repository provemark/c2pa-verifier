<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Report\StatusCode;

/**
 * Thrown for a timestamp header or token this layer does not read
 * (SPEC-016 AC6–AC9): a header of the wrong shape, a response that was
 * not granted, a token that breaks RFC 3161 / RFC 5652's own rules, and
 * every DER fault underneath, wrapped with what was being read. SPEC-017
 * maps it to `timeStamp.malformed`; one thrown with the code UNTRUSTED to
 * `timeStamp.untrusted`, where C2PA 2.4 §15.8.2 names that code (SPEC-088).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class TimestampException extends \RuntimeException
{
    /** The exception code of a fault §15.8.2 reports as timeStamp.untrusted (SPEC-088). */
    public const UNTRUSTED = 1;

    /** The code this fault is reported under. */
    public function statusCode(): StatusCode
    {
        return $this->getCode() === self::UNTRUSTED ? StatusCode::TimeStampUntrusted : StatusCode::TimeStampMalformed;
    }
}
