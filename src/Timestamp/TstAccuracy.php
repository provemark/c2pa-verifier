<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Timestamp;

/** RFC 3161 §2.4.2 `Accuracy`: seconds, millis [0], micros [1] — each optional. */
final readonly class TstAccuracy
{
    public function __construct(
        public ?int $seconds,
        public ?int $millis,
        public ?int $micros,
    ) {}
}
