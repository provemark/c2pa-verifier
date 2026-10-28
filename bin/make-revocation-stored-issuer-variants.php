<?php

declare(strict_types=1);

/*
 * Probes for SPEC-030 amendment 5 (step 490): stored OCSP responses about a trusted signer are read against the
 * issuer the chain walk verified, not against whatever x5chain carries second.
 *
 * A throw-away P-256 root R, a signer L issued by it, N (self-signed, R's subject, its own key) and B (self-signed,
 * unrelated). OCSP responses from `openssl ocsp`, without certificates: from R, L good and L revoked (keyCompromise);
 * forged, L good with N as the issuer (CertID from N, signed by N's key) and with B as the issuer (from B, by B). The
 * PNG fixture's manifest is re-signed with L, as bin/make-revocation-issuer-variants.php does, its unprotected header
 * carrying `rVals` with one response and a pad that keeps the store's length.
 *
 *   leaf-twin-forged.png    x5chain [L, N], N's forged good stapled
 *   leaf-bogus-forged.png   x5chain [L, B], B's forged good stapled
 *   leaf-alone-revoked.png  x5chain [L], R's revoked stapled
 *   leaf-root-good.png      x5chain [L, R], R's good stapled (the control)
 *   child-twin-forged.png   fixture-unsigned.png signed by M (another signer under R) with c2patool, its parentOf
 *                           ingredient leaf-twin-forged.png, whose manifest (N's forged good included) is in its store
 *   *.der                   the responses; root.settings.json: R in the legacy trust_anchors;
 *   allowed.settings.json   L on an allowed list (a "manifest" entry whose anchor B reaches nothing)
 *
 * c2patool 0.28.3 judges each file under root.settings.json.
 *
 * Usage: php bin/make-revocation-stored-issuer-variants.php <scratch-dir> <c2patool-0.28.3>
 * Keys live in a scratch directory for the run and are deleted before it ends; only public certificates, the
 * re-signed files, the responses and the settings are written under tests/. Tooling, not the verification path.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $tool] = [$argv[1] ?? '', $argv[2] ?? ''];
exec(escapeshellarg($tool).' --version 2>&1', $versionLines);
if (! is_dir($scratch) || ! is_file($tool) || ($versionLines[0] ?? '') !== 'c2patool 0.28.3') {
    fwrite(STDERR, "usage: php bin/make-revocation-stored-issuer-variants.php <scratch-dir outside the repository> <c2patool-0.28.3>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/revocation-stored-issuer-keys-'.bin2hex(random_bytes(4));
if (! mkdir($keys, 0o700)) {
    throw new RuntimeException("cannot create {$keys}");
}
// the keys go, whatever happens
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
        echo "keys deleted: {$keys}\n";
    }
});

function rsiRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function rsiSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/** @param  int<0, 7>  $mt */
function rsiHead(int $mt, int $n): string
{
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function rsiBstr(string $b): string
{
    return rsiHead(2, strlen($b)).$b;
}

/** DER ECDSA-Sig-Value → R || S, each 32 bytes wide. */
function rsiDerToRs(string $der): string
{
    $p = 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

function rsiDer(string $keys, string $name): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents("{$keys}/{$name}.pem")) ?? '', true);
}

/** A response about L, its CertID from $issuer and signed by $issuer's key: $index is the responder's database. */
function rsiResponse(string $keys, string $issuer, string $index): string
{
    file_put_contents("{$keys}/index.txt", $index);
    rsiRun(rsiSh('openssl', 'ocsp', '-issuer', "{$keys}/{$issuer}.pem", '-cert', "{$keys}/l.pem", '-reqout', "{$keys}/req.der", '-no_nonce'));
    rsiRun(rsiSh('openssl', 'ocsp', '-index', "{$keys}/index.txt", '-CA', "{$keys}/{$issuer}.pem", '-rsigner', "{$keys}/{$issuer}.pem", '-rkey', "{$keys}/{$issuer}.key", '-reqin', "{$keys}/req.der", '-respout', "{$keys}/resp.der", '-ndays', '3650', '-resp_no_certs'));

    return (string) file_get_contents("{$keys}/resp.der");
}

/**
 * The fixture's store with its COSE_Sign1 replaced: signed by L, x5chain the named certificates, $staple in rVals.
 *
 * @param  list<string>  $x5chain
 */
function rsiStore(string $keys, string $s, string $claimBytes, int $at, int $length, array $x5chain, string $staple): string
{
    $protected = "\xa2\x01\x26\x18\x21".rsiHead(4, count($x5chain)).implode('', array_map(static fn (string $name): string => rsiBstr(rsiDer($keys, $name)), $x5chain));
    // a first COSE with an empty signature, to borrow sigStructure() from the verifier's own parser
    $draft = "\xd2\x84".rsiBstr($protected)."\xa1\x63pad".rsiBstr('')."\xf6".rsiBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    rsiRun(rsiSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/l.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = rsiDerToRs((string) file_get_contents("{$keys}/sig"));
    // the unprotected header {"rVals": {"ocspVals": [staple]}, "pad": bstr} fills the box to the original length
    $rVals = "\x65rVals\xa1\x68ocspVals\x81".rsiBstr($staple);
    $padLength = $length - (2 + strlen(rsiBstr($protected)) + 1 + strlen($rVals) + 4 + 3 + 1 + strlen(rsiBstr($signature)));
    if ($padLength < 256) {
        throw new RuntimeException("the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".rsiBstr($protected)."\xa2".$rVals."\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".rsiBstr($signature);
    $store = substr($s, 0, $at).$cose.substr($s, $at + $length);
    if (strlen($cose) !== $length || strlen($store) !== strlen($s)) {
        throw new RuntimeException('the store changed length');
    }
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException('the new signature does not verify');
    }

    return $store;
}

file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n"
    ."[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    ."[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
$subject = '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=';
foreach (['r' => 'Throw-away Root (stored issuer)', 'n' => 'Throw-away Root (stored issuer)', 'b' => 'Unrelated B (stored issuer)'] as $name => $cn) {
    rsiRun(rsiSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    rsiRun(rsiSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject.$cn, '-days', '3650', '-set_serial', $name === 'r' ? '0x0A' : '0x0B', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/{$name}.pem"));
}
rsiRun(rsiSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/l.key"));
rsiRun(rsiSh('openssl', 'req', '-new', '-key', "{$keys}/l.key", '-subj', $subject.'Signer L (stored issuer)', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/l.csr"));
rsiRun(rsiSh('openssl', 'x509', '-req', '-in', "{$keys}/l.csr", '-CA', "{$keys}/r.pem", '-CAkey', "{$keys}/r.key", '-set_serial', '0x1A', '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/l.pem"));
rsiRun(rsiSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/m.key"));
rsiRun(rsiSh('openssl', 'pkcs8', '-topk8', '-nocrypt', '-in', "{$keys}/m.key", '-out', "{$keys}/m.pk8"));
rsiRun(rsiSh('openssl', 'req', '-new', '-key', "{$keys}/m.key", '-subj', $subject.'Signer M (stored issuer)', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/m.csr"));
rsiRun(rsiSh('openssl', 'x509', '-req', '-in', "{$keys}/m.csr", '-CA', "{$keys}/r.pem", '-CAkey', "{$keys}/r.key", '-set_serial', '0x1B', '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/m.pem"));
file_put_contents("{$keys}/m-chain.pem", file_get_contents("{$keys}/m.pem").file_get_contents("{$keys}/r.pem"));

$expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
$revokedAt = gmdate('ymdHis\Z', time() - 30 * 86400);
$good = "V\t{$expires}\t\t1A\tunknown\t/O=C2PA Verifier throw-away hierarchy\n";
$responses = [
    'leaf-revoked' => rsiResponse($keys, 'r', "R\t{$expires}\t{$revokedAt},keyCompromise\t1A\tunknown\t/O=C2PA Verifier throw-away hierarchy\n"),
    'leaf-good' => rsiResponse($keys, 'r', $good),
    'twin-forged-good' => rsiResponse($keys, 'n', $good),
    'bogus-forged-good' => rsiResponse($keys, 'b', $good),
];

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/revocation-stored-issuer';
$oracles = $root.'/tests/Fixtures/c2patool/revocation-stored-issuer';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
foreach ($responses as $name => $der) {
    file_put_contents("{$dir}/{$name}.der", $der);
}
foreach (['r' => 'throw-away-root', 'l' => 'signer-l', 'm' => 'signer-m', 'n' => 'name-twin-n', 'b' => 'unrelated-b'] as $name => $file) {
    copy("{$keys}/{$name}.pem", "{$dir}/{$file}.pem");
}
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$json = static fn (array $trust): string => json_encode(['verify' => ['verify_trust' => true], 'trust' => $trust], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
file_put_contents("{$dir}/root.settings.json", $json(['trust_anchors' => (string) file_get_contents("{$keys}/r.pem"), 'trust_config' => $storeCfg]));
// an allowed list lives in a "manifest" entry (SPEC-031), which needs anchors: B, which issues nothing here
file_put_contents("{$dir}/allowed.settings.json", $json(['trust_config' => $storeCfg, 'anchors' => [['trust_anchors' => (string) file_get_contents("{$keys}/b.pem"), 'allowed_list' => (string) file_get_contents("{$keys}/l.pem"), 'trust_kind' => 'manifest']]]));

// ---- the fixture's store and the signature box (step 31 offsets) ----
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
$COSE_LENGTH = 12305 - 8;   // the signature cbor box's contents
if (substr($s, $COSE, 2) !== "\xd2\x84") {
    throw new RuntimeException('the COSE_Sign1 is not where step 31 measured it');
}
$claimBytes = ManifestStore::fromTree((new JumbfParser)->parse($s))->active->claimBytes();

$probes = [
    'leaf-twin-forged' => [['l', 'n'], 'twin-forged-good'],
    'leaf-bogus-forged' => [['l', 'b'], 'bogus-forged-good'],
    'leaf-alone-revoked' => [['l'], 'leaf-revoked'],
    'leaf-root-good' => [['l', 'r'], 'leaf-good'],
];
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach ($probes as $name => [$x5chain, $staple]) {
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, rsiStore($keys, $s, $claimBytes, $COSE, $COSE_LENGTH, $x5chain, $responses[$staple])));
}
// the forged manifest as an ingredient of M's: c2patool copies the parent's store into the child's
file_put_contents("{$keys}/m.json", (string) json_encode([
    'alg' => 'es256', 'private_key' => "{$keys}/m.pk8", 'sign_cert' => "{$keys}/m-chain.pem",
    'claim_generator_info' => [['name' => 'c2pa-verifier stored-issuer probes', 'version' => '1']],
    'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']]]]],
], JSON_UNESCAPED_SLASHES));
rsiRun(rsiSh($tool, $root.'/tests/Fixtures/fixture-unsigned.png', '-m', "{$keys}/m.json", '-p', "{$dir}/leaf-twin-forged.png", '-o', "{$dir}/child-twin-forged.png", '-f'));
foreach ([...array_keys($probes), 'child-twin-forged'] as $name) {

    // ---- what c2patool 0.28.3 says ----
    $lines = [];
    exec(rsiSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/root.settings.json").' 2>&1', $lines, $code);
    file_put_contents("{$oracles}/{$name}--0.28.3".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
    $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
    printf("%-20s %s\n", $name, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
}
