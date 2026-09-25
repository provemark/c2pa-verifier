<?php

declare(strict_types=1);

namespace Provemark\C2paVerifier\Tests\Support;

use LogicException;

/**
 * A test-side DER walker for patching tokens (SPEC-016 AC6–AC7, SPEC-017
 * AC3–AC4): read a tag and a length, find the path of elements enclosing
 * an offset, replace bytes, re-encode every enclosing length DER-minimally.
 * Independent of Asn1\DerReader on purpose — a patch must not depend on
 * the code it is meant to exercise. Every patch was checked with
 * `openssl asn1parse` on the patched bytes (notes/step-41-timestamp-tests.md).
 */
final class DerPatch
{
    /**
     * A test-side DER walker for the patches of AC7: the identifier, header
     * length and content length of the element at $offset. Independent of the
     * reader under test on purpose — a patch must not depend on the code it
     * is meant to exercise.
     *
     * @return array{tag: int, headerLength: int, length: int}
     */
    public static function element(string $bytes, int $offset): array
    {
        $tag = ord($bytes[$offset]);
        $first = ord($bytes[$offset + 1]);
        if ($first < 0x80) {
            return ['tag' => $tag, 'headerLength' => 2, 'length' => $first];
        }
        $n = $first & 0x7F;
        $length = 0;
        for ($i = 0; $i < $n; $i++) {
            $length = ($length << 8) | ord($bytes[$offset + 2 + $i]);
        }

        return ['tag' => $tag, 'headerLength' => 2 + $n, 'length' => $length];
    }

    public static function length(int $length): string
    {
        if ($length < 0x80) {
            return pack('C', $length);
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return pack('C', 0x80 | strlen($bytes)).$bytes;
    }

    /**
     * Replace $oldLength bytes at $at by $new and re-encode the length of every
     * enclosing element (DER-minimal), walking from the root. An OCTET STRING
     * whose contents enclose $at is descended into as if it were constructed
     * (the eContent holds the TSTInfo's DER; a digest is not descended into).
     * $oldLength 0 inserts at $at; an
     * insertion at the end of nested elements is ambiguous, so $inside names
     * the element (by offset) whose contents receive it — the walk stops there.
     */
    public static function splice(string $bytes, int $at, int $oldLength, string $new, ?int $inside = null): string
    {
        $path = [];
        $offset = 0;
        while (true) {
            $element = self::element($bytes, $offset);
            $contentsStart = $offset + $element['headerLength'];
            $contentsEnd = $contentsStart + $element['length'];
            // constructed, or an OCTET STRING that wraps a SEQUENCE (the eContent) — never one that holds a digest
            $descendable = ($element['tag'] & 0x20) !== 0 || ($element['tag'] === 0x04 && $element['length'] > 0 && ord($bytes[$contentsStart]) === 0x30);
            $enclosesStrictly = $at >= $contentsStart && $at + $oldLength <= $contentsEnd && ! ($at === $offset && $oldLength === $element['headerLength'] + $element['length']);
            if (! $enclosesStrictly) {
                break;
            }
            $path[] = $offset; // its length changes even when it is a primitive holding the target (the imprint's OCTET STRING)
            if (! $descendable || $offset === $inside) {
                break;
            }
            $child = $contentsStart;
            $next = null;
            while ($child < $contentsEnd) {
                $c = self::element($bytes, $child);
                $childEnd = $child + $c['headerLength'] + $c['length'];
                if ($at >= $child && $at + $oldLength <= $childEnd) {
                    $next = $child;
                    break;
                }
                $child = $childEnd;
            }
            if ($next === null) {
                break; // $at is between children (an insertion point) or at the very end
            }
            $offset = $next;
        }
        if ($path === []) {
            throw new LogicException("no element encloses offset {$at}");
        }
        if ($inside !== null && ! in_array($inside, $path, true)) {
            throw new LogicException("the walk to offset {$at} did not pass the element at {$inside}: ".implode(',', $path));
        }
        $bytes = substr_replace($bytes, $new, $at, $oldLength);
        $delta = strlen($new) - $oldLength;
        foreach (array_reverse($path) as $p) {
            $element = self::element($bytes, $p);
            $header = pack('C', $element['tag']).self::length($element['length'] + $delta);
            $bytes = substr_replace($bytes, $header, $p, $element['headerLength']);
            $delta += strlen($header) - $element['headerLength'];
        }

        return $bytes;
    }

    /**
     * Every constructed element with at least one child, as [offset, contents offset, contents length,
     * the last child's offset], in file order — descending into an OCTET STRING that wraps a SEQUENCE,
     * as splice() does (SPEC-016 amendment 4: each one emptied, and each without its last child).
     *
     * @return list<array{offset: int, contents: int, length: int, last: int}>
     */
    public static function constructed(string $bytes, int $offset = 0, ?int $end = null): array
    {
        $end ??= strlen($bytes);
        $out = [];
        while ($offset < $end) {
            $element = self::element($bytes, $offset);
            $contents = $offset + $element['headerLength'];
            $descendable = ($element['tag'] & 0x20) !== 0 || ($element['tag'] === 0x04 && $element['length'] > 0 && ord($bytes[$contents]) === 0x30);
            if ($descendable && $element['length'] > 0) {
                $last = $contents;
                for ($child = $contents; $child < $contents + $element['length']; $child += $c['headerLength'] + $c['length']) {
                    $c = self::element($bytes, $child);
                    $last = $child;
                }
                if (($element['tag'] & 0x20) !== 0) {
                    $out[] = ['offset' => $offset, 'contents' => $contents, 'length' => $element['length'], 'last' => $last];
                }
                $out = [...$out, ...self::constructed($bytes, $contents, $contents + $element['length'])];
            }
            $offset = $contents + $element['length'];
        }

        return $out;
    }

    /**
     * The SET OF SignerInfo of a sigTst value: its offset, header length, contents offset and length. Found as the last child of SignedData.
     *
     * @return array{offset: int, headerLength: int, contents: int, length: int, tag: int}
     */
    public static function signerInfoSet(string $bytes): array
    {
        $signedData = 9 + 19; // the SEQUENCE inside [0] inside ContentInfo, on the C.jpg token: offset 19, plus the wrapper
        $sd = self::element($bytes, $signedData);
        $child = $signedData + $sd['headerLength'];
        $end = $child + $sd['length'];
        $last = null;
        while ($child < $end) {
            $e = self::element($bytes, $child);
            $last = ['offset' => $child, 'headerLength' => $e['headerLength'], 'contents' => $child + $e['headerLength'], 'length' => $e['length'], 'tag' => $e['tag']];
            $child += $e['headerLength'] + $e['length'];
        }
        if ($last === null || $last['tag'] !== 0x31) {
            throw new LogicException('the last child of SignedData is not a SET');
        }

        return $last;
    }

    /**
     * The [0] signedAttrs of the one SignerInfo.
     *
     * @return array{offset: int, headerLength: int, length: int}
     */
    public static function signedAttrs(string $bytes): array
    {
        $set = self::signerInfoSet($bytes);
        $si = self::element($bytes, $set['contents']);
        $child = $set['contents'] + $si['headerLength'];
        $end = $child + $si['length'];
        while ($child < $end) {
            $e = self::element($bytes, $child);
            if ($e['tag'] === 0xA0) {
                return ['offset' => $child, 'headerLength' => $e['headerLength'], 'length' => $e['length']];
            }
            $child += $e['headerLength'] + $e['length'];
        }
        throw new LogicException('no signedAttrs');
    }

    /**
     * The Attribute SEQUENCE inside signedAttrs whose OID has the given hex contents.
     *
     * @return array{offset: int, headerLength: int, length: int}
     */
    public static function attribute(string $bytes, string $oidHex): array
    {
        $attrs = self::signedAttrs($bytes);
        $child = $attrs['offset'] + $attrs['headerLength'];
        $end = $child + $attrs['length'];
        while ($child < $end) {
            $e = self::element($bytes, $child);
            $oid = self::element($bytes, $child + $e['headerLength']);
            if (bin2hex(substr($bytes, $child + $e['headerLength'] + $oid['headerLength'], $oid['length'])) === $oidHex) {
                return ['offset' => $child, 'headerLength' => $e['headerLength'], 'length' => $e['length']];
            }
            $child += $e['headerLength'] + $e['length'];
        }
        throw new LogicException("no attribute {$oidHex}");
    }
}
