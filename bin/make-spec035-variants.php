<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-035 (step 129a): a child that redacts an
 * assertion of its parent, made by c2patool 0.28.0's builder (-p and
 * "redactions"), and a variant whose ingredient claimSignature hash is
 * changed by a same-length patch and re-signed (as SPEC-034's script does).
 * Both c2patool versions judge every file.
 *
 * The three keys live in a temporary directory for the length of this
 * script and are overwritten and deleted before it ends.
 *
 * Run:   php bin/make-spec035-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 *
 * Writes:
 *   tests/Fixtures/redactions/<probe>.png, probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/redactions/<probe>--<version>.json (or .error.txt)
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;

$root = dirname(__DIR__);
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec035-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/redactions';
$oracles = $root.'/tests/Fixtures/c2patool/redactions';
$tmp = sys_get_temp_dir().'/c2pa-spec035-'.bin2hex(random_bytes(6));
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
        $fail("c2patool did not write {$to}");
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
$sign("{$tmp}/parent.png", ['redactions' => [$secret], 'assertions' => [
    ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.redacted', 'parameters' => ['redacted' => $secret]]]]],
]], "{$out}/redacted-with-action.png", "{$tmp}/parent.png");
$sign("{$tmp}/parent.png", ['redactions' => [$secret], 'assertions' => []], "{$out}/redacted-without-action.png", "{$tmp}/parent.png");

// ---- the failing probes, by a same-length patch and a new signature ----

/** @return array{0: string, 1: int, 2: int}  the PNG, the caBX data offset, its length */
$cabx = static function (string $png) use ($fail): array {
    $p = 8;
    while ($p + 8 <= strlen($png)) {
        $unpacked = unpack('N', substr($png, $p, 4));
        $length = is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : $fail('a PNG chunk length could not be read');
        if (substr($png, $p + 4, 4) === 'caBX') {
            return [$png, $p + 8, $length];
        }
        $p += 12 + $length;
    }
    $fail('no caBX chunk');
};
// only the active manifest, the last one: the store's own parse refuses the redacted parent until SPEC-035 is built
$manifestOf = static function (string $store) use ($fail): Manifest {
    $manifests = (new JumbfParser)->parse($store)->superboxes();
    $last = end($manifests);

    return $last === false ? $fail('the store has no manifest') : Manifest::fromBox($last);
};
$derToRs = static function (string $der): string {
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
};
$once = static function (string $haystack, string $needle, string $what, int $from = 0, ?int $to = null) use ($fail): int {
    $at = strpos($haystack, $needle, $from);
    if ($at === false || ($to !== null && $at >= $to)) {
        $fail("{$what}: not found");
    }

    return $at;
};
/**
 * Apply $edit to the store (it returns the new store and the new claim bytes), re-sign the claim, write the PNG.
 *
 * @param  callable(string, Manifest): string  $edit  store => store, same length
 */
$variant = static function (string $from, string $name, callable $edit) use ($out, $tmp, $cabx, $manifestOf, $derToRs, $once, $run, $q, $fail): void {
    [$png, $at, $length] = $cabx((string) file_get_contents("{$out}/{$from}.png"));
    $store = substr($png, $at, $length);
    $edited = $edit($store, $manifestOf($store));
    if (! is_string($edited)) {
        $fail("{$name}: the edit returned no store");
    }
    if (strlen($edited) !== strlen($store)) {
        $fail("{$name}: the store changed length");
    }
    $manifest = $manifestOf($edited);
    $claim = $manifest->claimBytes();
    $cose = $manifest->signatureBytes();
    $parsed = CoseSign1::fromBytes($cose);
    file_put_contents("{$tmp}/tbs", $parsed->sigStructure($claim));
    $run("openssl dgst -sha256 -sign {$q("{$tmp}/leaf.key")} -out {$q("{$tmp}/sig")} {$q("{$tmp}/tbs")}");
    $signature = $derToRs((string) file_get_contents("{$tmp}/sig"));
    if (substr($cose, -66, 2) !== "\x58\x40" || substr($cose, -64) !== $parsed->signature) {
        $fail("{$name}: the COSE_Sign1 does not end in its 64-byte signature");
    }
    $newCose = substr($cose, 0, -64).$signature;
    $edited = substr_replace($edited, $newCose, $once($edited, $cose, "{$name}: the COSE_Sign1"), strlen($cose));
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($newCose), $claim)) {
        $fail("{$name}: the new signature does not verify under its own leaf");
    }
    $chunk = 'caBX'.$edited;
    $png = substr_replace($png, $edited, $at, $length);
    $png = substr_replace($png, pack('N', crc32($chunk)), $at + $length, 4);
    file_put_contents("{$out}/{$name}.png", $png);
};
$flip = static fn (string $bytes, int $at): string => substr_replace($bytes, chr(ord($bytes[$at]) ^ 0x01), $at, 1);

// the child's ingredient claimSignature: one hash byte flipped, the claim's hashed URI for the ingredient recomputed
$variant('redacted-with-action', 'claim-signature-changed', static function (string $store, Manifest $m) use ($once, $flip): string {
    $label = null;
    foreach (array_keys($m->assertions) as $candidate) {
        if (str_starts_with($candidate, 'c2pa.ingredient')) {
            $label = $candidate;
        }
    }
    $data = $label === null ? null : $m->assertions[$label]->data;
    $signature = is_array($data) && is_array($data['claimSignature'] ?? null) ? $data['claimSignature']['hash'] ?? null : null;
    $box = $label === null ? null : $m->assertionStore->child($label);
    if ($box === null || ! $signature instanceof CborBytes) {
        throw new RuntimeException('the child has no ingredient with a claimSignature');
    }
    $entry = null;
    foreach ([...$m->claim->createdAssertions, ...$m->claim->gatheredAssertions] as $candidate) {
        if (str_ends_with($candidate->url, '/'.$label)) {
            $entry = $candidate->hash->bytes;
        }
    }
    if ($entry === null) {
        throw new RuntimeException('the claim does not list the ingredient');
    }
    $payload = $box->payload();
    $newPayload = $flip($payload, $once($payload, $signature->bytes, 'the claimSignature hash inside the ingredient') + 7);
    $store = substr_replace($store, $newPayload, $box->offset + 8, strlen($payload));
    $claim = $m->claimBytes();
    $newClaim = substr_replace($claim, hash('sha256', $newPayload, true), $once($claim, $entry, "the claim's hash of the ingredient"), 32);

    return substr_replace($store, $newClaim, $once($store, $claim, 'the claim'), strlen($claim));
});

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
