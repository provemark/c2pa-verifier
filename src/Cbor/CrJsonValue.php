<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Cbor;

/**
 * A decoded CBOR value as crJSON writes it: C2PA 2.4 crJSON Table 1
 * (SPEC-074). Byte strings as `b64'…'` (the closing quote as CBOR's
 * diagnostic notation has it, RFC 8610 Appendix G.2), tag 0 as its text,
 * maps as objects with their keys as strings, everything JSON already
 * has as it is. What the table cannot express is refused by name: a tag
 * other than 0, NaN and the infinities. The input must come from a
 * decoder made with `keepMaps: true` (SPEC-006 amendment 3); without it
 * an empty map reads as an empty array, so a keyed array is refused.
 *
 * @internal SPEC-025: not part of the public API. It may change, move or be
 * removed in any release; the contract is the nine classes named in the README.
 */
final class CrJsonValue
{
    /**
     * A JSON-encodable value: maps become stdClass, so that `json_encode`
     * writes `{}` for an empty one; call it with JSON_PRESERVE_ZERO_FRACTION.
     *
     * @throws CrJsonValueException naming the reason and the path
     */
    public static function of(mixed $cbor): mixed
    {
        return self::map($cbor, '$');
    }

    private static function map(mixed $value, string $path): mixed
    {
        if ($value instanceof CborBytes) {
            return "b64'".base64_encode($value->bytes)."'";
        }
        if ($value instanceof CborTag) {
            if ($value->number !== 0) {
                throw new CrJsonValueException(sprintf('tag %d at %s: crJSON Table 1 names only tag 0 (date-time)', $value->number, $path));
            }
            if (! is_string($value->value)) {
                throw new CrJsonValueException(sprintf('tag 0 at %s holds %s, not text (RFC 8949 §3.4.1)', $path, get_debug_type($value->value)));
            }

            return $value->value;
        }
        if ($value instanceof CborMap) {
            $object = new \stdClass;
            foreach ($value->entries as $key => $entry) {
                $object->{(string) $key} = self::map($entry, ($path === '$' ? '' : $path.'.').$key);
            }

            return $object;
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                throw new CrJsonValueException(sprintf('a keyed array at %s: a map decoded without keepMaps (SPEC-006 amendment 3), where an empty map cannot be told from an empty array', $path));
            }
            $list = [];
            foreach ($value as $i => $item) {
                $list[] = self::map($item, "{$path}[{$i}]");
            }

            return $list;
        }
        if (is_float($value) && is_nan($value)) {
            throw new CrJsonValueException(sprintf('NaN at %s: JSON has no NaN (RFC 8259 §6)', $path));
        }
        if (is_float($value) && is_infinite($value)) {
            throw new CrJsonValueException(sprintf('%sinfinity at %s: JSON has no infinity (RFC 8259 §6)', $value > 0 ? '+' : '-', $path));
        }
        if (is_int($value) || is_float($value) || is_string($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new CrJsonValueException(sprintf('%s at %s is not a decoded CBOR value', get_debug_type($value), $path));
    }
}
