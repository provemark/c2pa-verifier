<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use RuntimeException;

/**
 * A BMFF hash assertion whose exclusions go beyond a limit (SPEC-053).
 *
 * Not a HashException on purpose: that one reports a hash this verifier cannot
 * compute as a mismatch, and a plan that is too large is a malformed assertion,
 * refused before a byte of the asset is hashed.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class BmffLimitException extends RuntimeException {}
