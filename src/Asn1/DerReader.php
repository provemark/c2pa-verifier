<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Asn1;

/**
 * DER (X.690 §8, §10), the small closed subset a timestamp token needs
 * (SPEC-016, ADR-0004): identifier octets with tag numbers up to 30,
 * definite lengths in the short form or a minimal long form of at most
 * four bytes, content octets, and children for constructed elements.
 * Indefinite lengths, non-minimal lengths and high tag numbers are
 * refused — they are BER, and DER forbids them. Bounded: depth, element
 * count and input size, each checked before anything is allocated. Every
 * fault is an Asn1Exception naming the offset. Reads only.
 */
final readonly class DerReader
{
    public const DEFAULT_MAX_DEPTH = 32;

    public const DEFAULT_MAX_ELEMENTS = 65536;

    public const DEFAULT_MAX_BYTES = 1048576;

    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxElements = self::DEFAULT_MAX_ELEMENTS,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    /**
     * The one element that fills $bytes exactly; trailing bytes are an error.
     *
     * @throws Asn1Exception
     */
    public function read(string $bytes): Der
    {
        $der = $this->readAt($bytes, 0);
        $end = $der->length();
        if ($end !== strlen($bytes)) {
            throw new Asn1Exception(sprintf(
                '%d trailing byte(s) after the element at offset 0, which ended at offset %d',
                strlen($bytes) - $end,
                $end,
            ));
        }

        return $der;
    }

    /**
     * One element at $offset; `$der->length()` says where the next begins.
     *
     * @throws Asn1Exception
     */
    public function readAt(string $bytes, int $offset, int $depth = 0): Der
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new Asn1Exception(sprintf('the input of %d bytes exceeds the limit of %d bytes', strlen($bytes), $this->maxBytes));
        }
        $elements = 0;

        return $this->element($bytes, $offset, strlen($bytes), $depth, $elements);
    }

    /**
     * @param  int  $end  the first offset this element may not reach
     * @param  int  $elements  counted across the whole read, against maxElements
     */
    private function element(string $bytes, int $offset, int $end, int $depth, int &$elements): Der
    {
        if ($depth > $this->maxDepth) {
            throw new Asn1Exception(sprintf('the element at offset %d nests deeper than the limit of %d (depth)', $offset, $this->maxDepth));
        }
        if (++$elements > $this->maxElements) {
            throw new Asn1Exception(sprintf('the element at offset %d is beyond the limit of %d elements', $offset, $this->maxElements));
        }
        if ($offset >= $end) {
            throw new Asn1Exception(sprintf('an element is expected at offset %d but the input ends there', $offset));
        }

        // the identifier octet (X.690 §8.1.2)
        $identifier = ord($bytes[$offset]);
        $class = TagClass::from($identifier >> 6);
        $constructed = ($identifier & 0x20) !== 0;
        $tag = $identifier & 0x1F;
        if ($tag === 0x1F) {
            throw new Asn1Exception(sprintf('the element at offset %d uses the high tag number form (tag 31 or above), which is not supported', $offset));
        }

        // the length octets (X.690 §8.1.3, §10.1: definite, minimal)
        $cursor = $offset + 1;
        if ($cursor >= $end) {
            throw new Asn1Exception(sprintf('the length of the element at offset %d is missing (the input ends at %d)', $offset, $end));
        }
        $first = ord($bytes[$cursor]);
        $cursor++;
        if ($first < 0x80) {
            $length = $first;
        } elseif ($first === 0x80) {
            throw new Asn1Exception(sprintf('the element at offset %d has an indefinite length (offset %d); DER requires definite lengths', $offset, $cursor - 1));
        } elseif ($first === 0xFF) {
            throw new Asn1Exception(sprintf('the reserved length octet FF at offset %d', $cursor - 1));
        } else {
            $count = $first & 0x7F;
            if ($count > 4) {
                throw new Asn1Exception(sprintf('a length of %d bytes at offset %d is not supported (at most 4)', $count, $cursor - 1));
            }
            if ($cursor + $count > $end) {
                throw new Asn1Exception(sprintf('the length of the element at offset %d is truncated (offset %d)', $offset, $cursor - 1));
            }
            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($bytes[$cursor + $i]);
            }
            $minimal = $length >= 0x80 && ord($bytes[$cursor]) !== 0x00 && $count === strlen(ltrim(pack('N', $length), "\0"));
            if (! $minimal) {
                throw new Asn1Exception(sprintf('the length at offset %d is not minimal (BER, not DER)', $cursor - 1));
            }
            $cursor += $count;
        }
        $headerLength = $cursor - $offset;
        if ($cursor + $length > $end) {
            throw new Asn1Exception(sprintf('length %d at offset %d runs past the end (%d bytes)', $length, $offset + 1, $end));
        }
        $contents = substr($bytes, $cursor, $length);

        $children = null;
        if ($constructed) {
            $children = [];
            $child = $cursor;
            $contentsEnd = $cursor + $length;
            while ($child < $contentsEnd) {
                $element = $this->element($bytes, $child, $contentsEnd, $depth + 1, $elements);
                $children[] = $element;
                $child += $element->length();
            }
        }

        return new Der($class, $constructed, $tag, $offset, $headerLength, $contents, $children);
    }
}
