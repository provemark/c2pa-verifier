<?php

declare(strict_types=1);

/*
 * Step 421: Ed25519 certificates with algorithm parameters. RFC 8410 §3: "For all of the OIDs,
 * the parameters MUST be absent." C2PA 2.4 §14.5.1.1 lists id-Ed25519 with that section for
 * all certificates; c2pa 0.91.3 (c2pa-rs PR 2785) refuses an end-entity certificate whose
 * Ed25519 signatureAlgorithm or subjectPublicKeyInfo carries parameters.
 *
 * A throw-away Ed25519 hierarchy (root <- intermediate <- leaf) is made for the run, and the
 * PNG fixture's manifest is re-signed (EdDSA, x5chain = leaf + intermediate) per probe:
 *   control                        nothing changed
 *   leaf-spki-params               the leaf's key AlgorithmIdentifier carries NULL
 *   leaf-signature-params          the intermediate signs the leaf with NULL in both AlgorithmIdentifiers
 *   intermediate-signature-params  the root signs the intermediate with NULL in both
 * OpenSSL writes no such certificate, so the DER is edited and the issuer signs the new
 * tbsCertificate again (openssl pkeyutl -rawin). Keys live in a scratch directory for the run
 * and are deleted before it ends. Tooling, not the verification path.
 *
 * Usage: php bin/make-ed25519-params-variants.php <scratch-dir> <out-dir> [<c2patool>…]
 * Writes <probe>.png and <probe>.settings.json (the root as anchor) into <out-dir> and prints
 * each c2patool's answer and this verifier's, with the settings and without. When <out-dir> is
 * tests/Fixtures/<set>, each c2patool's JSON goes to tests/Fixtures/c2patool/<set>/
 * <probe>--<version>[--no-settings].json.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $out] = [$argv[1] ?? null, $argv[2] ?? null];
$tools = array_slice($argv, 3);
if (! is_string($scratch) || ! is_dir($scratch) || ! is_string($out)) {
    fwrite(STDERR, "usage: php bin/make-ed25519-params-variants.php <scratch-dir> <out-dir> [<c2patool>…]\n");
    exit(1);
}
if (! is_dir($out) && ! mkdir($out, 0755, true)) {
    throw new RuntimeException("cannot create {$out}");
}
$keys = rtrim($scratch, '/').'/ed25519-keys-'.bin2hex(random_bytes(4));
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

function edRun(string $command): string
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }

    return implode("\n", $lines);
}

function edSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

function edBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/**
 * A DER TLV.
 *
 * @param  int<0, 255>  $tag
 */
function edTlv(int $tag, string $content): string
{
    $n = strlen($content);
    $length = $n < 128 ? chr($n) : ($n < 256 ? "\x81".chr($n) : "\x82".pack('n', $n));

    return chr($tag).$length.$content;
}

/**
 * The elements of a DER SEQUENCE, each as its whole TLV.
 *
 * @return list<string>
 */
function edChildren(string $tlv): array
{
    $p = (ord($tlv[1]) & 0x80) ? 2 + (ord($tlv[1]) & 0x7F) : 2;
    $children = [];
    while ($p < strlen($tlv)) {
        $first = ord($tlv[$p + 1]);
        if ($first & 0x80) {
            $width = $first & 0x7F;
            $length = (int) hexdec(bin2hex(substr($tlv, $p + 2, $width)));
            $header = 2 + $width;
        } else {
            $length = $first;
            $header = 2;
        }
        $children[] = substr($tlv, $p, $header + $length);
        $p += $header + $length;
    }

    return $children;
}

const ED_ALG = "\x30\x05\x06\x03\x2b\x65\x70";                 // AlgorithmIdentifier { id-Ed25519 }
const ED_ALG_NULL = "\x30\x07\x06\x03\x2b\x65\x70\x05\x00";    // the same, with a NULL parameter

$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$derToPem = static fn (string $der): string => "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";

// tbsCertificate: [0] version, serialNumber, signature, issuer, validity, subject, subjectPublicKeyInfo, …

/**
 * The tbsCertificate's signature AlgorithmIdentifier with a NULL parameter.
 *
 * @param  list<string>  $e
 * @return list<string>
 */
function edWithSignatureParams(array $e): array
{
    if (($e[2] ?? null) !== ED_ALG) {
        throw new RuntimeException('the tbsCertificate signature is not id-Ed25519 without parameters');
    }

    return [...array_slice($e, 0, 2), ED_ALG_NULL, ...array_slice($e, 3)];
}

/**
 * The tbsCertificate's key AlgorithmIdentifier with a NULL parameter.
 *
 * @param  list<string>  $e
 * @return list<string>
 */
function edWithSpkiParams(array $e): array
{
    $spki = edChildren($e[6] ?? '');
    if (($spki[0] ?? null) !== ED_ALG || ! isset($spki[1])) {
        throw new RuntimeException('the key is not id-Ed25519 without parameters');
    }

    return [...array_slice($e, 0, 6), edTlv(0x30, ED_ALG_NULL.$spki[1]), ...array_slice($e, 7)];
}

/**
 * The certificate with a NULL parameter where $where says ('signature': in both copies of the signature
 * algorithm; 'key': in the key's AlgorithmIdentifier), its tbsCertificate signed again by $issuerKey.
 *
 * @param  'signature'|'key'  $where
 */
function edResign(string $der, string $where, string $issuerKey, string $keys): string
{
    [$tbs] = edChildren($der);
    $fields = edChildren($tbs);
    $tbs = edTlv(0x30, implode('', $where === 'signature' ? edWithSignatureParams($fields) : edWithSpkiParams($fields)));
    file_put_contents("{$keys}/tbs.der", $tbs);
    edRun(edSh('openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', $issuerKey, '-in', "{$keys}/tbs.der", '-out', "{$keys}/tbs.sig"));

    return edTlv(0x30, $tbs.($where === 'signature' ? ED_ALG_NULL : ED_ALG).edTlv(0x03, "\0".file_get_contents("{$keys}/tbs.sig")));
}

// ---- the throw-away hierarchy ----
file_put_contents("{$keys}/ext.cnf", <<<'CNF'
[req]
distinguished_name = dn
[dn]
[root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
[int]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
[leaf]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF);
foreach (['root', 'int', 'leaf'] as $who) {
    edRun(edSh('openssl', 'genpkey', '-algorithm', 'ED25519', '-out', "{$keys}/{$who}.key"));
}
$subject = static fn (string $cn): string => "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
edRun(edSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', $subject('Ed25519 Root'), '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/root.pem"));
foreach (['int' => 'root', 'leaf' => 'int'] as $who => $issuer) {
    edRun(edSh('openssl', 'req', '-new', '-key', "{$keys}/{$who}.key", '-subj', $subject("Ed25519 {$who}"), '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$who}.csr"));
    edRun(edSh('openssl', 'x509', '-req', '-in', "{$keys}/{$who}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $who, '-out', "{$keys}/{$who}.pem"));
}
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$intDer = $pemToDer((string) file_get_contents("{$keys}/int.pem"));
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$probes = [
    'control' => [$leafDer, $intDer],
    'leaf-spki-params' => [edResign($leafDer, 'key', "{$keys}/int.key", $keys), $intDer],
    'leaf-signature-params' => [edResign($leafDer, 'signature', "{$keys}/int.key", $keys), $intDer],
    'intermediate-signature-params' => [$leafDer, edResign($intDer, 'signature', "{$keys}/root.key", $keys)],
];

// ---- the fixture's store and its signature box (step 31 offsets) ----
$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$extracted = (new PngManifestStoreExtractor)->extract($stream);
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
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];

foreach ($probes as $probe => [$leaf, $intermediate]) {
    file_put_contents("{$keys}/{$probe}.leaf.pem", $derToPem($leaf));
    file_put_contents("{$keys}/{$probe}.int.pem", $derToPem($intermediate));
    $chainCheck = edRun(edSh('openssl', 'verify', '-CAfile', "{$keys}/root.pem", '-untrusted', "{$keys}/{$probe}.int.pem", "{$keys}/{$probe}.leaf.pem").' || true');

    // the protected header: {1: -8 (EdDSA), 33: [leaf, intermediate]}
    $protected = "\xa2\x01\x27\x18\x21\x82".edBstr($leaf).edBstr($intermediate);
    $draft = "\xd2\x84".edBstr($protected)."\xa1\x63pad".edBstr('')."\xf6".edBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/{$probe}.tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    edRun(edSh('openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', "{$keys}/leaf.key", '-in', "{$keys}/{$probe}.tbs", '-out', "{$keys}/{$probe}.sig"));
    $signature = (string) file_get_contents("{$keys}/{$probe}.sig");
    $fixed = 2 + strlen(edBstr($protected)) + 5 + 3 + 1 + strlen(edBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$probe}: the pad would be {$padLength} bytes");
    }
    $cose = "\xd2\x84".edBstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".edBstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$probe}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    file_put_contents("{$out}/{$probe}.png", pngWithStore($png, substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH)));
    file_put_contents("{$out}/{$probe}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    printf("%-30s openssl verify: %s\n", $probe, trim((string) preg_replace('/\s+/', ' ', substr($chainCheck, -120))));
}

// ---- the answers ----
$summary = static function (string $json): string {
    $j = json_decode($json, true);
    if (! is_array($j) || ! is_string($j['validation_state'] ?? null)) {
        return 'error: '.substr(trim((string) preg_replace('/\s+/', ' ', $json)), 0, 100);
    }
    $results = is_array($j['validation_results'] ?? null) ? $j['validation_results'] : [];
    $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
    $codes = [];
    foreach (['failure'] as $kind) {
        foreach (is_array($active[$kind] ?? null) ? $active[$kind] : [] as $st) {
            $code = is_array($st) && is_string($st['code'] ?? null) ? $st['code'] : '?';
            $why = is_array($st) && is_string($st['explanation'] ?? null) ? ' ('.substr($st['explanation'], 0, 90).')' : '';
            $codes[] = $code.$why;
        }
    }

    return $j['validation_state'].' '.implode('; ', array_unique($codes));
};
$judges = [];
foreach ($tools as $tool) {
    $judges[trim(edRun(edSh($tool, '--version')))] = [$tool];
}
$judges['this verifier'] = [PHP_BINARY, $root.'/bin/c2pa-verify'];
$fixtures = realpath($out);
$oracles = $fixtures !== false && dirname($fixtures) === realpath($root.'/tests/Fixtures') ? $root.'/tests/Fixtures/c2patool/'.basename($fixtures) : null;
if ($oracles !== null && ! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}
foreach (array_keys($probes) as $probe) {
    echo "\n{$probe}\n";
    foreach ($judges as $name => $judge) {
        foreach (['settings' => ['--settings', "{$out}/{$probe}.settings.json"], 'none' => []] as $mode => $extra) {
            $json = (string) shell_exec(edSh(...$judge, ...["{$out}/{$probe}.png", ...$extra]).' 2>&1');
            printf("  %-22s %-9s %s\n", $name, $mode, $summary($json));
            if ($oracles !== null && str_starts_with($name, 'c2patool ')) {
                file_put_contents(sprintf('%s/%s--%s%s.json', $oracles, $probe, substr($name, 9), $mode === 'none' ? '--no-settings' : ''), $json);
            }
        }
    }
}
