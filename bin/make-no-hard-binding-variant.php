<?php

declare(strict_types=1);

/*
 * SPEC-013 amendment 10 (step 47): a manifest that is properly signed and
 * carries NO hard binding — the case no writer in any corpus produces and
 * the case a verifier must refuse (C2PA 2.4 §15.10.1.2: a standard manifest
 * without a hard binding is `claim.hardBindings.missing`). Built from the
 * PNG fixture's store: the `c2pa.hash.data` assertion box is removed from
 * the assertion store, the claim's `created_assertions` is made to hold the
 * actions assertion instead (a claim must create at least one), and
 * `gathered_assertions` keeps the thumbnail; the claim is then re-signed
 * with a throw-away P-256 hierarchy exactly as bin/make-profile-variants.php
 * signs (protected header {alg, x5chain}, Sig_structure, the pad resized so
 * the signature box keeps its length). Keys live outside the repository for
 * the run and are deleted before it ends; the public root goes into a
 * settings file so that a test can show what a trusting operator would see.
 * Decided by Maurice van Loon on 2026-09-21: tooling may sign with
 * throw-away keys; the product never signs.
 *
 * Usage: php bin/make-no-hard-binding-variant.php <scratch-dir>. Tooling.
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
    fwrite(STDERR, "usage: php bin/make-no-hard-binding-variant.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/no-hard-binding-keys-'.bin2hex(random_bytes(4));
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

function run(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function sh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function bstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function derToRs(string $der, int $curveBytes): string
{
    if ($der[0] !== "\x30") {
        throw new RuntimeException('not a DER ECDSA signature');
    }
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $len = ord($der[$p + 1]);
        $int = ltrim(substr($der, $p + 2, $len), "\0");
        $rs .= str_pad($int, $curveBytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

// ---- the fixture's store (step 09 offsets) ----
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
$HASH_DATA_BOX = 32831;      // the c2pa.hash.data assertion box, 195 bytes
$CLAIM = 33081;              // the claim's CBOR map (7 pairs, 591 bytes)
$storeBox = [0, 38, 117];    // the boxes enclosing an assertion: store superbox, c2pa manifest superbox, assertion store superbox
$claimBox = [0, 38, 33026, 33073];
if (substr($s, $HASH_DATA_BOX + 4, 4) !== 'jumb' || ord($s[$CLAIM]) !== 0xA7) {
    throw new RuntimeException('the store is not laid out as step 09 measured');
}

// ---- the claim: created_assertions = [actions], gathered_assertions = [thumbnail] ----
$claim = bMapPairs($s, $CLAIM);
[, $createdStart, $createdEnd] = $claim['created_assertions'];     // 81 { c2pa.hash.data }
[, $gatheredStart, $gatheredEnd] = $claim['gathered_assertions'];  // 82 { thumbnail } { actions }
$thumbEntryStart = $gatheredStart + 1;
$thumbEntryEnd = bCborEnd($s, $thumbEntryStart);
$actionsEntry = substr($s, $thumbEntryEnd, $gatheredEnd - $thumbEntryEnd);
$thumbEntry = substr($s, $thumbEntryStart, $thumbEntryEnd - $thumbEntryStart);
if (! str_contains($actionsEntry, 'c2pa.actions.v2') || ! str_contains($thumbEntry, 'c2pa.thumbnail')) {
    throw new RuntimeException('the gathered list is not [thumbnail, actions] as step 09 measured');
}
// gathered first (the later pair), then created, so the earlier offsets stay valid
$s = bSplice($s, $gatheredStart, $gatheredEnd - $gatheredStart, "\x81".$thumbEntry, $claimBox);
$s = bSplice($s, $createdStart, $createdEnd - $createdStart, "\x81".$actionsEntry, $claimBox);

// ---- the assertion store: the hash.data box removed ----
$s = bSplice($s, $HASH_DATA_BOX, 195, '', $storeBox);

// ---- the store still parses, and the claim now names two assertions and no c2pa.hash.data ----
$manifest = ManifestStore::fromTree((new JumbfParser)->parse($s))->active;
$labels = array_keys($manifest->assertions);
$named = array_map(static fn ($u): string => $u->url, [...$manifest->claim->createdAssertions, ...$manifest->claim->gatheredAssertions]);
if (in_array('c2pa.hash.data', $labels, true) || count($named) !== 2 || count($manifest->claim->createdAssertions) !== 1) {
    throw new RuntimeException('the edit did not produce [actions] + [thumbnail] without c2pa.hash.data: '.implode(',', $labels).' / '.implode(',', $named));
}
$claimBytes = $manifest->claimBytes();

// ---- the signature box, wherever it now is ----
$COSE = strpos($s, "\xd2\x84", $CLAIM);
if ($COSE === false) {
    throw new RuntimeException('no COSE_Sign1 after the claim');
}
$COSE_LENGTH = bU32($s, $COSE - 8) - 8;

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
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
run(sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
run(sh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (no hard binding)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
run(sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
run(sh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=no hard binding', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
run(sh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

// ---- the new COSE_Sign1: {1: -7, 33: [leaf, root]}, signed over the edited claim ----
$protected = "\xa2\x01\x26\x18\x21\x82".bstr($leafDer).bstr($rootDer);
$draft = "\xd2\x84".bstr($protected)."\xa1\x63pad".bstr('')."\xf6".bstr(str_repeat("\0", 64));
$sigStructure = CoseSign1::fromBytes($draft)->sigStructure($claimBytes);
file_put_contents("{$keys}/tbs", $sigStructure);
run(sh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
$signature = derToRs((string) file_get_contents("{$keys}/sig"), 32);
$fixed = 2 + strlen(bstr($protected)) + 5 + 3 + 1 + strlen(bstr($signature));
$padLength = $COSE_LENGTH - $fixed;
if ($padLength < 256) {
    throw new RuntimeException("the pad would be {$padLength} bytes, too short for a 3-byte head");
}
$cose = "\xd2\x84".bstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".bstr($signature);
if (strlen($cose) !== $COSE_LENGTH) {
    throw new RuntimeException('COSE is '.strlen($cose)." bytes, not {$COSE_LENGTH}");
}
$store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
    throw new RuntimeException('the new signature does not verify under its own leaf');
}

// ---- out: the variant, its store, and the root as an anchor ----
$dir = $root.'/tests/Fixtures/binding';
file_put_contents("{$dir}/no-hard-binding.bin", $store);
file_put_contents("{$dir}/no-hard-binding.png", pngWithStore($png, $store));
file_put_contents("{$dir}/no-hard-binding-root.pem", $rootPem);
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/no-hard-binding-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
printf("%s  %d bytes  no-hard-binding: claim names %s; the signature verifies under the throw-away leaf\n", hash('sha256', $store), strlen($store), implode(', ', $named));
