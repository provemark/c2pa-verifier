<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-031 (step 111a): the `trust.anchors` settings,
 * the custom-EKU probe file, and what `c2patool` 0.28.0 says about each.
 *
 * What is committed is settings files (public certificates and EKU lists
 * only), one signed JPEG, one public root certificate, and c2patool's JSON.
 * The three keys that make the probe file live in a temporary directory for
 * the length of this script and are overwritten and deleted before it ends,
 * in the shape `bin/make-ocsp-variants.php` uses. This repository holds no
 * private key, not even a test key.
 *
 * Run:   php bin/make-anchors-variants.php <path-to-c2patool-0.28.0>
 * Needs: the `openssl` command and c2patool 0.28.0 (`c2pa` 0.91.0), the first
 *        version that reads `trust.anchors`. Tooling, not the verification
 *        path: the verifier runs no process and never loads this script.
 *
 * Writes:
 *   tests/Fixtures/trust/anchors/*.settings.json   the settings, see $settings below
 *   tests/Fixtures/trust/anchors/eku-probe-root.pem the probe's root, public
 *   tests/Fixtures/trust/anchors/eku-probe.jpg     fixture-unsigned.jpg signed by a leaf whose only
 *                                                  EKU is 1.3.6.1.4.1.99999.1, under an intermediate
 *   tests/Fixtures/c2patool/anchors/<file>--<settings>.json   c2patool 0.28.0's report, or
 *                                                  <file>--<settings>.error.txt when it exits non-zero
 */

$root = dirname(__DIR__);
$c2patool = $argv[1] ?? '';
if ($c2patool === '' || ! is_file($c2patool)) {
    fwrite(STDERR, "usage: php bin/make-anchors-variants.php <path-to-c2patool-0.28.0>\n");
    exit(1);
}
exec(escapeshellarg($c2patool).' --version 2>&1', $version);
if (($version[0] ?? '') !== 'c2patool 0.28.0') {
    fwrite(STDERR, 'this script is measured against c2patool 0.28.0, not '.($version[0] ?? 'nothing')."\n");
    exit(1);
}

$trust = $root.'/tests/Fixtures/trust';
$out = $trust.'/anchors';
$oracles = $root.'/tests/Fixtures/c2patool/anchors';
$tmp = sys_get_temp_dir().'/c2pa-anchors-'.bin2hex(random_bytes(6));
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
$read = static function (string $path): string {
    $text = file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "cannot read {$path}\n");
        exit(1);
    }

    return $text;
};

// ---- the probe: a root, an intermediate, and a leaf whose only EKU no list knows ----

$EKU = '1.3.6.1.4.1.99999.1';
file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=SPEC-031 EKU Probe Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
file_put_contents("{$tmp}/int.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=SPEC-031 EKU Probe Intermediate\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
file_put_contents("{$tmp}/leaf.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=SPEC-031 EKU Probe Signer\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage={$EKU}\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
foreach (['root', 'int', 'leaf'] as $name) {
    $run("openssl ecparam -name prime256v1 -genkey -noout -out {$q("{$tmp}/{$name}.raw.key")}");
    $run("openssl pkcs8 -topk8 -nocrypt -in {$q("{$tmp}/{$name}.raw.key")} -out {$q("{$tmp}/{$name}.key")}");
}
$run("openssl req -new -x509 -key {$q("{$tmp}/root.key")} -config {$q("{$tmp}/root.cnf")} -days 3650 -out {$q("{$tmp}/root.pem")}");
foreach (['int' => 'root', 'leaf' => 'int'] as $name => $issuer) {
    $run("openssl req -new -key {$q("{$tmp}/{$name}.key")} -config {$q("{$tmp}/{$name}.cnf")} -out {$q("{$tmp}/{$name}.csr")}");
    $run("openssl x509 -req -in {$q("{$tmp}/{$name}.csr")} -CA {$q("{$tmp}/{$issuer}.pem")} -CAkey {$q("{$tmp}/{$issuer}.key")} -CAcreateserial -days 3650 -extfile {$q("{$tmp}/{$name}.cnf")} -extensions v3 -out {$q("{$tmp}/{$name}.pem")}");
}
file_put_contents("{$tmp}/chain.pem", $read("{$tmp}/leaf.pem").$read("{$tmp}/int.pem"));
file_put_contents("{$tmp}/manifest.json", json_encode([
    'alg' => 'es256',
    'private_key' => "{$tmp}/leaf.key",
    'sign_cert' => "{$tmp}/chain.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier SPEC-031 probe', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
// c2patool will not sign with a leaf whose EKU it does not accept, so the signing run is told about it
file_put_contents("{$tmp}/sign.settings.json", json_encode(['trust' => ['trust_config' => "{$EKU}\n"]], JSON_THROW_ON_ERROR));
$probe = "{$out}/eku-probe.jpg";
$run("{$q($c2patool)} {$q($root.'/tests/Fixtures/fixture-unsigned.jpg')} -m {$q("{$tmp}/manifest.json")} --settings {$q("{$tmp}/sign.settings.json")} -o {$q($probe)} -f");
copy("{$tmp}/root.pem", "{$out}/eku-probe-root.pem");

// ---- the settings ----

$pem = static fn (string $file): string => $read("{$trust}/{$file}");
$testRoots = $pem('trust_anchors.pem');
$storeCfg = $pem('store.cfg');
$allowed = $pem('allowed_list.pem');
$truepic = $pem('truepic-root.pem');
$digicert = $pem('digicert-trusted-root-g4.pem');
$probeRoot = $read("{$out}/eku-probe-root.pem");
$verify = ['verify' => ['verify_trust' => true]];
$entry = static fn (string $anchors, string $kind, array $extra = []): array => ['trust_anchors' => $anchors, 'trust_kind' => $kind] + $extra;
$fullPlusDigicert = json_decode($pem('full-plus-digicert-g4.settings.json'), true, 8, JSON_THROW_ON_ERROR);
if (! is_array($fullPlusDigicert) || ! is_array($fullPlusDigicert['trust'] ?? null) || ! is_string($fullPlusDigicert['trust']['trust_anchors'] ?? null) || ! is_string($fullPlusDigicert['trust']['trust_config'] ?? null)) {
    fwrite(STDERR, "full-plus-digicert-g4.settings.json is not the shape SPEC-014 fixed\n");
    exit(1);
}
$digicertTrust = $fullPlusDigicert['trust'];

$settings = [
    // AC1: full.settings.json in the new shape, one entry
    'full' => $verify + ['trust' => ['anchors' => [$entry($testRoots, 'manifest')], 'trust_config' => $storeCfg]],
    // AC7: the twins of full and full-plus-digicert-g4 — the same PEM once per kind, which is what the legacy string means
    'full-twin' => $verify + ['trust' => ['anchors' => [$entry($testRoots, 'manifest'), $entry($testRoots, 'tsa')], 'trust_config' => $storeCfg]],
    'full-plus-digicert-g4-twin' => $verify + ['trust' => [
        'anchors' => [$entry($digicertTrust['trust_anchors'], 'manifest'), $entry($digicertTrust['trust_anchors'], 'tsa')],
        'trust_config' => $digicertTrust['trust_config'],
    ]],
    // AC2: the legacy string and an entry, added together
    'legacy-plus-truepic-entry' => $verify + ['trust' => ['trust_anchors' => $testRoots, 'anchors' => [$entry($truepic, 'manifest')], 'trust_config' => $storeCfg]],
    // AC3: the allowed list inside a manifest entry
    'allowed-in-entry' => $verify + ['trust' => ['anchors' => [$entry('', 'manifest', ['allowed_list' => $allowed])], 'trust_config' => $storeCfg]],
    // AC6: every anchor counts only for its own kind
    'test-roots-as-tsa' => $verify + ['trust' => ['anchors' => [$entry($testRoots, 'tsa')], 'trust_config' => $storeCfg]],
    'test-roots-as-cawg' => $verify + ['trust' => ['anchors' => [$entry($testRoots, 'cawg')], 'trust_config' => $storeCfg]],
    'digicert-as-tsa' => $verify + ['trust' => ['anchors' => [$entry($digicert, 'tsa')]]],
    'digicert-as-manifest' => $verify + ['trust' => ['anchors' => [$entry($digicert, 'manifest')]]],
    'tsa-with-allowed-list' => $verify + ['trust' => ['anchors' => [$entry($digicert, 'tsa', ['allowed_list' => $allowed])]]],
    // AC8: step 110's E1–E9 on the probe
    'e1-legacy-no-config' => $verify + ['trust' => ['trust_anchors' => $probeRoot]],
    'e2-legacy-top-config' => $verify + ['trust' => ['trust_anchors' => $probeRoot, 'trust_config' => "{$EKU}\n"]],
    'e2b-legacy-store-cfg' => $verify + ['trust' => ['trust_anchors' => $probeRoot, 'trust_config' => $storeCfg]],
    'e3-entry-no-config' => $verify + ['trust' => ['anchors' => [$entry($probeRoot, 'manifest')]]],
    'e4-entry-own-config' => $verify + ['trust' => ['anchors' => [$entry($probeRoot, 'manifest', ['trust_config' => "{$EKU}\n"])]]],
    'e5-config-on-another-entry' => $verify + ['trust' => ['anchors' => [$entry($probeRoot, 'manifest'), $entry($testRoots, 'manifest', ['trust_config' => "{$EKU}\n"])]]],
    'e6-entry-config-top-store-cfg' => $verify + ['trust' => ['anchors' => [$entry($probeRoot, 'manifest', ['trust_config' => "{$EKU}\n"])], 'trust_config' => $storeCfg]],
    'e7-entry-email-top-config' => $verify + ['trust' => ['anchors' => [$entry($probeRoot, 'manifest', ['trust_config' => "1.3.6.1.5.5.7.3.4\n"])], 'trust_config' => "{$EKU}\n"]],
    'e8-entry-no-config-top-config' => $verify + ['trust' => ['anchors' => [$entry($probeRoot, 'manifest')], 'trust_config' => "{$EKU}\n"]],
    'e9-no-anchors-top-config' => $verify + ['trust' => ['trust_config' => "{$EKU}\n"]],
];
foreach ($settings as $name => $value) {
    file_put_contents("{$out}/{$name}.settings.json", json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

// ---- what c2patool 0.28.0 says ----

$runs = [
    'fixture-signed.jpg' => ['full', 'allowed-in-entry', 'legacy-plus-truepic-entry', 'test-roots-as-tsa', 'test-roots-as-cawg', 'tsa-with-allowed-list'],
    'fixture-signed.png' => ['full'],
    'fixture-signed.webp' => ['full'],
    'fixture-signed.mp4' => ['full'],
    'c2pa-rs/C.jpg' => ['digicert-as-tsa', 'digicert-as-manifest'],
    'public-testfiles/truepic-20230212-camera.jpg' => ['legacy-plus-truepic-entry'],
    'trust/anchors/eku-probe.jpg' => ['e1-legacy-no-config', 'e2-legacy-top-config', 'e2b-legacy-store-cfg', 'e3-entry-no-config', 'e4-entry-own-config', 'e5-config-on-another-entry', 'e6-entry-config-top-store-cfg', 'e7-entry-email-top-config', 'e8-entry-no-config-top-config', 'e9-no-anchors-top-config'],
];
// AC4: the loose allowed lists, as they are in the repository today
$loose = ['allowed-only', 'full-plus-allowed', 'allowed-plus-wrong-root'];
foreach (glob("{$oracles}/*") ?: [] as $old) {
    unlink($old);
}
$record = static function (string $file, string $settingsPath, string $name) use ($c2patool, $q, $root, $oracles): void {
    exec("{$q($c2patool)} {$q($root.'/tests/Fixtures/'.$file)} --settings {$q($settingsPath)} 2>&1", $lines, $code);
    $base = $oracles.'/'.str_replace('/', '_', $file).'--'.$name;
    file_put_contents($code === 0 ? "{$base}.json" : "{$base}.error.txt", implode("\n", $lines)."\n");
    $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
    $state = is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}";
    printf("%-50s %-32s %s\n", $file, $name, $state);
};
foreach ($runs as $file => $names) {
    foreach ($names as $name) {
        $record($file, "{$out}/{$name}.settings.json", $name);
    }
}
foreach ($loose as $name) {
    $record('fixture-signed.jpg', "{$trust}/{$name}.settings.json", "loose-{$name}");
}

$shred();
if (glob($tmp.'/*') !== [] && is_dir($tmp)) {
    fwrite(STDERR, "a key survived in {$tmp}\n");
    exit(1);
}
printf("sha256 %s  eku-probe.jpg\n", hash_file('sha256', $probe));
