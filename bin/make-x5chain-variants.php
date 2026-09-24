<?php

declare(strict_types=1);

/*
 * Build the fixture of SPEC-008 amendment 2 (step 117): a signature whose
 * x5chain carries one certificate, written as RFC 9360 prescribes for that
 * case — a bare CBOR byte string, not an array. c2pa-rs writes it that way
 * when the signer sits directly under a root; this verifier refused it
 * (step 110).
 *
 * The two keys (a root, a leaf under it) live in a temporary directory for
 * the length of this script and are overwritten and deleted before it ends,
 * as in bin/make-ocsp-variants.php. This repository holds no private key.
 *
 * Run:   php bin/make-x5chain-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Needs: the `openssl` command and both c2patool versions. Tooling, not the
 *        verification path.
 *
 * Writes:
 *   tests/Fixtures/cose/x5chain-single.jpg                 fixture-unsigned.jpg, signed by the leaf
 *   tests/Fixtures/cose/x5chain-single-root.pem            the root, public
 *   tests/Fixtures/cose/x5chain-single-root.settings.json  the root as the only anchor, store.cfg as trust_config
 *   tests/Fixtures/c2patool/x5chain/<version>-<bare|root>.json   each c2patool's report
 */

$root = dirname(__DIR__);
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-x5chain-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

$cose = $root.'/tests/Fixtures/cose';
$oracles = $root.'/tests/Fixtures/c2patool/x5chain';
$tmp = sys_get_temp_dir().'/c2pa-x5chain-'.bin2hex(random_bytes(6));
foreach ([$cose, $oracles] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
}
if (! mkdir($tmp, 0o700, true)) {
    fwrite(STDERR, "cannot create {$tmp}\n");
    exit(1);
}
$shred = static function () use ($tmp): void {
    foreach (glob($tmp.'/*') ?: [] as $path) {
        if (is_file($path)) {
            $size = filesize($path);
            if (is_int($size) && $size > 0 && str_ends_with($path, '.key')) {
                file_put_contents($path, str_repeat("\0", $size));
            }
            unlink($path);
        }
    }
    @rmdir($tmp);
};
register_shutdown_function($shred);

$run = static function (string $command): void {
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        fwrite(STDERR, "failed: {$command}\n".implode("\n", $lines)."\n");
        exit(1);
    }
};
$q = static fn (string $path): string => escapeshellarg($path);

// ---- a root, and a leaf directly under it ----

file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=SPEC-008 One-Certificate Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
file_put_contents("{$tmp}/leaf.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=SPEC-008 One-Certificate Signer\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
foreach (['root', 'leaf'] as $name) {
    $run("openssl ecparam -name prime256v1 -genkey -noout -out {$q("{$tmp}/{$name}.raw.key")}");
    $run("openssl pkcs8 -topk8 -nocrypt -in {$q("{$tmp}/{$name}.raw.key")} -out {$q("{$tmp}/{$name}.key")}");
}
$run("openssl req -new -x509 -key {$q("{$tmp}/root.key")} -config {$q("{$tmp}/root.cnf")} -days 3650 -out {$q("{$tmp}/root.pem")}");
$run("openssl req -new -key {$q("{$tmp}/leaf.key")} -config {$q("{$tmp}/leaf.cnf")} -out {$q("{$tmp}/leaf.csr")}");
$run("openssl x509 -req -in {$q("{$tmp}/leaf.csr")} -CA {$q("{$tmp}/root.pem")} -CAkey {$q("{$tmp}/root.key")} -CAcreateserial -days 3650 -extfile {$q("{$tmp}/leaf.cnf")} -extensions v3 -out {$q("{$tmp}/leaf.pem")}");
file_put_contents("{$tmp}/manifest.json", json_encode([
    'alg' => 'es256',
    'private_key' => "{$tmp}/leaf.key",
    'sign_cert' => "{$tmp}/leaf.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier SPEC-008 probe', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$file = "{$cose}/x5chain-single.jpg";
$run("{$q($new)} {$q($root.'/tests/Fixtures/fixture-unsigned.jpg')} -m {$q("{$tmp}/manifest.json")} -o {$q($file)} -f");
copy("{$tmp}/root.pem", "{$cose}/x5chain-single-root.pem");
$settings = "{$cose}/x5chain-single-root.settings.json";
$pem = file_get_contents("{$tmp}/root.pem");
$cfg = file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
if ($pem === false || $cfg === false) {
    fwrite(STDERR, "cannot read the root or store.cfg\n");
    exit(1);
}
file_put_contents($settings, json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $pem, 'trust_config' => $cfg]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- what each c2patool says, without settings and with the root ----

foreach (['0.28.0' => $new, '0.27.22' => $old] as $label => $tool) {
    foreach (['bare' => '', 'root' => " --settings {$q($settings)}"] as $case => $flag) {
        exec("{$q($tool)} {$q($file)}{$flag} 2>&1", $lines, $code);
        if ($code !== 0) {
            fwrite(STDERR, "c2patool {$label} refused the file ({$case}):\n".implode("\n", $lines)."\n");
            exit(1);
        }
        file_put_contents("{$oracles}/{$label}-{$case}.json", implode("\n", $lines)."\n");
        $report = json_decode(implode("\n", $lines), true);
        printf("c2patool %-8s %-5s %s\n", $label, $case, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : '?');
        $lines = [];
    }
}

$shred();
if (is_dir($tmp)) {
    fwrite(STDERR, "a key survived in {$tmp}\n");
    exit(1);
}
printf("sha256 %s  x5chain-single.jpg\n", hash_file('sha256', $file));
