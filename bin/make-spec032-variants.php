<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-032 (step 122a): signed probes for the
 * `c2pa.created` digitalSourceType rule and for the external-reference
 * checks of C2PA 2.4 §15.10.3.2.2, and what both c2patool versions say.
 *
 * Every probe is fixture-unsigned.jpg signed by c2patool 0.28.0 with a
 * throwaway leaf (claim-signing + emailProtection) under a throwaway
 * intermediate and root. The three keys live in a temporary directory for
 * the length of this script and are overwritten and deleted before it ends.
 * This repository holds no private key.
 *
 * Run:   php bin/make-spec032-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Needs: the `openssl` command and both c2patool versions. Tooling only.
 *
 * Writes:
 *   tests/Fixtures/assertion-rules/<probe>.jpg
 *   tests/Fixtures/assertion-rules/probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/assertion-rules/<probe>--<version>-<bare|root>.json
 */

$root = dirname(__DIR__);
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec032-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/assertion-rules';
$oracles = $root.'/tests/Fixtures/c2patool/assertion-rules';
$tmp = sys_get_temp_dir().'/c2pa-spec032-'.bin2hex(random_bytes(6));
foreach ([$out, $oracles] as $dir) {
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

// ---- root → intermediate → leaf ----

$dn = static fn (string $cn): string => "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN={$cn}\nO=c2pa-verifier test\nC=NL\n";
file_put_contents("{$tmp}/int.cnf", $dn('SPEC-032 Probe Intermediate')."[v3]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
file_put_contents("{$tmp}/leaf.cnf", $dn('SPEC-032 Probe Signer')."[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.4.1.62558.2.1,emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
// req -x509 reads x509_extensions from the [req] section, so the root's file is written out in full
file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=SPEC-032 Probe Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
foreach (['root', 'int', 'leaf'] as $name) {
    $run("openssl ecparam -name prime256v1 -genkey -noout -out {$q("{$tmp}/{$name}.raw.key")}");
    $run("openssl pkcs8 -topk8 -nocrypt -in {$q("{$tmp}/{$name}.raw.key")} -out {$q("{$tmp}/{$name}.key")}");
}
$run("openssl req -new -x509 -key {$q("{$tmp}/root.key")} -config {$q("{$tmp}/root.cnf")} -days 3650 -out {$q("{$tmp}/root.pem")}");
foreach (['int' => 'root', 'leaf' => 'int'] as $name => $issuer) {
    $run("openssl req -new -key {$q("{$tmp}/{$name}.key")} -config {$q("{$tmp}/{$name}.cnf")} -out {$q("{$tmp}/{$name}.csr")}");
    $run("openssl x509 -req -in {$q("{$tmp}/{$name}.csr")} -CA {$q("{$tmp}/{$issuer}.pem")} -CAkey {$q("{$tmp}/{$issuer}.key")} -CAcreateserial -days 3650 -extfile {$q("{$tmp}/{$name}.cnf")} -extensions v3 -out {$q("{$tmp}/{$name}.pem")}");
}
$leafPem = file_get_contents("{$tmp}/leaf.pem");
$intPem = file_get_contents("{$tmp}/int.pem");
$rootPem = file_get_contents("{$tmp}/root.pem");
$storeCfg = file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
if ($leafPem === false || $intPem === false || $rootPem === false || $storeCfg === false) {
    fwrite(STDERR, "cannot read the certificates or store.cfg\n");
    exit(1);
}
file_put_contents("{$tmp}/chain.pem", $leafPem.$intPem);
copy("{$tmp}/root.pem", "{$out}/probe-root.pem");
$settings = "{$out}/probe-root.settings.json";
file_put_contents($settings, json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => rtrim($storeCfg)."\n"]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the probes ----

$dst = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture';
$created = ['action' => 'c2pa.created', 'digitalSourceType' => $dst];
$actions = static fn (array ...$list): array => ['label' => 'c2pa.actions.v2', 'data' => ['actions' => $list]];
$ref = static fn (mixed $data): array => ['label' => 'c2pa.external-reference', 'data' => $data];
$url = 'https://example.com/referenced';
$probes = [
    // rule A
    'created-without-source-type' => [$actions(['action' => 'c2pa.created'])],
    'created-with-source-type' => [$actions($created, ['action' => 'c2pa.edited'])],
    // rule B
    'reference-forbidden-label' => [$actions($created), $ref(['label' => 'c2pa.actions.v2', 'location' => ['url' => $url]])],
    'reference-no-location' => [$actions($created), $ref(['label' => 'stds.schema-org.CreativeWork'])],
    'reference-no-url' => [$actions($created), $ref(['location' => ['dc:format' => 'text/plain']])],
    'reference-empty-url' => [$actions($created), $ref(['location' => ['url' => '']])],
    'reference-alg-without-hash' => [$actions($created), $ref(['location' => ['url' => $url, 'alg' => 'sha256']])],
    'reference-hash-without-alg' => [$actions($created), $ref(['location' => ['url' => $url, 'hash' => '47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=']])],
    'reference-not-a-map' => [$actions($created), $ref(['not', 'a', 'map'])],
    'reference-unhashed' => [$actions($created), $ref(['location' => ['url' => $url]])],
    'reference-hashed' => [$actions($created), $ref(['label' => 'stds.schema-org.CreativeWork', 'location' => ['url' => $url, 'alg' => 'sha256', 'hash' => '47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=']])],
];
// c2patool refuses to sign with an EKU it does not know; the claim-signing OID is listed for the signing run
file_put_contents("{$tmp}/sign.settings.json", json_encode(['trust' => ['trust_config' => "1.3.6.1.4.1.62558.2.1\n1.3.6.1.5.5.7.3.4\n"]], JSON_THROW_ON_ERROR));
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach ($probes as $name => $assertions) {
    file_put_contents("{$tmp}/manifest.json", json_encode([
        'alg' => 'es256',
        'private_key' => "{$tmp}/leaf.key",
        'sign_cert' => "{$tmp}/chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier SPEC-032 probe', 'version' => '1']],
        'assertions' => $assertions,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $file = "{$out}/{$name}.jpg";
    // c2patool prints its own validation after signing and still writes the file; only a missing file is a failure
    exec("{$q($new)} {$q($root.'/tests/Fixtures/fixture-unsigned.jpg')} -m {$q("{$tmp}/manifest.json")} --settings {$q("{$tmp}/sign.settings.json")} -o {$q($file)} -f 2>&1", $ignored);
    if (! is_file($file) || filesize($file) === 0) {
        fwrite(STDERR, "c2patool did not write {$name}\n");
        exit(1);
    }
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $label => $tool) {
        foreach (['bare' => '', 'root' => " --settings {$q($settings)}"] as $case => $flag) {
            $lines = [];
            exec("{$q($tool)} {$q($file)}{$flag} 2>&1", $lines, $code);
            $target = "{$oracles}/{$name}--{$label}-{$case}".($code === 0 ? '.json' : '.error.txt');
            file_put_contents($target, implode("\n", $lines)."\n");
            $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
            $state = is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}";
            printf("%-30s %-8s %-5s %s\n", $name, $label, $case, $state);
        }
    }
}

$shred();
if (is_dir($tmp)) {
    fwrite(STDERR, "a key survived in {$tmp}\n");
    exit(1);
}
