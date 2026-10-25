<?php

declare(strict_types=1);

/*
 * Step 429: ingredient references that do not resolve, in a c2pa.opened and in the actions that take one or
 * more (SPEC-033 amendment 3; docs/reading-c2pa-2.4.md, group A, question 3). `c2patool` 0.28.3 signs
 * fixture-unsigned.jpg with a throw-away P-256 hierarchy; its builder keeps the references as given. The hashes in
 * the references are not those of their targets: neither `c2pa-rs` nor this verifier compares them (P06-5). Keys
 * live in a scratch directory for the run and are deleted before it ends; the public root goes into a settings
 * file.
 *
 * Variants (`good` names the ingredient assertion, `unresolvable` names c2pa.ingredient.v3__9, which is not there):
 *   opened-good                     one reference to the parentOf ingredient
 *   opened-good-unresolvable        that reference, then an unresolvable one
 *   opened-unresolvable-good        the same two, the other way round
 *   opened-good-twice               the good reference twice
 *   opened-good-component           the good reference, then one to a componentOf ingredient
 *   placed-good-unresolvable        c2pa.placed after c2pa.created: a componentOf reference, then an unresolvable one
 *   placed-unresolvable-good        the same two, the other way round
 *
 * Usage: php bin/make-opened-reference-variants.php <scratch-dir> <c2patool-0.28.3> <c2patool-0.28.1>
 * Needs `openssl`. Tooling, not the verification path.
 *
 * Writes:
 *   tests/Fixtures/opened-references/<variant>.jpg, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/opened-references/<variant>--<version>.json
 */

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.3' || $version($old) !== 'c2patool 0.28.1') {
    fwrite(STDERR, "usage: php bin/make-opened-reference-variants.php <scratch-dir outside the repository> <c2patool-0.28.3> <c2patool-0.28.1>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/opened-reference-keys-'.bin2hex(random_bytes(4));
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

function orRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function orSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/opened-references';
$oracles = $root.'/tests/Fixtures/c2patool/opened-references';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
orRun(orSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
orRun(orSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (opened reference probes)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
orRun(orSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
orRun(orSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Opened reference probe signer', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
orRun(orSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/leaf.pem"));
orRun(orSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/leaf.key", '-out', "{$keys}/leaf.pk8"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the probes ----
$ref = static fn (string $label): array => ['url' => "self#jumbf=c2pa.assertions/{$label}", 'hash' => '47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU='];
$ingredient = static fn (string $id, string $relationship): array => ['title' => "{$id}.jpg", 'format' => 'image/jpeg', 'relationship' => $relationship, 'instance_id' => "xmp:iid:{$id}", 'label' => $id];
$created = ['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture'];
$opened = static fn (array $references): array => [['action' => 'c2pa.opened', 'parameters' => ['ingredients' => $references]]];
$placed = static fn (array $references): array => [$created, ['action' => 'c2pa.placed', 'parameters' => ['ingredients' => $references]]];
// the builder labels the first ingredient c2pa.ingredient.v3 and the second c2pa.ingredient.v3__1
[$good, $second, $none] = [$ref('c2pa.ingredient.v3'), $ref('c2pa.ingredient.v3__1'), $ref('c2pa.ingredient.v3__9')];
// name => [actions, ingredients]
$probes = [
    'opened-good' => [$opened([$good]), [$ingredient('p1', 'parentOf')]],
    'opened-good-unresolvable' => [$opened([$good, $none]), [$ingredient('p1', 'parentOf')]],
    'opened-unresolvable-good' => [$opened([$none, $good]), [$ingredient('p1', 'parentOf')]],
    'opened-good-twice' => [$opened([$good, $good]), [$ingredient('p1', 'parentOf')]],
    'opened-good-component' => [$opened([$good, $second]), [$ingredient('p1', 'parentOf'), $ingredient('c1', 'componentOf')]],
    'placed-good-unresolvable' => [$placed([$good, $none]), [$ingredient('c1', 'componentOf')]],
    'placed-unresolvable-good' => [$placed([$none, $good]), [$ingredient('c1', 'componentOf')]],
];
foreach ($probes as $name => [$actions, $ingredients]) {
    file_put_contents("{$keys}/m.json", json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/leaf.pk8", 'sign_cert' => "{$keys}/leaf.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier opened reference probes', 'version' => '1']],
        'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => $actions]]],
        'ingredients' => $ingredients,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    // c2patool prints its own validation after signing; only a missing file is a failure
    exec(orSh($new, $root.'/tests/Fixtures/fixture-unsigned.jpg', '-m', "{$keys}/m.json", '-o', "{$dir}/{$name}.jpg", '-f').' 2>&1', $ignored);
    if (! is_file("{$dir}/{$name}.jpg")) {
        throw new RuntimeException("c2patool wrote no {$name}.jpg");
    }
}

// ---- the oracles ----
foreach (array_keys($probes) as $name) {
    foreach (['0.28.3' => $new, '0.28.1' => $old] as $v => $tool) {
        $lines = [];
        exec(orSh($tool, "{$dir}/{$name}.jpg", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        $results = is_array($json) && is_array($json['validation_results'] ?? null) ? $json['validation_results'] : [];
        $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
        $failures = is_array($active['failure'] ?? null) ? array_filter(array_column($active['failure'], 'code'), 'is_string') : [];
        printf("  %-26s %-7s %s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90), implode(',', $failures));
    }
}
