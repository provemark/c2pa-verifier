<?php

declare(strict_types=1);

/*
 * Step 60, the absence audit for M7: the method of step 48 applied to the rules SPEC-020, SPEC-021
 * and SPEC-022 added — for every "the verifier does X when Y is present", a *signed* store in which
 * Y is absent.
 *
 * The store this needs did not exist: a two-manifest store this project made itself. It is built
 * here from the PNG fixture — its manifest box copied under a second label, the copy placed first
 * (the active manifest is the last in the store, C2PA 2.4 §11.1.4.2) and named by a v3 ingredient
 * assertion in the active claim, with the hard binding re-bound and the active claim re-signed with
 * a throw-away hierarchy. The copy keeps the fixture's own signature, so the settings file carries
 * both roots and only the absence under test can make a variant Invalid.
 *
 *   two-manifests            the control: an active manifest and the ingredient it names, both whole
 *   ingredient-no-actions    the *ingredient* manifest has no actions assertion (SPEC-018's rule, now
 *                            applied to an ingredient by SPEC-021 — does it cost the file its verdict?)
 *   unreferenced-broken      a second manifest nobody names, with a broken signature: the specification
 *                            says a validator should ignore manifests it does not reach (§15.11.3.3),
 *                            and this measures what "ignore" is worth
 *   no-claim-signature       the ingredient assertion names the manifest but not its signature box
 *                            (§18.16.12.3 says both shall be stored; c2pa-rs only needs the second
 *                            one for redactions)
 *
 * Keys live outside the repository and are deleted before the script ends (Maurice van Loon,
 * 2026-09-21). Usage: php bin/make-m7-absence-variants.php <scratch-dir>. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$scratch = $argv[1] ?? null;
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-m7-absence-variants.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/m7-absence-keys-'.bin2hex(random_bytes(4));
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

function m7Run(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function m7Sh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A CBOR byte string: head plus bytes. */
function m7Bstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? pack('C', 0x40 + $n) : ($n < 256 ? pack('CC', 0x58, $n) : pack('Cn', 0x59, $n))).$b;
}

/** A CBOR text string: head plus text. */
function m7Tstr(string $t): string
{
    $n = strlen($t);

    return ($n < 24 ? pack('C', 0x60 + $n) : ($n < 256 ? pack('CC', 0x78, $n) : pack('Cn', 0x79, $n))).$t;
}

/** A hashed-uri map {url, hash}. */
function m7HashedUri(string $url, string $hash): string
{
    return "\xa2".m7Tstr('url').m7Tstr($url).m7Tstr('hash').m7Bstr($hash);
}

/** An assertion superbox: jumd (cbor UUID, toggles 3) and a cbor content box. */
function m7AssertionBox(string $label, string $payload): string
{
    $uuid = (string) hex2bin('63626f72001100108000'.'00aa00389b71');
    $jumd = 'jumd'.$uuid."\x03".$label."\0";
    $jumd = pack('N', 4 + strlen($jumd)).$jumd;
    $cbor = pack('N', 8 + strlen($payload)).'cbor'.$payload;
    $box = 'jumb'.$jumd.$cbor;

    return pack('N', 4 + strlen($box)).$box;
}

/** A DER ECDSA signature as R‖S of 2 × 32 bytes. */
function m7DerToRs(string $der): string
{
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

// ---- the throw-away hierarchy for the active manifest ----
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
m7Run(m7Sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
m7Run(m7Sh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (M7 absence)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
m7Run(m7Sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
m7Run(m7Sh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=M7 absence', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
m7Run(m7Sh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/m7-absence';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents($dir.'/throw-away-root.pem', $rootPem);
file_put_contents($dir.'/both-roots.settings.json', json_encode([
    'trust' => [
        // the throw-away root signs the active manifest; the fixture's own anchors the copy
        'trust_anchors' => $rootPem.(string) file_get_contents($root.'/tests/Fixtures/trust/trust_anchors.pem'),
        'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg'),
    ],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the fixture's store, and the pieces of it this script moves ----
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
$HEADER = 38;                       // the store superbox's LBox, TBox and description box
$manifestBox = substr($s, $HEADER, bU32($s, $HEADER));
$LABEL_AT = $HEADER + 33;           // where the manifest's label sits in its description box
$label = substr($s, $LABEL_AT, (int) strpos($s, "\0", $LABEL_AT) - $LABEL_AT);
$ACTIONS_BOX = 32636;               // the c2pa.actions.v2 assertion, 195 bytes (step 09)
$THUMBNAIL_BOX = 166;
$HASH_DATA_BOX = 32831;
$CLAIM = 33081;
$storeBox = [0, 38, 117];
$claimBox = [0, 38, 33026, 33073];

/**
 * The same manifest box under another label, of the same length so that nothing inside it moves. The
 * fixture's claim names its signature box by an **absolute** URI, so the label has to be replaced
 * there too — and that changes the claim, which is why the copy is re-signed below.
 */
function m7Relabel(string $box, string $label): string
{
    $at = 33;                        // the label inside the manifest box's description box
    $old = substr($box, $at, (int) strpos($box, "\0", $at) - $at);
    if (strlen($old) !== strlen($label)) {
        throw new RuntimeException('the new label must be as long as the old one');
    }
    $box = substr_replace($box, $label, $at, strlen($old));

    return str_replace($old, $label, $box);
}

/**
 * A store of two manifests: $ingredient first (so that the active manifest is the last, §11.1.4.2),
 * then $active, under a store superbox whose LBox counts them both.
 */
function m7Store(string $header, string $ingredient, string $active): string
{
    $store = $ingredient.$active;

    return pack('N', strlen($header) + strlen($store)).substr($header, 4).$store;
}

/**
 * The active manifest's claim with an ingredient assertion added: the assertion box goes in front of
 * the thumbnail, its entry into created_assertions, and every enclosing LBox grows.
 *
 * @return array{0: string, 1: int} the manifest box and how much it grew
 */
function m7WithIngredient(string $manifestBox, string $assertionBox, string $entry, int $createdStart, int $createdEnd, int $firstAssertion): array
{
    // the manifest box's own offsets are 38 less than the store's (this box starts at 0 here)
    $inBox = static fn (int $storeOffset): int => $storeOffset - 38;
    $box = bSplice($manifestBox, $inBox($createdStart), $createdEnd - $createdStart, "\x82".substr($manifestBox, $inBox($createdStart) + 1, $createdEnd - $createdStart - 1).$entry, [0, $inBox(33026), $inBox(33073)]);
    $box = bSplice($box, $inBox($firstAssertion), 0, $assertionBox, [0, $inBox(117)]);

    return [$box, strlen($assertionBox) + strlen($entry) - 0];
}

$claim = bMapPairs($s, $CLAIM);
[, $createdStart, $createdEnd] = $claim['created_assertions'];

/** The store with the hard binding re-bound to it, and the claim's hashed URI for the binding updated. */
function m7Rebind(string $store, string $png, int $hashDataBox, int $claimAt): string
{
    $hd = bMapPairs($store, $hashDataBox + 80);
    $ex = bMapPairs($store, $hd['exclusions'][1] + 1);
    $lengthValue = $ex['length'][1];
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $file = pngWithStore($png, $store);
    $ctx = hash_init('sha256');
    hash_update($ctx, substr($file, 0, 33));
    hash_update($ctx, substr($file, 33 + 12 + strlen($store)));
    $store = bReplace($store, $hd['hash'][1] + 2, substr($store, $hd['hash'][1] + 2, 32), hash_final($ctx, true));

    $uri = hash('sha256', substr($store, $hashDataBox + 8, bU32($store, $hashDataBox) - 8), true);
    $claim = bMapPairs($store, $claimAt);
    $list = $claim['created_assertions'][1];
    $count = ord($store[$list]) & 0x1F;
    $p = $list + 1;
    for ($i = 0; $i < $count; $i++) {
        $end = bCborEnd($store, $p);
        if (str_contains(substr($store, $p, $end - $p), 'c2pa.hash.data')) {
            $at = bMapPairs($store, $p)['hash'][1] + 2;

            return bReplace($store, $at, substr($store, $at, 32), $uri);
        }
        $p = $end;
    }
    throw new RuntimeException('no c2pa.hash.data entry in the claim');
}

/** The store with one manifest's claim re-signed by the throw-away leaf (the active one by default). */
function m7Resign(string $store, string $leafDer, string $rootDer, string $keys, string $name, ?string $label = null): string
{
    $parsed = ManifestStore::fromTree((new JumbfParser)->parse($store));
    $active = $label === null ? $parsed->active : $parsed->manifests[$label];
    $claimBytes = $active->claimBytes();
    $coseAt = strpos($store, $active->signatureBytes());
    if ($coseAt === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1 for the active manifest");
    }
    $length = strlen($active->signatureBytes());
    $protected = "\xa2\x01\x26\x18\x21\x82".m7Bstr($leafDer).m7Bstr($rootDer);
    $draft = "\xd2\x84".m7Bstr($protected)."\xa1\x63pad".m7Bstr('')."\xf6".m7Bstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    m7Run(m7Sh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = m7DerToRs((string) file_get_contents("{$keys}/sig"));
    $pad = $length - (2 + strlen(m7Bstr($protected)) + 5 + 3 + 1 + strlen(m7Bstr($signature)));
    if ($pad < 256) {
        throw new RuntimeException("{$name}: the pad would be {$pad} bytes");
    }
    $cose = "\xd2\x84".m7Bstr($protected)."\xa1\x63pad\x59".pack('n', $pad).str_repeat("\0", $pad)."\xf6".m7Bstr($signature);
    if (strlen($cose) !== $length || ! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not fit or does not verify");
    }

    return substr($store, 0, $coseAt).$cose.substr($store, $coseAt + $length);
}

// ---- the ingredient manifest: a copy of the fixture's, without its 32 KB thumbnail so that the
// two-manifest store stays under the 65535 bytes a two-byte CBOR exclusion length can name, with
// `gathered_assertions` dropped (its only entries were the thumbnail and the actions) and the
// actions entry moved into `created_assertions` — or left out entirely, which is the absence ----
$ingredientLabel = substr($label, 0, -1).'1';
$gathered = bMapPairs($s, $CLAIM)['gathered_assertions'];
[$gatheredKey, $gatheredStart, $gatheredEnd] = $gathered;
$thumbEnd = bCborEnd($s, $gatheredStart + 1);
$actionsEntry = substr($s, $thumbEnd, $gatheredEnd - $thumbEnd);
$hashDataEntry = substr($s, $createdStart + 1, $createdEnd - $createdStart - 1);

/**
 * A copy of the fixture's manifest box: no thumbnail, no `gathered_assertions` pair, and the actions
 * assertion kept or dropped. Offsets are the store's; inside the box they are 38 lower.
 */
function m7Copy(string $manifestBox, bool $withActions, string $hashDataEntry, string $actionsEntry, int $createdStart, int $createdEnd, int $gatheredKey, int $gatheredEnd, int $claim, int $thumbnailBox, int $actionsBox): string
{
    $inBox = static fn (int $offset): int => $offset - 38;
    $box = $manifestBox;
    // back to front: the claim's pairs, then the boxes, so that each offset is still where it was
    $box = bSplice($box, $inBox($gatheredKey), $gatheredEnd - $gatheredKey, '', [0, $inBox(33026), $inBox(33073)]);
    $created = $withActions ? "\x82".$hashDataEntry.$actionsEntry : "\x81".$hashDataEntry;
    $box = bSplice($box, $inBox($createdStart), $createdEnd - $createdStart, $created, [0, $inBox(33026), $inBox(33073)]);
    $box = bReplace($box, $inBox($claim), "\xa7", "\xa6");          // one pair fewer
    if (! $withActions) {
        $box = bSplice($box, $inBox($actionsBox), 195, '', [0, $inBox(117)]);
    }

    return bSplice($box, $inBox($thumbnailBox), 32470, '', [0, $inBox(117)]);
}

$variants = [];
foreach (['two-manifests' => true, 'ingredient-no-actions' => false, 'no-claim-signature' => true] as $name => $withActions) {
    $copy = m7Relabel(m7Copy($manifestBox, $withActions, $hashDataEntry, $actionsEntry, $createdStart, $createdEnd, $gatheredKey, $gatheredEnd, $CLAIM, $THUMBNAIL_BOX, $ACTIONS_BOX), $ingredientLabel);
    // the copy is re-signed first, so that the hashes the ingredient assertion records are the ones
    // the finished store carries
    $signed = m7Resign(m7Store(substr($s, 0, $HEADER), $copy, $manifestBox), $leafDer, $rootDer, $keys, $name, $ingredientLabel);
    $copy = substr($signed, $HEADER, strlen($copy));
    $ingredientParsed = ManifestStore::fromTree((new JumbfParser)->parse($signed))->manifests[$ingredientLabel];
    $manifestHash = hash('sha256', substr($copy, 8), true);
    $signatureHash = hash('sha256', $ingredientParsed->resolve(sprintf('self#jumbf=/c2pa/%s/c2pa.signature', $ingredientLabel))->payload(), true);
    // no-claim-signature: the same assertion without the second hashed URI. C2PA 2.4 §18.16.12.3 says
    // both shall be stored; c2pa-rs needs the second one only when a redaction forces the signature
    // method, so neither implementation refuses this — measured, and named in docs/comparison.md
    $withSignature = $name !== 'no-claim-signature';
    $payload = ($withSignature ? "\xa6" : "\xa5")
        .m7Tstr('dc:title').m7Tstr('the ingredient')
        .m7Tstr('dc:format').m7Tstr('image/png')
        .m7Tstr('relationship').m7Tstr('parentOf')
        .m7Tstr('activeManifest').m7HashedUri('self#jumbf=/c2pa/'.$ingredientLabel, $manifestHash)
        .($withSignature ? m7Tstr('claimSignature').m7HashedUri('self#jumbf=/c2pa/'.$ingredientLabel.'/c2pa.signature', $signatureHash) : '')
        .m7Tstr('validationResults')."\xa2".m7Tstr('activeManifest')."\xa3".m7Tstr('success')."\x80".m7Tstr('informational')."\x80".m7Tstr('failure')."\x80".m7Tstr('ingredientDeltas')."\x80";
    $assertionBox = m7AssertionBox('c2pa.ingredient.v3', $payload);
    $entry = m7HashedUri('self#jumbf=c2pa.assertions/c2pa.ingredient.v3', hash('sha256', substr($assertionBox, 8), true));
    [$activeBox] = m7WithIngredient($manifestBox, $assertionBox, $entry, $createdStart, $createdEnd, $THUMBNAIL_BOX);
    // the assertion box went in at the thumbnail's place, before both the hard binding and the claim;
    // the claim's own entry was added *inside* the claim, so it shifts nothing that follows the start
    $shift = strlen($assertionBox);

    $store = m7Store(substr($s, 0, $HEADER), $copy, $activeBox);
    $activeAt = $HEADER + strlen($copy);
    $store = m7Rebind($store, $png, $activeAt + ($HASH_DATA_BOX - 38) + $shift, $activeAt + ($CLAIM - 38) + $shift);
    $variants[$name] = m7Resign($store, $leafDer, $rootDer, $keys, $name);
}

// ---- unreferenced-broken: the copy is in the store, nobody names it, and its signature is broken ----
$copy = m7Relabel(m7Copy($manifestBox, true, $hashDataEntry, $actionsEntry, $createdStart, $createdEnd, $gatheredKey, $gatheredEnd, $CLAIM, $THUMBNAIL_BOX, $ACTIONS_BOX), $ingredientLabel);
$signed = m7Resign(m7Store(substr($s, 0, $HEADER), $copy, $manifestBox), $leafDer, $rootDer, $keys, 'unreferenced-broken', $ingredientLabel);
$copy = substr($signed, $HEADER, strlen($copy));
$brokenParsed = ManifestStore::fromTree((new JumbfParser)->parse($signed))->manifests[$ingredientLabel];
$coseAt = strpos($copy, $brokenParsed->signatureBytes());
if ($coseAt === false) {
    throw new RuntimeException('no COSE in the copy');
}
$flip = $coseAt + strlen($brokenParsed->signatureBytes()) - 8;
$copy[$flip] = chr(ord($copy[$flip]) ^ 0x01);
$store = m7Store(substr($s, 0, $HEADER), $copy, $manifestBox);
$activeAt = $HEADER + strlen($copy);
$store = m7Rebind($store, $png, $activeAt + ($HASH_DATA_BOX - 38), $activeAt + ($CLAIM - 38));
$variants['unreferenced-broken'] = m7Resign($store, $leafDer, $rootDer, $keys, 'unreferenced-broken');

foreach ($variants as $name => $store) {
    $parsed = ManifestStore::fromTree((new JumbfParser)->parse($store));
    file_put_contents("{$dir}/{$name}.bin", $store);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %d bytes  %-22s %d manifests, active %s\n", substr(hash('sha256', $store), 0, 16), strlen($store), $name, count($parsed->manifests), substr($parsed->active->label, -6));
}
