<?php

declare(strict_types=1);

/*
 * Probes for SPEC-086 amendment 3 (step 484): a stored OCSP `unknown` about the signer, and the revocation source
 * asked after it.
 *
 * A throw-away P-256 root R and a signer L issued by it. OCSP responses from `openssl ocsp`, signed by R, without
 * certificates: L unknown (its serial absent from the responder's index), L revoked (keyCompromise), L good. The PNG
 * fixture's manifest is re-signed with L, x5chain [L, R], as bin/make-revocation-issuer-variants.php does, its
 * unprotected header carrying `rVals` with the unknown response and a pad that keeps the store's length.
 *
 *   leaf-unknown.png      L's `unknown` stapled
 *   leaf-unknown.der      the stapled response; leaf-revoked.der, leaf-good.der: what a source may answer
 *   root.settings.json    R in the legacy trust_anchors
 *
 * c2patool 0.28.3 judges the file under the settings.
 *
 * Usage: php bin/make-revocation-unknown-variants.php <scratch-dir> <c2patool-0.28.3>
 * Keys live in a scratch directory for the run and are deleted before it ends; only public certificates, the
 * re-signed file, the responses and the settings are written under tests/. Tooling, not the verification path.
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
    fwrite(STDERR, "usage: php bin/make-revocation-unknown-variants.php <scratch-dir outside the repository> <c2patool-0.28.3>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/revocation-unknown-keys-'.bin2hex(random_bytes(4));
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

function ruRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function ruSh(string ...$parts): string
{
    return implode(' ', array_map(escapeshellarg(...), $parts));
}

/** @param  int<0, 7>  $mt */
function ruHead(int $mt, int $n): string
{
    $ib = $mt << 5;

    return match (true) {
        $n < 24 => pack('C', $ib | $n),
        $n < 256 => pack('CC', $ib | 24, $n),
        $n < 65536 => pack('Cn', $ib | 25, $n),
        default => pack('CN', $ib | 26, $n),
    };
}

function ruBstr(string $b): string
{
    return ruHead(2, strlen($b)).$b;
}

/** DER ECDSA-Sig-Value → R || S, each 32 bytes wide. */
function ruDerToRs(string $der): string
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

function ruDer(string $keys, string $name): string
{
    return (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', (string) file_get_contents("{$keys}/{$name}.pem")) ?? '', true);
}

/** A response about L from R: $index is the responder's database, so a serial absent from it is answered unknown. */
function ruResponse(string $keys, string $index): string
{
    file_put_contents("{$keys}/index.txt", $index);
    ruRun(ruSh('openssl', 'ocsp', '-issuer', "{$keys}/r.pem", '-cert', "{$keys}/l.pem", '-reqout', "{$keys}/req.der", '-no_nonce'));
    ruRun(ruSh('openssl', 'ocsp', '-index', "{$keys}/index.txt", '-CA', "{$keys}/r.pem", '-rsigner', "{$keys}/r.pem", '-rkey', "{$keys}/r.key", '-reqin', "{$keys}/req.der", '-respout', "{$keys}/resp.der", '-ndays', '3650', '-resp_no_certs'));

    return (string) file_get_contents("{$keys}/resp.der");
}

file_put_contents("{$keys}/ext.cnf", "[req]\ndistinguished_name=dn\n[dn]\n"
    ."[root]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
    ."[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=emailProtection\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
$subject = '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=';
ruRun(ruSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/r.key"));
ruRun(ruSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/r.key", '-subj', $subject.'Throw-away Root (unknown)', '-days', '3650', '-set_serial', '0x0A', '-config', "{$keys}/ext.cnf", '-extensions', 'root', '-out', "{$keys}/r.pem"));
ruRun(ruSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/l.key"));
ruRun(ruSh('openssl', 'req', '-new', '-key', "{$keys}/l.key", '-subj', $subject.'Signer L (unknown)', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/l.csr"));
ruRun(ruSh('openssl', 'x509', '-req', '-in', "{$keys}/l.csr", '-CA', "{$keys}/r.pem", '-CAkey', "{$keys}/r.key", '-set_serial', '0x1A', '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'leaf', '-out', "{$keys}/l.pem"));

$expires = gmdate('ymdHis\Z', time() + 3650 * 86400);
$revokedAt = gmdate('ymdHis\Z', time() - 30 * 86400);
$responses = [
    'leaf-unknown' => ruResponse($keys, "V\t{$expires}\t\t1B\tunknown\t/O=C2PA Verifier throw-away hierarchy\n"),
    'leaf-revoked' => ruResponse($keys, "R\t{$expires}\t{$revokedAt},keyCompromise\t1A\tunknown\t/O=C2PA Verifier throw-away hierarchy\n"),
    'leaf-good' => ruResponse($keys, "V\t{$expires}\t\t1A\tunknown\t/O=C2PA Verifier throw-away hierarchy\n"),
];

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/revocation-unknown';
$oracles = $root.'/tests/Fixtures/c2patool/revocation-unknown';
foreach ([$dir, $oracles] as $d) {
    if (! is_dir($d) && ! mkdir($d, 0o755, true)) {
        throw new RuntimeException("cannot create {$d}");
    }
}
foreach ($responses as $name => $der) {
    file_put_contents("{$dir}/{$name}.der", $der);
}
copy("{$keys}/r.pem", "{$dir}/throw-away-root.pem");
copy("{$keys}/l.pem", "{$dir}/signer-l.pem");
file_put_contents("{$dir}/root.settings.json", json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => (string) file_get_contents("{$keys}/r.pem"), 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

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

// the protected header: {1: -7 (ES256), 33: [L, R]}
$protected = "\xa2\x01\x26\x18\x21\x82".ruBstr(ruDer($keys, 'l')).ruBstr(ruDer($keys, 'r'));
// a first COSE with an empty signature, to borrow sigStructure() from the verifier's own parser
$draft = "\xd2\x84".ruBstr($protected)."\xa1\x63pad".ruBstr('')."\xf6".ruBstr(str_repeat("\0", 64));
file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
ruRun(ruSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/l.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
$signature = ruDerToRs((string) file_get_contents("{$keys}/sig"));
// the unprotected header {"rVals": {"ocspVals": [unknown]}, "pad": bstr} fills the box to the original length
$rVals = "\x65rVals\xa1\x68ocspVals\x81".ruBstr($responses['leaf-unknown']);
$padLength = $COSE_LENGTH - (2 + strlen(ruBstr($protected)) + 1 + strlen($rVals) + 4 + 3 + 1 + strlen(ruBstr($signature)));
if ($padLength < 256) {
    throw new RuntimeException("the pad would be {$padLength} bytes, too short for a 3-byte head");
}
$cose = "\xd2\x84".ruBstr($protected)."\xa2".$rVals."\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".ruBstr($signature);
$store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
if (strlen($cose) !== $COSE_LENGTH || strlen($store) !== strlen($s)) {
    throw new RuntimeException('the store changed length');
}
if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
    throw new RuntimeException('the new signature does not verify');
}
file_put_contents("{$dir}/leaf-unknown.png", pngWithStore($png, $store));

// ---- what c2patool 0.28.3 says ----
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
$lines = [];
exec(ruSh($tool, "{$dir}/leaf-unknown.png", '--settings', "{$dir}/root.settings.json").' 2>&1', $lines, $code);
file_put_contents("{$oracles}/leaf-unknown--0.28.3".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
$report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
printf("leaf-unknown  %s\n", is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
