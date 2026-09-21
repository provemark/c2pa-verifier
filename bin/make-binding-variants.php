<?php

declare(strict_types=1);

/*
 * M4 (SPEC-011/012): builds the hash-binding variants under
 * tests/Fixtures/binding/. Two kinds. File-level: the asset changed outside
 * the manifest store (a pixel byte, bytes appended, bytes inserted before
 * the store) — the claim and its signature untouched, so c2patool's verdict
 * is about the binding alone. Claim-level: the c2pa.hash.data assertion or
 * a hashed URI changed in the PNG fixture's store — every one of these also
 * breaks the signature, so the question to c2patool is which codes it emits
 * alongside claimSignature.mismatch. Prints the SHA-256 of every file
 * written. Run from the repository root. Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

function bU32(string $bytes, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $offset);

    return $u[1];
}

/**
 * @param  list<int>  $enclosing
 */
function bSplice(string $bytes, int $at, int $length, string $with, array $enclosing): string
{
    $delta = strlen($with) - $length;
    foreach ($enclosing as $lboxOffset) {
        $bytes = substr($bytes, 0, $lboxOffset).pack('N', bU32($bytes, $lboxOffset) + $delta).substr($bytes, $lboxOffset + 4);
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + $length);
}

function bReplace(string $bytes, int $at, string $expect, string $with): string
{
    if (substr($bytes, $at, strlen($expect)) !== $expect || strlen($with) !== strlen($expect)) {
        throw new RuntimeException(sprintf('expected %s at %d, found %s', bin2hex($expect), $at, bin2hex(substr($bytes, $at, strlen($expect)))));
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + strlen($expect));
}

function bFind(string $bytes, string $needle, int $from, int $to): int
{
    $at = strpos($bytes, $needle, $from);
    if ($at === false || $at >= $to) {
        throw new RuntimeException(sprintf('%s not found between %d and %d', bin2hex($needle), $from, $to));
    }

    return $at;
}

/** The end offset of the definite-length CBOR item at $p. */
function bCborEnd(string $d, int $p): int
{
    $ib = ord($d[$p]);
    $mt = $ib >> 5;
    $ai = $ib & 0x1F;
    $q = $p + 1;
    $arg = $ai;
    if ($ai >= 24 && $ai <= 27) {
        $w = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai];
        $arg = (int) hexdec(bin2hex(substr($d, $q, $w)));
        $q += $w;
    }

    return match ($mt) {
        0, 1, 7 => $q,
        2, 3 => $q + $arg,
        4 => array_reduce(range(1, max($arg, 0)), static fn (int $e): int => bCborEnd($d, $e), $q),
        5 => array_reduce(range(1, max($arg * 2, 0)), static fn (int $e): int => bCborEnd($d, $e), $q),
        default => bCborEnd($d, $q),
    };
}

/** @return array<string, array{0: int, 1: int, 2: int}> text key => [keyStart, valueStart, valueEnd] */
function bMapPairs(string $d, int $p): array
{
    $count = ord($d[$p]) & 0x1F;
    $q = $p + 1;
    $pairs = [];
    for ($i = 0; $i < $count; $i++) {
        $keyEnd = bCborEnd($d, $q);
        $key = substr($d, $q + 1, $keyEnd - $q - 1);
        $valueEnd = bCborEnd($d, $keyEnd);
        $pairs[$key] = [$q, $keyEnd, $valueEnd];
        $q = $valueEnd;
    }

    return $pairs;
}

function pngWithStore(string $png, string $store): string
{
    $oldLength = bU32($png, 33);
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, 33).$chunk.substr($png, 33 + 12 + $oldLength);
}

/** The PNG with one data byte of its IDAT chunk changed and the chunk's CRC recomputed: a clean pixel edit. */
function pngPixelChanged(string $png): string
{
    $p = bFind($png, 'IDAT', 40, strlen($png)) - 4;
    $length = bU32($png, $p);
    $data = substr($png, $p + 8, $length);
    $data[100] = chr(ord($data[100]) ^ 0x01);
    $chunk = pack('N', $length).'IDAT'.$data.pack('N', crc32('IDAT'.$data));

    return substr($png, 0, $p).$chunk.substr($png, $p + 12 + $length);
}

$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$jpg = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.jpg');
$webp = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.webp');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$s = $extracted->bytes;

// Step 09 offsets (PNG store): hash.data superbox 32831, its cbor box 32903, data 32911 (115 bytes);
// claim cbor box 33073, data 33081 (591 bytes). Enclosing LBoxes for each.
$HASH_DATA = 32911;
$hashBox = [0, 38, 117, 32831, 32903];
$claimBox = [0, 38, 33026, 33073];
$hd = bMapPairs($s, $HASH_DATA);
[$exKey, $exStart, $exEnd] = $hd['exclusions'];            // 81 a2 65 start 18 21 66 length 19 b3 d5
[$algKey, $algStart, $algEnd] = $hd['alg'];                // 66 sha256
[$padKey, $padStart, $padEnd] = $hd['pad'];                // 48 <8 zero bytes>
$claim = bMapPairs($s, 33081);
$created = bMapPairs($s, $claim['created_assertions'][1] + 1);   // the one entry after 0x81
$hashValue = $created['hash'][1];                                // 58 20 <32 bytes>

$fileVariants = [
    // ---- the asset changed, the store untouched: c2patool's verdict is about the binding alone ----
    'pixel-changed.png' => pngPixelChanged($png),
    'pixel-changed.jpg' => (static function (string $j): string {
        $p = strlen($j) - 100;
        $j[$p] = chr(ord($j[$p]) ^ 0x01);

        return $j;
    })($jpg),
    'bytes-appended.png' => $png.'trailing garbage',
    'bytes-appended.jpg' => $jpg.'trailing garbage',
    // 16 bytes inserted before the store: a tEXt chunk after IHDR shifts the caBX chunk (and every exclusion) by 16
    'bytes-inserted-before-store.png' => substr($png, 0, 33).pack('N', 4).'tEXt'.'a=b '.pack('N', crc32('tEXt'.'a=b ')).substr($png, 33),
];

$storeVariants = [
    // ---- the c2pa.hash.data assertion changed (breaks the signature and the assertion's own hashed URI) ----
    'exclusions-overlap' => bSplice($s, $exStart, $exEnd - $exStart, "\x82\xa2\x65start\x18\x21\x66length\x19\xb3\xd5\xa2\x65start\x19\x01\x00\x66length\x19\x01\x00", $hashBox),
    'exclusion-past-end' => bReplace($s, $exStart + 2 + 1 + 5 + 2 + 1 + 6 + 1, "\xb3\xd5", "\xff\xff"),   // length 46037 -> 65535
    // start 33 -> 32, not 34: with 34 the byte that enters the hash (33, a zero of the caBX
    // length) and the byte that leaves it (46070, a zero of the next chunk's length) are equal,
    // so the hash still matched — measured in step 23. One byte earlier hashes the IHDR CRC.
    'exclusion-shifted' => bReplace($s, $exStart + 2 + 1 + 5 + 1, "\x21", "\x20"),
    'hash-missing' => (static function (string $s, int $p, array $hd, array $enc): string {
        [$k, , $e] = $hd['hash'];
        $s = bSplice($s, $k, $e - $k, '', $enc);

        return bReplace($s, $p, "\xa5", "\xa4");
    })($s, $HASH_DATA, $hd, $hashBox),
    'alg-sha1' => bSplice($s, $algStart, $algEnd - $algStart, "\x64sha1", $hashBox),
    'pad-nonzero' => bReplace($s, $padStart + 1, str_repeat("\0", 8), str_repeat("\xff", 8)),
    // ---- a hashed URI's hash changed (the claim, so the signature breaks too) ----
    'hashed-uri-changed' => bReplace($s, $hashValue + 2, $s[$hashValue + 2], chr(ord($s[$hashValue + 2]) ^ 0x01)),
    // ---- an assertion in the store the claim does not declare: a copy of c2pa.actions.v2 relabelled c2pa.extra.v2 ----
    'assertion-undeclared' => (static function (string $s): string {
        $box = substr($s, 32636, 195);                                        // the actions assertion superbox
        $box = bReplace($box, 8 + 8 + 16 + 1, 'c2pa.actions.v2', 'c2pa.extraz.v2x');   // the same length, so nothing else moves

        return bSplice($s, 32831, 0, $box, [0, 38, 117]);                     // inserted before c2pa.hash.data, inside the assertion store
    })($s),
];

$dir = $root.'/tests/Fixtures/binding';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($fileVariants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %7d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
foreach ($storeVariants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.bin", $bytes);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $bytes));
    printf("%s  %7d  %s.bin\n", hash('sha256', $bytes), strlen($bytes), $name);
}
