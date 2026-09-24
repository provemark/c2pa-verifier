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
    // SPEC-039: a success, beside every verified signature, as c2patool reports it (C2PA 2.4 §15.8 names it)
    case ClaimSignatureInsideValidity = 'claimSignature.insideValidity';
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
    case AssertionActionIngredientMismatch = 'assertion.action.ingredientMismatch';   // SPEC-033
    case AssertionActionSoftBindingMissing = 'assertion.action.softBindingMissing';   // SPEC-033
    case AssertionExternalReferenceMalformed = 'assertion.external-reference.malformed';   // SPEC-032
    case AssertionDataHashMatch = 'assertion.dataHash.match';
    case AssertionDataHashMismatch = 'assertion.dataHash.mismatch';
    case AssertionBmffHashMatch = 'assertion.bmffHash.match';
    case AssertionBmffHashMismatch = 'assertion.bmffHash.mismatch';
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
    case IngredientManifestValidated = 'ingredient.manifest.validated';
    case IngredientManifestMismatch = 'ingredient.manifest.mismatch';
    case IngredientManifestMissing = 'ingredient.manifest.missing';
    case IngredientUnknownProvenance = 'ingredient.unknownProvenance';
    case AssertionIngredientMalformed = 'assertion.ingredient.malformed';
    case ManifestUpdateInvalid = 'manifest.update.invalid';
    case ManifestUpdateWrongParents = 'manifest.update.wrongParents';
    case ManifestMultipleParents = 'manifest.multipleParents';
    // SPEC-030: revocation as far as it can be known without a network — the OCSP
    // responses a signer staples into its own signature. The header carrying them is
    // unprotected, so a stapled response may lower trust and never raise it.
    case SigningCredentialOcspRevoked = 'signingCredential.ocsp.revoked';
    case SigningCredentialOcspNotRevoked = 'signingCredential.ocsp.notRevoked';
    case SigningCredentialOcspUnknown = 'signingCredential.ocsp.unknown';
    case SigningCredentialOcspSkipped = 'signingCredential.ocsp.skipped';
    // SPEC-035: redactions (C2PA 2.4 §6.8, §15.10.3.1) and the claim-signature method an ingredient
    // whose manifest lost a redacted assertion is checked by instead of its box hash (§15.11.3.3.1)
    case AssertionActionRedacted = 'assertion.action.redacted';
    case AssertionNotRedacted = 'assertion.notRedacted';
    case AssertionSelfRedacted = 'assertion.selfRedacted';
    case IngredientClaimSignatureValidated = 'ingredient.claimSignature.validated';
    case IngredientClaimSignatureMismatch = 'ingredient.claimSignature.mismatch';
    case IngredientClaimSignatureMissing = 'ingredient.claimSignature.missing';
    // SPEC-036: a redacted hard binding (§6.8; the §15 table, which deprecates assertion.dataHash.redacted for it)
    case AssertionHardBindingRedacted = 'assertion.hardBinding.redacted';
    // SPEC-037: a c2pa.redacted action whose reference resolves to nothing (§15.10.3.2.3)
    case AssertionActionRedactionMismatch = 'assertion.action.redactionMismatch';
    // SPEC-038 (amendment 1): the shape c2pa-rs refuses before it hashes (§15 table)
    case AssertionBmffHashMalformed = 'assertion.bmffHash.malformed';
    // SPEC-038: informational — exclusions beyond the C2PA box, ftyp and mfra (as c2patool 0.28.0 reports it)
    case AssertionBmffHashAdditionalExclusionsPresent = 'assertion.bmffHash.additionalExclusionsPresent';
    case GeneralError = 'general.error';

    public function isSuccess(): bool
    {
        return $this === self::ClaimSignatureValidated || $this === self::ClaimSignatureInsideValidity || $this === self::AssertionHashedUriMatch || $this === self::AssertionDataHashMatch || $this === self::AssertionBmffHashMatch || $this === self::SigningCredentialTrusted
            || $this === self::TimeStampValidated || $this === self::TimeStampTrusted
            || $this === self::IngredientManifestValidated   // SPEC-021: the ingredient's manifest box hashed as recorded
            || $this === self::SigningCredentialOcspNotRevoked;   // SPEC-030 — and its explanation says how little that proves
    }

    public function isInformational(): bool
    {
        // every timeStamp failure is informational: a broken timestamp costs the time, never the verdict (C2PA 2.4 §15; c2pa-rs; SPEC-017)
        return $this === self::AssertionDataHashAdditionalExclusionsPresent
            || $this === self::IngredientUnknownProvenance                 // SPEC-020: an ingredient without a manifest (§15.11.3.3)
            || $this === self::IngredientClaimSignatureValidated           // SPEC-035: as c2patool 0.28.0 records it
            || $this === self::AssertionBmffHashAdditionalExclusionsPresent   // SPEC-038, as its data-hash twin
            || $this === self::TimeStampMalformed || $this === self::TimeStampMismatch || $this === self::TimeStampOutsideValidity || $this === self::TimeStampUntrusted
            // SPEC-030: a response this verifier could not use costs nothing. The header is
            // unsigned, so failing a file over one would let an attacker deny any valid asset
            // by editing a byte no signature covers.
            || $this === self::SigningCredentialOcspSkipped || $this === self::SigningCredentialOcspUnknown;
    }

    public function isFailure(): bool
    {
        return ! $this->isSuccess() && ! $this->isInformational();
    }
}
