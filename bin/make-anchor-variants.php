<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-014 amendment 5 (step 273): chains whose trust anchor is outside its
 * own validity, and a control whose anchor is not. Each probe is fixture-unsigned.jpg signed by a
 * throwaway leaf, without a timestamp; the chain is carried in x5chain. Both c2patool versions judge
 * every probe under its anchor.
 *
 * What is committed is the signed JPEGs, the public anchors' settings, and c2patool's JSON. The keys
 * live in a temporary directory for the length of this script and are overwritten and deleted before
 * it ends, in the shape `bin/make-issuer-variants.php` uses. This repository holds no private key.
 *
 * Run:   php bin/make-anchor-variants.php <c2patool-0.28.1> <c2patool-0.27.22>
 * Needs: the `openssl` command, 3.4 or later (for -not_before / -not_after).
 *        Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/trust/anchor/<probe>.jpg
 *   tests/Fixtures/trust/anchor/<anchor>.settings.json
 *   tests/Fixtures/c2patool/anchor/<probe>--<settings>--<version>.json (or .error.txt)
 */

$root = dirname(__DIR__);
/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.1' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-anchor-variants.php <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/trust/anchor';
$oracles = $root.'/tests/Fixtures/c2patool/anchor';
$tmp = sys_get_temp_dir().'/c2pa-anchor-'.bin2hex(random_bytes(6));
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
register_shutdown_function(static function () use ($tmp): void {
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
    if (file_exists($tmp)) {
        fwrite(STDERR, "a key directory survived: {$tmp}\n");
    }
});

$run = static function (string $command): void {
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        fwrite(STDERR, "failed: {$command}\n".implode("\n", $lines)."\n");
        exit(1);
    }
};
$q = static fn (string $path): string => escapeshellarg($path);
$read = static function (string $path): string {
    $text = file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "cannot read {$path}\n");
        exit(1);
    }

    return $text;
};

// ---- the probe PKI -------------------------------------------------------------------------------

$dn = static fn (string $cn): string => "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN={$cn}\nO=c2pa-verifier test\nC=NL\n";
$caRoot = "basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n";
$int = "basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n";
$ee = "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n";
$y2020 = ['20200101000000Z', '20210101000000Z'];
/** @var array<string, array{string, string, ?string, ?array{string, string}}> $certs */
$certs = [
    // name => [CN, v3 extensions, issuer (null: self-signed), validity (null: ten years from now)]
    'root-valid' => ['SPEC-014 Anchor Probe Root Valid', $caRoot, null, null],
    'int-valid' => ['SPEC-014 Intermediate Under Valid Root', $int, 'root-valid', null],
    'leaf-valid' => ['SPEC-014 Signer Under Valid Root', $ee, 'int-valid', null],
    'root-expired' => ['SPEC-014 Anchor Probe Root Expired', $caRoot, null, $y2020],
    'int-expired-root' => ['SPEC-014 Intermediate Under Expired Root', $int, 'root-expired', null],
    'leaf-expired-root' => ['SPEC-014 Signer Under Expired Root', $ee, 'int-expired-root', null],
    'leaf-expired-direct' => ['SPEC-014 Signer Directly Under Expired Root', $ee, 'root-expired', null],
    'root-future' => ['SPEC-014 Anchor Probe Root Future', $caRoot, null, ['20900101000000Z', '21000101000000Z']],
    'int-future-root' => ['SPEC-014 Intermediate Under Future Root', $int, 'root-future', null],
    'leaf-future-root' => ['SPEC-014 Signer Under Future Root', $ee, 'int-future-root', null],
    'int-expired' => ['SPEC-014 Expired Intermediate Anchor', $int, 'root-valid', $y2020],
    'leaf-expired-int' => ['SPEC-014 Signer Under Expired Intermediate Anchor', $ee, 'int-expired', null],
];
foreach ($certs as $name => [$cn, $ext, $issuer, $validity]) {
    file_put_contents("{$tmp}/{$name}.cnf", $dn($cn)."[v3]\n".$ext);
    $run("openssl ecparam -name prime256v1 -genkey -noout -out {$q("{$tmp}/{$name}.raw.key")}");
    $run("openssl pkcs8 -topk8 -nocrypt -in {$q("{$tmp}/{$name}.raw.key")} -out {$q("{$tmp}/{$name}.key")}");
    $dates = $validity === null ? '-days 3650' : "-not_before {$validity[0]} -not_after {$validity[1]}";
    if ($issuer === null) {
        $run("openssl req -new -x509 -key {$q("{$tmp}/{$name}.key")} -config {$q("{$tmp}/{$name}.cnf")} -extensions v3 {$dates} -out {$q("{$tmp}/{$name}.pem")}");

        continue;
    }
    $run("openssl req -new -key {$q("{$tmp}/{$name}.key")} -config {$q("{$tmp}/{$name}.cnf")} -out {$q("{$tmp}/{$name}.csr")}");
    $run("openssl x509 -req -in {$q("{$tmp}/{$name}.csr")} -CA {$q("{$tmp}/{$issuer}.pem")} -CAkey {$q("{$tmp}/{$issuer}.key")} -CAcreateserial {$dates} -extfile {$q("{$tmp}/{$name}.cnf")} -extensions v3 -out {$q("{$tmp}/{$name}.pem")}");
}

// ---- the probes: leaf key, x5chain (leaf first), the anchor they are judged under ------------------

$probes = [
    'control' => ['leaf-valid', ['leaf-valid', 'int-valid'], 'root-valid'],
    'expired-anchor' => ['leaf-expired-root', ['leaf-expired-root', 'int-expired-root'], 'root-expired'],
    'expired-anchor-direct' => ['leaf-expired-direct', ['leaf-expired-direct'], 'root-expired'],
    'expired-anchor-in-x5chain' => ['leaf-expired-root', ['leaf-expired-root', 'int-expired-root', 'root-expired'], 'root-expired'],
    'future-anchor' => ['leaf-future-root', ['leaf-future-root', 'int-future-root'], 'root-future'],
    'expired-int-as-anchor' => ['leaf-expired-int', ['leaf-expired-int', 'int-expired'], 'int-expired'],
];
foreach ($probes as $probe => [$key, $chain]) {
    file_put_contents("{$tmp}/{$probe}.chain.pem", implode('', array_map(static fn (string $c): string => $read("{$tmp}/{$c}.pem"), $chain)));
    file_put_contents("{$tmp}/{$probe}.manifest.json", json_encode([
        'alg' => 'es256',
        'private_key' => "{$tmp}/{$key}.key",
        'sign_cert' => "{$tmp}/{$probe}.chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier SPEC-014 anchor probe', 'version' => '1']],
        'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $run("{$q($old)} {$q($root.'/tests/Fixtures/fixture-unsigned.jpg')} -m {$q("{$tmp}/{$probe}.manifest.json")} -o {$q("{$out}/{$probe}.jpg")} -f");
}

// ---- the settings: public certificates only --------------------------------------------------------

$storeCfg = $read($root.'/tests/Fixtures/trust/store.cfg');
foreach (['root-valid', 'root-expired', 'root-future', 'int-expired'] as $anchor) {
    // the legacy single string: both c2patool versions read it (step 107), and it anchors signers and TSAs
    $value = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $read("{$tmp}/{$anchor}.pem"), 'trust_config' => $storeCfg]];
    file_put_contents("{$out}/{$anchor}.settings.json", json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

// ---- the oracles -----------------------------------------------------------------------------------

$cases = [];
foreach ($probes as $probe => [, , $anchor]) {
    $cases[] = [$probe, $anchor];
}
$cases[] = ['expired-int-as-anchor', 'root-valid'];   // the expired intermediate in x5chain, not an anchor
foreach ($cases as [$probe, $setting]) {
    printf('%s  %-26s %-13s', hash('sha256', $read("{$out}/{$probe}.jpg")), $probe, $setting);
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $v => $tool) {
        exec("{$q($tool)} {$q("{$out}/{$probe}.jpg")} --settings {$q("{$out}/{$setting}.settings.json")} 2>&1", $lines, $exit);
        $text = implode("\n", $lines)."\n";
        unset($lines);
        foreach (glob("{$oracles}/{$probe}--{$setting}--{$v}.*") ?: [] as $stale) {
            unlink($stale);
        }
        file_put_contents("{$oracles}/{$probe}--{$setting}--{$v}".($exit === 0 ? '.json' : '.error.txt'), $text);
        $json = json_decode($text, true);
        printf(' %s=%s', $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error');
    }
    echo "\n";
}
