<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * A CBOR tag (major type 6) over its content, passed through with its number
 * — a tag is annotation; the layer that needs one (tag 18, COSE_Sign1, in
 * M3) decides what it means (SPEC-006).
 */
final readonly class CborTag
{
    public function __construct(
        public int $number,
        public mixed $value,
    ) {}
}
