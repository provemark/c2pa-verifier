<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-034 amendment 3 (step 522): icons that are not a
 * hashed URI, and what c2patool 0.28.6 and 0.28.3 say of them.
 *
 * Three controls are fixture-unsigned.png signed by c2patool 0.28.6, whose
 * builder embeds each icon as a hashed URI into a c2pa.icon assertion: one in
 * claim_generator_info, one in templates, one in an action's softwareAgent.
 * Every probe is made from a control by replacing the icon's CBOR with other
 * CBOR of exactly the same length, then signing the claim again with the same
 * throwaway leaf (SPEC-034 open question 3):
 *   - a resource reference {format, identifier}, the identifier padded to fit;
 *   - for claim_generator_info also a text in place of the map, and a map that
 *     holds both forms (url, hash, format, identifier), the generator's name
 *     shortened by as many bytes as the map grew.
 * In the actions assertion the claim's hashed URI for it is recomputed. No box
 * changes length. The chunk CRC is recomputed, the new signature is verified
 * under its own leaf before anything is written, and both c2patool versions
 * then judge the result like any other file.
 *
 * The three keys live in a temporary directory for the length of this script
 * and are overwritten and deleted before it ends.
 *
 * Run:   php bin/make-icon-form-variants.php <c2patool-0.28.6> <c2patool-0.28.3>
 *
 * Writes:
 *   tests/Fixtures/icon-forms/<probe>.png, probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/icon-forms/<probe>--<version>.json (or .error.txt)
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

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
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.6' || $version($old) !== 'c2patool 0.28.3') {
    fwrite(STDERR, "usage: php bin/make-icon-form-variants.php <c2patool-0.28.6> <c2patool-0.28.3>\n");
    exit(1);
}

$out = $root.'/tests/Fixtures/icon-forms';
$oracles = $root.'/tests/Fixtures/c2patool/icon-forms';
$tmp = sys_get_temp_dir().'/c2pa-icon-forms-'.bin2hex(random_bytes(6));
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
file_put_contents("{$tmp}/root.cnf", "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=Icon Form Probe Root\nO=c2pa-verifier test\nC=NL\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
file_put_contents("{$tmp}/int.cnf", $dn('Icon Form Probe Intermediate')."[v3]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
file_put_contents("{$tmp}/leaf.cnf", $dn('Icon Form Probe Signer')."[v3]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.4.1.62558.2.1,emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
foreach (['root', 'int', 'leaf'] as $name) {
    $run("openssl ecparam -name prime256v1 -genkey -noout -out {$q("{$tmp}/{$name}.raw.key")}");
    $run("openssl pkcs8 -topk8 -nocrypt -in {$q("{$tmp}/{$name}.raw.key")} -out {$q("{$tmp}/{$name}.key")}");
}
$run("openssl req -new -x509 -key {$q("{$tmp}/root.key")} -config {$q("{$tmp}/root.cnf")} -days 3650 -out {$q("{$tmp}/root.pem")}");
foreach (['int' => 'root', 'leaf' => 'int'] as $name => $issuer) {
    $run("openssl req -new -key {$q("{$tmp}/{$name}.key")} -config {$q("{$tmp}/{$name}.cnf")} -out {$q("{$tmp}/{$name}.csr")}");
    $run("openssl x509 -req -in {$q("{$tmp}/{$name}.csr")} -CA {$q("{$tmp}/{$issuer}.pem")} -CAkey {$q("{$tmp}/{$issuer}.key")} -CAcreateserial -days 3650 -extfile {$q("{$tmp}/{$name}.cnf")} -extensions v3 -out {$q("{$tmp}/{$name}.pem")}");
}
$rootPem = (string) file_get_contents("{$tmp}/root.pem");
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
file_put_contents("{$tmp}/chain.pem", file_get_contents("{$tmp}/leaf.pem").file_get_contents("{$tmp}/int.pem"));
copy("{$tmp}/root.pem", "{$out}/probe-root.pem");
$settings = "{$out}/probe-root.settings.json";
file_put_contents($settings, json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => rtrim($storeCfg)."\n"]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
copy($root.'/tests/Fixtures/fixture-unsigned.png', "{$tmp}/icon.png");
file_put_contents("{$tmp}/sign.settings.json", json_encode(['trust' => ['trust_config' => "1.3.6.1.4.1.62558.2.1\n1.3.6.1.5.5.7.3.4\n"]], JSON_THROW_ON_ERROR));

// ---- the controls, from the builder ----

$dst = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture';
$created = ['action' => 'c2pa.created', 'digitalSourceType' => $dst];
$icon = ['format' => 'image/png', 'identifier' => 'icon.png'];
// a long name: the probe with both icon forms shortens it by as many bytes as its icon map grows
$generator = ['name' => 'c2pa-verifier icon-form probe, with a name long enough to give way', 'version' => '1'];
$built = [
    'generator-icon' => [[$generator + ['icon' => $icon]], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created]]]]],
    'templates-icon' => [[$generator], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created], 'templates' => [['action' => 'c2pa.edited', 'icon' => $icon]]]]]],
    'action-agent-icon' => [[$generator], [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [$created + ['softwareAgent' => ['name' => 'agent', 'icon' => $icon]]]]]]],
];
foreach (glob("{$out}/*.png") ?: [] as $stale) {
    unlink($stale);
}
foreach ($built as $name => [$info, $assertions]) {
    file_put_contents("{$tmp}/manifest.json", json_encode(['alg' => 'es256', 'private_key' => "{$tmp}/leaf.key", 'sign_cert' => "{$tmp}/chain.pem", 'claim_generator_info' => $info, 'assertions' => $assertions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    // c2patool prints its own validation after signing and still writes the file
    exec("{$q($new)} {$q($root.'/tests/Fixtures/fixture-unsigned.png')} -m {$q("{$tmp}/manifest.json")} --settings {$q("{$tmp}/sign.settings.json")} -o {$q("{$out}/{$name}.png")} -f 2>&1", $ignored);
    if (! is_file("{$out}/{$name}.png") || filesize("{$out}/{$name}.png") === 0) {
        $fail("c2patool did not write {$name}");
    }
}

// ---- CBOR of a given length ----

$text = static function (string $s) use ($fail): string {
    $n = strlen($s);
    if ($n < 24) {
        return chr(0x60 + $n).$s;
    }
    if ($n < 256) {
        return "\x78".chr($n).$s;
    }
    $fail('text too long');
};
/** A text of exactly $length encoded bytes, starting with $prefix and padded with x. */
$textOf = static function (string $prefix, int $length) use ($text, $fail): string {
    for ($n = 0; $n <= $length; $n++) {
        $candidate = $text(str_pad(substr($prefix, 0, $n), $n, 'x'));
        if (strlen($candidate) === $length) {
            return $candidate;
        }
    }
    $fail("no text of {$length} bytes");
};
/** {format: image/png, identifier: …} of exactly $length bytes. */
$resourceOf = static function (int $length) use ($text, $textOf): string {
    $head = "\xA2".$text('format').$text('image/png').$text('identifier');

    return $head.$textOf('icon-form-probe-resource-reference.png', $length - strlen($head));
};
/**
 * The map at $p rebuilt with some values replaced, in the same key order.
 *
 * @param  array<string, string>  $values  key => new value bytes
 * @param  array<string, string>  $extra  key => value bytes appended
 */
$rebuild = static function (string $d, int $p, array $values, array $extra = []) use ($text): string {
    $pairs = bMapPairs($d, $p);
    $count = count($pairs) + count($extra);
    if ($count > 23) {
        throw new RuntimeException('a map of more than 23 pairs');
    }
    $bytes = chr(0xA0 + $count);
    foreach ($pairs as $key => [$keyStart, $valueStart, $valueEnd]) {
        $value = $values[$key] ?? null;
        $bytes .= substr($d, $keyStart, $valueStart - $keyStart).(is_string($value) ? $value : substr($d, $valueStart, $valueEnd - $valueStart));
    }
    foreach ($extra as $key => $value) {
        if (! is_string($key) || ! is_string($value)) {
            throw new RuntimeException('an extra pair is not text => bytes');
        }
        $bytes .= $text($key).$value;
    }

    return $bytes;
};

// ---- re-signing a same-length edit ----

/** @return array{0: string, 1: int, 2: int}  the PNG, the caBX data offset, its length */
$cabx = static function (string $png) use ($fail): array {
    $p = 8;
    while ($p + 8 <= strlen($png)) {
        $length = bU32($png, $p);
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
$once = static function (string $haystack, string $needle, string $what) use ($fail): int {
    $at = strpos($haystack, $needle);
    if ($at === false || strpos($haystack, $needle, $at + 1) !== false) {
        $fail("{$what}: not found exactly once");
    }

    return $at;
};
/** @param  callable(string, Manifest): string  $edit  store => store, same length */
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
    $png = substr_replace($png, $edited, $at, $length);
    $png = substr_replace($png, pack('N', crc32('caBX'.$edited)), $at + $length, 4);
    file_put_contents("{$out}/{$name}.png", $png);
};

// ---- claim_generator_info: three forms ----

/**
 * The claim with its generator-info map rewritten by $map (the claim, the map's offset => the new map bytes).
 *
 * @param  callable(string, int): string  $map
 */
$generatorEdit = static function (callable $map) use ($once, $fail): callable {
    return static function (string $store, Manifest $m) use ($map, $once, $fail): string {
        $claim = $m->claimBytes();
        $info = bMapPairs($claim, 0)['claim_generator_info'] ?? $fail('the claim has no claim_generator_info');
        $first = $info[1];   // in a v2 claim, one map (a v1 claim has a list)
        if ((ord($claim[$first]) >> 5) !== 5) {
            $fail('claim_generator_info is not a map');
        }
        $newMap = $map($claim, $first);
        if (! is_string($newMap)) {
            $fail('the map edit returned no bytes');
        }
        $newClaim = substr_replace($claim, $newMap, $first, bCborEnd($claim, $first) - $first);
        if (strlen($newClaim) !== strlen($claim)) {
            $fail('the claim changed length: '.(strlen($newClaim) - strlen($claim)));
        }

        return substr_replace($store, $newClaim, $once($store, $claim, 'the claim'), strlen($claim));
    };
};
$iconSpan = static function (string $d, int $mapAt) use ($fail): array {
    $pairs = bMapPairs($d, $mapAt);
    [, $start, $end] = $pairs['icon'] ?? $fail('the map has no icon');

    return [$start, $end];
};
$variant('generator-icon', 'generator-icon-resource', $generatorEdit(static function (string $claim, int $at) use ($rebuild, $iconSpan, $resourceOf): string {
    [$start, $end] = $iconSpan($claim, $at);

    return $rebuild($claim, $at, ['icon' => $resourceOf($end - $start)]);
}));
$variant('generator-icon', 'generator-icon-text', $generatorEdit(static function (string $claim, int $at) use ($rebuild, $iconSpan, $textOf): string {
    [$start, $end] = $iconSpan($claim, $at);

    return $rebuild($claim, $at, ['icon' => $textOf('icon.png', $end - $start)]);
}));
$variant('generator-icon', 'generator-icon-both', $generatorEdit(static function (string $claim, int $at) use ($rebuild, $iconSpan, $text, $textOf, $fail): string {
    [$start, $end] = $iconSpan($claim, $at);
    $iconAt = $start;
    $hashed = substr($claim, $iconAt, $end - $iconAt);
    // the hashed URI map with format and identifier added
    $both = $rebuild($claim, $iconAt, [], ['format' => $text('image/png'), 'identifier' => $text('icon.png')]);
    $grown = strlen($both) - strlen($hashed);
    [, $nameStart, $nameEnd] = bMapPairs($claim, $at)['name'] ?? $fail('the generator info has no name');
    $name = $textOf('c2pa-verifier icon-form probe', $nameEnd - $nameStart - $grown);

    return $rebuild($claim, $at, ['icon' => $both, 'name' => $name]);
}));

// ---- the actions assertion: a resource reference in templates and in an action's softwareAgent ----

/**
 * @param  callable(string): array{0: int, 1: int}  $locate  the assertion's CBOR => the icon's span in it
 */
$actionsEdit = static function (callable $locate) use ($resourceOf, $once, $fail): callable {
    return static function (string $store, Manifest $m) use ($locate, $resourceOf, $once, $fail): string {
        $box = $m->assertionStore->child('c2pa.actions.v2') ?? $fail('no actions assertion');
        $entry = null;
        foreach ([...$m->claim->createdAssertions, ...$m->claim->gatheredAssertions] as $candidate) {
            if (str_ends_with($candidate->url, '/c2pa.actions.v2')) {
                $entry = $candidate->hash->bytes;
            }
        }
        if ($entry === null) {
            $fail('the claim lists no actions assertion');
        }
        $payload = $box->payload();
        // the superbox payload: its description box, then the cbor content box
        $cbor = bU32($payload, 0) + 8;
        if (substr($payload, $cbor - 4, 4) !== 'cbor') {
            $fail('the actions assertion holds no cbor box after its description');
        }
        $span = $locate(substr($payload, $cbor));
        if (! is_array($span) || ! is_int($span[0] ?? null) || ! is_int($span[1] ?? null)) {
            $fail('the icon was not located');
        }
        [$start, $end] = $span;
        [$start, $end] = [$start + $cbor, $end + $cbor];
        $newPayload = substr_replace($payload, $resourceOf($end - $start), $start, $end - $start);
        $store = substr_replace($store, $newPayload, $box->offset + 8, strlen($payload));
        $claim = $m->claimBytes();
        $newClaim = substr_replace($claim, hash('sha256', $newPayload, true), $once($claim, $entry, "the claim's hash of the actions assertion"), 32);

        return substr_replace($store, $newClaim, $once($store, $claim, 'the claim'), strlen($claim));
    };
};
$variant('templates-icon', 'templates-icon-resource', $actionsEdit(static function (string $payload) use ($iconSpan, $fail): array {
    [, $listAt] = bMapPairs($payload, 0)['templates'] ?? $fail('no templates');

    return $iconSpan($payload, $listAt + 1);
}));
$variant('action-agent-icon', 'action-agent-icon-resource', $actionsEdit(static function (string $payload) use ($iconSpan, $fail): array {
    [, $listAt] = bMapPairs($payload, 0)['actions'] ?? $fail('no actions');
    [, $agentAt] = bMapPairs($payload, $listAt + 1)['softwareAgent'] ?? $fail('no softwareAgent');

    return $iconSpan($payload, $agentAt);
}));

// ---- what each c2patool says, with the root as anchor ----

foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (glob("{$out}/*.png") ?: [] as $file) {
    $name = basename($file, '.png');
    foreach (['0.28.6' => $new, '0.28.3' => $old] as $label => $tool) {
        $lines = [];
        exec("{$q($tool)} {$q($file)} --settings {$q($settings)} 2>&1", $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$label}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        printf("%-30s %-8s %s\n", $name, $label, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
    }
}

$shred();
if (is_dir($tmp)) {
    $fail("a key survived in {$tmp}");
}
