<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Report;

/**
 * The status codes of C2PA 2.4 §15.2.2 this verifier can emit, verbatim
 * (SPEC-010). No word of our own: a case enters here only through the spec
 * that emits it. Success, informational and failure are the table's three
 * kinds; of the codes below only claimSignature.validated is a success.
 */
enum StatusCode: string
{
    case ClaimSignatureValidated = 'claimSignature.validated';
    case ClaimSignatureMismatch = 'claimSignature.mismatch';
    case ClaimSignatureMissing = 'claimSignature.missing';
    case AlgorithmUnsupported = 'algorithm.unsupported';
    case SigningCredentialInvalid = 'signingCredential.invalid';
    case ClaimMissing = 'claim.missing';
    case ClaimMultiple = 'claim.multiple';
    case ClaimCborInvalid = 'claim.cbor.invalid';
    case ClaimMalformed = 'claim.malformed';
    case AssertionJsonInvalid = 'assertion.json.invalid';
    case AssertionMissing = 'assertion.missing';
    case GeneralError = 'general.error';

    public function isSuccess(): bool
    {
        return $this === self::ClaimSignatureValidated;
    }

    public function isInformational(): bool
    {
        return false;   // none of the codes above; M6's timeStamp.* will be the first
    }

    public function isFailure(): bool
    {
        return ! $this->isSuccess() && ! $this->isInformational();
    }
}
