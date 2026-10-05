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
 * verifier reports a manifest only then (SPEC-013 amendment 16). It is
 * `true` unless an extractor says otherwise: only the RIFF walk does so
 * far, and the other extractors keep their earlier report.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class ContainerException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly bool $storeReached = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
