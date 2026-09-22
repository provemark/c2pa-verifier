<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

/**
 * A JUMBF content box (SPEC-005): `cbor`, `json`, `bfdb`, `bidb` or
 * `uuid`, with its data uninterpreted. $offset and $length are the box's,
 * header included; the data starts eight bytes on.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class ContentBox
{
    public function __construct(
        public int $offset,
        public int $length,
        public string $type,
        public string $data,
    ) {}
}
