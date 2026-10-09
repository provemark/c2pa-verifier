<?php

declare(strict_types=1);

/*
 * Step 247 (measurement): a name constraint over a name that is not UTF-8. A throw-away P-256 root, an
 * intermediate whose nameConstraints permit only directoryName O = T61String "Caf\xE9", and two leaves
 * under it, each re-signing the PNG fixture's claim as bin/make-chain-constraint-variants.php does:
 *
 *   t61-inside    the leaf's O is T61String "Caf\xE9", byte for byte the permitted name: inside
 *   t61-outside   the leaf's O is T61String "Other\xFF": outside (OpenSSL: permitted subtree violation)
 *
 * Neither byte string is UTF-8. The permitted subtree is written as DER, so that OpenSSL keeps the
 * T61String as given. Keys live in a directory outside the repository for the run and are deleted
 * before it ends.
 * Usage: php bin/make-name-encoding-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>
 * Writes tests/Fixtures/name-encoding/ and tests/Fixtures/c2patool/name-encoding/. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (! is_dir($scratch) || ! is_executable($new) || ! is_executable($old)) {
    fwrite(STDERR, "usage: php bin/make-name-encoding-variants.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(2);
}
// escapeshellarg() drops bytes that are not UTF-8 under a UTF-8 locale; these names are such bytes
setlocale(LC_CTYPE, 'C');
$keys = rtrim($scratch, '/').'/name-encoding-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0o700)) {
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

function neRun(string $command, bool $mayFail = false): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0 && ! $mayFail) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function neSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/** @param  int<0, 7>  $mt */
function neHead(int $mt, int $n): string
{
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function neBstr(string $b): string
{
    return neHead(2, strlen($b)).$b;
}

function neDer(string $pem): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
}

/** DER ECDSA-Sig-Value → R || S, each 32 bytes wide. */
function neDerToRs(string $der): string
{
    $p = 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/** The x5chain value: an array of DER certificates. */
function neChain(string ...$ders): string
{
    return neHead(4, count($ders)).implode('', array_map(neBstr(...), $ders));
}

/**
 * A COSE_Sign1 of exactly $length bytes: the protected map, the unprotected map with its pad
 * filling the rest, a null payload, the signature.
 *
 * @param  list<string>  $unprotectedPairs  encoded key/value pairs before the pad
 */
function neCose(string $protected, array $unprotectedPairs, string $signature, int $length): string
{
    $fixed = 2 + strlen(neBstr($protected)) + 1 + strlen(implode('', $unprotectedPairs)) + 4 + 3 + 1 + strlen(neBstr($signature));
    $pad = $length - $fixed;
    if ($pad < 256 || $pad > 65535) {
        throw new RuntimeException("the pad would be {$pad} bytes");
    }
    $cose = "\xd2\x84".neBstr($protected).neHead(5, count($unprotectedPairs) + 1).implode('', $unprotectedPairs)."\x63pad\x59".pack('n', $pad).str_repeat("\0", $pad)."\xf6".neBstr($signature);
    if (strlen($cose) !== $length) {
        throw new RuntimeException('the COSE is '.strlen($cose)." bytes, not {$length}");
    }

    return $cose;
}

/** A DER element: tag, short or one-byte long length, contents. */
function neTlv(int $tag, string $contents): string
{
    $n = strlen($contents);
    if ($n > 255 || $tag < 0 || $tag > 255) {
        throw new RuntimeException('neTlv() writes one-byte tags and lengths below 256');
    }

    return chr($tag).($n < 128 ? chr($n) : "\x81".chr($n)).$contents;
}

// NameConstraints { permittedSubtrees [0] { GeneralSubtree { base [4] Name { RDN { O = T61String "Caf\xE9" } } } } }
$permitted = neTlv(0x30, neTlv(0x31, neTlv(0x30, "\x06\x03\x55\x04\x0a".neTlv(0x14, "Caf\xE9"))));
$nameConstraints = strtoupper(bin2hex(neTlv(0x30, neTlv(0xA0, neTlv(0x30, neTlv(0xA4, $permitted))))));

$org = '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY';
file_put_contents("{$keys}/ext.cnf", <<<CNF
[req]
distinguished_name = dn
string_mask = default
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[v3_nc]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
nameConstraints = critical, DER:{$nameConstraints}
[v3_leaf]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = emailProtection
authorityKeyIdentifier = keyid
CNF);

$issue = static function (string $name, string $subject, string $section, ?string $issuer) use ($keys): string {
    neRun(neSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    if ($issuer === null) {
        neRun(neSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
    } else {
        neRun(neSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
        neRun(neSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-sha256', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
    }

    return (string) file_get_contents("{$keys}/{$name}.pem");
};

$pem = [];
$pem['root'] = $issue('root', "{$org}/CN=Throw-away Name-encoding Root", 'v3_root', null);
$pem['int-t61'] = $issue('int-t61', "{$org}/CN=T61-constrained Intermediate", 'v3_nc', 'root');
$pem['t61-inside'] = $issue('t61-inside', "/O=Caf\xE9/CN=Inside, T61", 'v3_leaf', 'int-t61');
$pem['t61-outside'] = $issue('t61-outside', "/O=Other\xFF/CN=Outside, T61", 'v3_leaf', 'int-t61');
foreach (['t61-inside' => "Caf\xE9", 't61-outside' => "Other\xFF"] as $leaf => $o) {
    if (! str_contains(neDer($pem[$leaf]), neTlv(0x14, $o))) {
        throw new RuntimeException("{$leaf}: the subject's O is not the T61String asked for");
    }
}

$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
$extracted = $stream === false ? null : (new PngManifestStoreExtractor)->extract($stream);
if ($extracted === null || strlen($extracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$s = $extracted->bytes;
$COSE = 33728;
$COSE_LENGTH = 12305 - 8;
if (substr($s, $COSE, 2) !== "\xd2\x84") {
    throw new RuntimeException('the COSE_Sign1 is not where step 31 measured it');
}
$claimBytes = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();

$dir = $root.'/tests/Fixtures/name-encoding';
$oracles = $root.'/tests/Fixtures/c2patool/name-encoding';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
foreach ($pem as $name => $certificate) {
    file_put_contents("{$dir}/{$name}.pem", $certificate);
}
$settings = ['trust' => ['trust_anchors' => $pem['root'], 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

foreach (['t61-inside', 't61-outside'] as $leaf) {
    $protected = "\xa2\x01\x26\x18\x21".neChain(neDer($pem[$leaf]), neDer($pem['int-t61']));
    file_put_contents("{$keys}/tbs", "\x84\x6aSignature1".neBstr($protected).neBstr('').neBstr($claimBytes));
    neRun(neSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/{$leaf}.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $cose = neCose($protected, [], neDerToRs((string) file_get_contents("{$keys}/sig")), $COSE_LENGTH);
    file_put_contents("{$dir}/{$leaf}.png", pngWithStore($png, substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH)));
    // RFC 5280 path validation by OpenSSL, as a reference
    echo $leaf, ': openssl verify: ', trim(neRun(neSh('openssl', 'verify', '-CAfile', "{$dir}/root.pem", '-untrusted', "{$dir}/int-t61.pem", "{$dir}/{$leaf}.pem").' 2>&1', true)), "\n";
    foreach (['0.28.1' => $new, '0.27.22' => $old] as $version => $tool) {
        $json = neRun(neSh($tool, '--settings', "{$dir}/root.settings.json", "{$dir}/{$leaf}.png").' 2>&1', true);
        file_put_contents("{$oracles}/{$leaf}--{$version}.json", $json);
        $decoded = json_decode($json, true);
        $state = is_array($decoded) && is_string($decoded['validation_state'] ?? null) ? $decoded['validation_state'] : trim($json);
        printf("%s %s: %s\n", $leaf, $version, $state);
    }
}
