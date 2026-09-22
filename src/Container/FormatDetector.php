<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * The container format from the first twelve bytes, nothing else read
 * (SPEC-013): JPEG's SOI, PNG's signature, RIFF's header with the WEBP form
 * type. Anything else is null — an unknown format is an error for the
 * caller, never a guess. The stream is rewound afterwards.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class FormatDetector
{
    public const PROBE_LENGTH = 12;

    /**
     * @param  resource  $stream  readable and seekable
     * @return 'jpeg'|'png'|'webp'|null
     */
    public function detect($stream): ?string
    {
        $head = $this->head($stream);
        if (str_starts_with($head, "\xFF\xD8")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'png';
        }
        if (strlen($head) === self::PROBE_LENGTH && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    /**
     * The first bytes, for the detection and for the message when it fails.
     *
     * @param  resource  $stream
     */
    public function head($stream): string
    {
        if (! is_resource($stream) || ! rewind($stream)) {
            throw new \InvalidArgumentException('FormatDetector needs a seekable stream resource');
        }
        $head = fread($stream, self::PROBE_LENGTH);
        rewind($stream);

        return $head === false ? '' : $head;
    }
}
