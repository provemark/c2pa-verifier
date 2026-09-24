<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-037 (step 132a): children of a parent that carries an extra
 * com.example.secret, made by c2patool 0.28.0's builder with -p and "redactions", each with one
 * c2pa.redacted action whose parameters vary (step 131's shapes). Both c2patool versions judge
 * every file. The throwaway keys are shredded; a surviving key is an error.
 *
 * Usage: php bin/make-spec037-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes:
 *   tests/Fixtures/redacted-action/<probe>.png, probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/redacted-action/<probe>--<version>.json (or .error.txt)
 * Tooling.
 */

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec037-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/redacted-action';
$oracles = $root.'/tests/Fixtures/c2patool/redacted-action';
$tmp = sys_get_temp_dir().'/c2pa-spec037-'.bin2hex(random_bytes(6));
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
$fail = static function (string $why): never {
    fwrite(STDERR, $why."\n");
    exit(1);
};

// ---- root → intermediate → leaf ----

$dn = static fn (string $cn): string => "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN={$cn}\nO=c2pa-verifier test\nC=NL\n";
file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=SPEC-035 Probe Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
file_put_contents("{$tmp}/int.cnf", $dn('SPEC-035 Probe Intermediate')."[v3]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
file_put_contents("{$tmp}/leaf.cnf", $dn('SPEC-035 Probe Signer')."[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.4.1.62558.2.1,emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
foreach (['root', 'int', 'leaf'] as $name) {
    $run("openssl ecparam -name prime256v1 -genkey -noout -out {$q("{$tmp}/{$name}.raw.key")}");
    $run("openssl pkcs8 -topk8 -nocrypt -in {$q("{$tmp}/{$name}.raw.key")} -out {$q("{$tmp}/{$name}.key")}");
}
$run("openssl req -new -x509 -key {$q("{$tmp}/root.key")} -config {$q("{$tmp}/root.cnf")} -days 3650 -out {$q("{$tmp}/root.pem")}");
foreach (['int' => 'root', 'leaf' => 'int'] as $name => $issuer) {
    $run("openssl req -new -key {$q("{$tmp}/{$name}.key")} -config {$q("{$tmp}/{$name}.cnf")} -out {$q("{$tmp}/{$name}.csr")}");
    $run("openssl x509 -req -in {$q("{$tmp}/{$name}.csr")} -CA {$q("{$tmp}/{$issuer}.pem")} -CAkey {$q("{$tmp}/{$issuer}.key")} -CAcreateserial -days 3650 -extfile {$q("{$tmp}/{$name}.cnf")} -extensions v3 -out {$q("{$tmp}/{$name}.pem")}");
}
$leafPem = (string) file_get_contents("{$tmp}/leaf.pem");
$intPem = (string) file_get_contents("{$tmp}/int.pem");
$rootPem = (string) file_get_contents("{$tmp}/root.pem");
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
file_put_contents("{$tmp}/chain.pem", $leafPem.$intPem);
copy("{$tmp}/root.pem", "{$out}/probe-root.pem");
$settings = "{$out}/probe-root.settings.json";
file_put_contents($settings, json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => rtrim($storeCfg)."\n"]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents("{$tmp}/sign.settings.json", json_encode(['trust' => ['trust_config' => "1.3.6.1.4.1.62558.2.1\n1.3.6.1.5.5.7.3.4\n"]], JSON_THROW_ON_ERROR));

// ---- the parent, and the redacting children, from the builder ----

$dst = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture';
$generator = ['name' => 'c2pa-verifier SPEC-035 probe', 'version' => '1'];
$sign = static function (string $from, array $manifest, string $to, ?string $parent = null) use ($new, $tmp, $q, $fail): void {
    file_put_contents("{$tmp}/manifest.json", json_encode(['alg' => 'es256', 'private_key' => "{$tmp}/leaf.key", 'sign_cert' => "{$tmp}/chain.pem", 'claim_generator_info' => [['name' => 'c2pa-verifier SPEC-035 probe', 'version' => '1']]] + $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $withParent = $parent === null ? '' : " -p {$q($parent)}";
    // c2patool prints its own validation after signing and still writes the file
    exec("{$q($new)} {$q($from)} -m {$q("{$tmp}/manifest.json")}{$withParent} --settings {$q("{$tmp}/sign.settings.json")} -o {$q($to)} -f 2>&1", $ignored);
    if (! is_file($to) || filesize($to) === 0) {
        $fail("c2patool did not write {$to}: ".implode(' | ', array_slice($ignored, -3)));
    }
};
$sign($root.'/tests/Fixtures/fixture-unsigned.png', ['assertions' => [
    ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => $dst]]]],
    ['label' => 'com.example.secret', 'data' => ['note' => 'redacted by the child']],
]], "{$tmp}/parent.png");
exec("{$q($new)} {$q("{$tmp}/parent.png")} 2>&1", $lines);
$parentReport = json_decode(implode("\n", $lines), true);
$parentLabel = is_array($parentReport) && is_string($parentReport['active_manifest'] ?? null) ? $parentReport['active_manifest'] : $fail('the parent has no active manifest');
$secret = "self#jumbf=/c2pa/{$parentLabel}/c2pa.assertions/com.example.secret";
$other = "self#jumbf=/c2pa/{$parentLabel}/c2pa.assertions/c2pa.actions.v2";
$probes = [
    'valid' => [[$secret], ['redacted' => $secret]],
    'no-parameters' => [[$secret], null],
    'parameters-without-redacted' => [[$secret], ['description' => 'removed a secret']],
    'foreign-manifest' => [[$secret], ['redacted' => 'self#jumbf=/c2pa/urn:c2pa:00000000-0000-4000-8000-000000000000/c2pa.assertions/com.example.secret']],
    'unknown-label' => [[$secret], ['redacted' => "self#jumbf=/c2pa/{$parentLabel}/c2pa.assertions/com.example.nothing"]],
    'present-not-redacted' => [[$secret], ['redacted' => $other]],
    'relative' => [[$secret], ['redacted' => 'self#jumbf=c2pa.assertions/com.example.secret']],
    'no-redaction-list' => [[], ['redacted' => $secret]],
];
foreach ($probes as $name => [$redactions, $parameters]) {
    $action = ['action' => 'c2pa.redacted', 'reason' => 'c2pa.PII.present'] + ($parameters === null ? [] : ['parameters' => $parameters]);
    $sign("{$tmp}/parent.png", ($redactions === [] ? [] : ['redactions' => $redactions]) + ['assertions' => [
        ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$action]]],
    ]], "{$out}/{$name}.png", "{$tmp}/parent.png");
}

// ---- what each c2patool says, with the root as anchor ----

foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (glob("{$out}/*.png") ?: [] as $file) {
    $name = basename($file, '.png');
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $label => $tool) {
        $lines = [];
        exec("{$q($tool)} {$q($file)} --settings {$q($settings)} 2>&1", $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$label}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        printf("%-32s %-8s %s\n", $name, $label, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
    }
}

$shred();
if (is_dir($tmp)) {
    $fail("a key survived in {$tmp}");
}
