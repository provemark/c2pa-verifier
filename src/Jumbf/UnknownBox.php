<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

/**
 * A box this verifier does not recognise — a superbox with an unknown type
 * UUID, or a content box of an unknown type — kept in the tree, its
 * contents not walked (C2PA 2.4 §11.1.2: "skip over and ignore"; SPEC-005
 * AC7). Skipping is not forgetting: the box keeps its place and its bytes
 * so that a later layer can hash or refuse it.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class UnknownBox
{
    public function __construct(
        public int $offset,
        public int $length,
        public string $type,
        public ?string $uuid,
        public ?string $label,
        public string $bytes,
    ) {}
}
