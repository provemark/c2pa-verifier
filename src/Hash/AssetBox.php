<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

/**
 * One named box of an asset for the general box hash (SPEC-090; C2PA 2.4 §18.7): its name as a
 * box-hash-map writes it, and the bytes of the file it covers.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class AssetBox
{
    public function __construct(
        public string $name,
        public int $start,
        public int $length,
    ) {}
}
