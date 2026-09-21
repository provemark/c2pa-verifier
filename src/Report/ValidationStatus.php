<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Report;

/**
 * One line of the report (SPEC-010): a code from C2PA 2.4 §15, the JUMBF
 * URI of the box it is about, and our own explanation — offsets, hex, the
 * clause — which is not a second vocabulary: the code is the word, the
 * explanation the reason.
 */
final readonly class ValidationStatus
{
    public function __construct(
        public StatusCode $code,
        public string $url,
        public string $explanation,
    ) {}

    /** @return array{code: string, url: string, explanation: string} */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'url' => $this->url, 'explanation' => $this->explanation];
    }
}
