<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Report;

/**
 * The status codes of C2PA 2.4 §15.2.2 this verifier can emit, verbatim
 * (SPEC-010; SPEC-011 adds the three assertion.hashedURI / undeclared
 * codes, SPEC-012 the six of the data hash, SPEC-014 the two of the
 * signing credential's trust, SPEC-015 signingCredential.expired, SPEC-017 the six of the timestamp, SPEC-018 assertion.action.malformed). No word of our own: a case enters here only
 * through the spec that emits it. Success, informational and failure are
 * the table's three kinds: the successes are claimSignature.validated,
 * assertion.hashedURI.match, assertion.dataHash.match and
 * signingCredential.trusted; the one informational so far is
 * assertion.dataHash.additionalExclusionsPresent.
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
    case AssertionActionMalformed = 'assertion.action.malformed';
    case AssertionDataHashMatch = 'assertion.dataHash.match';
    case AssertionDataHashMismatch = 'assertion.dataHash.mismatch';
    case AssertionDataHashMalformed = 'assertion.dataHash.malformed';
    case AssertionDataHashAdditionalExclusionsPresent = 'assertion.dataHash.additionalExclusionsPresent';
    case ClaimHardBindingsMissing = 'claim.hardBindings.missing';
    case AssertionMultipleHardBindings = 'assertion.multipleHardBindings';
    case SigningCredentialTrusted = 'signingCredential.trusted';
    case SigningCredentialUntrusted = 'signingCredential.untrusted';
    case SigningCredentialExpired = 'signingCredential.expired';
    case TimeStampValidated = 'timeStamp.validated';
    case TimeStampTrusted = 'timeStamp.trusted';
    case TimeStampMalformed = 'timeStamp.malformed';
    case TimeStampMismatch = 'timeStamp.mismatch';
    case TimeStampOutsideValidity = 'timeStamp.outsideValidity';
    case TimeStampUntrusted = 'timeStamp.untrusted';
    case IngredientManifestMissing = 'ingredient.manifest.missing';
    case IngredientUnknownProvenance = 'ingredient.unknownProvenance';
    case AssertionIngredientMalformed = 'assertion.ingredient.malformed';
    case GeneralError = 'general.error';

    public function isSuccess(): bool
    {
        return $this === self::ClaimSignatureValidated || $this === self::AssertionHashedUriMatch || $this === self::AssertionDataHashMatch || $this === self::SigningCredentialTrusted
            || $this === self::TimeStampValidated || $this === self::TimeStampTrusted;
    }

    public function isInformational(): bool
    {
        // every timeStamp failure is informational: a broken timestamp costs the time, never the verdict (C2PA 2.4 §15; c2pa-rs; SPEC-017)
        return $this === self::AssertionDataHashAdditionalExclusionsPresent
            || $this === self::IngredientUnknownProvenance                 // SPEC-020: an ingredient without a manifest (§15.11.3.3)
            || $this === self::TimeStampMalformed || $this === self::TimeStampMismatch || $this === self::TimeStampOutsideValidity || $this === self::TimeStampUntrusted;
    }

    public function isFailure(): bool
    {
        return ! $this->isSuccess() && ! $this->isInformational();
    }
}
