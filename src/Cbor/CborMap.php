<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * A CBOR map (major type 5), kept as a map: only from a decoder made with
 * `keepMaps: true` (SPEC-006 amendment 3). A PHP array cannot tell an
 * empty map from an empty array, or a map keyed 0, 1, 2… from a list;
 * crJSON must (SPEC-074). The keys follow AC13's rules, so they are
 * unique and never a text that reads as an integer.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class CborMap
{
    /** @param  array<int|string, mixed>  $entries  the keys and values, in the order they were encoded */
    public function __construct(public array $entries) {}
}
