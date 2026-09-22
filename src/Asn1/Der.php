<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Asn1;

use Provemark\C2paVerifier\Support\Bytes;

/**
 * One DER element as the reader found it (SPEC-016): identifier, offset,
 * header length, content octets and — when constructed — its children.
 * The typed accessors turn the content octets into a value and refuse the
 * wrong tag, so that a structure reads `$der->child(2)->oid()` and gets
 * either the OID or an Asn1Exception naming the offset and both tags.
 * Values are the reader's: an INTEGER is a decimal string, a time a UTC
 * epoch, an OID dotted decimal. Nothing here encodes.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final readonly class Der
{
    public const BOOLEAN = 1;

    public const INTEGER = 2;

    public const OCTET_STRING = 4;

    public const NULL = 5;

    public const OBJECT_IDENTIFIER = 6;

    public const UTF8_STRING = 12;

    public const SEQUENCE = 16;

    public const SET = 17;

    public const UTC_TIME = 23;

    public const GENERALIZED_TIME = 24;

    /** @var array<int, string> */
    public const UNIVERSAL_NAMES = [
        self::BOOLEAN => 'BOOLEAN',
        self::INTEGER => 'INTEGER',
        3 => 'BIT STRING',
        self::OCTET_STRING => 'OCTET STRING',
        self::NULL => 'NULL',
        self::OBJECT_IDENTIFIER => 'OBJECT IDENTIFIER',
        self::UTF8_STRING => 'UTF8String',
        self::SEQUENCE => 'SEQUENCE',
        self::SET => 'SET',
        19 => 'PrintableString',
        22 => 'IA5String',
        self::UTC_TIME => 'UTCTime',
        self::GENERALIZED_TIME => 'GeneralizedTime',
    ];

    /**
     * @param  int  $offset  of the identifier octet in the input
     * @param  int  $headerLength  identifier + length octets
     * @param  string  $contents  the content octets (for a constructed element: the same bytes its children were read from)
     * @param  list<Der>|null  $children  constructed only
     */
    public function __construct(
        public TagClass $class,
        public bool $constructed,
        public int $tag,
        public int $offset,
        public int $headerLength,
        public string $contents,
        public ?array $children,
    ) {}

    /** The whole element as it stood in the input: header + contents. */
    public function encoded(): string
    {
        return $this->header().$this->contents;
    }

    /** The whole element's length: header + contents. */
    public function length(): int
    {
        return $this->headerLength + strlen($this->contents);
    }

    public function is(TagClass $class, int $tag): bool
    {
        return $this->class === $class && $this->tag === $tag;
    }

    /** `INTEGER`, `[0]`, … — how messages name this element. */
    public function describe(): string
    {
        return $this->class->describe($this->tag);
    }

    /** @return list<Der> */
    public function sequence(): array
    {
        return $this->childrenOf(self::SEQUENCE);
    }

    /** @return list<Der> */
    public function set(): array
    {
        return $this->childrenOf(self::SET);
    }

    /**
     * An INTEGER as a decimal string. Non-negative unless $signed: serials,
     * versions and counts are never negative, but an RFC 3161 nonce is a
     * random value TSA clients encode as they draw it, high bit and all
     * (SPEC-016 amendment 3) — read as two's complement, with a minus sign.
     */
    public function integer(bool $signed = false): string
    {
        $bytes = $this->integerBytes();
        if (ord($bytes[0]) < 0x80) {
            return Bytes::hexToDecimal(bin2hex($bytes));
        }
        if (! $signed) {
            throw new Asn1Exception(sprintf('INTEGER at offset %d is negative (%s)', $this->offset, Bytes::hex(substr($bytes, 0, 4))));
        }
        // −x = ~(x − 1): invert the octets and add one, from the least significant end
        $magnitude = ~$bytes;
        for ($i = strlen($magnitude) - 1; $i >= 0; $i--) {
            $sum = ord($magnitude[$i]) + 1;
            $magnitude[$i] = chr($sum & 0xFF);
            if ($sum < 0x100) {
                break;
            }
        }

        return '-'.Bytes::hexToDecimal(bin2hex($magnitude));
    }

    /** The content octets of an INTEGER, checked for the minimal two's-complement encoding DER requires (X.690 §8.3.2). */
    public function integerBytes(): string
    {
        $bytes = $this->primitive(self::INTEGER);
        $n = strlen($bytes);
        if ($n === 0) {
            throw new Asn1Exception(sprintf('INTEGER at offset %d is empty', $this->offset));
        }
        if ($n > 1) {
            $first = ord($bytes[0]);
            $second = ord($bytes[1]);
            if (($first === 0x00 && $second < 0x80) || ($first === 0xFF && $second >= 0x80)) {
                throw new Asn1Exception(sprintf('INTEGER at offset %d is not minimally encoded (leading %02X %02X)', $this->offset, $first, $second));
            }
        }

        return $bytes;
    }

    /** Dotted decimal (X.690 §8.19). */
    public function oid(): string
    {
        $bytes = $this->primitive(self::OBJECT_IDENTIFIER);
        $n = strlen($bytes);
        if ($n === 0) {
            throw new Asn1Exception(sprintf('OBJECT IDENTIFIER at offset %d is empty', $this->offset));
        }
        $arcs = [];
        $value = 0;
        $open = false;
        for ($i = 0; $i < $n; $i++) {
            $byte = ord($bytes[$i]);
            if (! $open && $byte === 0x80) {
                throw new Asn1Exception(sprintf('OBJECT IDENTIFIER at offset %d: subidentifier %d has a leading 80 byte (not minimal)', $this->offset, count($arcs)));
            }
            if ($value > (PHP_INT_MAX >> 7)) {
                throw new Asn1Exception(sprintf('OBJECT IDENTIFIER at offset %d: subidentifier %d is too large', $this->offset, count($arcs)));
            }
            $value = ($value << 7) | ($byte & 0x7F);
            $open = ($byte & 0x80) !== 0;
            if (! $open) {
                if ($arcs === []) {
                    // the first subidentifier folds the first two arcs: X*40 + Y, with X in 0..2 (X.690 §8.19.4)
                    $x = $value < 80 ? intdiv($value, 40) : 2;
                    $arcs[] = $x;
                    $arcs[] = $value - $x * 40;
                } else {
                    $arcs[] = $value;
                }
                $value = 0;
            }
        }
        if ($open) {
            throw new Asn1Exception(sprintf('OBJECT IDENTIFIER at offset %d: the last subidentifier is unterminated', $this->offset));
        }

        return implode('.', $arcs);
    }

    public function octets(): string
    {
        return $this->primitive(self::OCTET_STRING);
    }

    public function boolean(): bool
    {
        $bytes = $this->primitive(self::BOOLEAN);
        if (strlen($bytes) !== 1) {
            throw new Asn1Exception(sprintf('BOOLEAN at offset %d must be one byte, found %d', $this->offset, strlen($bytes)));
        }
        $byte = ord($bytes[0]);
        if ($byte !== 0x00 && $byte !== 0xFF) {
            throw new Asn1Exception(sprintf('BOOLEAN at offset %d must be 00 or FF, found %02X (not DER)', $this->offset, $byte));
        }

        return $byte === 0xFF;
    }

    public function null(): void
    {
        $bytes = $this->primitive(self::NULL);
        if ($bytes !== '') {
            throw new Asn1Exception(sprintf('NULL at offset %d must be empty, found %d byte(s)', $this->offset, strlen($bytes)));
        }
    }

    /**
     * UTCTime (`YYMMDDHHMMSSZ`, RFC 5280 §4.1.2.5.1: 50–99 → 19xx, 00–49 → 20xx)
     * or GeneralizedTime (`YYYYMMDDHHMMSS[.f…]Z`, RFC 3161 allows fractions —
     * dropped), as a UTC epoch. Validated as a real date: minute 63 or
     * 31 February is an error, not a time.
     */
    public function time(): int
    {
        if (! $this->is(TagClass::Universal, self::UTC_TIME) && ! $this->is(TagClass::Universal, self::GENERALIZED_TIME)) {
            throw $this->wrongTag('UTCTime or GeneralizedTime');
        }
        $name = $this->describe();
        $text = $this->contents;
        if ($this->constructed) {
            throw new Asn1Exception(sprintf('%s at offset %d is constructed; DER requires primitive', $name, $this->offset));
        }
        if (! str_ends_with($text, 'Z')) {
            throw new Asn1Exception(sprintf('%s at offset %d (%s) must end in Z (UTC)', $name, $this->offset, Bytes::printableText($text)));
        }
        $pattern = $this->tag === self::UTC_TIME
            ? '/\A(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z\z/'
            : '/\A(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:\.\d+)?Z\z/';
        if (preg_match($pattern, $text, $m) !== 1) {
            throw new Asn1Exception(sprintf('%s at offset %d (%s) is not %s', $name, $this->offset, Bytes::printableText($text), $this->tag === self::UTC_TIME ? 'YYMMDDHHMMSSZ' : 'YYYYMMDDHHMMSSZ'));
        }
        $year = (int) $m[1];
        if ($this->tag === self::UTC_TIME) {
            $year += $year >= 50 ? 1900 : 2000;
        }
        [$month, $day, $hour, $minute, $second] = [(int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]];
        foreach (['month' => [$month, 1, 12], 'hour' => [$hour, 0, 23], 'minute' => [$minute, 0, 59], 'second' => [$second, 0, 59]] as $what => [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw new Asn1Exception(sprintf('%s at offset %d: %s is not a date (%s %d)', $name, $this->offset, $text, $what, $value));
            }
        }
        if (! checkdate($month, $day, $year)) {
            throw new Asn1Exception(sprintf('%s at offset %d: %s is not a date (day %d)', $name, $this->offset, $text, $day));
        }

        $epoch = gmmktime($hour, $minute, $second, $month, $day, $year);
        if ($epoch === false) {
            throw new Asn1Exception(sprintf('%s at offset %d: %s is out of range', $name, $this->offset, $text));
        }

        return $epoch;
    }

    /**
     * The digits after the point of a GeneralizedTime with fractional seconds
     * (RFC 3161 allows them; c2patool keeps them in `signature_info.time`),
     * as written; null when there are none or for a UTCTime.
     */
    public function timeFraction(): ?string
    {
        $this->time();   // the same validation, the same refusals
        if ($this->tag !== self::GENERALIZED_TIME) {
            return null;
        }

        return preg_match('/\.(\d+)Z\z/', $this->contents, $m) === 1 ? $m[1] : null;
    }

    /** This element, asserted context-specific [n]. */
    public function tagged(int $n): self
    {
        if (! $this->is(TagClass::ContextSpecific, $n)) {
            throw $this->wrongTag(sprintf('[%d]', $n));
        }

        return $this;
    }

    /** The i-th child of a constructed element. */
    public function child(int $i): self
    {
        if ($this->children === null) {
            throw new Asn1Exception(sprintf('%s at offset %d is primitive and has no children', $this->describe(), $this->offset));
        }
        if (! isset($this->children[$i])) {
            throw new Asn1Exception(sprintf('%s at offset %d has %d child(ren), no child %d', $this->describe(), $this->offset, count($this->children), $i));
        }

        return $this->children[$i];
    }

    /** The i-th child when it exists, else null (OPTIONAL fields). */
    public function optional(int $i): ?self
    {
        return $this->children[$i] ?? null;
    }

    public function childCount(): int
    {
        return $this->children === null ? 0 : count($this->children);
    }

    /** @return list<Der> */
    private function childrenOf(int $tag): array
    {
        if (! $this->is(TagClass::Universal, $tag) || $this->children === null) {
            throw $this->wrongTag(self::UNIVERSAL_NAMES[$tag]);
        }

        return $this->children;
    }

    private function primitive(int $tag): string
    {
        if (! $this->is(TagClass::Universal, $tag)) {
            throw $this->wrongTag(self::UNIVERSAL_NAMES[$tag]);
        }
        if ($this->constructed) {
            throw new Asn1Exception(sprintf('%s at offset %d is constructed; DER requires primitive', $this->describe(), $this->offset));
        }

        return $this->contents;
    }

    private function wrongTag(string $expected): Asn1Exception
    {
        return new Asn1Exception(sprintf('expected %s at offset %d, found %s', $expected, $this->offset, $this->describe()));
    }

    private function header(): string
    {
        $identifier = ($this->class->value << 6) | ($this->constructed ? 0x20 : 0) | $this->tag;
        $length = strlen($this->contents);
        if ($length < 0x80) {
            return pack('CC', $identifier, $length);
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return pack('CC', $identifier, 0x80 | strlen($bytes)).$bytes;
    }
}
