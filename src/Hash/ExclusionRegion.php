<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

/**
 * A stretch of the asset as an ExclusionAllowance maps it (SPEC-069): where
 * it lies, what it is ("APP1 Exif payload", "the APP2 segment at 44"), and
 * whether an exclusion beyond the manifest store may lie wholly inside it.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ExclusionRegion
{
    public function __construct(
        public int $start,
        public int $length,
        public string $what,
        public bool $allowed,
    ) {}
}
