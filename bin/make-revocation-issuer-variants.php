<?php

declare(strict_types=1);

/*
 * Probes for SPEC-086 amendment 2 and SPEC-087 amendment 1 (step 483): which certificate the revocation source is
 * told issued the signer, and the second chain walk of a signer with only the Time Stamping EKU.
 *
 * A throw-away P-256 hierarchy: root R; signer L issued by R itself; an unrelated self-signed B; intermediate I
 * issued by R; and T, issued by I, whose only EKU is timeStamping (critical). OCSP responses from `openssl ocsp`,
 * each signed by the issuer of the certificate it is about, without certificates. The PNG fixture's manifest is
 * re-signed with each signer, as bin/make-tsa-signer-variants.php does: a new protected header (ES256, the
 * x5chain below), a new signature over the Sig_structure, and the unprotected pad resized so that the store keeps
 * its length.
 *
 *   leaf-alone.png        x5chain [L]: R issued L, and R is not in x5chain
 *   leaf-bogus.png        x5chain [L, B]: the second certificate did not issue L
 *   leaf-root.png         x5chain [L, R]: the control, R in x5chain
 *   leaf-name-twin.png    x5chain [L, N]: N has R's subject but its own key
 *   leaf-key-twin.png     x5chain [L, K]: K has R's key but another subject
 *   tsa-signer.png        x5chain [T, I]
 *   tsa-signer-staple.png the same, with I's revocation stapled in the unprotected rVals
 *
 *   leaf-revoked.der      L revoked (keyCompromise), from R
 *   int-revoked.der       I revoked (keyCompromise), from R
 *   root.settings.json    R in the legacy trust_anchors
 *   tsa-split.settings.json  I in the legacy trust_anchors, R as a "manifest" entry
 *
 * c2patool 0.28.3 judges each file under the settings that name its anchors.
 *
 * Usage: php bin/make-revocation-issuer-variants.php <scratch-dir> <c2patool-0.28.3>
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
    fwrite(STDERR, "usage: php bin/make-revocation-issuer-variants.php <scratch-dir outside the repository> <c2patool-0.28.3>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/revocation-issuer-keys-'.bin2hex(random_bytes(4));
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

function riRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function riSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/** @param  int<0, 7>  $mt */
function riHead(int $mt, int $n): string
{
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function riBstr(string $b): string
{
    return riHead(2, strlen($b)).$b;
}

/** DER ECDSA-Sig-Value → R || S, each 32 bytes wide. */
function riDerToRs(string $der): string
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

function riDer(string $keys, string $name): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents("{$keys}/{$name}.pem")) ?? '', true);
}

/** A throw-away P-256 certificate: self-signed when $issuer is null. */
function riCert(string $keys, string $name, string $cn, string $section, ?string $issuer, string $serial): void
{
    riRun(riSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/{$name}.key"));
    $subject = "/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN={$cn}";
    if ($issuer === null) {
        riRun(riSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-days', '3650', '-set_serial', '0x'.$serial, '-config', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));

        return;
    }
    riRun(riSh('openssl', 'req', '-new', '-key', "{$keys}/{$name}.key", '-subj', $subject, '-config', "{$keys}/ext.cnf", '-out', "{$keys}/{$name}.csr"));
    riRun(riSh('openssl', 'x509', '-req', '-in', "{$keys}/{$name}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', '0x'.$serial, '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', $section, '-out', "{$keys}/{$name}.pem"));
}

/** An OCSP response revoking $cert (keyCompromise, 30 days ago), signed by $issuer itself. */
function riRevoked(string $keys, string $cert, string $issuer, string $serial): string
{
    $expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
    $revokedAt = gmdate('ymdHis\Z', time() - 30 * 86400);
    file_put_contents("{$keys}/index.txt", "R\t{$expires}\t{$revokedAt},keyCompromise\t{$serial}\tunknown\t/O=C2PA Verifier throw-away hierarchy\n");
    riRun(riSh('openssl', 'ocsp', '-issuer', "{$keys}/{$issuer}.pem", '-cert', "{$keys}/{$cert}.pem", '-reqout', "{$keys}/req.der", '-no_nonce'));
    riRun(riSh('openssl', 'ocsp', '-index', "{$keys}/index.txt", '-CA', "{$keys}/{$issuer}.pem", '-rsigner', "{$keys}/{$issuer}.pem", '-rkey', "{$keys}/{$issuer}.key", '-reqin', "{$keys}/req.der", '-respout', "{$keys}/resp.der", '-ndays', '3650', '-resp_no_certs'));

    return (string) file_get_contents("{$keys}/resp.der");
}

file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n"
    ."[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    ."[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n"
    ."[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n"
    ."[tsa-only]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=critical,timeStamping\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
riCert($keys, 'r', 'Throw-away Root (issuer)', 'root', null, '0A');
riCert($keys, 'l', 'Signer L (issuer)', 'leaf', 'r', '1A');
riCert($keys, 'b', 'Unrelated B (issuer)', 'root', null, '0B');
// N: R's subject, its own key; K: R's key, its own subject
riRun(riSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/n.key"));
riRun(riSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/n.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (issuer)', '-days', '3650', '-set_serial', '0x0C', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/n.pem"));
riRun(riSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/r.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Key twin K (issuer)', '-days', '3650', '-set_serial', '0x0D', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/k.pem"));
riCert($keys, 'i', 'Throw-away Intermediate (issuer)', 'ca', 'r', '1B');
riCert($keys, 't', 'Time-stamping signer T (issuer)', 'tsa-only', 'i', '2A');

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/revocation-issuer';
$oracles = $root.'/tests/Fixtures/c2patool/revocation-issuer';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
$leafRevoked = riRevoked($keys, 'l', 'r', '1A');
$intRevoked = riRevoked($keys, 'i', 'r', '1B');
file_put_contents("{$dir}/leaf-revoked.der", $leafRevoked);
file_put_contents("{$dir}/int-revoked.der", $intRevoked);
foreach (['r' => 'throw-away-root', 'l' => 'signer-l', 'b' => 'unrelated-b', 'n' => 'name-twin-n', 'k' => 'key-twin-k', 'i' => 'intermediate-i', 't' => 'signer-t'] as $name => $as) {
    copy("{$keys}/{$name}.pem", "{$dir}/{$as}.pem");
}
$pem = static fn (string $name): string => (string) file_get_contents("{$keys}/{$name}.pem");
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$settingsFiles = [
    'root' => ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $pem('r'), 'trust_config' => $storeCfg]],
    'tsa-split' => ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $pem('i'), 'anchors' => [['trust_anchors' => $pem('r'), 'trust_kind' => 'manifest']], 'trust_config' => $storeCfg]],
];
foreach ($settingsFiles as $name => $settings) {
    file_put_contents("{$dir}/{$name}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

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
$verifier = new SignatureVerifier;

$probes = [
    'leaf-alone' => ['l', ['l'], null],
    'leaf-bogus' => ['l', ['l', 'b'], null],
    'leaf-root' => ['l', ['l', 'r'], null],
    'leaf-name-twin' => ['l', ['l', 'n'], null],
    'leaf-key-twin' => ['l', ['l', 'k'], null],
    'tsa-signer' => ['t', ['t', 'i'], null],
    'tsa-signer-staple' => ['t', ['t', 'i'], $intRevoked],
];
foreach ($probes as $name => [$signer, $x5chain, $staple]) {
    // the protected header: {1: -7 (ES256), 33: [x5chain…]}
    $protected = "\xa2\x01\x26\x18\x21".riHead(4, count($x5chain)).implode('', array_map(static fn (string $c): string => riBstr(riDer($keys, $c)), $x5chain));
    // a first COSE with an empty signature, to borrow sigStructure() from the verifier's own parser
    $draft = "\xd2\x84".riBstr($protected)."\xa1\x63pad".riBstr('')."\xf6".riBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    riRun(riSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/{$signer}.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = riDerToRs((string) file_get_contents("{$keys}/sig"));
    // the unprotected header {("rVals": {"ocspVals": [staple]},) "pad": bstr} fills the box to the original length
    $rVals = $staple === null ? '' : "\x65rVals\xa1\x68ocspVals\x81".riBstr($staple);
    $fixed = 2 + strlen(riBstr($protected)) + 1 + strlen($rVals) + 4 + 3 + 1 + strlen(riBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $unprotected = ($staple === null ? "\xa1" : "\xa2").$rVals."\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength);
    $cose = "\xd2\x84".riBstr($protected).$unprotected."\xf6".riBstr($signature);
    $store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
    if (strlen($cose) !== $COSE_LENGTH || strlen($store) !== strlen($s)) {
        throw new RuntimeException("{$name}: the store changed length");
    }
    if (! $verifier->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify");
    }
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
}

// ---- what c2patool 0.28.3 says, under the settings that name each file's anchors ----
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (['leaf-alone' => 'root', 'leaf-bogus' => 'root', 'leaf-root' => 'root', 'leaf-name-twin' => 'root', 'leaf-key-twin' => 'root', 'tsa-signer' => 'tsa-split', 'tsa-signer-staple' => 'tsa-split'] as $name => $settings) {
    $lines = [];
    exec(riSh($tool, "{$dir}/{$name}.png", '--settings', "{$dir}/{$settings}.settings.json").' 2>&1', $lines, $code);
    file_put_contents("{$oracles}/{$name}--0.28.3".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
    $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
    printf("%-18s %-10s %s\n", $name, $settings, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
}
