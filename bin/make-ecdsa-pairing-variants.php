<?php

declare(strict_types=1);

/*
 * Step 422: ECDSA algorithms under keys on another curve. C2PA 2.4 §13.2.1: "Implementations shall
 * accept keys on any of these curves for all ECDSA algorithm choices"; C2PA 2.5 §12.3.1: "Validators
 * may reject other pairings". SPEC-009 AC6 accepts them. c2pa-rs verifies ECDSA in one of two ways:
 * its OpenSSL path reads a signature as R‖S only when its length is the algorithm's (64, 96, 132 bytes),
 * its Rust-native path takes the curve from the key.
 *
 * A throw-away P-256 root signs one leaf per probe, on the probe's curve, and the PNG fixture's
 * manifest is re-signed with the probe's algorithm (x5chain = leaf + root, the signature R‖S at the
 * curve's size, as a signer with that key makes it):
 *   es256-p256, es384-p384, es512-p521   the matching pairs (controls)
 *   es256-p384, es384-p256, es512-p384   crossed pairs
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
 *
 * Usage: php bin/make-ecdsa-pairing-variants.php <scratch-dir> <out-dir> [<c2patool>…]
 * Writes <probe>.png and <probe>.settings.json (the root as anchor) into <out-dir> and prints each
 * c2patool's answer and this verifier's, with the settings and without. When <out-dir> is
 * tests/Fixtures/<set>, each c2patool's JSON goes to tests/Fixtures/c2patool/<set>/
 * <probe>--<version>[--no-settings].json.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $out] = [$argv[1] ?? null, $argv[2] ?? null];
$tools = array_slice($argv, 3);
if (! is_string($scratch) || ! is_dir($scratch) || ! is_string($out)) {
    fwrite(STDERR, "usage: php bin/make-ecdsa-pairing-variants.php <scratch-dir> <out-dir> [<c2patool>…]\n");
    exit(1);
}
if (! is_dir($out) && ! mkdir($out, 0755, true)) {
    throw new RuntimeException("cannot create {$out}");
}
$keys = rtrim($scratch, '/').'/ecdsa-keys-'.bin2hex(random_bytes(4));
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

function epRun(string $command): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function epSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

function epBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/** A DER ECDSA-Sig-Value as R‖S, each $size bytes. */
function epDerToRs(string $der, int $size): string
{
    $p = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $length = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $length), "\0"), $size, "\0", STR_PAD_LEFT);
        $p += 2 + $length;
    }

    return $rs;
}

// probe => [COSE alg, its hash, the leaf's curve, the curve's coordinate size]
$probes = [
    'es256-p256' => [-7, 'sha256', 'prime256v1', 32],
    'es384-p384' => [-35, 'sha384', 'secp384r1', 48],
    'es512-p521' => [-36, 'sha512', 'secp521r1', 66],
    'es256-p384' => [-7, 'sha256', 'secp384r1', 48],
    'es384-p256' => [-35, 'sha384', 'prime256v1', 32],
    'es512-p384' => [-36, 'sha512', 'secp384r1', 48],
];

// ---- the throw-away root ----
file_put_contents("{$keys}/ext.cnf", <<<'CNF'
[req]
distinguished_name = dn
[dn]
[root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
[leaf]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF);
$subject = static fn (string $cn): string => "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
epRun(epSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
epRun(epSh('openssl', 'req', '-x509', '-new', '-sha256', '-key', "{$keys}/root.key", '-subj', $subject('ECDSA pairing root'), '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);

// ---- the fixture's store and its signature box (step 31 offsets) ----
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
$COSE_LENGTH = 12305 - 8;
if (substr($s, $COSE, 2) !== "\xd2\x84") {
    throw new RuntimeException('the COSE_Sign1 is not where step 31 measured it');
}
$claimBytes = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];

foreach ($probes as $probe => [$alg, $hash, $curve, $size]) {
    epRun(epSh('openssl', 'ecparam', '-genkey', '-name', $curve, '-noout', '-out', "{$keys}/{$probe}.key"));
    epRun(epSh('openssl', 'req', '-new', '-key', "{$keys}/{$probe}.key", '-subj', $subject("ECDSA pairing {$probe}"), '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$probe}.csr"));
    epRun(epSh('openssl', 'x509', '-req', '-sha256', '-in', "{$keys}/{$probe}.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/{$probe}.pem"));
    $leafDer = $pemToDer((string) file_get_contents("{$keys}/{$probe}.pem"));

    // the protected header: {1: alg, 33: [leaf, root]}; every C2PA alg is negative
    $protected = "\xa2\x01".($alg >= -24 ? chr(0x20 - 1 - $alg) : "\x38".chr(-1 - $alg))."\x18\x21\x82".epBstr($leafDer).epBstr($rootDer);
    $draft = "\xd2\x84".epBstr($protected)."\xa1\x63pad".epBstr('')."\xf6".epBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/{$probe}.tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    epRun(epSh('openssl', 'dgst', '-'.$hash, '-sign', "{$keys}/{$probe}.key", '-out', "{$keys}/{$probe}.sig", "{$keys}/{$probe}.tbs"));
    $signature = epDerToRs((string) file_get_contents("{$keys}/{$probe}.sig"), $size);
    $fixed = 2 + strlen(epBstr($protected)) + 5 + 3 + 1 + strlen(epBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$probe}: the pad would be {$padLength} bytes");
    }
    $cose = "\xd2\x84".epBstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".epBstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$probe}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    file_put_contents("{$out}/{$probe}.png", pngWithStore($png, substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH)));
    file_put_contents("{$out}/{$probe}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    // OpenSSL's own answer on the signature, with the key and the algorithm's hash
    epRun(epSh('openssl', 'ec', '-in', "{$keys}/{$probe}.key", '-pubout', '-out', "{$keys}/{$probe}.pub"));
    $ossl = trim((string) shell_exec(epSh('openssl', 'dgst', '-'.$hash, '-verify', "{$keys}/{$probe}.pub", '-signature', "{$keys}/{$probe}.sig", "{$keys}/{$probe}.tbs").' 2>&1'));
    printf("%-12s alg %d, %s, R‖S %d bytes; openssl dgst -verify: %s\n", $probe, $alg, $curve, strlen($signature), $ossl);
}

// ---- the answers ----
$summary = static function (string $json): string {
    $j = json_decode($json, true);
    if (! is_array($j) || ! is_string($j['validation_state'] ?? null)) {
        return 'error: '.substr(trim((string) preg_replace('/\s+/', ' ', $json)), 0, 100);
    }
    $results = is_array($j['validation_results'] ?? null) ? $j['validation_results'] : [];
    $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
    $codes = [];
    foreach (is_array($active['failure'] ?? null) ? $active['failure'] : [] as $st) {
        $code = is_array($st) && is_string($st['code'] ?? null) ? $st['code'] : '?';
        $why = is_array($st) && is_string($st['explanation'] ?? null) ? ' ('.substr($st['explanation'], 0, 90).')' : '';
        $codes[] = $code.$why;
    }

    return $j['validation_state'].' '.implode('; ', array_unique($codes));
};
$judges = [];
foreach ($tools as $tool) {
    $judges[trim(epRun(epSh($tool, '--version')))] = [$tool];
}
$judges['this verifier'] = [PHP_BINARY, $root.'/bin/c2pa-verify'];
$fixtures = realpath($out);
$oracles = $fixtures !== false && dirname($fixtures) === realpath($root.'/tests/Fixtures') ? $root.'/tests/Fixtures/c2patool/'.basename($fixtures) : null;
if ($oracles !== null && ! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}
foreach (array_keys($probes) as $probe) {
    echo "\n{$probe}\n";
    foreach ($judges as $name => $judge) {
        foreach (['settings' => ['--settings', "{$out}/{$probe}.settings.json"], 'none' => []] as $mode => $extra) {
            $json = (string) shell_exec(epSh(...$judge, ...["{$out}/{$probe}.png", ...$extra]).' 2>&1');
            printf("  %-22s %-9s %s\n", $name, $mode, $summary($json));
            if ($oracles !== null && str_starts_with($name, 'c2patool ')) {
                file_put_contents(sprintf('%s/%s--%s%s.json', $oracles, $probe, substr($name, 9), $mode === 'none' ? '--no-settings' : ''), $json);
            }
        }
    }
}
