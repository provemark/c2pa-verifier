<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

/**
 * One parse's state: the bytes, the box counter, and the two checks every
 * box header goes through — that it fits inside its parent and that its
 * LBox is usable (SPEC-005 AC8, AC9, AC16). Internal to JumbfParser.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class JumbfWalk
{
    private int $boxes = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly int $maxBoxes,
    ) {}

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    public function slice(int $offset, int $length): string
    {
        return substr($this->bytes, $offset, $length);
    }

    /**
     * Reads and validates a box header at $offset inside a parent that ends
     * at $parentEnd; counts the box unless $count is false (a description
     * box peeked at ahead of its superbox, or a private box, is counted
     * where it is walked).
     *
     * @return array{0: int, 1: string} LBox, TBox
     */
    public function header(int $offset, int $parentEnd, bool $count = true): array
    {
        if ($offset + 8 > $parentEnd || $offset + 8 > $this->size()) {
            throw new JumbfException(sprintf('box at offset %d: no room for an 8-byte box header before %d', $offset, min($parentEnd, $this->size())));
        }
        /** @var array{lbox: int, tbox: string} $h */
        $h = unpack('Nlbox/a4tbox', $this->bytes, $offset);
        if ($h['lbox'] === 0 || $h['lbox'] === 1) {
            throw new JumbfException(sprintf('box at offset %d: LBox %d is not supported (0 = to the end, 1 = a 64-bit length)', $offset, $h['lbox']));
        }
        if ($h['lbox'] < 8) {
            throw new JumbfException(sprintf('box at offset %d: LBox %d is shorter than the 8-byte box header', $offset, $h['lbox']));
        }
        if ($offset + $h['lbox'] > $parentEnd) {
            throw new JumbfException(sprintf(
                'box at offset %d ends at %d, past its parent, which ends at %d',
                $offset,
                $offset + $h['lbox'],
                $parentEnd,
            ));
        }
        if ($count) {
            $this->boxes++;
            if ($this->boxes > $this->maxBoxes) {
                throw new JumbfException(sprintf('box at offset %d: box %d exceeds the limit of %d boxes', $offset, $this->boxes, $this->maxBoxes));
            }
        }

        return [$h['lbox'], $h['tbox']];
    }
}
