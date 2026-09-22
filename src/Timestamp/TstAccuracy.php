<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

/** RFC 3161 §2.4.2 `Accuracy`: seconds, millis [0], micros [1] — each optional.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class TstAccuracy
{
    public function __construct(
        public ?int $seconds,
        public ?int $millis,
        public ?int $micros,
    ) {}
}
