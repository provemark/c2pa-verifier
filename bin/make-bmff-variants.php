<?php

declare(strict_types=1);

/*
 * SPEC-027, step 78a: the BMFF-hash variants, built byte by byte from
 * tests/Fixtures/fixture-signed.mp4.
 *
 * Each keeps the manifest and its signature intact and changes only what the
 * hard binding covers, so that a failure is the hash and nothing else. The one
 * exception is `xpath-nested`, which edits four bytes inside the assertion and
 * therefore breaks the claim signature too; its test says so.
 *
 * Tooling, outside the Deptrac layers. Run: php bin/make-bmff-variants.php
 */

$root = dirname(__DIR__);
$source = $root.'/tests/Fixtures/fixture-signed.mp4';
$directory = $root.'/tests/Fixtures/bmff';

$bytes = (string) file_get_contents($source);
if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
    throw new RuntimeException("cannot create {$directory}");
}

$written = [];
$write = static function (string $name, string $contents) use ($directory, &$written): void {
    file_put_contents($directory.'/'.$name, $contents);
    $written[$name] = strlen($contents);
};

// The layout, measured in step 73: ftyp(0,32) uuid(32,13578) moov(13610,945)
// free(14555,8) mdat(14563,1878). The hash covers moov and mdat, each preceded
// by its own offset as a big-endian uint64.
const MOOV_AT = 13610;
const MDAT_AT = 14563;

// AC2: one byte of mdat, changed. Everything else — including every offset — is
// what it was, so only the bytes can be what failed.
$changed = $bytes;
$changed[MDAT_AT + 100] = chr(ord($changed[MDAT_AT + 100]) ^ 0xFF);
$write('mdat-byte-changed.mp4', $changed);

// AC3: an eight-byte `free` box in front of moov. Every byte the hash covers is
// identical; only where those bytes sit has changed. Without the offset markers
// this file would verify, which is the whole reason the markers exist.
$free = pack('N', 8).'free';
$write('box-moved.mp4', substr($bytes, 0, MOOV_AT).$free.substr($bytes, MOOV_AT));

// AC5: a nested exclusion path. `/free` and `/a/b` are both five characters, so
// the CBOR keeps its length and only the four bytes of the string move — which
// does break the claim signature, and the test asserts the hash refusal comes
// with it rather than instead of it.
$at = strpos($bytes, '/free', 77);
if ($at === false) {
    throw new RuntimeException('no /free exclusion in the assertion');
}
$write('xpath-nested.mp4', substr_replace($bytes, '/a/b', $at, 4));

printf("%d variants in %s\n", count($written), $directory);
foreach ($written as $name => $size) {
    printf("  %-26s %6d bytes\n", $name, $size);
}
