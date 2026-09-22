<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

/**
 * A JUMBF description box, `jumd` (ISO 19566-5 A.3 as C2PA 2.4 §11.1.4.1
 * restates it): the superbox's type UUID, a toggles byte, and the fields
 * the toggles announce — a label, an id, a signature, a private box. In a
 * C2PA store the private box is the `c2sh` salt (§8.4.2.3).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class DescriptionBox
{
    public const TOGGLE_REQUESTABLE = 0x01;

    public const TOGGLE_LABEL = 0x02;

    public const TOGGLE_ID = 0x04;

    public const TOGGLE_SIGNATURE = 0x08;

    public const TOGGLE_PRIVATE = 0x10;

    public function __construct(
        public int $offset,
        public int $length,
        /** Lower-case, hyphenated: 63327061-0011-0010-8000-00aa00389b71 */
        public string $uuid,
        public int $toggles,
        public string $label,
        public ?int $id,
        /** 32 bytes, or null */
        public ?string $signature,
        /** 16 or 32 bytes of `c2sh` salt, or null */
        public ?string $salt,
    ) {}

    public function requestable(): bool
    {
        return ($this->toggles & self::TOGGLE_REQUESTABLE) !== 0;
    }
}
