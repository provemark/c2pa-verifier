<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * Thrown for every malformed or out-of-order container case (SPEC-001
 * AC3, AC5–AC7, AC9–AC11). There is never a partial result: an extractor
 * either returns the whole store, null, or throws.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class ContainerException extends \RuntimeException {}
