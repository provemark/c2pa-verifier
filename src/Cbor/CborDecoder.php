<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

use Provemark\C2paVerifier\Support\Bytes;

/**
 * CBOR (RFC 8949), the measured subset decoded and the rest refused
 * (SPEC-006). Major types 0–7 with definite lengths; integers within PHP's
 * int; byte strings as CborBytes, text as string (valid UTF-8), arrays as
 * lists, maps as arrays with int|string keys, tags as CborTag, and of major
 * type 7 false, true, null and floats (amendment 2). Indefinite lengths, other simple
 * values, reserved additional information, duplicate keys, truncation and
 * trailing bytes are errors naming the offset. Limits are checked before
 * anything is allocated. Decodes only; nothing here encodes.
 */
final readonly class CborDecoder
{
    public const DEFAULT_MAX_DEPTH = 32;

    public const DEFAULT_MAX_ITEMS = 65536;

    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {}

    /**
     * Exactly one data item; bytes left over are an error. The value is an
     * int, a string (text), a CborBytes, a list, an array with int|string
     * keys (a map), a CborTag, a bool or null — nested the same way.
     *
     * @throws CborException
     */
    public function decode(string $bytes): mixed
    {
        $offset = 0;
        $value = $this->item($bytes, $offset, 0);
        if ($offset !== strlen($bytes)) {
            throw new CborException(sprintf(
                '%d byte(s) remain after the value, which ended at offset %d',
                strlen($bytes) - $offset,
                $offset,
            ));
        }

        return $value;
    }

    /** One data item at $offset; $offset is left after it. */
    private function item(string $bytes, int &$offset, int $depth): mixed
    {
        $head = $offset;
        $initial = ord($this->take($bytes, $offset, 1, sprintf('the initial byte of the item at offset %d', $head)));
        $majorType = $initial >> 5;
        $additional = $initial & 0x1F;

        if ($additional >= 28 && $additional <= 30) {
            throw new CborException(sprintf('additional information %d at offset %d is reserved', $additional, $head));
        }
        if ($additional === 31) {
            throw new CborException($majorType === 7
                ? sprintf('break at offset %d outside an indefinite-length item', $head)
                : sprintf('indefinite length at offset %d is not supported', $head));
        }
        if ($majorType === 7) {
            return $this->simple($bytes, $offset, $head, $additional);
        }

        $argument = $this->argument($bytes, $offset, $head, $additional);

        switch ($majorType) {
            case 0:
                return $this->fits($argument, $head);
            case 1:
                return -1 - $this->fits($argument, $head);
            case 2:
                return new CborBytes($this->string($bytes, $offset, $head, $argument, 'byte string'));
            case 3:
                $text = $this->string($bytes, $offset, $head, $argument, 'text string');
                if (! mb_check_encoding($text, 'UTF-8')) {
                    throw new CborException(sprintf('text string at offset %d is not valid UTF-8: %s', $head, Bytes::hex(substr($text, 0, 32))));
                }

                return $text;
            case 4:
                return $this->array($bytes, $offset, $head, $argument, $depth);
            case 5:
                return $this->map($bytes, $offset, $head, $argument, $depth);
            default:
                $this->enter($depth, $head);

                return new CborTag($this->fits($argument, $head), $this->item($bytes, $offset, $depth + 1));
        }
    }

    /** The argument that follows the initial byte: the value itself, or 1/2/4/8 bytes of it. As int|float; floats are for major type 7. */
    private function argument(string $bytes, int &$offset, int $head, int $additional): int
    {
        if ($additional <= 23) {
            return $additional;
        }
        $width = match ($additional) {
            24 => 1, 25 => 2, 26 => 4, default => 8
        };
        $raw = $this->take($bytes, $offset, $width, sprintf('the argument of the item at offset %d', $head));
        /** @var array{1: int} $u */
        $u = unpack(match ($width) {
            1 => 'C', 2 => 'n', 4 => 'N', default => 'J'
        }, $raw);

        return $u[1];
    }

    /** An unsigned 64-bit argument that PHP's signed int cannot hold reads back negative. */
    private function fits(int $argument, int $head): int
    {
        if ($argument < 0) {
            throw new CborException(sprintf('integer at offset %d does not fit a 64-bit signed integer', $head));
        }

        return $argument;
    }

    /** Major type 7: false, true, null, and the three float widths (SPEC-006 amendment 2); everything else is refused before its bytes are read. */
    private function simple(string $bytes, int &$offset, int $head, int $additional): mixed
    {
        if ($additional >= 25) {   // 28–31 were refused before this point
            return $this->float($bytes, $offset, $head, $additional);
        }
        if ($additional === 24) {
            $value = ord($this->take($bytes, $offset, 1, sprintf('the simple value at offset %d', $head)));
            throw new CborException($value < 32
                ? sprintf('simple value %d at offset %d in the two-byte form is not well-formed', $value, $head)
                : sprintf('simple value %d at offset %d is not supported', $value, $head));
        }

        return match ($additional) {
            20 => false,
            21 => true,
            22 => null,
            23 => throw new CborException(sprintf('simple value 23 (undefined) at offset %d is not supported', $head)),
            default => throw new CborException(sprintf('simple value %d at offset %d is not supported', $additional, $head)),
        };
    }

    /**
     * An IEEE 754 float of 16, 32 or 64 bits (RFC 8949 §3.3), as a PHP
     * float. Single and double are unpack()'s 'G' and 'E'; half precision
     * PHP does not know, so its 1 + 5 + 10 bits are converted by hand —
     * subnormals, the infinities and NaN included. Decoding a float touches
     * no verification: every hash this verifier checks is over bytes.
     */
    private function float(string $bytes, int &$offset, int $head, int $additional): float
    {
        $width = match ($additional) {
            25 => 2, 26 => 4, default => 8
        };
        $raw = $this->take($bytes, $offset, $width, sprintf('the float at offset %d', $head));
        if ($width === 4) {
            /** @var array{1: float} $u */
            $u = unpack('G', $raw);

            return $u[1];
        }
        if ($width === 8) {
            /** @var array{1: float} $u */
            $u = unpack('E', $raw);

            return $u[1];
        }

        /** @var array{1: int} $u */
        $u = unpack('n', $raw);
        $half = $u[1];
        $sign = ($half & 0x8000) !== 0 ? -1.0 : 1.0;
        $exponent = ($half >> 10) & 0x1F;
        $mantissa = $half & 0x03FF;
        if ($exponent === 0x1F) {
            return $mantissa === 0 ? $sign * INF : NAN;
        }
        if ($exponent === 0) {
            return $sign * $mantissa * 2 ** -24;   // subnormal: no implicit leading 1
        }

        return $sign * (1 + $mantissa / 1024) * 2 ** ($exponent - 15);
    }

    /** The bytes of a definite-length string, after checking they are all there. */
    private function string(string $bytes, int &$offset, int $head, int $length, string $what): string
    {
        $available = strlen($bytes) - $offset;
        if ($length < 0 || $length > $available) {
            throw new CborException(sprintf(
                'unexpected end of input at offset %d: %s at offset %d needs %s bytes, %d available',
                $offset,
                $what,
                $head,
                $length < 0 ? 'more than 9223372036854775807' : (string) $length,
                $available,
            ));
        }
        $string = substr($bytes, $offset, $length);
        $offset += $length;

        return $string;
    }

    /** @return list<mixed> */
    private function array(string $bytes, int &$offset, int $head, int $count, int $depth): array
    {
        $this->countable('array', $head, $count);
        $this->enter($depth, $head);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->item($bytes, $offset, $depth + 1);
        }

        return $items;
    }

    /** @return array<int|string, mixed> */
    private function map(string $bytes, int &$offset, int $head, int $count, int $depth): array
    {
        $this->countable('map', $head, $count);
        $this->enter($depth, $head);
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $keyOffset = $offset;
            $key = $this->item($bytes, $offset, $depth + 1);
            if (! is_int($key) && ! is_string($key)) {
                throw new CborException(sprintf('map key at offset %d is %s; keys must be integers or text', $keyOffset, self::kind($key)));
            }
            if (is_string($key) && (string) (int) $key === $key) {
                // "1" and 1 are different CBOR keys but the same PHP array offset.
                throw new CborException(sprintf('map key %s at offset %d would collide with an integer key', self::show($key), $keyOffset));
            }
            // The value first, so a truncated map is reported as truncation
            // (RFC 8949 Appendix F lists "a2 00 00 00" that way), then the key.
            $value = $this->item($bytes, $offset, $depth + 1);
            if (array_key_exists($key, $map)) {
                throw new CborException(sprintf('duplicate map key %s at offset %d', self::show($key), $keyOffset));
            }
            $map[$key] = $value;
        }

        return $map;
    }

    private function countable(string $what, int $head, int $count): void
    {
        if ($count < 0 || $count > $this->maxItems) {
            throw new CborException(sprintf('%s at offset %d declares %s items, above the limit of %d', $what, $head, $count < 0 ? 'more than 9223372036854775807' : (string) $count, $this->maxItems));
        }
    }

    private function enter(int $depth, int $head): void
    {
        if ($depth + 1 > $this->maxDepth) {
            throw new CborException(sprintf('depth %d exceeds the limit of %d (item at offset %d)', $depth + 1, $this->maxDepth, $head));
        }
    }

    /** Exactly $length bytes at $offset, or the end-of-input error. */
    private function take(string $bytes, int &$offset, int $length, string $what): string
    {
        if ($offset + $length > strlen($bytes)) {
            throw new CborException(sprintf(
                'unexpected end of input at offset %d: wanted %d byte(s) for %s, %d available',
                $offset,
                $length,
                $what,
                strlen($bytes) - $offset,
            ));
        }
        $taken = substr($bytes, $offset, $length);
        $offset += $length;

        return $taken;
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => 'a byte string',
            $value instanceof CborTag => 'a tag',
            is_array($value) => array_is_list($value) ? 'an array' : 'a map',
            is_bool($value) => 'a boolean',
            $value === null => 'null',
            default => gettype($value),
        };
    }

    /** A key for a message: ints as they are, text quoted when printable ASCII, otherwise hex — never raw. */
    private static function show(int|string $key): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        return preg_match('/\A[\x20-\x7E]*\z/', $key) === 1 ? '"'.$key.'"' : Bytes::hex($key);
    }
}
