<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-034 (step 126a): icon references in the four
 * places C2PA 2.4 names, and what both c2patool versions say of them.
 *
 * The matching probes are fixture-unsigned.png signed by c2patool 0.28.0,
 * whose builder embeds the icon as a hashed URI into a c2pa.icon assertion.
 * The failing ones cannot come from the builder, so they are made from a
 * matching probe by a same-length patch inside its caBX chunk and a new
 * signature with the same throwaway leaf (SPEC-034 open question 3):
 *   - a claim_generator_info icon: one byte of its hash flipped in the
 *     claim, or its url turned from c2pa.icon into c2pa.icoX;
 *   - an actions-assertion icon: one byte of its hash flipped in the
 *     assertion, and the claim's hashed URI for that assertion recomputed.
 * No box changes length. The chunk CRC is recomputed, the new signature is
 * verified under its own leaf before anything is written, and both
 * c2patool versions then judge the result like any other file.
 *
 * The three keys live in a temporary directory for the length of this
 * script and are overwritten and deleted before it ends.
 *
 * Run:   php bin/make-spec034-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 *
 * Writes:
 *   tests/Fixtures/icons/<probe>.png, probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/icons/<probe>--<version>.json (or .error.txt)
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\Manifest;
use Provemark\C2paVerifier\Manifest\ManifestStore;

$root = dirname(__DIR__);
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec034-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/icons';
$oracles = $root.'/tests/Fixtures/c2patool/icons';
$tmp = sys_get_temp_dir().'/c2pa-spec034-'.bin2hex(random_bytes(6));
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
file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=SPEC-034 Probe Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
file_put_contents("{$tmp}/int.cnf", $dn('SPEC-034 Probe Intermediate')."[v3]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
file_put_contents("{$tmp}/leaf.cnf", $dn('SPEC-034 Probe Signer')."[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.4.1.62558.2.1,emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
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
copy($root.'/tests/Fixtures/fixture-unsigned.png', "{$tmp}/icon.png");
file_put_contents("{$tmp}/sign.settings.json", json_encode(['trust' => ['trust_config' => "1.3.6.1.4.1.62558.2.1\n1.3.6.1.5.5.7.3.4\n"]], JSON_THROW_ON_ERROR));

// ---- the matching probes, from the builder ----

$dst = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture';
$created = ['action' => 'c2pa.created', 'digitalSourceType' => $dst];
$icon = ['format' => 'image/png', 'identifier' => 'icon.png'];
$generator = ['name' => 'c2pa-verifier SPEC-034 probe', 'version' => '1'];
$built = [
    'generator-icon' => [[$generator + ['icon' => $icon]], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created]]]]],
    'agents-icon' => [[$generator], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created], 'softwareAgents' => [['name' => 'agent', 'icon' => $icon]]]]]],
    'action-agent-icon' => [[$generator], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created + ['softwareAgent' => ['name' => 'agent', 'icon' => $icon]]]]]]],
    'templates-icon' => [[$generator], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created], 'templates' => [['action' => 'c2pa.edited', 'icon' => $icon]]]]]],
    'generator-icon-external' => [[$generator + ['icon' => ['url' => 'https://example.com/icon.png', 'alg' => 'sha256', 'hash' => '47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=']]], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created]]]]],
];
foreach ($built as $name => [$info, $assertions]) {
    file_put_contents("{$tmp}/manifest.json", json_encode(['alg' => 'es256', 'private_key' => "{$tmp}/leaf.key", 'sign_cert' => "{$tmp}/chain.pem", 'claim_generator_info' => $info, 'assertions' => $assertions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    // c2patool prints its own validation after signing and still writes the file
    exec("{$q($new)} {$q($root.'/tests/Fixtures/fixture-unsigned.png')} -m {$q("{$tmp}/manifest.json")} --settings {$q("{$tmp}/sign.settings.json")} -o {$q("{$out}/{$name}.png")} -f 2>&1", $ignored);
    if (! is_file("{$out}/{$name}.png") || filesize("{$out}/{$name}.png") === 0) {
        $fail("c2patool did not write {$name}");
    }
}

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
$manifestOf = static fn (string $store): Manifest => ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
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

// claim_generator_info's icon: one hash byte, and the url
$generatorIconHash = static function (Manifest $m): string {
    $icon = $m->claim->claimGeneratorInfo[0]['icon'] ?? null;
    if (! is_array($icon) || ! $icon['hash'] instanceof CborBytes) {
        throw new RuntimeException('the probe has no hashed claim_generator_info icon');
    }

    return $icon['hash']->bytes;
};
$variant('generator-icon', 'generator-icon-hash-changed', static function (string $store, Manifest $m) use ($once, $flip, $generatorIconHash): string {
    $claim = $m->claimBytes();
    $from = $once($claim, 'claim_generator_info', 'the generator info key');
    $to = $once($claim, 'created_assertions', 'the created list key');
    $newClaim = $flip($claim, $once($claim, $generatorIconHash($m), 'the icon hash inside claim_generator_info', $from, $to) + 7);

    return substr_replace($store, $newClaim, $once($store, $claim, 'the claim'), strlen($claim));
});
$variant('generator-icon', 'generator-icon-unresolved', static function (string $store, Manifest $m) use ($once): string {
    $claim = $m->claimBytes();
    $from = $once($claim, 'claim_generator_info', 'the generator info key');
    $to = $once($claim, 'created_assertions', 'the created list key');
    $at = $once($claim, 'c2pa.assertions/c2pa.icon', 'the icon url inside claim_generator_info', $from, $to);
    $newClaim = substr_replace($claim, 'c2pa.assertions/c2pa.icoX', $at, strlen('c2pa.assertions/c2pa.icon'));

    return substr_replace($store, $newClaim, $once($store, $claim, 'the claim'), strlen($claim));
});
// an icon inside the actions assertion: one hash byte, and the claim's hashed URI for the assertion recomputed.
// Not for softwareAgents: c2patool 0.28.0's builder leaves that icon a resource reference ({format, identifier})
// and embeds no c2pa.icon for it, so there is no hashed URI to break (step 126a).
foreach (['action-agent-icon', 'templates-icon'] as $from) {
    $variant($from, "{$from}-hash-changed", static function (string $store, Manifest $m) use ($once, $flip): string {
        $box = $m->assertionStore->child('c2pa.actions.v2');
        $entry = null;
        foreach ([...$m->claim->createdAssertions, ...$m->claim->gatheredAssertions] as $candidate) {
            if (str_ends_with($candidate->url, '/c2pa.icon')) {
                $iconHash = $candidate->hash->bytes;
            }
            if (str_ends_with($candidate->url, '/c2pa.actions.v2')) {
                $entry = $candidate->hash->bytes;
            }
        }
        if ($box === null || ! isset($iconHash) || $entry === null) {
            throw new RuntimeException('the probe lacks its actions assertion or icon');
        }
        $payload = $box->payload();
        $newPayload = $flip($payload, $once($payload, $iconHash, 'the icon hash inside the actions assertion') + 7);
        $store = substr_replace($store, $newPayload, $box->offset + 8, strlen($payload));
        $claim = $m->claimBytes();
        $newClaim = substr_replace($claim, hash('sha256', $newPayload, true), $once($claim, $entry, "the claim's hash of the actions assertion"), 32);

        return substr_replace($store, $newClaim, $once($store, $claim, 'the claim'), strlen($claim));
    });
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
