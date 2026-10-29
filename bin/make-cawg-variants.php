<?php

declare(strict_types=1);

/*
 * Step 502: CAWG identity assertions where SPEC-013 amendment 7's refusal did not look (SPEC-013 amendment 24,
 * SPEC-021 amendment 8). `c2patool` 0.28.3 signs fixture-unsigned.png with a throw-away hierarchy (root ←
 * intermediate ← signer, P-256). It numbers an instance label itself (`cawg.identity__1` becomes
 * `cawg.identity__1__1`), so each probe is signed with a label of the same length that is no CAWG label
 * (`cawg.identitx`, `cawg.identitx-i1`: an `__n` of its own c2patool would number again), and the label is then renamed: the assertion's hash recomputed in the claim, the claim
 * signed again with the same key, the caBX chunk's CRC recomputed. The file before the rename is the control.
 *   cawg-instance-control      active manifest with `cawg.identitx-i1`
 *   cawg-instance              the same, renamed `cawg.identity__1`
 *   cawg-ingredient-control    parent of a manifest with `cawg.identitx`
 *   cawg-ingredient            parent of that manifest renamed `cawg.identity`
 *   cawg-ingredient-instance   parent of a manifest with `cawg.identitx-i2` renamed `cawg.identity__2`
 *
 * Usage: php bin/make-cawg-variants.php <scratch-dir> <c2patool-0.28.3>
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
 *
 * Writes:
 *   tests/Fixtures/cawg/<probe>.png, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/cawg/<probe>--0.28.3.json
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $tool] = [$argv[1] ?? null, $argv[2] ?? ''];
exec(escapeshellarg($tool).' --version 2>&1', $versionLines);
if (! is_string($scratch) || ! is_dir($scratch) || ($versionLines[0] ?? '') !== 'c2patool 0.28.3') {
    fwrite(STDERR, "usage: php bin/make-cawg-variants.php <scratch-dir outside the repository> <c2patool-0.28.3>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/cawg-keys-'.bin2hex(random_bytes(4));
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

function cwRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function cwSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function cwCert(string $keys, string $name, string $cn, string $section, ?string $issuer, string $serial): void
{
    cwRun(cwSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    $subject = "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
    if ($issuer === null) {
        cwRun(cwSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-set_serial', '0x'.$serial, '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));

        return;
    }
    cwRun(cwSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    cwRun(cwSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
}

/**
 * fixture-unsigned.png signed by the throw-away signer, with $assertions and $parent (an ingredient) when given.
 *
 * @param  list<array<string, mixed>>  $assertions
 */
function cwSign(string $keys, string $tool, string $root, array $assertions, ?string $parent, string $out): void
{
    file_put_contents("{$keys}/m.json", (string) json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/signer.pk8", 'sign_cert' => "{$keys}/signer-chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier cawg probes', 'version' => '1']],
        'assertions' => $assertions,
    ], JSON_UNESCAPED_SLASHES));
    $command = cwSh($tool, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-o', $out, '-f');
    cwRun($parent === null ? $command : $command.' '.cwSh('-p', $parent));
}

/**
 * An assertion shaped like a CAWG identity assertion, its credential a placeholder no one could validate.
 *
 * @return array<string, mixed>
 */
function cwIdentity(string $label): array
{
    return ['label' => $label, 'data' => ['signer_payload' => ['referenced_assertions' => []], 'signature' => 'not a credential', 'sig_type' => 'cawg.x509.cose']];
}

/** $old replaced by $new (the same length) in $bytes, exactly $count times. */
function cwSwap(string &$bytes, string $old, string $new, int $count): void
{
    if (strlen($old) !== strlen($new) || substr_count($bytes, $old) !== $count) {
        throw new RuntimeException(sprintf('expected %s %d time(s)', bin2hex(substr($old, 0, 8)), $count));
    }
    $bytes = str_replace($old, $new, $bytes);
}

/**
 * The store in a PNG's caBX chunk, and the offset of its first byte.
 *
 * @return array{0: string, 1: int}
 */
function cwStore(string $png): array
{
    $at = strpos($png, 'caBX');
    if ($at === false) {
        throw new RuntimeException('no caBX chunk');
    }
    /** @var array{1: int} $length */
    $length = unpack('N', substr($png, $at - 4, 4));

    return [substr($png, $at + 4, $length[1]), $at + 4];
}

/** The payload of $manifest's assertion box labelled $label, in $store. */
function cwPayload(string $store, Manifest $manifest, string $label): string
{
    foreach ($manifest->assertionStore->children as $child) {
        if ($child instanceof Superbox && $child->description->label === $label) {
            return substr($store, $child->offset + 8, $child->length - 8);
        }
    }
    throw new RuntimeException("no assertion {$label}");
}

/** The label of $manifest's first assertion whose label starts with $prefix, or null. */
function cwLabel(Manifest $manifest, string $prefix): ?string
{
    foreach ($manifest->assertionStore->children as $child) {
        if ($child instanceof Superbox && str_starts_with($child->description->label, $prefix)) {
            return $child->description->label;
        }
    }

    return null;
}

/** The superbox of $manifest whose label starts with $prefix. */
function cwChild(Manifest $manifest, string $prefix): Superbox
{
    foreach ($manifest->box->children as $child) {
        if ($child instanceof Superbox && str_starts_with($child->description->label, $prefix)) {
            return $child;
        }
    }
    throw new RuntimeException("no {$prefix} box in {$manifest->label}");
}

/** The manifest the active one names as its ingredient (the one before it in the store), or the active one. */
function cwTarget(string $store, bool $inIngredient): Manifest
{
    $graph = ManifestStore::fromTree((new JumbfParser)->parse($store));
    $manifests = array_values($graph->manifests);

    return $inIngredient ? $manifests[count($manifests) - 2] : $graph->active;
}

/**
 * $from with the assertion $old renamed $new: in the active manifest, or in the ingredient manifest it names.
 * The label is replaced wherever it occurs (the description box, the claim's hashed URI and, in a parent,
 * the statuses c2patool recorded when it read the ingredient), the assertion's hash recomputed in its claim
 * and that claim signed again with $key. For an ingredient, the parent's digests of the ingredient manifest,
 * its ingredient assertion's and its actions' hashes are recomputed and the active claim signed again too.
 * Last, the caBX chunk's CRC.
 */
function cwRename(string $from, string $to, string $old, string $new, string $key, bool $inIngredient): void
{
    $png = (string) file_get_contents($from);
    [$store, $start] = cwStore($png);
    $before = $store;
    $payload = cwPayload($store, cwTarget($store, $inIngredient), $old);
    $active = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $ingredientLabel = cwLabel($active, 'c2pa.ingredient');
    $actionsLabel = cwLabel($active, 'c2pa.actions');
    if ($inIngredient && ($ingredientLabel === null || $actionsLabel === null)) {
        throw new RuntimeException('the active manifest has no ingredient or actions assertion');
    }
    $ingredientBefore = $ingredientLabel === null ? '' : cwPayload($store, $active, $ingredientLabel);
    $actionsBefore = $actionsLabel === null ? '' : cwPayload($store, $active, $actionsLabel);

    $count = substr_count($store, $old);
    if ($count < 2) {
        throw new RuntimeException("{$old} occurs {$count} time(s)");
    }
    cwSwap($store, $old, $new, $count);
    $edited = cwTarget($store, $inIngredient);
    cwSwap($store, hash('sha256', $payload, true), hash('sha256', cwPayload($store, $edited, $new), true), 1);
    $store = cwResign($store, cwTarget($store, $inIngredient), $key);

    if ($inIngredient) {
        // the ingredient assertion names the ingredient manifest's box, claim and signature box by digest
        $original = cwTarget($before, true);
        $now = cwTarget($store, true);
        $replaced = 0;
        foreach ([[$original->box, $now->box], [cwChild($original, 'c2pa.signature'), cwChild($now, 'c2pa.signature')], [cwChild($original, 'c2pa.claim'), cwChild($now, 'c2pa.claim')]] as [$a, $b]) {
            $oldDigest = hash('sha256', substr($before, $a->offset + 8, $a->length - 8), true);
            $n = substr_count($store, $oldDigest);
            if ($n > 0) {
                cwSwap($store, $oldDigest, hash('sha256', substr($store, $b->offset + 8, $b->length - 8), true), $n);
                $replaced += $n;
            }
        }
        if ($replaced === 0) {
            throw new RuntimeException('the ingredient assertion names no digest of the ingredient manifest');
        }
        // the ingredient assertion's own hash (in the claim and in an action), then the actions' (in the claim)
        $active = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
        $oldHash = hash('sha256', $ingredientBefore, true);
        cwSwap($store, $oldHash, hash('sha256', cwPayload($store, $active, $ingredientLabel), true), substr_count($store, $oldHash));
        $active = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
        $actionsNow = cwPayload($store, $active, $actionsLabel);
        if ($actionsNow !== $actionsBefore) {
            cwSwap($store, hash('sha256', $actionsBefore, true), hash('sha256', $actionsNow, true), 1);
        }
        $store = cwResign($store, ManifestStore::fromTree((new JumbfParser)->parse($store))->active, $key);
    }

    $png = substr($png, 0, $start).$store.pack('N', crc32('caBX'.$store)).substr($png, $start + strlen($store) + 4);
    file_put_contents($to, $png);
}

/** $store with $manifest's claim signed again by $key: ES256 over the new Sig_structure, DER turned into R || S. */
function cwResign(string $store, Manifest $manifest, string $key): string
{
    $signature = $manifest->signatureBytes();
    if (substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }
    file_put_contents(dirname($key).'/tbs', CoseSign1::fromBytes($signature)->sigStructure($manifest->claimBytes()));
    cwRun(cwSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $q = 2; $i < 2; $i++) {
        $l = ord($der[$q + 1]);
        $rs .= str_pad(ltrim(substr($der, $q + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $q += 2 + $l;
    }
    cwSwap($store, substr($signature, -64), $rs, 1);

    return $store;
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/cawg';
$oracles = $root.'/tests/Fixtures/c2patool/cawg';
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
cwCert($keys, 'root', 'Throw-away Root (cawg)', 'root', null, '0C');
cwCert($keys, 'int', 'Throw-away Intermediate (cawg)', 'ca', 'root', '1C');
cwCert($keys, 'signer', 'Signer (cawg)', 'leaf', 'int', '2C');
cwRun(cwSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/signer.key", '-out', "{$keys}/signer.pk8"));
file_put_contents("{$keys}/signer-chain.pem", (string) file_get_contents("{$keys}/signer.pem").(string) file_get_contents("{$keys}/int.pem"));
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the probes ----
$key = "{$keys}/signer.key";
cwSign($keys, $tool, $root, [cwIdentity('cawg.identitx-i1')], null, "{$dir}/cawg-instance-control.png");
cwRename("{$dir}/cawg-instance-control.png", "{$dir}/cawg-instance.png", 'cawg.identitx-i1', 'cawg.identity__1', $key, false);

cwSign($keys, $tool, $root, [cwIdentity('cawg.identitx')], null, "{$keys}/child.png");
cwSign($keys, $tool, $root, [cwIdentity('cawg.identitx-i2')], null, "{$keys}/child-instance.png");
cwSign($keys, $tool, $root, [], "{$keys}/child.png", "{$dir}/cawg-ingredient-control.png");
cwRename("{$dir}/cawg-ingredient-control.png", "{$dir}/cawg-ingredient.png", 'cawg.identitx', 'cawg.identity', $key, true);
cwSign($keys, $tool, $root, [], "{$keys}/child-instance.png", "{$keys}/parent-instance.png");
cwRename("{$keys}/parent-instance.png", "{$dir}/cawg-ingredient-instance.png", 'cawg.identitx-i2', 'cawg.identity__2', $key, true);

// ---- the oracle ----
foreach (['cawg-instance-control', 'cawg-instance', 'cawg-ingredient-control', 'cawg-ingredient', 'cawg-ingredient-instance'] as $name) {
    $lines = [];
    exec(cwSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
    file_put_contents("{$oracles}/{$name}--0.28.3.json", implode("\n", $lines)."\n");
    $json = json_decode(implode("\n", $lines), true);
    printf("  %-26s %s\n", $name, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
}
