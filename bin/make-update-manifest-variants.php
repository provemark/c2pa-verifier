<?php

declare(strict_types=1);

/*
 * Step 57 (SPEC-022): variants of c2pa-rs/update_manifest.jpg — the one corpus file with a c2um
 * update manifest — and one of the PNG fixture, for the rules of C2PA 2.4 §11.2.3 and §15.12.
 *
 *   pixel-changed          one byte of the JPEG *outside* the manifest store flipped; nothing is
 *                          re-signed (the store is untouched), so this measures only whether the
 *                          adjusted exclusion still covers the store and no more (AC3)
 *   action-not-allowed     the update manifest's action c2pa.opened -> c2pa.edited (AC4 a)
 *   hash-in-update         its c2pa.time-stamp assertion renamed c2pa.hash.data (AC4 b)
 *   ingredient-inputto     its ingredient's relationship parentOf -> inputTo (AC4 c: no parentOf)
 *   no-standard-parent     the *parent* manifest's box UUID c2ma -> c2um, so the parentOf chain
 *                          never reaches a standard manifest (AC5)
 *   two-parents            the PNG fixture with two parentOf ingredient assertions (AC6)
 *
 * Every variant but the first is re-signed with a throw-away P-256 hierarchy (keys outside the
 * repository, deleted at the end of the run); the public root goes into a settings file together
 * with the C2PA test anchors, so that only the fault under test can make a variant Invalid.
 * Decided by Maurice van Loon on 2026-09-21: tooling may sign with throw-away keys.
 *
 * Usage: php bin/make-update-manifest-variants.php <scratch-dir>. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$scratch = $argv[1] ?? null;
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-update-manifest-variants.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/update-manifest-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function umRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function umSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A CBOR byte string head plus the bytes. */
function umBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? pack('C', 0x40 + $n) : ($n < 256 ? pack('CC', 0x58, $n) : pack('Cn', 0x59, $n))).$b;
}

/** A DER ECDSA signature as R‖S of 2 × 32 bytes (what COSE carries for ES256). */
function umDerToRs(string $der): string
{
    if ($der[0] !== "\x30") {
        throw new RuntimeException('not a DER ECDSA signature');
    }
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/**
 * The JPEG with a new manifest store: the one APP11 segment rebuilt (CI "JP", the box instance and
 * packet sequence numbers kept, then the store's bytes — its first 8 are LBox and TBox).
 */
function umJpegWithStore(string $jpeg, string $store, int $segmentAt): string
{
    $header = unpack('n', substr($jpeg, $segmentAt + 2, 2));
    if ($header === false || ! is_int($header[1])) {
        throw new RuntimeException('cannot read the APP11 segment length');
    }
    $old = $header[1];
    if (strlen($store) + 10 > 65535) {
        throw new RuntimeException('the store no longer fits in one APP11 segment');
    }
    // after the marker and the length: CI "JP" (2), the box instance number (2), the packet sequence
    // number (4) — kept as they were — and then the store, whose first 8 bytes are LBox and TBox
    $segment = "\xff\xeb".pack('n', 10 + strlen($store)).substr($jpeg, $segmentAt + 4, 8).$store;

    return substr($jpeg, 0, $segmentAt).$segment.substr($jpeg, $segmentAt + 2 + $old);
}

/**
 * The update manifest's claim: the offset of its CBOR payload, that payload's length, and the offset
 * of the manifest box itself.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function umClaimAt(string $store): array
{
    $box = 38 + bU32($store, 38);                       // the second top-level box: the c2um manifest
    $p = $box + 8;
    $end = $box + bU32($store, $box);
    while ($p < $end) {
        $len = bU32($store, $p);
        if (substr($store, $p + 4, 4) === 'jumb' && str_starts_with(substr($store, $p + 33, 16), 'c2pa.claim')) {
            $payload = $p + 8 + bU32($store, $p + 8);   // after the description box
            if (substr($store, $payload + 4, 4) !== 'cbor') {
                throw new RuntimeException('the claim box does not hold a cbor box');
            }

            return [$payload + 8, bU32($store, $payload) - 8, $box];
        }
        $p += $len;
    }
    throw new RuntimeException('no claim box in the update manifest');
}

/** The store with the hashed URI for $label in the update manifest's claim recomputed. */
function umRehash(string $store, string $label, string $oldHash): string
{
    [$claimAt, $claimLength] = umClaimAt($store);
    $box = umAssertionBoxOffset($store, $label);
    $hash = hash('sha256', substr($store, $box + 8, bU32($store, $box) - 8), true);
    $at = strpos($store, $oldHash, $claimAt);
    if ($at === false || $at > $claimAt + $claimLength) {
        throw new RuntimeException("the hashed URI for {$label} is not in the claim");
    }

    return bReplace($store, $at, $oldHash, $hash);
}

/** The offset of the update manifest's assertion box with this label. */
function umAssertionBoxOffset(string $store, string $label): int
{
    [, , $manifestBox] = umClaimAt($store);
    $p = $manifestBox + 8;
    $end = $manifestBox + bU32($store, $manifestBox);
    while ($p < $end) {
        $len = bU32($store, $p);
        if (substr($store, $p + 33, strlen('c2pa.assertions')) === 'c2pa.assertions') {
            $q = $p + 8;
            $stop = $p + $len;
            while ($q < $stop) {
                $l = bU32($store, $q);
                $name = substr($store, $q + 33, (int) strpos($store, "\0", $q + 33) - $q - 33);
                if ($name === $label) {
                    return $q;
                }
                $q += $l;
            }
        }
        $p += $len;
    }
    throw new RuntimeException("no assertion {$label} in the update manifest");
}

/**
 * The boxes whose LBox encloses an edit inside the assertion $label of the update manifest: the store
 * superbox, the manifest box, the assertion store, the assertion box, and — for an edit inside the
 * content — its cbor box. Every one of them must grow or shrink with the edit.
 *
 * @return list<int>
 */
function umEnclosing(string $store, string $label, bool $inContent): array
{
    [, , $manifestBox] = umClaimAt($store);
    $assertionStore = null;
    $p = $manifestBox + 8;
    $end = $manifestBox + bU32($store, $manifestBox);
    while ($p < $end) {
        if (substr($store, $p + 33, strlen('c2pa.assertions')) === 'c2pa.assertions') {
            $assertionStore = $p;
        }
        $p += bU32($store, $p);
    }
    if ($assertionStore === null) {
        throw new RuntimeException('no assertion store in the update manifest');
    }
    $box = umAssertionBoxOffset($store, $label);
    $chain = [0, $manifestBox, $assertionStore, $box];
    if ($inContent) {
        $chain[] = $box + 8 + bU32($store, $box + 8);   // the cbor box after the description box
    } else {
        $chain[] = $box + 8;                            // the description box, which carries the label
    }

    return $chain;
}

/** The hashed URI hash of $label as the update manifest's claim currently records it. */
function umClaimHash(string $store, string $label): string
{
    $box = umAssertionBoxOffset($store, $label);

    return hash('sha256', substr($store, $box + 8, bU32($store, $box) - 8), true);
}

/** A CBOR text string head plus the text. */
function umTstr(string $t): string
{
    $n = strlen($t);

    return ($n < 24 ? pack('C', 0x60 + $n) : ($n < 256 ? pack('CC', 0x78, $n) : pack('Cn', 0x79, $n))).$t;
}

/** A v1 ingredient assertion with a parentOf relationship and no manifest reference. */
function umIngredientCbor(string $title): string
{
    return "\xa4".umTstr('dc:title').umTstr($title)
        .umTstr('dc:format').umTstr('image/png')
        .umTstr('instanceID').umTstr('xmp:iid:00000000-0000-0000-0000-'.substr(md5($title), 0, 12))
        .umTstr('relationship').umTstr('parentOf');
}

/** A hashed-uri map {url, hash} as the claim lists them. */
function umHashedUriEntry(string $url, string $hash): string
{
    return "\xa2".umTstr('url').umTstr($url).umTstr('hash').umBstr($hash);
}

/** An assertion superbox: jumd (cbor UUID, toggles 3) and a cbor content box. */
function umAssertionBox(string $label, string $payload): string
{
    $uuid = (string) hex2bin('63626f72001100108000'.'00aa00389b71');
    $jumd = 'jumd'.$uuid."\x03".$label."\0";
    $jumd = pack('N', 4 + strlen($jumd)).$jumd;
    $cbor = pack('N', 8 + strlen($payload)).'cbor'.$payload;
    $box = 'jumb'.$jumd.$cbor;

    return pack('N', 4 + strlen($box)).$box;
}

/** The PNG fixture's hard binding re-bound after the store grew by $grown bytes. */
function umRebindPng(string $store, string $png, int $grown, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox + $grown;
    $hd = bMapPairs($store, $box + 80);
    $ex = bMapPairs($store, $hd['exclusions'][1] + 1);
    $lengthValue = $ex['length'][1];
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $ctx = hash_init('sha256');
    $file = pngWithStore($png, $store);
    hash_update($ctx, substr($file, 0, 33));
    hash_update($ctx, substr($file, 33 + 12 + strlen($store)));
    $store = bReplace($store, $hd['hash'][1] + 2, substr($store, $hd['hash'][1] + 2, 32), hash_final($ctx, true));
    $uri = hash('sha256', substr($store, $box + 8, bU32($store, $box) - 8), true);
    $claim = bMapPairs($store, $claimAt + $grown);
    $list = $claim['created_assertions'][1];
    $count = ord($store[$list]) & 0x1F;
    $q = $list + 1;
    for ($i = 0; $i < $count; $i++) {
        $end = bCborEnd($store, $q);
        if (str_contains(substr($store, $q, $end - $q), 'c2pa.hash.data')) {
            $at = bMapPairs($store, $q)['hash'][1] + 2;

            return bReplace($store, $at, substr($store, $at, 32), $uri);
        }
        $q = $end;
    }
    throw new RuntimeException('no c2pa.hash.data entry in the claim');
}

/** The PNG store with its claim re-signed by the throw-away leaf, the COSE padded to its old length. */
function umResignPng(string $store, string $leafDer, string $rootDer, string $keys, string $name): string
{
    $parsed = ManifestStore::fromTree((new JumbfParser)->parse($store));
    $claimBytes = $parsed->active->claimBytes();
    $coseAt = strpos($store, $parsed->active->signatureBytes());
    if ($coseAt === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1");
    }
    $length = strlen($parsed->active->signatureBytes());
    $protected = "\xa2\x01\x26\x18\x21\x82".umBstr($leafDer).umBstr($rootDer);
    $draft = "\xd2\x84".umBstr($protected)."\xa1\x63pad".umBstr('')."\xf6".umBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    umRun(umSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = umDerToRs((string) file_get_contents("{$keys}/sig"));
    $pad = $length - (2 + strlen(umBstr($protected)) + 5 + 3 + 1 + strlen(umBstr($signature)));
    if ($pad < 256) {
        throw new RuntimeException("{$name}: the pad would be {$pad} bytes");
    }
    $cose = "\xd2\x84".umBstr($protected)."\xa1\x63pad\x59".pack('n', $pad).str_repeat("\0", $pad)."\xf6".umBstr($signature);
    if (strlen($cose) !== $length || ! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not fit or does not verify");
    }

    return substr($store, 0, $coseAt).$cose.substr($store, $coseAt + $length);
}

// ---- the throw-away hierarchy ----
$root = dirname(__DIR__);
$cnf = <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[good]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
extendedKeyUsage = emailProtection
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);
umRun(umSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
umRun(umSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (update manifests)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
umRun(umSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
umRun(umSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=update manifests', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
umRun(umSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/update-manifest';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$full = json_decode((string) file_get_contents($root.'/tests/Fixtures/trust/full.settings.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($full) && is_array($full['trust']) && is_string($full['trust']['trust_anchors']));
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode([
    'trust' => ['trust_anchors' => $rootPem.$full['trust']['trust_anchors'], 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/** Re-sign the update manifest's claim and put the COSE back in its box (padded to the same length). */
function umResign(string $store, string $leafDer, string $rootDer, string $keys, string $name): string
{
    [$claimAt, $claimLength, $manifestBox] = umClaimAt($store);
    $claimBytes = substr($store, $claimAt, $claimLength);
    // the signature box of the same manifest
    $p = $manifestBox + 8;
    $end = $manifestBox + bU32($store, $manifestBox);
    $coseAt = null;
    $coseLength = 0;
    while ($p < $end) {
        $len = bU32($store, $p);
        if (substr($store, $p + 33, strlen('c2pa.signature')) === 'c2pa.signature') {
            $payload = $p + 8 + bU32($store, $p + 8);
            $coseAt = $payload + 8;
            $coseLength = bU32($store, $payload) - 8;
        }
        $p += $len;
    }
    if ($coseAt === null) {
        throw new RuntimeException("{$name}: no signature box in the update manifest");
    }
    $protected = "\xa2\x01\x26\x18\x21\x82".umBstr($leafDer).umBstr($rootDer);
    $draft = "\xd2\x84".umBstr($protected)."\xa1\x63pad".umBstr('')."\xf6".umBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    umRun(umSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = umDerToRs((string) file_get_contents("{$keys}/sig"));
    $fixed = 2 + strlen(umBstr($protected)) + 5 + 3 + 1 + strlen(umBstr($signature));
    $pad = $coseLength - $fixed;
    if ($pad < 256) {
        throw new RuntimeException("{$name}: the pad would be {$pad} bytes");
    }
    $cose = "\xd2\x84".umBstr($protected)."\xa1\x63pad\x59".pack('n', $pad).str_repeat("\0", $pad)."\xf6".umBstr($signature);
    if (strlen($cose) !== $coseLength) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$coseLength}");
    }
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify");
    }

    return substr($store, 0, $coseAt).$cose.substr($store, $coseAt + $coseLength);
}

// ---- the fixture ----
$jpegPath = $root.'/tests/Fixtures/c2pa-rs/update_manifest.jpg';
$jpeg = (string) file_get_contents($jpegPath);
$stream = fopen($jpegPath, 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open update_manifest.jpg');
}
$extracted = (new JpegManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 43595) {
    throw new RuntimeException('the update-manifest store is not the one measured in step 57');
}
$s = $extracted->bytes;
$segmentAt = $extracted->ranges[0]['start'];
if (umJpegWithStore($jpeg, $s, $segmentAt) !== $jpeg) {
    throw new RuntimeException('the APP11 rebuild is not byte-exact on the unchanged store');
}

// 1. pixel-changed: one byte after the store, nothing re-signed
$after = $segmentAt + $extracted->ranges[0]['length'] + 500;
$pixel = $jpeg;
$pixel[$after] = chr(ord($pixel[$after]) ^ 0x01);
file_put_contents("{$dir}/pixel-changed.jpg", $pixel);
printf("%s  %d bytes  pixel-changed (byte %d, outside the store)\n", hash('sha256', $pixel), strlen($pixel), $after);

// 2. action-not-allowed: c2pa.opened -> c2pa.edited inside the actions assertion (same length)
$actionsBox = umAssertionBoxOffset($s, 'c2pa.actions');
$oldActionsHash = umClaimHash($s, 'c2pa.actions');
$opened = strpos($s, "\x6bc2pa.opened", $actionsBox);
if ($opened === false || $opened > $actionsBox + bU32($s, $actionsBox)) {
    throw new RuntimeException('no c2pa.opened action in the update manifest');
}
$edited = bReplace($s, $opened, "\x6bc2pa.opened", "\x6bc2pa.edited");
$edited = umRehash($edited, 'c2pa.actions', $oldActionsHash);
$edited = umResign($edited, $leafDer, $rootDer, $keys, 'action-not-allowed');
file_put_contents("{$dir}/action-not-allowed.jpg", umJpegWithStore($jpeg, $edited, $segmentAt));
printf("%s  %d bytes  action-not-allowed\n", hash('sha256', $edited), strlen($edited));

// 3. hash-in-update: the c2pa.time-stamp assertion renamed c2pa.hash.data (one byte shorter)
$tsBox = umAssertionBoxOffset($s, 'c2pa.time-stamp');
$oldTsHash = umClaimHash($s, 'c2pa.time-stamp');
$renamed = bSplice($s, $tsBox + 33, strlen('c2pa.time-stamp'), 'c2pa.hash.data', umEnclosing($s, 'c2pa.time-stamp', false));
$renamed = umRehash($renamed, 'c2pa.hash.data', $oldTsHash);
$renamed = umResign($renamed, $leafDer, $rootDer, $keys, 'hash-in-update');
file_put_contents("{$dir}/hash-in-update.jpg", umJpegWithStore($jpeg, $renamed, $segmentAt));
printf("%s  %d bytes  hash-in-update\n", hash('sha256', $renamed), strlen($renamed));

// 4. ingredient-inputto: parentOf -> inputTo (one byte shorter)
$ingredientBox = umAssertionBoxOffset($s, 'c2pa.ingredient.v3');
$oldIngredientHash = umClaimHash($s, 'c2pa.ingredient.v3');
$parentOf = strpos($s, "\x68parentOf", $ingredientBox);
if ($parentOf === false || $parentOf > $ingredientBox + bU32($s, $ingredientBox)) {
    throw new RuntimeException('no parentOf relationship in the update manifest');
}
$input = bSplice($s, $parentOf, strlen("\x68parentOf"), "\x67inputTo", umEnclosing($s, 'c2pa.ingredient.v3', true));
$input = umRehash($input, 'c2pa.ingredient.v3', $oldIngredientHash);
$input = umResign($input, $leafDer, $rootDer, $keys, 'ingredient-inputto');
file_put_contents("{$dir}/ingredient-inputto.jpg", umJpegWithStore($jpeg, $input, $segmentAt));
printf("%s  %d bytes  ingredient-inputto\n", hash('sha256', $input), strlen($input));

// 5. no-standard-parent: the parent manifest's box UUID c2ma -> c2um (same length)
$parentUuid = 38 + 8 + 8;   // the parent box, its description box, then the UUID
$c2ma = (string) hex2bin('63326d610011001080000'.'0aa00389b71');
$c2um = (string) hex2bin('633275'.'6d00110010800000aa00389b71');
if (substr($s, $parentUuid, 16) !== $c2ma) {
    throw new RuntimeException('the parent box does not start with the c2ma UUID where step 57 measured it');
}
$chain = bReplace($s, $parentUuid, $c2ma, $c2um);
// the ingredient's activeManifest hash covers the parent box's payload, which now differs
$oldReference = hash('sha256', substr($s, 38 + 8, bU32($s, 38) - 8), true);
$newReference = hash('sha256', substr($chain, 38 + 8, bU32($chain, 38) - 8), true);
$at = strpos($chain, $oldReference, $ingredientBox);
if ($at === false || $at > $ingredientBox + bU32($chain, $ingredientBox)) {
    throw new RuntimeException('the activeManifest hash is not in the ingredient assertion');
}
$chain = bReplace($chain, $at, $oldReference, $newReference);
$chain = umRehash($chain, 'c2pa.ingredient.v3', $oldIngredientHash);
$chain = umResign($chain, $leafDer, $rootDer, $keys, 'no-standard-parent');
file_put_contents("{$dir}/no-standard-parent.jpg", umJpegWithStore($jpeg, $chain, $segmentAt));
printf("%s  %d bytes  no-standard-parent\n", hash('sha256', $chain), strlen($chain));

// ============================================================================
// 6. two-parents, from the PNG fixture: two ingredient assertions, both parentOf (AC6)
// ============================================================================
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$pngStream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($pngStream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$pngExtracted = (new PngManifestStoreExtractor)->extract($pngStream);
if ($pngExtracted === null || strlen($pngExtracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$p = $pngExtracted->bytes;
$FIRST_ASSERTION_BOX = 166;
$HASH_DATA_BOX = 32831;
$CLAIM = 33081;
$pngClaim = bMapPairs($p, $CLAIM);
[, $createdStart, $createdEnd] = $pngClaim['created_assertions'];
$entries = '';
$boxes = '';
foreach (['c2pa.ingredient', 'c2pa.ingredient__1'] as $n => $label) {
    $payload = umIngredientCbor(sprintf('parent %d.png', $n + 1));
    $box = umAssertionBox($label, $payload);
    $boxes .= $box;
    $entries .= umHashedUriEntry('self#jumbf=c2pa.assertions/'.$label, hash('sha256', substr($box, 8), true));
}
$two = bSplice($p, $createdStart, $createdEnd - $createdStart, "\x83".substr($p, $createdStart + 1, $createdEnd - $createdStart - 1).$entries, [0, 38, 33026, 33073]);
$two = bSplice($two, $FIRST_ASSERTION_BOX, 0, $boxes, [0, 38, 117]);
$two = umRebindPng($two, $png, strlen($boxes), $HASH_DATA_BOX, $CLAIM);
$two = umResignPng($two, $leafDer, $rootDer, $keys, 'two-parents');
file_put_contents("{$dir}/two-parents.bin", $two);
file_put_contents("{$dir}/two-parents.png", pngWithStore($png, $two));
printf("%s  %d bytes  two-parents\n", hash('sha256', $two), strlen($two));
