<?php

declare(strict_types=1);

/*
 * Step 433: an ingredient manifest that carries a malformed external reference (C2PA 2.4 §15.11.3.3.1, which
 * validates an ingredient's assertions per §15.10, §15.10.3.2.2 among them). `c2patool` 0.28.3 signs
 * fixture-unsigned.jpg with a throw-away hierarchy (root ← intermediate ← signer, P-256), with one of the
 * SPEC-032 probes under tests/Fixtures/assertion-rules/ as its parent (`-p`):
 *   ingredient-reference-control     parent reference-hashed.jpg: a well-formed reference
 *   ingredient-reference-empty-url   parent reference-empty-url.jpg: a reference whose url is empty
 *   ingredient-reference-unrecorded  the same, with the fault c2patool recorded in the ingredient assertion's
 *                                    validationResults made unrecognisable (one letter of the code changed), the
 *                                    hashes that cover it recomputed and the claim signed again with the same key
 *
 * Usage: php bin/make-ingredient-reference-variants.php <scratch-dir> <c2patool-0.28.3> <c2patool-0.28.1>
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
 *
 * Writes:
 *   tests/Fixtures/ingredient-reference/<probe>.jpg, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/ingredient-reference/<probe>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.3' || $version($old) !== 'c2patool 0.28.1') {
    fwrite(STDERR, "usage: php bin/make-ingredient-reference-variants.php <scratch-dir outside the repository> <c2patool-0.28.3> <c2patool-0.28.1>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/ingredient-reference-keys-'.bin2hex(random_bytes(4));
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

function irRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function irSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function irCert(string $keys, string $name, string $cn, string $section, ?string $issuer, string $serial): void
{
    irRun(irSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    $subject = "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
    if ($issuer === null) {
        irRun(irSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-set_serial', '0x'.$serial, '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));

        return;
    }
    irRun(irSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    irRun(irSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
}

/** fixture-unsigned.jpg signed by the throw-away signer with $parent as its parent ingredient. */
function irSign(string $keys, string $tool, string $root, string $parent, string $out): void
{
    file_put_contents("{$keys}/m.json", (string) json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/signer.pk8", 'sign_cert' => "{$keys}/signer-chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier ingredient-reference probes', 'version' => '1']],
    ], JSON_UNESCAPED_SLASHES));
    irRun(irSh($tool, $root.'/tests/Fixtures/fixture-unsigned.jpg', '-m', "{$keys}/m.json", '-p', $parent, '-o', $out, '-f'));
}

/** $old replaced by $new (the same length) in the file and the store alike, $count times in each: never across an APP11 seam. */
function irSwap(string &$bytes, string &$store, string $old, string $new, int $count): void
{
    if (strlen($old) !== strlen($new) || substr_count($store, $old) !== $count || substr_count($bytes, $old) !== $count) {
        throw new RuntimeException(sprintf('expected %s %d time(s) in the store and in the file', bin2hex(substr($old, 0, 8)), $count));
    }
    $store = str_replace($old, $new, $store);
    $bytes = str_replace($old, $new, $bytes);
}

/** The payload of the active manifest's assertion box whose label starts with $prefix, in $store. */
function irPayload(string $store, Manifest $manifest, string $prefix): string
{
    foreach ($manifest->assertionStore->children as $child) {
        if ($child instanceof Superbox && str_starts_with($child->description->label, $prefix)) {
            return substr($store, $child->offset + 8, $child->length - 8);
        }
    }
    throw new RuntimeException("no assertion {$prefix}");
}

/** $from with the recorded external-reference fault unrecognisable, the hashes over it recomputed, the claim signed again. */
function irUnrecord(string $from, string $to, string $key): void
{
    $bytes = (string) file_get_contents($from);
    $stream = fopen($from, 'rb');
    $extracted = $stream === false ? null : (new JpegManifestStoreExtractor)->extract($stream);
    if ($extracted === null) {
        throw new RuntimeException("no store in {$from}");
    }
    $store = $extracted->bytes;
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $claim = $manifest->claimBytes();
    $signature = $manifest->signatureBytes();
    if (substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }

    // the recorded code, in the ingredient assertion; then its hash (in the claim and the c2pa.opened action),
    // then the actions assertion's hash (in the claim)
    $ingredient = irPayload($store, $manifest, 'c2pa.ingredient');
    $actions = irPayload($store, $manifest, 'c2pa.actions');
    irSwap($bytes, $store, 'assertion.external-reference.malformed', 'assertion.external-reference.malformeX', 1);
    $ingredientHashes = [hash('sha256', $ingredient, true), hash('sha256', irPayload($store, $manifest, 'c2pa.ingredient'), true)];
    irSwap($bytes, $store, $ingredientHashes[0], $ingredientHashes[1], 2);
    $actionsHashes = [hash('sha256', $actions, true), hash('sha256', irPayload($store, $manifest, 'c2pa.actions'), true)];
    irSwap($bytes, $store, $actionsHashes[0], $actionsHashes[1], 1);

    // the claim signed again: ES256 over the new Sig_structure, DER turned into R || S
    $edited = str_replace([$ingredientHashes[0], $actionsHashes[0]], [$ingredientHashes[1], $actionsHashes[1]], $claim);
    file_put_contents(dirname($key).'/tbs', CoseSign1::fromBytes($signature)->sigStructure($edited));
    irRun(irSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $q = 2; $i < 2; $i++) {
        $l = ord($der[$q + 1]);
        $rs .= str_pad(ltrim(substr($der, $q + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $q += 2 + $l;
    }
    irSwap($bytes, $store, substr($signature, -64), $rs, 1);
    file_put_contents($to, $bytes);
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/ingredient-reference';
$oracles = $root.'/tests/Fixtures/c2patool/ingredient-reference';
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
irCert($keys, 'root', 'Throw-away Root (ingredient reference)', 'root', null, '0A');
irCert($keys, 'int', 'Throw-away Intermediate (ingredient reference)', 'ca', 'root', '1A');
irCert($keys, 'signer', 'Signer (ingredient reference)', 'leaf', 'int', '2A');
irRun(irSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/signer.key", '-out', "{$keys}/signer.pk8"));
file_put_contents("{$keys}/signer-chain.pem", (string) file_get_contents("{$keys}/signer.pem").(string) file_get_contents("{$keys}/int.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
// both roots: this hierarchy's, and the one that signed the SPEC-032 parents
$anchors = $rootPem.(string) file_get_contents($root.'/tests/Fixtures/assertion-rules/probe-root.pem');
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $anchors, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the probes ----
$probes = ['ingredient-reference-control' => 'reference-hashed.jpg', 'ingredient-reference-empty-url' => 'reference-empty-url.jpg'];
foreach ($probes as $name => $parent) {
    irSign($keys, $new, $root, $root.'/tests/Fixtures/assertion-rules/'.$parent, "{$dir}/{$name}.jpg");
}
irUnrecord("{$dir}/ingredient-reference-empty-url.jpg", "{$dir}/ingredient-reference-unrecorded.jpg", "{$keys}/signer.key");
$probes['ingredient-reference-unrecorded'] = 'reference-empty-url.jpg';

// ---- the oracles ----
foreach (array_keys($probes) as $name) {
    foreach (['0.28.3' => $new, '0.28.1' => $old] as $v => $tool) {
        $lines = [];
        exec(irSh($tool, "{$dir}/{$name}.jpg", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-32s %-7s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
    }
}
