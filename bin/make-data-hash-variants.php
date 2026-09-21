<?php

declare(strict_types=1);

/*
 * SPEC-012 (step 26): builds the data-hash variants under tests/Fixtures/binding/
 * from the PNG fixture's store. Every one edits the c2pa.hash.data assertion
 * (or its label) and therefore its hashed URI in the claim; where the edit
 * is meant to be *valid* (an extra exclusion, sha384, a duplicate binding)
 * the data hash is recomputed over the resulting PNG and the claim's hashed
 * URI for the assertion is recomputed too, so that only the signature is
 * broken — c2patool is then asked whether the data hash matches, which is
 * the check on this script's arithmetic before any of it is in src/. Each
 * variant is written as .bin (the store) and .png (the fixture carrying
 * it, CRC recomputed). Prints the SHA-256 of every store. Run from the
 * repository root. Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

/**
 * The hash of $bytes with the [start, length] ranges skipped — what
 * c2pa.hash.data covers (C2PA 2.4 §15.12.1). Tooling: the file is in memory here.
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function dataHash(string $alg, string $bytes, array $exclusions): string
{
    usort($exclusions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $ctx = hash_init($alg);
    $p = 0;
    foreach ($exclusions as [$start, $length]) {
        hash_update($ctx, substr($bytes, $p, $start - $p));
        $p = $start + $length;
    }
    hash_update($ctx, substr($bytes, $p));

    return hash_final($ctx, true);
}

$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$s = $extracted->bytes;

// Step 09 offsets (PNG store): assertion store 117 (ends 33026); hash.data superbox 32831 (195 bytes),
// its jumd 32839, its cbor box 32903, data 32911 (115 bytes, map of 5: exclusions, name, alg, hash, pad);
// claim superbox 33026, cbor box 33073, data 33081 (map of 7). The caBX chunk starts at 33 in the PNG:
// store offset x is PNG offset 33 + 8 + x; the store's exclusion is [33, 12 + strlen(store)].
$HASH_DATA_BOX = 32831;
$HASH_DATA = 32911;
$CLAIM = 33081;
$storeBox = [0, 38, 117];
$hashBox = [0, 38, 117, 32831, 32903];
$hashJumd = [0, 38, 117, 32831, 32839];
$claimBox = [0, 38, 33026, 33073];
$LABEL = 32839 + 8 + 16 + 1;                                       // the jumd's label, after LBox/TBox, UUID, toggles
$hd = bMapPairs($s, $HASH_DATA);
[$exKey, $exStart, $exEnd] = $hd['exclusions'];                    // 81 a2 65 start 18 21 66 length 19 b3 d5
[$algKey, $algStart, $algEnd] = $hd['alg'];                        // 66 sha256
[, $hashStart, $hashEnd] = $hd['hash'];                            // 58 20 <32>
$claim = bMapPairs($s, $CLAIM);
$createdList = $claim['created_assertions'][1];                    // 81
$createdEntry = $createdList + 1;                                  // a2 63 url 78 2a <42> 64 hash 58 20 <32>
$createdEntryEnd = bCborEnd($s, $createdEntry);
$created = bMapPairs($s, $createdEntry);
$urlStart = $created['url'][1];
$claimHashValue = $created['hash'][1] + 2;                         // the 32 bytes

$exclusion = static fn (int $start, int $length): string => "\xa2\x65start".cborUint($start)."\x66length".cborUint($length);

function cborUint(int $n): string
{
    if ($n < 0) {
        throw new RuntimeException('a CBOR unsigned integer cannot be negative');
    }

    return match (true) {
        $n < 24 => chr($n),
        $n < 256 => "\x18".chr($n),
        $n < 65536 => "\x19".pack('n', $n),
        default => "\x1a".pack('N', $n),
    };
}

/**
 * The store with its hash.data assertion's `hash` recomputed over the PNG that
 * carries it, minus $extraRanges and the store's own range, and the claim's
 * hashed URI for the assertion recomputed. The signature is left broken.
 *
 * @param  list<array{0: int, 1: int}>  $extraRanges  PNG offsets
 */
function rehashed(string $store, string $png, string $alg, int $hashValueAt, int $digestLength, array $extraRanges, int $claimHashValueAt, int $hashDataBox): string
{
    $carrier = pngWithStore($png, $store);
    $digest = dataHash($alg, $carrier, [[33, 12 + strlen($store)], ...$extraRanges]);
    if (strlen($digest) !== $digestLength) {
        throw new RuntimeException('digest length');
    }
    $store = bReplace($store, $hashValueAt, substr($store, $hashValueAt, $digestLength), $digest);
    $boxLength = bU32($store, $hashDataBox);
    $uri = hash('sha256', substr($store, $hashDataBox + 8, $boxLength - 8), true);

    return bReplace($store, $claimHashValueAt, substr($store, $claimHashValueAt, 32), $uri);
}

// ---- AC4/AC5: a second exclusion over 64 bytes of IDAT data; the store's exclusion grows by the 19 bytes the entry adds ----
$extra = [46500, 64];
$twoRanges = static function (string $s, bool $sorted) use ($exStart, $exEnd, $hashBox, $exclusion, $extra): string {
    $store = $exclusion(33, 12 + 46025 + 19);
    $other = $exclusion($extra[0], $extra[1]);
    $list = "\x82".($sorted ? $store.$other : $other.$store);
    if (strlen($list) !== $exEnd - $exStart + 19) {
        throw new RuntimeException('exclusion list length');
    }

    return bSplice($s, $exStart, $exEnd - $exStart, $list, $hashBox);
};

// ---- AC7: sha384, 48-byte hash (+16 bytes, so the store's exclusion grows by 16) ----
$sha384 = static function (string $s) use ($algStart, $algEnd, $hashStart, $hashEnd, $exStart, $exEnd, $hashBox, $exclusion): string {
    // back to front, so that each offset is still where it was measured
    $s = bSplice($s, $hashStart, $hashEnd - $hashStart, "\x58\x30".str_repeat("\0", 48), $hashBox);
    $s = bSplice($s, $algStart, $algEnd - $algStart, "\x66sha384", $hashBox);

    return bSplice($s, $exStart, $exEnd - $exStart, "\x81".$exclusion(33, 12 + 46025 + 16), $hashBox);
};

// ---- AC8: the label of the hash.data box and the claim's url for it ----
$relabel = static function (string $s, string $label) use ($LABEL, $hashJumd, $urlStart, $claimBox): string {
    $s = bSplice($s, $LABEL, 14, $label, $hashJumd);                                   // 'c2pa.hash.data' is 14 bytes
    $delta = strlen($label) - 14;
    $url = 'self#jumbf=c2pa.assertions/'.$label;                                        // 27 + label
    if (strlen($url) > 255) {
        throw new RuntimeException('url too long for a one-byte text-string length');
    }

    // the jumd splice moved the claim, and its LBoxes, by $delta
    return bSplice($s, $urlStart + $delta, 2 + 27 + 14, "\x78".chr(strlen($url)).$url, array_map(static fn (int $o): int => $o < 117 ? $o : $o + $delta, $claimBox));
};

$variants = [
    'exclusion-extra' => static fn (string $s): string => rehashed($twoRanges($s, true), $png, 'sha256', $hashStart + 2 + 19, 32, [$extra], $claimHashValue + 19, $HASH_DATA_BOX),
    'exclusions-unsorted' => static fn (string $s): string => rehashed($twoRanges($s, false), $png, 'sha256', $hashStart + 2 + 19, 32, [$extra], $claimHashValue + 19, $HASH_DATA_BOX),
    // ---- AC6: shape faults (the hashed URI is left as it was: SPEC-011 says mismatch, SPEC-012 answers on its own) ----
    'exclusions-not-list' => static fn (string $s): string => bSplice($s, $exStart, $exEnd - $exStart, substr($s, $exStart + 1, $exEnd - $exStart - 1), $hashBox),   // the one map itself, not a list of one
    'exclusion-start-negative' => static fn (string $s): string => bSplice($s, $exStart + 2 + 6, 2, "\x20", $hashBox),                                                 // 18 21 -> 20 (-1)
    'exclusion-length-text' => static fn (string $s): string => bSplice($s, $exStart + 2 + 6 + 2 + 7, 3, "\x65".'46037', $hashBox),                                    // 19 b3 d5 -> "46037"
    'hash-as-text' => static fn (string $s): string => bReplace($s, $hashStart, "\x58\x20".substr($s, $hashStart + 2, 32), "\x78\x20".substr(bin2hex(substr($s, $hashStart + 2, 16)), 0, 32)),
    'exclusions-too-many' => static fn (string $s): string => bSplice($s, $exStart, $exEnd - $exStart, "\x99\x04\x01".str_repeat($exclusion(0, 0), 1025), $hashBox),
    // ---- AC7: the assertion's alg removed (map of 5 -> 4; the claim's sha256 applies), and sha384 ----
    'alg-missing' => static function (string $s) use ($algKey, $algEnd, $hashBox, $HASH_DATA, $exStart, $png, $hashStart, $claimHashValue, $HASH_DATA_BOX): string {
        $s = bReplace(bSplice($s, $algKey, $algEnd - $algKey, '', $hashBox), $HASH_DATA, "\xa5", "\xa4");   // the pair is 11 bytes: the store shrinks, so its exclusion does
        $s = bReplace($s, $exStart + 17, "\x19\xb3\xd5", cborUint(12 + 46025 - 11));

        return rehashed($s, $png, 'sha256', $hashStart + 2 - 11, 32, [], $claimHashValue - 11, $HASH_DATA_BOX);
    },
    'alg-sha384' => static fn (string $s): string => rehashed($sha384($s), $png, 'sha384', $hashStart + 2, 48, [], $claimHashValue + 16, $HASH_DATA_BOX),
    // ---- AC8: no hard binding, another kind, two ----
    'hard-binding-missing' => static fn (string $s): string => rehashed($relabel($s, 'c2pa.othr.data'), $png, 'sha256', $hashStart + 2, 32, [], $claimHashValue, $HASH_DATA_BOX),
    // the label is 3 bytes longer, so the assertion's cbor moves by 3 and the claim's hash value by 3 + 3 (the url grew too)
    'hard-binding-bmff' => static fn (string $s): string => rehashed($relabel($s, 'c2pa.hash.bmff.v2'), $png, 'sha256', $hashStart + 2 + 3, 32, [], $claimHashValue + 6, $HASH_DATA_BOX),
    'hard-bindings-two' => static function (string $s) use ($HASH_DATA_BOX, $storeBox, $createdList, $createdEntry, $createdEntryEnd, $claimBox, $exStart, $hashStart, $claimHashValue, $png): string {
        $entryLength = $createdEntryEnd - $createdEntry;                                            // 87
        $final = 46025 + 195 + $entryLength;                                                        // the store with a second box and a second entry
        $s = bReplace($s, $exStart + 17, "\x19\xb3\xd5", cborUint(12 + $final));                   // before copying, so both boxes carry it
        $s = bSplice($s, 33026, 0, substr($s, $HASH_DATA_BOX, 195), $storeBox);                     // a second c2pa.hash.data at the end of the store
        $entry = substr($s, $createdEntry + 195, $entryLength);                                     // the claim moved by 195
        $s = bSplice($s, $createdEntryEnd + 195, 0, $entry, array_map(static fn (int $o): int => $o < 117 ? $o : $o + 195, $claimBox));
        $s = bReplace($s, $createdList + 195, "\x81", "\x82");
        // the data hash over the carrier minus the store, in both boxes; the hashed URI (identical boxes) in both entries
        $digest = dataHash('sha256', pngWithStore($png, $s), [[33, 12 + $final]]);
        foreach ([$hashStart + 2, 33026 + ($hashStart + 2 - $HASH_DATA_BOX)] as $at) {
            $s = bReplace($s, $at, substr($s, $at, 32), $digest);
        }
        $uri = hash('sha256', substr($s, $HASH_DATA_BOX + 8, 195 - 8), true);
        foreach ([$claimHashValue + 195, $claimHashValue + 195 + $entryLength] as $at) {
            $s = bReplace($s, $at, substr($s, $at, 32), $uri);
        }

        return $s;
    },
];

$dir = $root.'/tests/Fixtures/binding';
foreach ($variants as $name => $make) {
    $bytes = $make($s);
    file_put_contents("{$dir}/{$name}.bin", $bytes);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $bytes));
    printf("%s  %7d  %s.bin\n", hash('sha256', $bytes), strlen($bytes), $name);
}
