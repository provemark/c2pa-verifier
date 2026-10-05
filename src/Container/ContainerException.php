<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * Thrown for every malformed or out-of-order container case (SPEC-001
 * AC3, AC5–AC7, AC9–AC11). There is never a partial result: an extractor
 * either returns the whole store, null, or throws.
 *
 * `$storeReached` says whether the extractor had come to the manifest
 * store when the container failed (SPEC-003 amendment 3, AC18). The
 * verifier reports a manifest only then (SPEC-013 amendment 16). Every
 * extractor sets it, JPEG, PNG, ISOBMFF, RIFF and ID3 alike (SPEC-013
 * amendments 16 to 18); the default `true` is what a fault outside an
 * extractor's walk reports.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class ContainerException extends \RuntimeException
{
    /** The first three parameters are RuntimeException's, in its order; `$storeReached` comes last (step 217). */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly bool $storeReached = true,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
