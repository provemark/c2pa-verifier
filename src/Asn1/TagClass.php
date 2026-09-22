<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Asn1;

/** The two class bits of a DER identifier octet (X.690 §8.1.2.2).
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
enum TagClass: int
{
    case Universal = 0;
    case Application = 1;
    case ContextSpecific = 2;
    case Private = 3;

    /** How a message names a tag of this class: `INTEGER`, `[0]`, `APPLICATION 3`, `PRIVATE 3`. */
    public function describe(int $tag): string
    {
        return match ($this) {
            self::Universal => Der::UNIVERSAL_NAMES[$tag] ?? sprintf('UNIVERSAL %d', $tag),
            self::ContextSpecific => sprintf('[%d]', $tag),
            self::Application => sprintf('APPLICATION %d', $tag),
            self::Private => sprintf('PRIVATE %d', $tag),
        };
    }
}
