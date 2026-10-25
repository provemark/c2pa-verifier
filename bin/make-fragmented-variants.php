<?php

declare(strict_types=1);

/*
 * SPEC-028, step 83a: the broken fragmented streams.
 *
 * Each variant is the five-fragment stream with exactly one thing wrong, so
 * that a failure names one cause. AC5's cases — a fragment withheld, a
 * fragment offered twice — need no file: the test simply offers a different
 * set, which is the point of taking an iterable.
 *
 * Tooling, outside the Deptrac layers. Run: php bin/make-fragmented-variants.php
 */

$root = dirname(__DIR__);
$source = $root.'/tests/Fixtures/bmff-fragmented';
$directory = $source.'/broken';

if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
    throw new RuntimeException("cannot create {$directory}");
}

/** One byte of a file, flipped, written under a new name. */
function flip(string $from, string $to, int $at): void
{
    $bytes = (string) file_get_contents($from);
    $bytes[$at] = chr(ord($bytes[$at]) ^ 0xFF);
    file_put_contents($to, $bytes);
}

// AC2: the init segment's moov, which `initHash` covers. Measured in step 82:
// ftyp(0,28) uuid(28,13647) moov(13675,805).
flip($source.'/init.mp4', $directory.'/init-byte-changed.mp4', 13675 + 100);

// AC3: a leaf. seg_3's mdat begins at 435 in every fragment of this stream.
flip($source.'/seg_3.m4s', $directory.'/seg_3-byte-changed.m4s', 435 + 100);

/** A fragment with its merkle box's `location` changed, written under a new name. */
function relocate(string $from, string $to, string $old, string $new): void
{
    $bytes = (string) file_get_contents($from);
    $key = "\x68location";   // the CBOR text string "location", then its one-byte integer value
    if (substr_count($bytes, $key.$old) !== 1) {
        throw new RuntimeException("expected one location {$old} in {$from}");
    }
    file_put_contents($to, str_replace($key.$old, $key.$new, $bytes));
}

// AC8 (step 151, amendment 1): a location outside [0, count). The merkle box is excluded from the
// leaf hash, so the fragment still hashes to the same leaf; only its claimed place changes.
relocate($source.'/seg_5.m4s', $directory.'/seg_5-location-5.m4s', "\x04", "\x05");
relocate($source.'/seg_1.m4s', $directory.'/seg_1-location-minus-1.m4s', "\x00", "\x20");

// step 432: a fragment with no merkle box. seg_3's C2PA `uuid` box retyped `free`, its length and contents
// kept: nothing in the fragment says where it belongs in the tree (C2PA 2.4 §15.12.2.2)
$bytes = (string) file_get_contents($source.'/seg_3.m4s');
$at = strpos($bytes, 'uuid');
if ($at === false || substr_count($bytes, 'uuid') !== 1) {
    throw new RuntimeException('expected one uuid box in seg_3.m4s');
}
file_put_contents($directory.'/seg_3-no-merkle.m4s', substr_replace($bytes, 'free', $at, 4));

// step 435: the merkle map's own alg (C2PA 2.4 §13.1: the nearest identifier) set to sha384, the assertion's left
// at sha256; the same length, not re-signed, so the assertion's hashed URI no longer matches either
$bytes = (string) file_get_contents($source.'/init.mp4');
$map = "\x65count\x05\x63alg\x66sha256\x68initHash";
if (substr_count($bytes, $map) !== 1) {
    throw new RuntimeException('expected one merkle map with alg sha256 in init.mp4');
}
file_put_contents($directory.'/init-merkle-alg-sha384.mp4', str_replace($map, str_replace('sha256', 'sha384', $map), $bytes));

printf("6 variants in %s\n", $directory);
printf("AC4 uses ../foreign-seg_3.m4s, a fragment of the seven-fragment stream\n");
printf("AC5 needs no file: the test offers four fragments, then five with one repeated\n");
