<?php

declare(strict_types=1);

/*
 * SPEC-005: builds the malformed manifest-store variants under
 * tests/Fixtures/jumbf/ from the store inside tests/Fixtures/fixture-signed.png
 * (extracted with the SPEC-002 extractor). Each variant changes one field of
 * the store, or grows one box by a few bytes with every enclosing LBox
 * adjusted, nothing else. Next to every `.bin` a `.png` is written with the
 * variant store re-embedded in the fixture's caBX chunk (CRC recomputed), so
 * that c2patool can be asked what it makes of it. Run from the repository
 * root; prints the SHA-256 of every file it writes. Tooling, not product code.
 *
 * Offsets are those measured in notes/step-09-manifest-store-inside.md for
 * the PNG store; the script checks the bytes it expects before it changes them.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

/** Overwrites a big-endian field of $width bytes at $offset with $value. */
function jumbfPut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = $width - 1; $i >= 0; $i--) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/** Replaces $length bytes at $offset with $with (same length), after checking what is there. */
function jumbfReplace(string $bytes, int $offset, string $expect, string $with): string
{
    if (substr($bytes, $offset, strlen($expect)) !== $expect) {
        throw new RuntimeException(sprintf('expected %s at %d, found %s', bin2hex($expect), $offset, bin2hex(substr($bytes, $offset, strlen($expect)))));
    }
    if (strlen($with) !== strlen($expect)) {
        throw new RuntimeException('jumbfReplace keeps the length; use jumbfGrow to change it');
    }

    return substr($bytes, 0, $offset).$with.substr($bytes, $offset + strlen($expect));
}

/**
 * Inserts $extra bytes at $at and adds their length to the LBox at every
 * offset in $enclosing (the boxes that contain the insertion point).
 *
 * @param  list<int>  $enclosing
 */
function jumbfGrow(string $bytes, int $at, string $extra, array $enclosing): string
{
    foreach ($enclosing as $lboxOffset) {
        $bytes = jumbfPut($bytes, $lboxOffset, 4, jumbfU32($bytes, $lboxOffset) + strlen($extra));
    }

    return substr($bytes, 0, $at).$extra.substr($bytes, $at);
}

/** hex2bin() that returns string, never false: the hex here is a constant. */
function jumbfHex(string $hex): string
{
    $bin = hex2bin($hex);
    if ($bin === false) {
        throw new RuntimeException("not hex: {$hex}");
    }

    return $bin;
}

function jumbfU32(string $bytes, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $offset);

    return $u[1];
}

/** A superbox nested $depth deep, each level with a minimal description box; a cbor box innermost. */
function jumbfNested(int $depth): string
{
    $inner = $depth === 0
        ? pack('N', 9).'cbor'.chr(0xA0)   // an empty CBOR map
        : jumbfNested($depth - 1);
    $jumd = pack('N', 8 + 16 + 1 + 2).'jumd'.jumbfHex('6332706100110010800000aa00389b71').chr(3)."a\0";

    return pack('N', 8 + strlen($jumd) + strlen($inner)).'jumb'.$jumd.$inner;
}

/** The fixture PNG with $store in place of its caBX chunk's data, CRC recomputed. */
function pngWithStore(string $png, string $store): string
{
    $cabxOffset = 33; // after the signature (8) and IHDR (25)
    $oldLength = jumbfU32($png, $cabxOffset);
    if (substr($png, $cabxOffset + 4, 4) !== 'caBX') {
        throw new RuntimeException('caBX chunk not at offset 33');
    }
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, $cabxOffset).$chunk.substr($png, $cabxOffset + 12 + $oldLength);
}

$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null) {
    throw new RuntimeException('no store in the PNG fixture');
}
$s = $extracted->bytes;
if (strlen($s) !== 46025 || hash('sha256', $s) !== '1a018eb892c4b30c112976cd7411df24baa9ce788cfe2904f58a69dec6e057df') {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}

// Offsets measured in step 09 (PNG store). Superbox header = LBox(4) TBox(4); jumd = header, uuid(16), toggles(1), label, NUL, [c2sh].
$ROOT = 0;
$ROOT_JUMD = 8;
$MANIFEST = 38;
$MANIFEST_JUMD = 46;
$ASSERTIONS = 117;
$THUMB = 166;
$THUMB_JUMD = 174;
$THUMB_BIDB = 264;
$HASHDATA = 32831;
$HASHDATA_JUMD = 32839;
$CLAIM = 33026;
$CLAIM_JUMD = 33034;
$CLAIM_CBOR = 33073;
$CLAIM_TOGGLES = $CLAIM_JUMD + 8 + 16;          // 33058
$CLAIM_LABEL = $CLAIM_TOGGLES + 1;              // 33059, 'c2pa.claim.v2', NUL at 33072
$HASHDATA_SALT = $HASHDATA_JUMD + 8 + 16 + 1 + 15; // 32879: the c2sh box (LBox 24, 'c2sh', 16 bytes)
$UUID_C2PA = jumbfHex('6332706100110010800000aa00389b71');
$UUID_C2MA = jumbfHex('63326d6100110010800000aa00389b71');
$UUID_C2CM = jumbfHex('6332636d00110010800000aa00389b71');
$UUID_C2UM = jumbfHex('6332756d00110010800000aa00389b71');

$variants = [
    // AC7: the thumbnail assertion's type UUID unknown
    'unknown-uuid' => jumbfReplace($s, $THUMB_JUMD + 8, jumbfHex('40cb0c32bb8a489da70b2ad6f47f4369'), str_repeat("\xFF", 16)),
    // AC8: the claim superbox's LBox 0, 1, 7
    'lbox-zero' => jumbfPut($s, $CLAIM, 4, 0),
    'lbox-one' => jumbfPut($s, $CLAIM, 4, 1),
    'lbox-seven' => jumbfPut($s, $CLAIM, 4, 7),
    // AC9: the hash.data superbox's LBox 195 -> 205, overrunning the assertion store
    'child-overruns' => jumbfPut($s, $HASHDATA, 4, 205),
    // AC10: the root's LBox 46025 -> 46026, its children end one byte early
    'root-lbox-plus-one' => jumbfPut($s, $ROOT, 4, 46026),
    // AC11: the claim's description box typed jumx
    'first-child-not-jumd' => jumbfReplace($s, $CLAIM_JUMD + 4, 'jumd', 'jumx'),
    // AC12: description-box faults on the claim's jumd, and the hash.data salt
    'toggles-bit5' => jumbfPut($s, $CLAIM_TOGGLES, 1, 35),
    'toggles-no-label' => jumbfPut($s, $CLAIM_TOGGLES, 1, 1),
    'label-no-nul' => jumbfReplace($s, $CLAIM_LABEL + 13, "\0", 'x'),
    'label-slash' => jumbfReplace($s, $CLAIM_LABEL, 'c2pa.claim.v2', 'c2pa/claim.v2'),
    'label-control' => jumbfReplace($s, $CLAIM_LABEL, 'c2pa.claim.v2', "c2pa\x01claim.v2"),
    // a 20-byte salt: 4 bytes inserted into the c2sh box, every enclosing LBox +4
    'salt-20' => jumbfGrow($s, $HASHDATA_SALT + 24, "\0\0\0\0", [$ROOT, $MANIFEST, $ASSERTIONS, $HASHDATA, $HASHDATA_JUMD, $HASHDATA_SALT]),
    // a 32-byte salt: valid per §8.4.2.3 (the assertion's hash no longer matches the claim: M4's concern)
    'salt-32' => jumbfGrow($s, $HASHDATA_SALT + 24, str_repeat("\xAB", 16), [$ROOT, $MANIFEST, $ASSERTIONS, $HASHDATA, $HASHDATA_JUMD, $HASHDATA_SALT]),
    'private-not-c2sh' => jumbfReplace($s, $HASHDATA_SALT + 4, 'c2sh', 'c2sx'),
    // AC13: compressed / update manifests, a brob content box
    'uuid-c2cm' => jumbfReplace($s, $MANIFEST_JUMD + 8, $UUID_C2MA, $UUID_C2CM),
    'uuid-c2um' => jumbfReplace($s, $MANIFEST_JUMD + 8, $UUID_C2MA, $UUID_C2UM),
    'brob' => jumbfReplace($s, $CLAIM_CBOR + 4, 'cbor', 'brob'),
    // AC14: bfdb without bidb
    'bidb-missing' => jumbfReplace($s, $THUMB_BIDB + 4, 'bidb', 'bxdb'),
    // AC15: the root
    'root-uuid-c2ma' => jumbfReplace($s, $ROOT_JUMD + 8, $UUID_C2PA, $UUID_C2MA),
    'root-label' => jumbfReplace($s, $ROOT_JUMD + 8 + 16 + 1, 'c2pa', 'c2pb'),
    'not-a-superbox' => jumbfReplace($s, 4, 'jumb', 'cbor'),
    // AC16: seventeen nested superboxes
    'depth-17' => jumbfNested(16),
];

$dir = $root.'/tests/Fixtures/jumbf';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.bin", $bytes);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $bytes));
    printf("%s  %6d  %s.bin\n", hash('sha256', $bytes), strlen($bytes), $name);
}
