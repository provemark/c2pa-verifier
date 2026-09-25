<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * How many CBOR data items may still be decoded (SPEC-043 AC1). Mutable on purpose: one budget is
 * shared by every claim and assertion of a manifest store, because those stay decoded in memory, and
 * a limit per container or per decode lets many of them add up. The largest total measured in any
 * real file or fixture is 5,285 items; the default is twelve times that, as the per-container limit.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class CborBudget
{
    public const DEFAULT_ITEMS = 65536;

    private int $remaining;

    public function __construct(public readonly int $items = self::DEFAULT_ITEMS)
    {
        $this->remaining = $items;
    }

    /** @throws CborException when the budget is spent */
    public function take(int $offset): void
    {
        if (--$this->remaining < 0) {
            throw new CborException(sprintf('the item at offset %d is beyond the limit of %d CBOR items for this manifest store (SPEC-043)', $offset, $this->items));
        }
    }
}
