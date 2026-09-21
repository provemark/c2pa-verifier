<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Jumbf;

/**
 * A JUMBF superbox, `jumb`: a description box followed by content boxes,
 * superboxes or unknown boxes, in file order (SPEC-005). It keeps the whole
 * store (one shared, copy-on-write string) and its own byte range, so that
 * payload() — what C2PA 2.4 §8.4.2.3 hashes — is exact.
 */
final readonly class Superbox
{
    /**
     * @param  list<Superbox|ContentBox|UnknownBox>  $children
     * @param  string  $store  the whole manifest store this box lies in
     */
    public function __construct(
        public int $offset,
        public int $length,
        public DescriptionBox $description,
        public array $children,
        private string $store,
    ) {}

    /** The child superbox with this label, or null; the caller decides whether absence is an error. */
    public function child(string $label): ?self
    {
        foreach ($this->children as $child) {
            if ($child instanceof self && $child->description->label === $label) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<Superbox> */
    public function superboxes(): array
    {
        return array_values(array_filter($this->children, static fn ($child): bool => $child instanceof self));
    }

    /** @return list<ContentBox> */
    public function contentBoxes(): array
    {
        return array_values(array_filter($this->children, static fn ($child): bool => $child instanceof ContentBox));
    }

    /** The contents without the 8-byte superbox header: the bytes a hashed URI to this box covers (§8.4.2.3). */
    public function payload(): string
    {
        return substr($this->store, $this->offset + 8, $this->length - 8);
    }
}
