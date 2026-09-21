<?php

declare(strict_types=1);

/*
 * SPEC-011 (step 24): builds the hashed-URI variants under tests/Fixtures/binding/
 * from the PNG fixture's store. Every one edits the claim or the assertion
 * store; those that touch the claim also break the signature, which is the
 * point — the hashed-URI check and the signature check are independent, and
 * c2patool is asked which codes it emits alongside claimSignature.mismatch.
 * Each variant is written as .bin (the store) and .png (the fixture with that
 * store in its caBX chunk, CRC recomputed). Prints the SHA-256 of every store.
 * Run from the repository root. Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

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

// Step 09 offsets (PNG store): assertion store superbox 117 (LBox 32909, ends 33026);
// thumbnail assertion 166 (32470), actions assertion 32636 (195), hash.data assertion 32831 (195);
// claim superbox 33026, its cbor box 33073, claim data 33081 (591 bytes, map of 7, `alg` the last pair).
$ASSERTION_STORE_END = 33026;
$ACTIONS = 32636;
$CLAIM = 33081;
$storeBox = [0, 38, 117];
$claimBox = [0, 38, 33026, 33073];

$claim = bMapPairs($s, $CLAIM);
$createdEntry = $claim['created_assertions'][1] + 1;              // the one map after 0x81: c2pa.hash.data
$created = bMapPairs($s, $createdEntry);
$gatheredFirst = $claim['gathered_assertions'][1] + 1;            // the first of two after 0x82: c2pa.thumbnail.claim
$gathered = bMapPairs($s, $gatheredFirst);
[$createdHashKey, $createdHashValue, $createdHashEnd] = $created['hash'];   // 58 20 <32 bytes>
[, $gatheredHashValue] = $gathered['hash'];
[$algKey, $algValue, $algEnd] = $claim['alg'];                     // 63 alg 66 sha256
$claimEnd = bCborEnd($s, $CLAIM);
if ($claimEnd !== $CLAIM + 591 || ord($s[$CLAIM]) !== 0xA7) {
    throw new RuntimeException('the claim is not the map of 7 measured in step 09');
}

$hashDataPayload = substr($s, 32831 + 8, 195 - 8);                 // what the hashed URI covers (§8.4.2.3)

$actionsBox = substr($s, $ACTIONS, 195);
$flip = static fn (string $s, int $at): string => bReplace($s, $at, $s[$at], chr(ord($s[$at]) ^ 0x01));

$variants = [
    // ---- AC3: the claim's hashes changed — first entry, then first and second ----
    'hashed-uris-two-changed' => $flip($flip($s, $createdHashValue + 2), $gatheredHashValue + 2),
    // ---- AC4: the first entry's 32-byte hash cut to 31 ----
    'hashed-uri-truncated' => bSplice($s, $createdHashValue, $createdHashEnd - $createdHashValue, "\x58\x1f".substr($s, $createdHashValue + 2, 31), $claimBox),
    // ---- AC5: a second c2pa.actions.v2 box, byte for byte the first, at the end of the assertion store ----
    'assertion-duplicate-label' => bSplice($s, $ASSERTION_STORE_END, 0, $actionsBox, $storeBox),
    // ---- AC6: a copy of the actions box with an unknown UUID and a label no entry names ----
    'assertion-undeclared-unknown-uuid' => (static function (string $s, string $box, array $enc, int $at): string {
        $box = bReplace($box, 8 + 8, "\x63\x62\x6f\x72\x00\x11\x00\x10\x80\x00\x00\xaa\x00\x38\x9b\x71", "\xde\xad\xbe\xef\x00\x11\x00\x10\x80\x00\x00\xaa\x00\x38\x9b\x71");   // cbor -> an unknown UUID
        $box = bReplace($box, 8 + 8 + 16 + 1, 'c2pa.actions.v2', 'c2pa.extraz.v2x');

        return bSplice($s, $at, 0, $box, $enc);
    })($s, $actionsBox, $storeBox, $ASSERTION_STORE_END),
    // ---- AC7: the first entry carries its own alg (sha384, 48-byte hash); the claim's alg still sha256 ----
    'uri-alg-sha384' => (static function (string $s, int $entry, int $hashValue, int $hashEnd, string $payload, array $enc): string {
        $s = bSplice($s, $hashValue, $hashEnd - $hashValue, "\x58\x30".hash('sha384', $payload, true)."\x63alg\x66sha384", $enc);

        return bReplace($s, $entry, "\xa2", "\xa3");
    })($s, $createdEntry, $createdHashValue, $createdHashEnd, $hashDataPayload, $claimBox),
    // ---- AC7: the claim's alg sha256 -> sha1; no entry carries its own ----
    'claim-alg-sha1' => bSplice($s, $algValue, $algEnd - $algValue, "\x64sha1", $claimBox),
    // ---- AC7: the claim's alg pair removed (map of 7 -> 6) ----
    'claim-alg-missing' => bReplace(bSplice($s, $algKey, $algEnd - $algKey, '', $claimBox), $CLAIM, "\xa7", "\xa6"),
    // ---- AC8: redacted_assertions with one entry appended to the claim (map of 7 -> 8) ----
    'claim-redacted' => bReplace(bSplice($s, $claimEnd, 0, "\x73redacted_assertions\x81\x78\x2aself#jumbf=c2pa.assertions/c2pa.actions.v2", $claimBox), $CLAIM, "\xa7", "\xa8"),
];

$dir = $root.'/tests/Fixtures/binding';
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.bin", $bytes);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $bytes));
    printf("%s  %7d  %s.bin\n", hash('sha256', $bytes), strlen($bytes), $name);
}
