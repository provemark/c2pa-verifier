<?php

declare(strict_types=1);

/*
 * Steps 315 and 317: ISOBMFF probes from the reading of C2PA 2.4 (docs/reading-c2pa-2.4.md, L1 and L12).
 * `c2patool` 0.28.1 signs with a throw-away P-256 hierarchy; a probe that `c2patool` will not write is made by
 * changing a few bytes of one assertion without changing their length, re-hashing the claim's hashed URI for it
 * and signing the claim again with the same throw-away leaf (the COSE keeps its length). Keys live in a scratch
 * directory for the run and are deleted before it ends; the public root goes into a settings file.
 *
 * Variants:
 *   sha384-control           fixture-unsigned.mp4 signed with hash_alg sha384 (claim and BMFF hash on SHA-384)
 *   sha384-bmff-no-alg       the same, the BMFF hash's alg key renamed: the claim's alg must apply (§13.1, §15.4.1)
 *   init-alone               a fragmented stream's init segment (ffmpeg dash, `c2patool fragment`), without its fragments
 *   init-no-count            the same, its merkle map's count key renamed
 *   init-count-zero          the same, its merkle map's count set to 0
 *
 * Usage: php bin/make-bmff-probe-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>
 * Needs `openssl`, `ffmpeg` (8.0 measured). Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/bmff-probes/<variant>.mp4, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/bmff-probes/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.1' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-bmff-probe-variants.php <scratch-dir outside the repository> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/bmff-probe-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($keys, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function bqRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function bqSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/**
 * $from replaced by $to (the same length) inside the assertion box labelled $label, the claim's hashed URI for it
 * re-hashed with whichever algorithm the claim used, and the claim signed again (ES256) with $key.
 */
function bqEdit(string $file, string $label, string $from, string $to, string $key): string
{
    if (strlen($from) !== strlen($to)) {
        throw new RuntimeException('an edit keeps its length');
    }
    $bytes = (string) file_get_contents($file);
    $stream = fopen($file, 'rb');
    $store = $stream === false ? null : (new IsobmffManifestStoreExtractor)->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no store in {$file}");
    }
    $s = $store->bytes;
    $storeAt = strpos($bytes, $s);
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($s))->active;
    $claim = $manifest->claimBytes();
    $claimAt = strpos($s, $claim);
    $at = strpos($s, $label."\0");
    if ($storeAt === false || $claimAt === false || $at === false) {
        throw new RuntimeException("{$label} not found");
    }
    $box = (int) strrpos(substr($s, 0, $at), 'jumb') - 4;
    $length = bU32($s, $box);
    $p = strpos($s, $from, $at);
    if ($p === false || $p >= $box + $length) {
        throw new RuntimeException("the bytes to change are not in {$label}");
    }
    $before = substr($s, $box + 8, $length - 8);
    $s = substr_replace($s, $to, $p, strlen($to));
    $digest = null;
    foreach (['sha256', 'sha384', 'sha512'] as $algorithm) {
        $q = strpos($claim, hash($algorithm, $before, true));
        if ($q !== false) {
            $digest = hash($algorithm, substr($s, $box + 8, $length - 8), true);
            $claim = substr_replace($claim, $digest, $q, strlen($digest));
            break;
        }
    }
    if ($digest === null) {
        throw new RuntimeException("the claim holds no hash of {$label}");
    }
    $s = substr_replace($s, $claim, $claimAt, strlen($claim));
    $signature = $manifest->signatureBytes();
    $coseAt = strpos($s, $signature);
    if ($coseAt === false || substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }
    file_put_contents(dirname($key).'/tbs', CoseSign1::fromBytes($signature)->sigStructure($claim));
    bqRun(bqSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $q = 2; $i < 2; $i++) {
        $l = ord($der[$q + 1]);
        $rs .= str_pad(ltrim(substr($der, $q + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $q += 2 + $l;
    }
    $s = substr_replace($s, $rs, $coseAt + strlen($signature) - 64, 64);

    return substr_replace($bytes, $s, $storeAt, strlen($s));
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/bmff-probes';
$oracles = $root.'/tests/Fixtures/c2patool/bmff-probes';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
bqRun(bqSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
bqRun(bqSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (BMFF probes)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
bqRun(bqSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
bqRun(bqSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=BMFF probe signer', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
bqRun(bqSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/leaf.pem"));
bqRun(bqSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/leaf.key", '-out', "{$keys}/leaf.pk8"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
$manifest = static fn (?string $hashAlg): string => (string) json_encode(array_filter([
    'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem", 'hash_alg' => $hashAlg,
    'claim_generator_info' => [['name' => 'c2pa-verifier BMFF probes', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], static fn ($v): bool => $v !== null), JSON_UNESCAPED_SLASHES);

// ---- L1: a SHA-384 claim, the BMFF hash without alg ----
file_put_contents("{$keys}/m-sha384.json", $manifest('sha384'));
bqRun(bqSh($new, $root.'/tests/Fixtures/fixture-unsigned.mp4', '-m', "{$keys}/m-sha384.json", '-o', "{$dir}/sha384-control.mp4", '-f'));
file_put_contents("{$dir}/sha384-bmff-no-alg.mp4", bqEdit("{$dir}/sha384-control.mp4", 'c2pa.hash.bmff.v3', "\x63alg", "\x63alX", "{$keys}/leaf.key"));

// ---- L12: a fragmented stream's init segment alone ----
mkdir("{$keys}/frag");
bqRun('cd '.escapeshellarg("{$keys}/frag").' && '.bqSh('ffmpeg', '-loglevel', 'error', '-stream_loop', '4', '-i', $root.'/tests/Fixtures/fixture-unsigned.mp4', '-c', 'copy', '-f', 'dash', '-seg_duration', '0.3', '-init_seg_name', 'init.mp4', '-media_seg_name', 'seg_$Number$.m4s', 'out.mpd'));
file_put_contents("{$keys}/m.json", $manifest(null));
bqRun(bqSh($new, '-m', "{$keys}/m.json", '-o', "{$keys}/signed", "{$keys}/frag/init.mp4", 'fragment', '--fragments_glob', 'seg_*.m4s'));
$init = (string) (glob("{$keys}/signed/*/init.mp4")[0] ?? glob("{$keys}/signed/init.mp4")[0] ?? '');
if ($init === '') {
    throw new RuntimeException('c2patool wrote no signed init segment');
}
copy($init, "{$dir}/init-alone.mp4");
file_put_contents("{$dir}/init-no-count.mp4", bqEdit($init, 'c2pa.hash.bmff.v3', "\x65count", "\x65counX", "{$keys}/leaf.key"));
$bytes = (string) file_get_contents($init);
$c = strpos($bytes, "\x65count");
if ($c === false || ord($bytes[$c + 6]) >= 24 || ord($bytes[$c + 6]) === 0) {
    throw new RuntimeException('the merkle count is not a small positive integer');
}
file_put_contents("{$dir}/init-count-zero.mp4", bqEdit($init, 'c2pa.hash.bmff.v3', "\x65count".$bytes[$c + 6], "\x65count\x00", "{$keys}/leaf.key"));

// ---- the oracles ----
foreach (['sha384-control', 'sha384-bmff-no-alg', 'init-alone', 'init-no-count', 'init-count-zero'] as $name) {
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $v => $tool) {
        $lines = [];
        exec(bqSh($tool, "{$dir}/{$name}.mp4", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-22s %-8s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
    }
}
