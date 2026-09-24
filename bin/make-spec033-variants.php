<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-033 (step 125a): signed probes for the actions
 * content rules (one opening, ingredient references, translation, related
 * assertions, watermarks), and what both c2patool versions say.
 *
 * Every probe is fixture-unsigned.jpg signed by c2patool 0.28.0 with a
 * throwaway leaf (claim-signing + emailProtection) under a throwaway
 * intermediate and root. The three keys live in a temporary directory for
 * the length of this script and are overwritten and deleted before it ends.
 * This repository holds no private key.
 *
 * Run:   php bin/make-spec033-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Needs: the `openssl` command and both c2patool versions. Tooling only.
 *
 * Writes:
 *   tests/Fixtures/actions-rules/<probe>.jpg
 *   tests/Fixtures/actions-rules/probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/actions-rules/<probe>--<version>.json  (with the root as anchor)
 */

$root = dirname(__DIR__);
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec033-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/actions-rules';
$oracles = $root.'/tests/Fixtures/c2patool/actions-rules';
$tmp = sys_get_temp_dir().'/c2pa-spec033-'.bin2hex(random_bytes(6));
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
file_put_contents("{$tmp}/int.cnf", $dn('SPEC-033 Probe Intermediate')."[v3]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
file_put_contents("{$tmp}/leaf.cnf", $dn('SPEC-033 Probe Signer')."[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.4.1.62558.2.1,emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
// req -x509 reads x509_extensions from the [req] section, so the root's file is written out in full
file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=SPEC-033 Probe Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
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
$ingredient = static fn (string $id, string $relationship): array => ['title' => "{$id}.jpg", 'format' => 'image/jpeg', 'relationship' => $relationship, 'instance_id' => "xmp:iid:{$id}", 'label' => $id];
$ref = static fn (string $label): array => ['url' => "self#jumbf=c2pa.assertions/{$label}", 'hash' => '47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU='];
$note = ['label' => 'com.example.note', 'data' => ['note' => 'a related assertion']];
// `value` is a byte string in c2pa-rs's SoftBindingBlock: given as a list of byte values, which the builder encodes as bytes
$softBinding = ['label' => 'c2pa.soft-binding', 'data' => ['alg' => 'com.example.watermark', 'blocks' => [['scope' => new stdClass, 'value' => [1, 2, 3, 4]]]]];
// name => [assertions, ingredients]
$probes = [
    // AC1: one opening
    'created-then-opened' => [[$actions($created, ['action' => 'c2pa.opened'])], []],
    'edited-then-created' => [[$actions(['action' => 'c2pa.edited'], $created)], []],
    // AC2: opened/placed/removed without usable references
    'placed-no-parameters' => [[$actions($created, ['action' => 'c2pa.placed'])], []],
    'placed-no-ingredients' => [[$actions($created, ['action' => 'c2pa.placed', 'parameters' => ['description' => 'no references']])], []],
    'placed-empty-ingredients' => [[$actions($created, ['action' => 'c2pa.placed', 'parameters' => ['ingredients' => []]])], []],
    'placed-unresolvable' => [[$actions($created, ['action' => 'c2pa.placed', 'parameters' => ['ingredients' => [$ref('c2pa.ingredient.v3__9')]]])], []],
    // AC3: the relationship
    'placed-parent' => [[$actions($created, ['action' => 'c2pa.placed', 'parameters' => ['ingredientIds' => ['p1']]])], [$ingredient('p1', 'parentOf')]],
    'placed-component' => [[$actions($created, ['action' => 'c2pa.placed', 'parameters' => ['ingredientIds' => ['c1']]])], [$ingredient('c1', 'componentOf')]],
    'opened-component' => [[$actions(['action' => 'c2pa.opened', 'parameters' => ['ingredientIds' => ['c1']]])], [$ingredient('c1', 'componentOf')]],
    'opened-parent' => [[$actions(['action' => 'c2pa.opened', 'parameters' => ['ingredientIds' => ['p1']]])], [$ingredient('p1', 'parentOf')]],
    // AC4: transcoded and repackaged
    'transcoded-component' => [[$actions($created, ['action' => 'c2pa.transcoded', 'parameters' => ['ingredientIds' => ['c1']]])], [$ingredient('c1', 'componentOf')]],
    'repackaged-no-reference' => [[$actions($created, ['action' => 'c2pa.repackaged'])], []],
    // AC5: translation
    'translated-no-parameters' => [[$actions($created, ['action' => 'c2pa.translated'])], []],
    'translated-source-only' => [[$actions($created, ['action' => 'c2pa.translated', 'parameters' => ['sourceLanguage' => 'nl']])], []],
    'translated-both' => [[$actions($created, ['action' => 'c2pa.translated', 'parameters' => ['sourceLanguage' => 'nl', 'targetLanguage' => 'en']])], []],
    // AC6: related assertions
    'related-empty' => [[$actions($created, ['action' => 'c2pa.edited', 'parameters' => ['relatedAssertions' => []]])], []],
    'related-missing' => [[$actions($created, ['action' => 'c2pa.edited', 'parameters' => ['relatedAssertions' => [$ref('com.example.missing')]]])], []],
    'related-actions' => [[$actions($created, ['action' => 'c2pa.edited', 'parameters' => ['relatedAssertions' => [$ref('c2pa.actions.v2')]]])], []],
    'related-note' => [[$actions($created, ['action' => 'c2pa.edited', 'parameters' => ['relatedAssertions' => [$ref('com.example.note')]]]), $note], []],
    // AC7: watermarks
    'watermarked-no-soft-binding' => [[$actions($created, ['action' => 'c2pa.watermarked.bound'])], []],
    'watermarked-with-soft-binding' => [[$actions($created, ['action' => 'c2pa.watermarked.bound']), $softBinding], []],
];
// c2patool refuses to sign with an EKU it does not know; the claim-signing OID is listed for the signing run
file_put_contents("{$tmp}/sign.settings.json", json_encode(['trust' => ['trust_config' => "1.3.6.1.4.1.62558.2.1\n1.3.6.1.5.5.7.3.4\n"]], JSON_THROW_ON_ERROR));
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach ($probes as $name => [$assertions, $ingredients]) {
    file_put_contents("{$tmp}/manifest.json", json_encode([
        'alg' => 'es256',
        'private_key' => "{$tmp}/leaf.key",
        'sign_cert' => "{$tmp}/chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier SPEC-033 probe', 'version' => '1']],
        'assertions' => $assertions,
        'ingredients' => $ingredients,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $file = "{$out}/{$name}.jpg";
    // c2patool prints its own validation after signing and still writes the file; only a missing file is a failure
    exec("{$q($new)} {$q($root.'/tests/Fixtures/fixture-unsigned.jpg')} -m {$q("{$tmp}/manifest.json")} --settings {$q("{$tmp}/sign.settings.json")} -o {$q($file)} -f 2>&1", $ignored);
    if (! is_file($file) || filesize($file) === 0) {
        @unlink($file);
        printf("%-30s not written by c2patool: %s\n", $name, trim((string) end($ignored)));   // open question 4: left to the seam

        continue;
    }
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $label => $tool) {
        foreach (['root' => " --settings {$q($settings)}"] as $case => $flag) {
            $lines = [];
            exec("{$q($tool)} {$q($file)}{$flag} 2>&1", $lines, $code);
            $target = "{$oracles}/{$name}--{$label}".($code === 0 ? '.json' : '.error.txt');
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
