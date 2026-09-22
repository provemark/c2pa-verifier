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
 * general.error where §15 has no word. Never a partial store. Where the
 * box is known the exception also carries its absolute JUMBF URI
 * (SPEC-007 amendment 3, defined in SPEC-013), so that the Verifier can
 * report the fault where c2patool would.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class ManifestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly StatusCode $status = StatusCode::GeneralError,
        ?\Throwable $previous = null,
        public readonly ?string $url = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The same fault, now with the URI of the box it was found in (kept if it already had one). */
    public function at(string $url): self
    {
        return $this->url === null ? new self($this->getMessage(), $this->status, $this->getPrevious(), $url) : $this;
    }
}
