<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

/**
 * Thrown when the boxes and CBOR do not add up to a manifest (SPEC-007
 * AC8–AC14): a claim of an unknown version, a missing field, a reference
 * that resolves to nothing or to an unknown box, a manifest with two claims.
 * Never a partial store.
 */
final class ManifestException extends \RuntimeException {}
