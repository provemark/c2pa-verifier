<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Hash;

use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\Manifest;

/**
 * Which assertions bind a manifest to its asset (SPEC-012 amendment 9). C2PA 2.4 §6.4
 * labels a second assertion of a type with `__1`, a third with `__2`, so a hard binding
 * is known by its base label; §15.10.1.2 allows exactly one of any kind.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class HardBindings
{
    /** A hard binding by its exact base label. */
    private const EXACT = ['c2pa.hash.data'];

    /** A hard binding by its base label, or one that continues it with a dot (`c2pa.hash.bmff.v3`). */
    private const FAMILIES = ['c2pa.hash.bmff', 'c2pa.hash.boxes', 'c2pa.hash.collection.data'];

    /** The label without its instance suffix: `c2pa.hash.data__1` is a `c2pa.hash.data` (§6.4). */
    public static function baseLabel(string $label): string
    {
        return (string) preg_replace('/__\d+\z/', '', $label);
    }

    public static function isHardBinding(string $label): bool
    {
        $base = self::baseLabel($label);
        if (in_array($base, self::EXACT, true)) {
            return true;
        }
        foreach (self::FAMILIES as $family) {
            if ($base === $family || str_starts_with($base, $family.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every hard binding in the manifest's assertion store, box by box: two boxes under one
     * label are two.
     *
     * @return list<Superbox> their boxes, in store order
     */
    public static function in(Manifest $manifest): array
    {
        return array_values(array_filter($manifest->assertionStore->superboxes(), static fn (Superbox $box): bool => self::isHardBinding($box->description->label)));
    }

    /** "c2pa.hash.data at 32831, c2pa.hash.data__1 at 33026": for an explanation. */
    public static function describe(Superbox ...$boxes): string
    {
        return implode(', ', array_map(static fn (Superbox $box): string => sprintf('%s at %d', $box->description->label, $box->offset), $boxes));
    }
}
