<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

use Provemark\C2paVerifier\Report\StatusCode;

/**
 * Thrown when the signature box is not the COSE_Sign1_Tagged structure C2PA
 * 2.4 §13.2 requires (SPEC-008), or when the signature cannot be verified
 * (SPEC-009), carrying the §15 code (SPEC-008/009 amendment 1, defined in
 * SPEC-010): algorithm.unsupported for an alg this installation cannot
 * verify, signingCredential.invalid for a key or chain that is not
 * acceptable, general.error for a structural fault. A named fault — never
 * "the signature does not verify".
 */
final class CoseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly StatusCode $status = StatusCode::GeneralError,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
