<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Container;

/**
 * The manifest store exactly as it was embedded in the container, one JUMBF
 * box from LBox to its last data byte, reassembled but not interpreted.
 * What the bytes mean is M2's concern (SPEC-001, Scope). With it, the byte
 * ranges of the *file* the store and its container framing occupy
 * (SPEC-001/002/003 amendment, for SPEC-012): one per piece, in file
 * order, contiguous pieces merged — what the data hash's exclusion for
 * the store must equal.
 */
final readonly class ManifestStoreBytes
{
    /** @var list<array{start: int, length: int}> */
    public array $ranges;

    /**
     * @param  list<array{start: int, length: int}>  $ranges  in file order; adjacent ranges are merged here
     */
    public function __construct(public string $bytes, array $ranges)
    {
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['start'] + $merged[$last]['length'] === $range['start']) {
                $merged[$last] = ['start' => $merged[$last]['start'], 'length' => $merged[$last]['length'] + $range['length']];
            } else {
                $merged[] = $range;
            }
        }
        $this->ranges = $merged;
    }
}
