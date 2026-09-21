<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * A CBOR byte string (major type 2), kept apart from text so that a hash can
 * never be mistaken for a label and a JSON view knows what to base64
 * (SPEC-006 AC3).
 */
final readonly class CborBytes
{
    public function __construct(public string $bytes) {}
}
