<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Report;

/**
 * The status codes of C2PA 2.4 §15.2.2 this verifier can emit, verbatim
 * (SPEC-010; SPEC-011 adds the three assertion.hashedURI / undeclared
 * codes). No word of our own: a case enters here only through the spec
 * that emits it. Success, informational and failure are the table's three
 * kinds; of the codes below claimSignature.validated and
 * assertion.hashedURI.match are the successes.
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
    case AssertionHashedUriMatch = 'assertion.hashedURI.match';
    case AssertionHashedUriMismatch = 'assertion.hashedURI.mismatch';
    case AssertionUndeclared = 'assertion.undeclared';
    case GeneralError = 'general.error';

    public function isSuccess(): bool
    {
        return $this === self::ClaimSignatureValidated || $this === self::AssertionHashedUriMatch;
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
