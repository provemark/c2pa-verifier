<?php

declare(strict_types=1);

/*
 * SPEC-066 amendment 2 (step 430): an ingredient manifest whose own signer stapled a `revoked` response, in a
 * store without certificate-status assertions (docs/reading-c2pa-2.4.md, group A, question 4). The hierarchy and
 * the responses are made as in bin/make-revocation-variants.php: a throw-away root ← intermediate ← signers P and
 * U (P-256), and OCSP responses from `openssl ocsp`, signed by the intermediate, without certificates.
 *
 * The parent is fixture-unsigned.png signed by P with `c2patool` 0.28.3; its COSE `pad` is then replaced by an
 * `rVals` holding one response about P, of the same total length (the unprotected header is not signed). The
 * child is fixture-unsigned.png signed by U with that parent as its parentOf ingredient, so the parent's manifest,
 * staple included, is in the child's store as an ingredient manifest.
 *   parent                  P, no response
 *   parent-staple-revoked   P, its own response: revoked (keyCompromise)
 *   parent-staple-good      P, its own response: good
 *   child-control           U, with `parent` as ingredient
 *   child-staple-revoked    U, with `parent-staple-revoked` as ingredient
 *   child-staple-good       U, with `parent-staple-good` as ingredient
 *
 * Usage: php bin/make-ingredient-staple-variants.php <scratch-dir> <c2patool-0.28.3> <c2patool-0.28.1>
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
 *
 * Writes:
 *   tests/Fixtures/ingredient-staple/<variant>.png, throw-away-root.pem, throw-away-root.settings.json
 *   tests/Fixtures/c2patool/ingredient-staple/<variant>--<version>.json
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.3' || $version($old) !== 'c2patool 0.28.1') {
    fwrite(STDERR, "usage: php bin/make-ingredient-staple-variants.php <scratch-dir outside the repository> <c2patool-0.28.3> <c2patool-0.28.1>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/ingredient-staple-keys-'.bin2hex(random_bytes(4));
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

function isRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function isSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A CBOR head of major type $major for the length $n. */
function isHead(int $major, int $n): string
{
    return match (true) {
        $n < 24 => pack('C', ($major << 5) | $n),
        $n < 256 => pack('CC', ($major << 5) | 24, $n),
        default => pack('Cn', ($major << 5) | 25, $n),
    };
}

/** @return array{0: string, 1: int} the caBX store and the chunk's offset */
function isStore(string $png): array
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $png);
    rewind($stream);
    $store = (new PngManifestStoreExtractor)->extract($stream);
    if ($store === null) {
        throw new RuntimeException('no store');
    }

    return [$store->bytes, $store->ranges[0]['start']];
}

/** The caBX chunk at $chunk replaced by one holding $s, its length and CRC set. */
function isChunk(string $png, int $chunk, string $s): string
{
    return substr($png, 0, $chunk).pack('N', strlen($s)).'caBX'.$s.pack('N', crc32('caBX'.$s)).substr($png, $chunk + 12 + bU32($png, $chunk));
}

/** The content of the cbor box of the last superbox labelled $label: the active manifest's. */
function isCbor(string $s, string $label): string
{
    $at = strrpos($s, $label."\0");
    $cbor = $at === false ? false : strpos($s, 'cbor', $at);
    if ($cbor === false) {
        throw new RuntimeException("no cbor box after {$label}");
    }

    return substr($s, $cbor + 4, bU32($s, $cbor - 4) - 8);
}

/**
 * The active manifest's COSE unprotected header `{"pad": bstr(1000)}` replaced by `{"rVals": {"ocspVals": [...]},
 * "pad": bstr(k)}` of the same length; nothing else in the store changes.
 *
 * @param  list<string>  $responses
 */
function isStaple(string $s, array $responses): string
{
    $cose = isCbor($s, 'c2pa.signature');
    $at = strpos($cose, "\xa1\x63pad\x59");
    if ($at === false) {
        throw new RuntimeException('the unprotected header is not {"pad": bstr}');
    }
    $padLength = bU32("\0\0".substr($cose, $at + 6, 2), 0);
    $total = 1 + 4 + 3 + $padLength;
    $rVals = "\x65rVals\xa1\x68ocspVals".isHead(4, count($responses)).implode('', array_map(static fn (string $r): string => isHead(2, strlen($r)).$r, $responses));
    $room = $total - 1 - strlen($rVals) - 4;
    $k = $room - 3;
    while ($k >= 0 && strlen(isHead(2, $k)) + $k !== $room) {
        $k++;
        if ($k > $room) {
            throw new RuntimeException('no pad length fits');
        }
    }
    if ($k < 0) {
        throw new RuntimeException('the responses do not fit in the pad');
    }
    $header = "\xa2".$rVals."\x63pad".isHead(2, $k).str_repeat("\0", $k);
    if (strlen($header) !== $total) {
        throw new RuntimeException('the new header is not the old length');
    }
    $newCose = substr_replace($cose, $header, $at, $total);
    $coseAt = (int) strrpos($s, $cose);

    return substr_replace($s, $newCose, $coseAt, strlen($cose));
}

/**
 * A throw-away P-256 certificate: self-signed when $issuer is null.
 */
function isCert(string $keys, string $name, string $cn, string $section, ?string $issuer, string $serial): void
{
    isRun(isSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    $subject = "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
    if ($issuer === null) {
        isRun(isSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-set_serial', '0x'.$serial, '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));

        return;
    }
    isRun(isSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    isRun(isSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
}

/** An OCSP response about $cert, signed by $issuer itself, the certificate's status given by $status. */
function isResponse(string $keys, string $cert, string $issuer, string $serial, string $status): string
{
    $expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
    $revokedAt = gmdate('ymdHis\Z', time() - 30 * 86400);
    $subject = '/O=C2PA Verifier throw-away hierarchy';
    file_put_contents("{$keys}/index.txt", match ($status) {
        'good' => "V\t{$expires}\t\t{$serial}\tunknown\t{$subject}\n",
        'revoked' => "R\t{$expires}\t{$revokedAt},keyCompromise\t{$serial}\tunknown\t{$subject}\n",
        'removed' => "R\t{$expires}\t{$revokedAt},removeFromCRL\t{$serial}\tunknown\t{$subject}\n",
        default => throw new RuntimeException("unknown status {$status}"),
    });
    isRun(isSh('openssl', 'ocsp', '-issuer', "{$keys}/{$issuer}.pem", '-cert', "{$keys}/{$cert}.pem", '-reqout', "{$keys}/req.der", '-no_nonce'));
    isRun(isSh('openssl', 'ocsp', '-index', "{$keys}/index.txt", '-CA', "{$keys}/{$issuer}.pem", '-rsigner', "{$keys}/{$issuer}.pem", '-rkey', "{$keys}/{$issuer}.key", '-reqin', "{$keys}/req.der", '-respout', "{$keys}/resp.der", '-ndays', '3650', '-resp_no_certs'));

    return (string) file_get_contents("{$keys}/resp.der");
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/ingredient-staple';
$oracles = $root.'/tests/Fixtures/c2patool/ingredient-staple';
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
isCert($keys, 'root', 'Throw-away Root (ingredient staple)', 'root', null, '0A');
isCert($keys, 'int', 'Throw-away Intermediate (ingredient staple)', 'ca', 'root', '1A');
isCert($keys, 'p', 'Signer P (ingredient staple)', 'leaf', 'int', '2A');
isCert($keys, 'u', 'Signer U (ingredient staple)', 'leaf', 'int', '2B');
isCert($keys, 'other', 'Another signer (ingredient staple)', 'leaf', 'int', '2C');
foreach (['p', 'u'] as $leaf) {
    isRun(isSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/{$leaf}.key", '-out', "{$keys}/{$leaf}.pk8"));
    file_put_contents("{$keys}/{$leaf}-chain.pem", (string) file_get_contents("{$keys}/{$leaf}.pem").(string) file_get_contents("{$keys}/int.pem"));
}
$rootPem = (string) file_get_contents("{$keys}/root.pem");
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/**
 * fixture-unsigned.png signed by $leaf (its chain with the intermediate), with $extra assertions and $parent.
 *
 * @param  list<array<string, mixed>>  $extra
 */
function isSign(string $keys, string $tool, string $root, string $leaf, array $extra, ?string $parent, string $out): string
{
    file_put_contents("{$keys}/m.json", (string) json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/{$leaf}.pk8", 'sign_cert' => "{$keys}/{$leaf}-chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier ingredient staple probes', 'version' => '1']],
        'assertions' => [
            ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]],
            ...$extra,
        ],
    ], JSON_UNESCAPED_SLASHES));
    isRun(isSh(...array_merge([$tool, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json"], $parent === null ? [] : ['-p', $parent], ['-o', $out, '-f'])));

    return (string) file_get_contents($out);
}

// ---- the probes ----
$parent = isSign($keys, $new, $root, 'p', [], null, "{$dir}/parent.png");
[$ps, $pchunk] = isStore($parent);
foreach (['revoked', 'good'] as $status) {
    file_put_contents("{$dir}/parent-staple-{$status}.png", isChunk($parent, $pchunk, isStaple($ps, [isResponse($keys, 'p', 'int', '2A', $status)])));
}
isSign($keys, $new, $root, 'u', [], "{$dir}/parent.png", "{$dir}/child-control.png");
foreach (['revoked', 'good'] as $status) {
    isSign($keys, $new, $root, 'u', [], "{$dir}/parent-staple-{$status}.png", "{$dir}/child-staple-{$status}.png");
}

// ---- the oracles ----
foreach (['parent', 'parent-staple-revoked', 'parent-staple-good', 'child-control', 'child-staple-revoked', 'child-staple-good'] as $name) {
    foreach (['0.28.3' => $new, '0.28.1' => $old] as $v => $tool) {
        $lines = [];
        exec(isSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-22s %-7s %s\n", $name, $v, is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : 'error: '.substr(implode(' ', $lines), 0, 90));
    }
}
