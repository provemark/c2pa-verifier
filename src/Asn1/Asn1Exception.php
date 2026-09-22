<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Asn1;

/**
 * Thrown for everything the DER reader does not read (SPEC-016 AC2): a
 * length past the end, an indefinite or non-minimal length, a tag outside
 * the ten, a value that is not what its tag says (a time that is not a
 * date), and the limits. Every message names a byte offset within the
 * input. There is never a partial element.
 */
final class Asn1Exception extends \RuntimeException {}
