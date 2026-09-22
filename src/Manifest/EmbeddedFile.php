<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Manifest;

/**
 * The data of an embedded-file assertion (JUMBF `bfdb` + `bidb`): the media
 * type from the description box, the bytes from the data box — a thumbnail,
 * typically.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class EmbeddedFile
{
    public function __construct(
        public string $format,
        public string $bytes,
    ) {}
}
