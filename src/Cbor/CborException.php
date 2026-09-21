<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * Thrown for everything the decoder does not decode (SPEC-006 AC5–AC14):
 * malformed input, the parts of CBOR outside the measured subset, and the
 * limits. Every message names a byte offset. There is never a partial value.
 */
final class CborException extends \RuntimeException {}
