<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Trust;

/**
 * Thrown when trust settings cannot be read whole (SPEC-014 AC7) or a
 * certificate cannot be parsed: a settings object is either complete or
 * absent, never partial. The message names the field and the fault and
 * never echoes key material.
 */
final class TrustException extends \RuntimeException {}
