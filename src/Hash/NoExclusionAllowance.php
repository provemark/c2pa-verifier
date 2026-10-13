<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

/**
 * A format in which no measured writer excludes anything beyond the manifest
 * store, so nothing is allowed (SPEC-069): PNG, WebP, GIF, WAV, AVI, MP3,
 * FLAC and plain text, and DataHashCheck's default.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class NoExclusionAllowance implements ExclusionAllowance
{
    public function __construct(private string $format = 'this format') {}

    public function format(): string
    {
        return $this->format;
    }

    public function rule(): string
    {
        return sprintf('%s allows no exclusion beyond the manifest store', $this->format);
    }

    public function regions($stream): array
    {
        return [];
    }
}
