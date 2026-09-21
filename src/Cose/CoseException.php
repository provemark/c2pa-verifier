<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cose;

/**
 * Thrown when the signature box is not the COSE_Sign1_Tagged structure C2PA
 * 2.4 §13.2 requires (SPEC-008 AC7–AC12): wrong tag, wrong item count, a
 * present payload, a protected header without an integer alg, no usable
 * x5chain, a limit exceeded. A structural fault, named — never "the
 * signature does not verify".
 */
final class CoseException extends \RuntimeException {}
