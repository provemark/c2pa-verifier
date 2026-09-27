<?php

declare(strict_types=1);

/*
 * Build the fixtures of step 159 (SPEC-031 amendment): may a time-stamping certificate sign a
 * manifest? A throw-away P-256 root and two leaves under it, and the PNG fixture's manifest
 * re-signed with each, as bin/make-profile-variants.php does (step 33): a new protected header
 * (alg ES256, x5chain = leaf + root), a new signature over the Sig_structure, and the unprotected
 * pad resized so that the store keeps its length.
 *
 *   tsa-only.png  the leaf's only EKU is timeStamping (critical), as a TSA certificate's is
 *   email.png     the leaf's only EKU is emailProtection: the guard
 *
 * Three settings name the root: in the legacy trust_anchors field (which serves signers and
 * TSAs alike), as a "manifest" entry of trust.anchors, and as a "tsa" entry. Both c2patool
 * versions judge every file under every settings file. Found by the review of step 157.
 *
 * Keys live in a directory outside the repository for the duration of the run and are deleted
 * before the script ends; only public certificates, the re-signed files and the settings are
 * written under tests/ (tooling may sign with throw-away keys; the product never signs).
 *
 * Usage: php bin/make-tsa-signer-variants.php <scratch-dir> <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes:
 *   tests/Fixtures/tsa-signer/<leaf>.png, <leaf>.leaf.pem, throw-away-root.pem, <settings>.settings.json
 *   tests/Fixtures/c2patool/tsa-signer/<leaf>--<settings>--<version>.json (or .error.txt)
 * Tooling.
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
[$scratch, $new, $old] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_dir($scratch) || ! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-tsa-signer-variants.php <scratch-dir outside the repository> <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/tsa-signer-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0o700)) {
    throw new RuntimeException("cannot create {$keys}");
}
// the keys go, whatever happens
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function tsRun(string $command): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function tsSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/**
 * CBOR head for a major type and argument, shortest form.
 *
 * @param  int<0, 7>  $mt
 */
function tsHead(int $mt, int $n): string
{
    if ($n < 0) {
        throw new RuntimeException('a CBOR argument cannot be negative');
    }
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function tsBstr(string $b): string
{
    return tsHead(2, strlen($b)).$b;
}

/** DER ECDSA-Sig-Value → R || S, each 32 bytes wide. */
function tsDerToRs(string $der): string
{
    $p = 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

function tsDer(string $pem): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
}

file_put_contents("{$keys}/ext.cnf", <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[tsa-only]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = critical, timeStamping
authorityKeyIdentifier = keyid
[email]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = emailProtection
authorityKeyIdentifier = keyid
CNF);

// ---- the throw-away root ----
tsRun(tsSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
tsRun(tsSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away TSA-signer Root', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = tsDer($rootPem);

// ---- the fixture's store and the signature box (step 31 offsets) ----
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
$COSE = 33728;
$COSE_LENGTH = 12305 - 8;   // the signature cbor box's contents
if (substr($s, $COSE, 2) !== "\xd2\x84") {
    throw new RuntimeException('the COSE_Sign1 is not where step 31 measured it');
}
$claimBytes = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();

$dir = $root.'/tests/Fixtures/tsa-signer';
$oracles = $root.'/tests/Fixtures/c2patool/tsa-signer';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$settingsFiles = [
    'legacy' => ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => $storeCfg], 'verify' => ['verify_trust' => true]],
    'manifest-entry' => ['trust' => ['anchors' => [['trust_anchors' => $rootPem, 'trust_kind' => 'manifest']], 'trust_config' => $storeCfg], 'verify' => ['verify_trust' => true]],
    'tsa-entry' => ['trust' => ['anchors' => [['trust_anchors' => $rootPem, 'trust_kind' => 'tsa']], 'trust_config' => $storeCfg], 'verify' => ['verify_trust' => true]],
];
foreach ($settingsFiles as $name => $settings) {
    file_put_contents("{$dir}/{$name}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

$verifier = new SignatureVerifier;
foreach (['tsa-only', 'email'] as $name) {
    $key = "{$keys}/{$name}.key";
    tsRun(tsSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', $key));
    tsRun(tsSh('openssl', 'req', '-new', '-key', $key, '-subj', "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=tsa-signer {$name}", '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    tsRun(tsSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $name, '-out', "{$keys}/{$name}.pem"));
    $leafPem = (string) file_get_contents("{$keys}/{$name}.pem");
    file_put_contents("{$dir}/{$name}.leaf.pem", $leafPem);

    // the protected header: {1: -7 (ES256), 33: [leaf, root]}
    $protected = "\xa2\x01\x26\x18\x21\x82".tsBstr(tsDer($leafPem)).tsBstr($rootDer);
    // a first COSE with an empty signature, to borrow sigStructure() from the verifier's own parser
    $draft = "\xd2\x84".tsBstr($protected)."\xa1\x63pad".tsBstr('')."\xf6".tsBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/{$name}.tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    tsRun(tsSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', "{$keys}/{$name}.sig", "{$keys}/{$name}.tbs"));
    $signature = tsDerToRs((string) file_get_contents("{$keys}/{$name}.sig"));
    // the pad fills the box to the original length: 2 + protected + (a1 63 pad + 59 xxxx + pad) + f6 + signature
    $padLength = $COSE_LENGTH - (2 + strlen(tsBstr($protected)) + 5 + 3 + 1 + strlen(tsBstr($signature)));
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".tsBstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".tsBstr($signature);
    $store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
    if (strlen($cose) !== $COSE_LENGTH || strlen($store) !== strlen($s)) {
        throw new RuntimeException("{$name}: the store changed length");
    }
    if (! $verifier->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify");
    }
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
}

// ---- what each c2patool says under each settings file ----
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (['tsa-only', 'email'] as $name) {
    foreach (array_keys($settingsFiles) as $settings) {
        foreach (['0.28.0' => $new, '0.27.22' => $old] as $tag => $tool) {
            $lines = [];
            exec(tsSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/{$settings}.settings.json").' 2>&1', $lines, $code);
            file_put_contents("{$oracles}/{$name}--{$settings}--{$tag}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
            $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
            printf("%-9s %-15s %-8s %s\n", $name, $settings, $tag, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
        }
    }
}
