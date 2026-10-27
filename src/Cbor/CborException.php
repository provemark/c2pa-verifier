<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * Thrown for everything the decoder does not decode (SPEC-006 AC5–AC14):
 * malformed input, the parts of CBOR outside the measured subset, and the
 * limits. Every message names a byte offset. There is never a partial value.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class CborException extends \RuntimeException
{
    /**
     * The code of a limit of this decoder (items, depth, the store-wide budget) or of an item outside the subset it
     * decodes (an integer or tag above 2^63 - 1, a simple value other than false, true and null, a map key that is not
     * an integer or text, or text that would collide with an integer key): the input may be valid CBOR. Every other
     * exception has code 0: the input is not well-formed or not valid CBOR (RFC 8949 §5.3). SPEC-088 amendment 1.
     */
    public const LIMIT = 1;
}
