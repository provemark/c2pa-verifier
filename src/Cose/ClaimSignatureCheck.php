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

        return [$verifies
            ? new ValidationStatus(StatusCode::ClaimSignatureValidated, $url, sprintf('the claim signature verifies under the leaf certificate (alg %d, %d certificates in x5chain)', $cose->alg, count($cose->chain)))
            : new ValidationStatus(StatusCode::ClaimSignatureMismatch, $url, sprintf('the claim signature does not verify under the leaf certificate (alg %d)', $cose->alg)),
        ];
    }
}
