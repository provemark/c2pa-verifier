<?php

declare(strict_types=1);

/*
 * Step 511: an assertion label made only of digits (SPEC-007 amendment 10). PHP turns such an array key into an
 * integer, and the checks that took the key as a string threw. `c2patool` 0.28.3 signs fixture-unsigned.png with
 * such a label as it signs any other, under a throw-away hierarchy (root ← intermediate ← signer, P-256):
 *   numeric-label-control   c2pa.actions.v2 and an assertion `probe.note`
 *   numeric-label-5         c2pa.actions.v2 and an assertion `5`
 *   numeric-label-0         c2pa.actions.v2 and an assertion `0`
 *
 * Usage: php bin/make-numeric-label-variants.php <scratch-dir> <c2patool-0.28.3>
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
 *
 * Writes:
 *   tests/Fixtures/numeric-label/<probe>.png, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/numeric-label/<probe>--0.28.3.json
 */

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $tool] = [$argv[1] ?? null, $argv[2] ?? ''];
exec(escapeshellarg($tool).' --version 2>&1', $versionLines);
if (! is_string($scratch) || ! is_dir($scratch) || ($versionLines[0] ?? '') !== 'c2patool 0.28.3') {
    fwrite(STDERR, "usage: php bin/make-numeric-label-variants.php <scratch-dir outside the repository> <c2patool-0.28.3>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/numeric-label-keys-'.bin2hex(random_bytes(4));
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

function nlRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function nlSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function nlCert(string $keys, string $name, string $cn, string $section, ?string $issuer, string $serial): void
{
    nlRun(nlSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    $subject = "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
    if ($issuer === null) {
        nlRun(nlSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-set_serial', '0x'.$serial, '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));

        return;
    }
    nlRun(nlSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    nlRun(nlSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/numeric-label';
$oracles = $root.'/tests/Fixtures/c2patool/numeric-label';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}

// ---- the hierarchy ----
file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n"
    ."[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n"
    ."[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    ."[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
nlCert($keys, 'root', 'Throw-away Root (numeric label)', 'root', null, '0D');
nlCert($keys, 'int', 'Throw-away Intermediate (numeric label)', 'ca', 'root', '1D');
nlCert($keys, 'signer', 'Signer (numeric label)', 'leaf', 'int', '2D');
nlRun(nlSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/signer.key", '-out', "{$keys}/signer.pk8"));
file_put_contents("{$keys}/signer-chain.pem", (string) file_get_contents("{$keys}/signer.pem").(string) file_get_contents("{$keys}/int.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the probes, and the oracle ----
$actions = ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]];
foreach (['numeric-label-control' => 'probe.note', 'numeric-label-5' => '5', 'numeric-label-0' => '0'] as $name => $label) {
    file_put_contents("{$keys}/m.json", (string) json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/signer.pk8", 'sign_cert' => "{$keys}/signer-chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier numeric-label probes', 'version' => '1']],
        'assertions' => [$actions, ['label' => $label, 'data' => ['note' => 'an assertion of no type c2patool knows']]],
    ], JSON_UNESCAPED_SLASHES));
    nlRun(nlSh($tool, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-o', "{$dir}/{$name}.png", '-f'));
    $lines = [];
    exec(nlSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
    file_put_contents("{$oracles}/{$name}--0.28.3.json", implode("\n", $lines)."\n");
    $json = json_decode(implode("\n", $lines), true);
    printf("  %-24s %s\n", $name, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
}
