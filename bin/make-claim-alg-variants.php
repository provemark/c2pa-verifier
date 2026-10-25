<?php

declare(strict_types=1);

/*
 * Step 427: a claim without `alg` (SPEC-027 amendment 9; docs/reading-c2pa-2.4.md, group A, question 1).
 * `c2patool` 0.28.3 signs fixture-unsigned.mp4 with a throw-away P-256 hierarchy; a probe that `c2patool` will
 * not write is made by editing the claim without changing its length and signing it again with the same
 * throw-away leaf (the COSE keeps its length). Keys live in a scratch directory for the run and are deleted
 * before it ends; the public root goes into a settings file.
 *
 * Variants:
 *   control                  fixture-unsigned.mp4 signed (claim alg sha256, BMFF hash alg sha256)
 *   entries-alg              the claim's `alg: sha256` moved into both hashed-URI entries, 11 bytes taken
 *                            off the instanceID to keep the length: a claim without alg whose entries name
 *                            their own, which C2PA 2.4 §15.4.2 allows
 *   entries-alg-bmff-no-alg  the same, the BMFF hash's alg key renamed: no algorithm is named for the binding,
 *                            §15.4.1's algorithm.unsupported
 *
 * Usage: php bin/make-claim-alg-variants.php <scratch-dir> <c2patool-0.28.3> <c2patool-0.28.1>
 * Needs `openssl`. Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/claim-alg/<variant>.mp4, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/claim-alg/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.3' || $version($old) !== 'c2patool 0.28.1') {
    fwrite(STDERR, "usage: php bin/make-claim-alg-variants.php <scratch-dir outside the repository> <c2patool-0.28.3> <c2patool-0.28.1>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/claim-alg-keys-'.bin2hex(random_bytes(4));
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

function caRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function caSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/**
 * The file's bytes, its store, where the store starts, and the active manifest.
 *
 * @return array{string, string, int, Manifest}
 */
function caParts(string $file): array
{
    $bytes = (string) file_get_contents($file);
    $stream = fopen($file, 'rb');
    $store = $stream === false ? null : (new IsobmffManifestStoreExtractor)->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no store in {$file}");
    }
    $at = strpos($bytes, $store->bytes);
    if ($at === false) {
        throw new RuntimeException("the store is not found in {$file}");
    }

    return [$bytes, $store->bytes, $at, ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active];
}

/** $claim replaced by $edited (the same length) in the store, and the claim signed again (ES256) with $key. */
function caResign(string $store, Manifest $manifest, string $claim, string $edited, string $key): string
{
    $claimAt = strpos($store, $claim);
    if (strlen($claim) !== strlen($edited) || $claimAt === false) {
        throw new RuntimeException('an edit of the claim keeps its length');
    }
    $store = substr_replace($store, $edited, $claimAt, strlen($edited));
    $signature = $manifest->signatureBytes();
    $coseAt = strpos($store, $signature);
    if ($coseAt === false || substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }
    file_put_contents(dirname($key).'/tbs', CoseSign1::fromBytes($signature)->sigStructure($edited));
    caRun(caSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $q = 2; $i < 2; $i++) {
        $l = ord($der[$q + 1]);
        $rs .= str_pad(ltrim(substr($der, $q + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $q += 2 + $l;
    }

    return substr_replace($store, $rs, $coseAt + strlen($signature) - 64, 64);
}

/** The BMFF hash's alg key renamed alX, the claim's entry for it re-hashed (SHA-256), the claim signed again. */
function caBmffNoAlg(string $file, string $key): string
{
    [$bytes, $s, $storeAt, $manifest] = caParts($file);
    $claim = $manifest->claimBytes();
    $at = strpos($s, "c2pa.hash.bmff.v3\0");
    if ($at === false) {
        throw new RuntimeException('no c2pa.hash.bmff.v3');
    }
    $box = (int) strrpos(substr($s, 0, $at), 'jumb') - 4;
    $length = bU32($s, $box);
    $p = strpos($s, "\x63alg", $at);
    if ($p === false || $p >= $box + $length) {
        throw new RuntimeException('the BMFF hash carries no alg');
    }
    $before = substr($s, $box + 8, $length - 8);
    $s = substr_replace($s, "\x63alX", $p, 4);
    $q = strpos($claim, hash('sha256', $before, true));
    if ($q === false) {
        throw new RuntimeException('the claim holds no SHA-256 hash of the BMFF hash');
    }
    $edited = substr_replace($claim, hash('sha256', substr($s, $box + 8, $length - 8), true), $q, 32);

    return substr_replace($bytes, caResign($s, $manifest, $claim, $edited, $key), $storeAt, strlen($s));
}

/**
 * The claim's `alg: sha256` pair moved into the hashed-URI entries of the BMFF hash and the actions; the 11
 * bytes the second copy needs come off the instanceID, whose length stays a one-byte CBOR length.
 */
function caEntriesAlg(string $file, string $key): string
{
    [$bytes, $s, $storeAt, $manifest] = caParts($file);
    $claim = $manifest->claimBytes();
    $pair = "\x63alg\x66sha256";
    if (substr_count($claim, $pair) !== 1 || ! in_array(ord($claim[0]), [0xA6, 0xA7, 0xA8], true)) {
        throw new RuntimeException('the claim is not the shape this script edits');
    }
    $edited = str_replace($pair, '', $claim);
    $edited[0] = chr(ord($claim[0]) - 1);
    foreach (['c2pa.hash.bmff.v3', 'c2pa.actions.v2'] as $label) {
        $url = strpos($edited, "self#jumbf=c2pa.assertions/{$label}");
        $map = $url === false ? false : strrpos(substr($edited, 0, $url), "\xa2\x63url");
        $hash = $url === false ? false : strpos($edited, "\x64hash\x58\x20", $url);
        if ($map === false || $hash === false) {
            throw new RuntimeException("no {url, hash} entry for {$label}");
        }
        $end = $hash + 5 + 2 + 32;
        $edited = substr($edited, 0, $end).$pair.substr($edited, $end);
        $edited[$map] = "\xa3";
    }
    $id = strpos($edited, "\x6ainstanceID\x78");
    $l = $id === false ? 0 : ord($edited[$id + 12]);
    if ($id === false || $l - 11 < 24) {
        throw new RuntimeException('the instanceID is too short to give 11 bytes');
    }
    $edited = substr($edited, 0, $id + 12).chr($l - 11).substr($edited, $id + 13, $l - 11).substr($edited, $id + 13 + $l);

    return substr_replace($bytes, caResign($s, $manifest, $claim, $edited, $key), $storeAt, strlen($s));
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/claim-alg';
$oracles = $root.'/tests/Fixtures/c2patool/claim-alg';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
caRun(caSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
caRun(caSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (claim alg probes)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
caRun(caSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
caRun(caSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Claim alg probe signer', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
caRun(caSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/leaf.pem"));
caRun(caSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/leaf.key", '-out', "{$keys}/leaf.pk8"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents("{$keys}/m.json", json_encode([
    'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier claim alg probes', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

caRun(caSh($new, $root.'/tests/Fixtures/fixture-unsigned.mp4', '-m', "{$keys}/m.json", '-o', "{$dir}/control.mp4", '-f'));
file_put_contents("{$dir}/entries-alg.mp4", caEntriesAlg("{$dir}/control.mp4", "{$keys}/leaf.key"));
file_put_contents("{$keys}/bmff-no-alg.mp4", caBmffNoAlg("{$dir}/control.mp4", "{$keys}/leaf.key"));
file_put_contents("{$dir}/entries-alg-bmff-no-alg.mp4", caEntriesAlg("{$keys}/bmff-no-alg.mp4", "{$keys}/leaf.key"));

// ---- the oracles ----
foreach (['control', 'entries-alg', 'entries-alg-bmff-no-alg'] as $name) {
    foreach (['0.28.3' => $new, '0.28.1' => $old] as $v => $tool) {
        $lines = [];
        exec(caSh($tool, "{$dir}/{$name}.mp4", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-24s %-7s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
    }
}
