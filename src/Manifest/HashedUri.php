<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Cbor\CborBytes;

/**
 * A hashed URI (C2PA 2.4 hashed-uri-map): a JUMBF URI, the hash of the
 * box it names, and optionally the algorithm; without `alg` the claim's
 * applies. Comparing the hash with the box is M4.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class HashedUri
{
    public function __construct(
        public string $url,
        public CborBytes $hash,
        public ?string $alg,
    ) {}
}
