<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

use Provemark\C2paVerifier\Jumbf\Superbox;

/**
 * One assertion of the assertion store: its label, its superbox (what M4
 * hashes), and its data decoded by content type — a CBOR value, a JSON
 * value, an EmbeddedFile, or the raw bytes of a `uuid` box (SPEC-007).
 */
final readonly class Assertion
{
    public function __construct(
        public string $label,
        public Superbox $box,
        public mixed $data,
    ) {}
}
