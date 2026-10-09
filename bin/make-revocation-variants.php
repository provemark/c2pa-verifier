<?php

declare(strict_types=1);

/*
 * SPEC-066 (step 338): probes for revocation beyond the signer's own staple (C2PA 2.4 §15.9). A throw-away
 * hierarchy root ← intermediate ← signers (P-256), and OCSP responses from `openssl ocsp`, each signed by the
 * issuer of the certificate it is about, without certificates (`-resp_no_certs`), so that they are small.
 *
 * A — a CA of the path: `c2patool` 0.28.1 signs fixture-unsigned.png with the signer and the intermediate in
 * its x5chain; the 1000-byte `pad` of the COSE unprotected header is then replaced by an `rVals` holding one
 * response and a shorter pad of the same total length. The unprotected header is not signed and the store
 * keeps its length, so nothing else changes.
 *   ca-control   no response
 *   ca-revoked   the intermediate revoked (keyCompromise), from the root
 *   ca-good      the intermediate good
 *   ca-removed   the intermediate revoked with removeFromCRL
 *   ca-broken    ca-revoked with one byte of its signature changed
 *   ca-other     a good response about another certificate
 *
 * B — certificate-status assertions: cs-parent.png is signed by signer P; each child is signed by signer U with
 * cs-parent.png as its parent and a `c2pa.certificate-status` whose `ocspVals` hold placeholders, then made
 * byte strings with the responses, U's claim re-hashed and signed again.
 *   cs-good      P good
 *   cs-revoked   P revoked
 *   cs-two       a good response about another certificate, then P revoked
 *   cs-own       U revoked: the assertion about its own manifest's signer
 *
 * Usage: php bin/make-revocation-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>
 * Keys live in a scratch directory for the run and are deleted before it ends. Tooling, not the
 * verification path.
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
if (! is_string($scratch) || ! is_dir($scratch) || $version($new) !== 'c2patool 0.28.1' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-revocation-variants.php <scratch-dir outside the repository> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/revocation-keys-'.bin2hex(random_bytes(4));
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

function rvRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function rvSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A CBOR head of major type $major for the length $n. */
function rvHead(int $major, int $n): string
{
    return match (true) {
        $n < 24 => pack('C', ($major << 5) | $n),
        $n < 256 => pack('CC', ($major << 5) | 24, $n),
        default => pack('Cn', ($major << 5) | 25, $n),
    };
}

/** @return array{0: string, 1: int} the caBX store and the chunk's offset */
function rvStore(string $png): array
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
function rvChunk(string $png, int $chunk, string $s): string
{
    return substr($png, 0, $chunk).pack('N', strlen($s)).'caBX'.$s.pack('N', crc32('caBX'.$s)).substr($png, $chunk + 12 + bU32($png, $chunk));
}

/** The content of the cbor box of the last superbox labelled $label: the active manifest's. */
function rvCbor(string $s, string $label): string
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
function rvStaple(string $s, array $responses): string
{
    $cose = rvCbor($s, 'c2pa.signature');
    $at = strpos($cose, "\xa1\x63pad\x59");
    if ($at === false) {
        throw new RuntimeException('the unprotected header is not {"pad": bstr}');
    }
    $padLength = bU32("\0\0".substr($cose, $at + 6, 2), 0);
    $total = 1 + 4 + 3 + $padLength;
    $rVals = "\x65rVals\xa1\x68ocspVals".rvHead(4, count($responses)).implode('', array_map(static fn (string $r): string => rvHead(2, strlen($r)).$r, $responses));
    $room = $total - 1 - strlen($rVals) - 4;
    $k = $room - 3;
    while ($k >= 0 && strlen(rvHead(2, $k)) + $k !== $room) {
        $k++;
        if ($k > $room) {
            throw new RuntimeException('no pad length fits');
        }
    }
    if ($k < 0) {
        throw new RuntimeException('the responses do not fit in the pad');
    }
    $header = "\xa2".$rVals."\x63pad".rvHead(2, $k).str_repeat("\0", $k);
    if (strlen($header) !== $total) {
        throw new RuntimeException('the new header is not the old length');
    }
    $newCose = substr_replace($cose, $header, $at, $total);
    $coseAt = (int) strrpos($s, $cose);

    return substr_replace($s, $newCose, $coseAt, strlen($cose));
}

/**
 * $from replaced by $to (the same length) inside the last assertion box labelled $label, the claim's hashed URI
 * for it re-hashed (SHA-256), and the claim signed again (ES256) with $key; the Sig_structure built here.
 */
function rvEdit(string $s, string $label, string $from, string $to, string $key): string
{
    if (strlen($from) !== strlen($to)) {
        throw new RuntimeException('an edit keeps its length');
    }
    $at = strrpos($s, $label."\0");
    $box = $at === false ? -1 : (int) strrpos(substr($s, 0, $at), 'jumb') - 4;
    if ($box < 0) {
        throw new RuntimeException("no {$label} box");
    }
    $length = bU32($s, $box);
    $p = strpos($s, $from, $at);
    if ($p === false || $p >= $box + $length) {
        throw new RuntimeException("the bytes to change are not in {$label}");
    }
    $claim = rvCbor($s, 'c2pa.claim.v2');
    $q = strpos($claim, hash('sha256', substr($s, $box + 8, $length - 8), true));
    if ($q === false) {
        throw new RuntimeException("the claim holds no hash of {$label}");
    }
    $signature = rvCbor($s, 'c2pa.signature');
    $claimAt = (int) strrpos($s, $claim);
    $s = substr_replace($s, $to, $p, strlen($to));
    $newClaim = substr_replace($claim, hash('sha256', substr($s, $box + 8, $length - 8), true), $q, 32);
    $s = substr_replace($s, $newClaim, $claimAt, strlen($newClaim));
    $head = ord($signature[2]);
    [$plen, $pat] = $head === 0x58 ? [ord($signature[3]), 4] : ($head === 0x59 ? [bU32("\0\0".substr($signature, 3, 2), 0), 5] : [$head - 0x40, 3]);
    file_put_contents(dirname($key).'/tbs', "\x84\x6aSignature1".rvHead(2, $plen).substr($signature, $pat, $plen)."\x40".rvHead(2, strlen($newClaim)).$newClaim);
    rvRun(rvSh('openssl', 'dgst', '-sha256', '-sign', $key, '-out', dirname($key).'/sig', dirname($key).'/tbs'));
    $der = (string) file_get_contents(dirname($key).'/sig');
    $rs = '';
    for ($i = 0, $o = 2; $i < 2; $i++) {
        $l = ord($der[$o + 1]);
        $rs .= str_pad(ltrim(substr($der, $o + 2, $l), "\0"), 32, "\0", STR_PAD_LEFT);
        $o += 2 + $l;
    }
    $coseAt = (int) strrpos($s, $signature);
    if (substr($signature, -66, 2) !== "\x58\x40") {
        throw new RuntimeException('the COSE_Sign1 does not end in a 64-byte signature');
    }

    return substr_replace($s, $rs, $coseAt + strlen($signature) - 64, 64);
}

/**
 * A throw-away P-256 certificate: self-signed when $issuer is null.
 */
function rvCert(string $keys, string $name, string $cn, string $section, ?string $issuer, string $serial): void
{
    rvRun(rvSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    $subject = "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
    if ($issuer === null) {
        rvRun(rvSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-set_serial', '0x'.$serial, '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));

        return;
    }
    rvRun(rvSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    rvRun(rvSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
}

/** An OCSP response about $cert, signed by $issuer itself, the certificate's status given by $status. */
function rvResponse(string $keys, string $cert, string $issuer, string $serial, string $status): string
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
    rvRun(rvSh('openssl', 'ocsp', '-issuer', "{$keys}/{$issuer}.pem", '-cert', "{$keys}/{$cert}.pem", '-reqout', "{$keys}/req.der", '-no_nonce'));
    rvRun(rvSh('openssl', 'ocsp', '-index', "{$keys}/index.txt", '-CA', "{$keys}/{$issuer}.pem", '-rsigner', "{$keys}/{$issuer}.pem", '-rkey', "{$keys}/{$issuer}.key", '-reqin', "{$keys}/req.der", '-respout', "{$keys}/resp.der", '-ndays', '3650', '-resp_no_certs'));

    return (string) file_get_contents("{$keys}/resp.der");
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/revocation';
$oracles = $root.'/tests/Fixtures/c2patool/revocation';
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
rvCert($keys, 'root', 'Throw-away Root (revocation)', 'root', null, '0A');
rvCert($keys, 'int', 'Throw-away Intermediate (revocation)', 'ca', 'root', '1A');
rvCert($keys, 'p', 'Signer P (revocation)', 'leaf', 'int', '2A');
rvCert($keys, 'u', 'Signer U (revocation)', 'leaf', 'int', '2B');
rvCert($keys, 'other', 'Another signer (revocation)', 'leaf', 'int', '2C');
foreach (['p', 'u'] as $leaf) {
    rvRun(rvSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/{$leaf}.key", '-out', "{$keys}/{$leaf}.pk8"));
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
function rvSign(string $keys, string $tool, string $root, string $leaf, array $extra, ?string $parent, string $out): string
{
    file_put_contents("{$keys}/m.json", (string) json_encode([
        'alg' => 'es256', 'private_key' => "{$keys}/{$leaf}.pk8", 'sign_cert' => "{$keys}/{$leaf}-chain.pem",
        'claim_generator_info' => [['name' => 'c2pa-verifier revocation probes', 'version' => '1']],
        'assertions' => [
            ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]],
            ...$extra,
        ],
    ], JSON_UNESCAPED_SLASHES));
    rvRun(rvSh(...array_merge([$tool, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json"], $parent === null ? [] : ['-p', $parent], ['-o', $out, '-f'])));

    return (string) file_get_contents($out);
}

// ---- A: a CA of the path, stapled ----
$caRevoked = rvResponse($keys, 'int', 'root', '1A', 'revoked');
$broken = substr_replace($caRevoked, chr(ord($caRevoked[strlen($caRevoked) - 1]) ^ 0x01), -1, 1);
$base = rvSign($keys, $new, $root, 'u', [], null, "{$dir}/ca-control.png");
[$s, $chunk] = rvStore($base);
foreach ([
    'ca-revoked' => [$caRevoked],
    'ca-good' => [rvResponse($keys, 'int', 'root', '1A', 'good')],
    'ca-removed' => [rvResponse($keys, 'int', 'root', '1A', 'removed')],
    'ca-broken' => [$broken],
    'ca-other' => [rvResponse($keys, 'other', 'int', '2C', 'good')],
] as $name => $responses) {
    file_put_contents("{$dir}/{$name}.png", rvChunk($base, $chunk, rvStaple($s, $responses)));
}

// ---- B: certificate-status assertions in a child, about the parent's signer or its own ----
rvSign($keys, $new, $root, 'p', [], null, "{$dir}/cs-parent.png");
foreach ([
    'cs-good' => [rvResponse($keys, 'p', 'int', '2A', 'good')],
    'cs-revoked' => [rvResponse($keys, 'p', 'int', '2A', 'revoked')],
    'cs-two' => [rvResponse($keys, 'other', 'int', '2C', 'good'), rvResponse($keys, 'p', 'int', '2A', 'revoked')],
    'cs-own' => [rvResponse($keys, 'u', 'int', '2B', 'revoked')],
] as $name => $responses) {
    $placeholders = array_map(static fn (string $r, int $i): string => str_repeat(chr(0x41 + $i), strlen($r)), $responses, array_keys($responses));
    $png = rvSign($keys, $new, $root, 'u', [['label' => 'c2pa.certificate-status', 'data' => ['ocspVals' => $placeholders]]], "{$dir}/cs-parent.png", "{$keys}/child.png");
    [$cs, $cchunk] = rvStore($png);
    foreach ($responses as $i => $r) {
        $cs = rvEdit($cs, 'c2pa.certificate-status', rvHead(3, strlen($r)).$placeholders[$i], rvHead(2, strlen($r)).$r, "{$keys}/u.key");
    }
    file_put_contents("{$dir}/{$name}.png", rvChunk($png, $cchunk, $cs));
}

// ---- the oracles ----
foreach (['ca-control', 'ca-revoked', 'ca-good', 'ca-removed', 'ca-broken', 'ca-other', 'cs-parent', 'cs-good', 'cs-revoked', 'cs-two', 'cs-own'] as $name) {
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $v => $tool) {
        $lines = [];
        exec(rvSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/throw-away-root.settings.json").' 2>&1', $lines);
        file_put_contents("{$oracles}/{$name}--{$v}.json", implode("\n", $lines)."\n");
        /** @var array{validation_state?: string}|null $json */
        $json = json_decode(implode("\n", $lines), true);
        printf("  %-12s %-8s %s\n", $name, $v, $json['validation_state'] ?? 'error: '.substr(implode(' ', $lines), 0, 90));
    }
}
