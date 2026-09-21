<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Report\StatusCode;

/**
 * Thrown when the boxes and CBOR do not add up to a manifest (SPEC-007
 * AC8–AC14), carrying the C2PA 2.4 §15 code for the fault where it is
 * found (SPEC-007 amendment 1, defined in SPEC-010): claim.missing,
 * claim.multiple, claim.cbor.invalid, claim.malformed,
 * claimSignature.missing, assertion.missing, assertion.json.invalid — and
 * general.error where §15 has no word. Never a partial store.
 */
final class ManifestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly StatusCode $status = StatusCode::GeneralError,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
