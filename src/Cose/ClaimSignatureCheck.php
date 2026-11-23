<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;

/**
 * The claim-signature check as a list of statuses (SPEC-010; C2PA 2.4
 * §15.7): the signature box parsed (SPEC-008), the signature verified
 * (SPEC-009), and one of claimSignature.validated, claimSignature.mismatch,
 * or the code the CoseException carries — always with the signature box's
 * absolute JUMBF URI and the reason.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ClaimSignatureCheck
{
    public function __construct(
        private SignatureVerifier $verifier = new SignatureVerifier,
    ) {}

    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array
    {
        return $this->checkBytes(
            $manifest->signatureBytes(),
            $manifest->claimBytes(),
            sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $manifest->label),
        );
    }

    /**
     * The same check on bare bytes, for signatures that come without a
     * manifest (test vectors, a detached store).
     *
     * @return list<ValidationStatus>
     */
    public function checkBytes(string $signatureBytes, string $claimBytes, string $url): array
    {
        try {
            $cose = CoseSign1::fromBytes($signatureBytes);
            $verifies = $this->verifier->verify($cose, $claimBytes);
        } catch (CoseException $e) {
            return [new ValidationStatus($e->status, $url, $e->getMessage())];
        }

        if (! $verifies) {
            return [new ValidationStatus(StatusCode::ClaimSignatureMismatch, $url, sprintf('the claim signature does not verify under the leaf certificate (alg %d)', $cose->alg))];
        }

        // SPEC-039: insideValidity directly before claimSignature.validated, as c2patool lists them; the
        // certificate's own period is judged elsewhere, and withinValidity() withdraws the code (amendment 3)
        return [
            new ValidationStatus(StatusCode::ClaimSignatureInsideValidity, $url, 'claim signature valid'),
            new ValidationStatus(StatusCode::ClaimSignatureValidated, $url, sprintf('the claim signature verifies under the leaf certificate (alg %d, %d certificates in x5chain)', $cose->alg, count($cose->chain))),
        ];
    }

    /**
     * SPEC-039 amendment 3 (C2PA 2.4 §15.8): claimSignature.insideValidity is not reported beside a
     * signingCredential.expired on the same signature, as c2pa withholds it since 0.91.11. Called once the
     * certificate checks have run; every other status keeps its place.
     *
     * @param  list<ValidationStatus>  $statuses
     * @return list<ValidationStatus>
     */
    public static function withinValidity(array $statuses): array
    {
        $expired = [];
        foreach ($statuses as $status) {
            if ($status->code === StatusCode::SigningCredentialExpired) {
                $expired[$status->url] = true;
            }
        }

        return $expired === [] ? $statuses : array_values(array_filter($statuses, static fn (ValidationStatus $s): bool => $s->code !== StatusCode::ClaimSignatureInsideValidity || ! isset($expired[$s->url])));
    }

    /**
     * SPEC-010 amendment 7 (C2PA 2.4 §15.7, "if the claim has not yet been rejected"): claimSignature.validated
     * is withdrawn where the same signature carries a refusal of its credential, as c2pa's verify_internal does
     * since 0.91.11. signingCredential.untrusted is not a refusal, as in c2pa. Called once the certificate,
     * trust and revocation checks have run; every other status keeps its place.
     *
     * @param  list<ValidationStatus>  $statuses
     * @return list<ValidationStatus>
     */
    public static function withRefusals(array $statuses): array
    {
        $refused = [];
        foreach ($statuses as $status) {
            if (in_array($status->code, [StatusCode::SigningCredentialInvalid, StatusCode::SigningCredentialOcspRevoked, StatusCode::SigningCredentialExpired, StatusCode::AlgorithmUnsupported], true)) {
                $refused[$status->url] = true;
            }
        }

        return $refused === [] ? $statuses : array_values(array_filter($statuses, static fn (ValidationStatus $s): bool => $s->code !== StatusCode::ClaimSignatureValidated || ! isset($refused[$s->url])));
    }
}
