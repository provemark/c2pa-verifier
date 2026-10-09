<?php

declare(strict_types=1);

/*
 * The timestamp matrix (step 289): one valid signer chain, one valid timestamp
 * authority chain (leaf <- intermediate <- anchor, the leaf with the critical
 * timeStamping EKU), and variants in which one property of the timestamp differs:
 * a certificate of the TSA chain, the trust settings, or the token itself. Each probe
 * is the PNG fixture's store with its claim re-signed by a throw-away signer and an
 * RFC 3161 token from `openssl ts -reply` in the unprotected header (`sigTst2`, over
 * the CounterSignature structure of C2PA 2.4 §14.6, as `TimestampCheck` builds it).
 * `openssl ts -reply` refuses a TSA certificate whose extended key usage is not the
 * critical timeStamping alone, so those variants (step 292) are signed another way:
 * `openssl ts -reply` makes the TSTInfo with a helper certificate for the same key and
 * the base TSA extensions, and `openssl cms -sign -cades` signs that TSTInfo again
 * with the probe's TSA certificate (`sign => cms`; `control-cms` checks the route).
 *
 * Every probe is judged by c2patool 0.27.22 and 0.28.1, by `openssl ts -verify` and
 * by this verifier. The probes whose signer is short-lived are judged only after the
 * signer has expired, so that only a trusted timestamp can keep them valid.
 *
 * Keys live in a scratch directory for the run and are deleted before it ends.
 * Tooling, not the verification path.
 *
 * Usage: php bin/make-tsa-matrix.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22> [<set> <probe>…]
 * Without a set: every probe into <scratch-dir>/tsa-matrix/ (<probe>.png,
 * <probe>.settings.json, matrix.tsv). With a set: only the named probes, as fixtures,
 * into tests/Fixtures/timestamp/<set>/, with both c2patool versions' JSON, with the
 * probe's settings and without any, in tests/Fixtures/c2patool/<set>/
 * <probe>--<version>.json and <probe>--<version>--no-settings.json.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$scratch, $new, $old] = [$argv[1] ?? null, $argv[2] ?? '', $argv[3] ?? ''];
$set = $argv[4] ?? '';   // '' = the whole matrix into the scratch directory
$only = array_slice($argv, 5);

if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-tsa-matrix.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/tsa-matrix-keys-'.bin2hex(random_bytes(4));
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

function txRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function txSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function txBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function txDerToRs(string $der, int $curveBytes): string
{
    if ($der[0] !== "\x30") {
        throw new RuntimeException('not a DER ECDSA signature');
    }
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        if ($der[$p] !== "\x02") {
            throw new RuntimeException('not a DER ECDSA signature');
        }
        $len = ord($der[$p + 1]);
        $int = ltrim(substr($der, $p + 2, $len), "\0");
        $rs .= str_pad($int, $curveBytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

// ---- the fixture's store (step 09 offsets) ----
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
$HASH_DATA_BOX = 32831;      // the c2pa.hash.data assertion box, 195 bytes
$CLAIM = 33081;              // the claim's CBOR map (7 pairs, 591 bytes)
$storeBox = [0, 38, 117];    // the boxes enclosing an assertion: store superbox, c2pa manifest superbox, assertion store superbox
$claimBox = [0, 38, 33026, 33073];
if (substr($s, $HASH_DATA_BOX + 4, 4) !== 'jumb' || ord($s[$CLAIM]) !== 0xA7) {
    throw new RuntimeException('the store is not laid out as step 09 measured');
}

// ---- the fixture's assertion entries and boxes (step 09 offsets) ----
$THUMBNAIL_BOX = 166;        // c2pa.thumbnail.claim.png, 32470 bytes
$ACTIONS_BOX = 32636;        // c2pa.actions.v2, 195 bytes
$claim = bMapPairs($s, $CLAIM);
[, $createdStart, $createdEnd] = $claim['created_assertions'];     // 81 { c2pa.hash.data }
[, $gatheredStart, $gatheredEnd] = $claim['gathered_assertions'];  // 82 { thumbnail } { actions }
$hashDataEntry = substr($s, $createdStart + 1, $createdEnd - $createdStart - 1);
$thumbEntryEnd = bCborEnd($s, $gatheredStart + 1);
$thumbEntry = substr($s, $gatheredStart + 1, $thumbEntryEnd - $gatheredStart - 1);
$actionsEntry = substr($s, $thumbEntryEnd, $gatheredEnd - $thumbEntryEnd);
if (! str_contains($hashDataEntry, 'c2pa.hash.data') || ! str_contains($thumbEntry, 'c2pa.thumbnail') || ! str_contains($actionsEntry, 'c2pa.actions.v2')) {
    throw new RuntimeException('the claim lists are not [hash.data] + [thumbnail, actions] as step 09 measured');
}
$list = static fn (string ...$entries): string => pack('C', 0x80 + count($entries)).implode('', $entries);

/**
 * The hash of $bytes with the [start, length] ranges skipped — what c2pa.hash.data covers (C2PA 2.4 §15.12.1).
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function txAbsenceDataHash(string $bytes, array $exclusions): string
{
    usort($exclusions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $ctx = hash_init('sha256');
    $p = 0;
    foreach ($exclusions as [$start, $length]) {
        hash_update($ctx, substr($bytes, $p, $start - $p));
        $p = $start + $length;
    }
    hash_update($ctx, substr($bytes, $p));

    return hash_final($ctx, true);
}

/** The offset of the entry map naming $label in a CBOR list of hashed-URI maps at $list, or null. */
function txEntryFor(string $s, int $list, string $label): ?int
{
    $count = ord($s[$list]) & 0x1F;
    $p = $list + 1;
    for ($i = 0; $i < $count; $i++) {
        $end = bCborEnd($s, $p);
        if (str_contains(substr($s, $p, $end - $p), $label)) {
            return $p;
        }
        $p = $end;
    }

    return null;
}

/**
 * A store whose boxes moved: the hash.data assertion's exclusion re-lengthened to the new store, its data hash
 * recomputed over the PNG that will carry the store, and the claim's hashed URI for it recomputed — so that only
 * the absence under test differs from a valid file. $shift = bytes removed before the hash.data box and the claim.
 */
function txRebind(string $store, string $png, int $shift, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox - $shift;
    $payload = $box + 80;   // the cbor box's payload inside the assertion superbox (step 26 offsets)
    $hd = bMapPairs($store, $payload);
    $exclusionMap = $hd['exclusions'][1] + 1;   // 81 a2 …
    $ex = bMapPairs($store, $exclusionMap);
    $lengthValue = $ex['length'][1];            // 19 xx xx
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $digest = txAbsenceDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt - $shift);
    $entry = txEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data') ?? txEntryFor($store, $claim['gathered_assertions'][1], 'c2pa.hash.data');
    if ($entry === null) {
        throw new RuntimeException('no c2pa.hash.data entry in the claim');
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

/** The claim's hashed URI for the assertion box at $box recomputed (sha256 over the box minus its 8-byte header, §8.4.2.3). */
function txRehashEntry(string $store, int $box, int $claimAt, string $label): string
{
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt);
    $entry = txEntryFor($store, $claim['created_assertions'][1], $label) ?? txEntryFor($store, $claim['gathered_assertions'][1], $label);
    if ($entry === null) {
        throw new RuntimeException("no {$label} entry in the claim");
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

/**
 * @param  list<string>  $ext
 * @return list<string>
 */
function tsaReplace(array $ext, string $prefix, ?string $with): array
{
    $kept = [];
    foreach ($ext as $line) {
        if (! str_starts_with($line, $prefix.'=')) {
            $kept[] = $line;
        }
    }
    if ($with !== null) {
        $kept[] = $prefix.'='.$with;
    }

    return $kept;
}

/**
 * One certificate's recipe: extensions, key, signature digest (by its issuer), validity.
 *
 * @param  list<string>  $ext
 * @param  array{0: string, 1: string}|null  $validity
 * @return array{ext: list<string>, key: string, md: string, validity: array{0: string, 1: string}|null}
 */
function tsaCert(array $ext, string $key = 'p256', string $md = 'sha256', ?array $validity = null): array
{
    return ['ext' => $ext, 'key' => $key, 'md' => $md, 'validity' => $validity];
}

// ---- the matrix ----------------------------------------------------------------------------------

$out = $set === '' ? rtrim((string) $scratch, '/').'/tsa-matrix' : $root.'/tests/Fixtures/timestamp/'.$set;
$oracles = $set === '' ? null : $root.'/tests/Fixtures/c2patool/'.$set;
if ($oracles !== null && ! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}
if (! is_dir($out) && ! mkdir($out, 0755, true)) {
    throw new RuntimeException("cannot create {$out}");
}

$CA = ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign', 'subjectKeyIdentifier=hash'];
$INT = [...$CA, 'authorityKeyIdentifier=keyid'];
$LEAF = ['basicConstraints=critical,CA:FALSE', 'keyUsage=critical,digitalSignature', 'extendedKeyUsage=emailProtection', 'subjectKeyIdentifier=hash', 'authorityKeyIdentifier=keyid'];
$TSA = ['basicConstraints=critical,CA:FALSE', 'keyUsage=critical,digitalSignature', 'extendedKeyUsage=critical,timeStamping', 'subjectKeyIdentifier=hash', 'authorityKeyIdentifier=keyid'];
$PAST = ['20200101000000Z', '20210101000000Z'];
$FUTURE = ['20900101000000Z', '21000101000000Z'];
$SHORT = [gmdate('YmdHis\Z', time() - 86400), gmdate('YmdHis\Z', time() + 150)];   // a signer that expires during the run

$base = [
    'root' => tsaCert($CA), 'int' => tsaCert($INT), 'leaf' => tsaCert($LEAF),
    'tsa-root' => tsaCert($CA), 'tsa-int' => tsaCert($INT), 'tsa-leaf' => tsaCert($TSA),
];
/**
 * probe => [certificate changes [who => [field, value]], options]
 * options: trust (both|signer-only|tsa-as-manifest|legacy), header (sigTst2|sigTst|both|none), over (right|wrong),
 * sign (ts|cms)
 *
 * @var array<string, array{0: array<string, array{0: 'ext'|'key'|'md'|'validity', 1: list<string>|string}>, 1: array<string, string>}> $variants
 */
$variants = [
    'control' => [[], []],
    'no-timestamp' => [[], ['header' => 'none']],
    // the timestamp authority's chain
    'tsa-leaf-expired' => [['tsa-leaf' => ['validity', $PAST]], []],
    'tsa-leaf-not-yet-valid' => [['tsa-leaf' => ['validity', $FUTURE]], []],
    'tsa-leaf-no-key-usage' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'keyUsage', null)]], []],
    'tsa-leaf-ca-true' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'basicConstraints', 'critical,CA:TRUE')]], []],
    'tsa-leaf-sha1' => [['tsa-leaf' => ['md', 'sha1']], []],
    'tsa-leaf-rsa2048' => [['tsa-leaf' => ['key', 'rsa2048']], []],
    'tsa-int-expired' => [['tsa-int' => ['validity', $PAST]], []],
    'tsa-int-no-key-usage' => [['tsa-int' => ['ext', tsaReplace($INT, 'keyUsage', null)]], []],
    'tsa-int-ca-false' => [['tsa-int' => ['ext', tsaReplace($INT, 'basicConstraints', 'critical,CA:FALSE')]], []],
    'tsa-root-expired' => [['tsa-root' => ['validity', $PAST]], []],
    'tsa-root-not-yet-valid' => [['tsa-root' => ['validity', $FUTURE]], []],
    // the trust settings
    'tsa-not-configured' => [[], ['trust' => 'signer-only']],
    'tsa-root-as-manifest' => [[], ['trust' => 'tsa-as-manifest']],
    'legacy-string-both' => [[], ['trust' => 'legacy']],
    // the token
    'token-over-wrong-bytes' => [[], ['over' => 'wrong']],
    'header-sigtst-v1' => [[], ['header' => 'sigTst']],
    'header-both' => [[], ['header' => 'both']],
    // a timestamp that must keep an expired signer valid, and one that cannot
    'expired-signer-trusted-tsa' => [['leaf' => ['validity', $SHORT]], []],
    'expired-signer-untrusted-tsa' => [['leaf' => ['validity', $SHORT]], ['trust' => 'signer-only']],
    'expired-signer-no-timestamp' => [['leaf' => ['validity', $SHORT]], ['header' => 'none']],
    // the TSA leaf's extended key usage (step 292), signed through cms
    'control-cms' => [[], ['sign' => 'cms']],
    'tsa-leaf-eku-not-critical' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'extendedKeyUsage', 'timeStamping')]], ['sign' => 'cms']],
    'tsa-leaf-eku-plus-email' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'extendedKeyUsage', 'critical,timeStamping,emailProtection')]], ['sign' => 'cms']],
    'tsa-leaf-eku-email-only' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'extendedKeyUsage', 'critical,emailProtection')]], ['sign' => 'cms']],
    'tsa-leaf-no-eku' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'extendedKeyUsage', null)]], ['sign' => 'cms']],
    'tsa-leaf-eku-any' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'extendedKeyUsage', 'critical,anyExtendedKeyUsage')]], ['sign' => 'cms']],
    'tsa-leaf-eku-plus-ocsp' => [['tsa-leaf' => ['ext', tsaReplace($TSA, 'extendedKeyUsage', 'critical,timeStamping,OCSPSigning')]], ['sign' => 'cms']],
];

$genKey = static function (string $path, string $kind): void {
    match ($kind) {
        'p256' => txRun(txSh('openssl', 'genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256', '-out', $path)),
        'rsa2048' => txRun(txSh('openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $path)),
        default => throw new RuntimeException("unknown key kind {$kind}"),
    };
};
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$manifest = ManifestStore::fromTree((new JumbfParser)->parse($s))->active;
$claimBytes = $manifest->claimBytes();
$COSE = strpos($s, $manifest->signatureBytes());
if ($COSE === false) {
    throw new RuntimeException('no COSE_Sign1 in the store');
}
$COSE_LENGTH = strlen($manifest->signatureBytes());
$issuerOf = ['root' => null, 'int' => 'root', 'leaf' => 'int', 'tsa-root' => null, 'tsa-int' => 'tsa-root', 'tsa-leaf' => 'tsa-int'];
file_put_contents("{$keys}/tsa.cnf", "[tsa]\ndefault_tsa = t\n[t]\nserial = {$keys}/tsa-serial\ncrypto_device = builtin\nsigner_digest = sha256\ndefault_policy = 1.3.6.1.4.1.99999.10\ndigests = sha256\naccuracy = secs:1\ness_cert_id_alg = sha256\ness_cert_id_chain = no\n");
file_put_contents("{$keys}/tsa-serial", "01\n");

if ($set !== '') {
    $unknown = array_diff($only, array_keys($variants));
    if ($only === [] || $unknown !== []) {
        throw new RuntimeException('name the probes of the set; unknown: '.implode(', ', $unknown));
    }
    $variants = array_intersect_key($variants, array_flip($only));
}
$built = [];
$waitUntil = 0;
foreach ($variants as $probe => [$changes, $options]) {
    $recipe = $base;
    foreach ($changes as $who => [$field, $value]) {
        $was = $recipe[$who];
        $recipe[$who] = match ($field) {
            'ext' => tsaCert(is_array($value) ? $value : [$value], $was['key'], $was['md'], $was['validity']),
            'validity' => tsaCert($was['ext'], $was['key'], $was['md'], is_array($value) && count($value) === 2 ? [$value[0], $value[1]] : throw new RuntimeException("{$probe}: a validity is two dates")),
            'key' => tsaCert($was['ext'], is_string($value) ? $value : throw new RuntimeException("{$probe}: a key is a name"), $was['md'], $was['validity']),
            'md' => tsaCert($was['ext'], $was['key'], is_string($value) ? $value : throw new RuntimeException("{$probe}: a digest is a name"), $was['validity']),
        };
        if ($who === 'leaf' && $field === 'validity' && is_array($value)) {
            $waitUntil = max($waitUntil, (int) strtotime(substr((string) $value[1], 0, 8).'T'.substr((string) $value[1], 8, 6).'Z'));
        }
    }
    foreach ($issuerOf as $who => $issuer) {
        $r = $recipe[$who];
        $genKey("{$keys}/{$who}.key", $r['key']);
        file_put_contents("{$keys}/{$who}.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TSA Matrix {$who} ({$probe})\nO=c2pa-verifier timestamp matrix\nC=NL\n[v3]\n".implode("\n", $r['ext'])."\n");
        $dates = $r['validity'] === null ? ['-days', '3650'] : ['-not_before', $r['validity'][0], '-not_after', $r['validity'][1]];
        if ($issuer === null) {
            txRun(txSh(...array_merge(['openssl', 'req', '-new', '-x509', '-'.$r['md'], '-key', "{$keys}/{$who}.key", '-config', "{$keys}/{$who}.cnf", '-extensions', 'v3'], $dates, ['-out', "{$keys}/{$who}.pem"])));
        } else {
            txRun(txSh('openssl', 'req', '-new', '-key', "{$keys}/{$who}.key", '-config', "{$keys}/{$who}.cnf", '-out', "{$keys}/{$who}.csr"));
            txRun(txSh(...array_merge(['openssl', 'x509', '-req', '-'.$r['md'], '-in', "{$keys}/{$who}.csr", '-CA', "{$keys}/{$issuer}.pem", '-CAkey', "{$keys}/{$issuer}.key", '-set_serial', (string) random_int(1000, 99999999)], $dates, ['-extfile', "{$keys}/{$who}.cnf", '-extensions', 'v3', '-out', "{$keys}/{$who}.pem"])));
        }
    }

    // the signature over the claim: {1: -7, 33: [leaf, intermediate]}
    $protected = "\xa2\x01\x26\x18\x21\x82".txBstr($pemToDer((string) file_get_contents("{$keys}/leaf.pem"))).txBstr($pemToDer((string) file_get_contents("{$keys}/int.pem")));
    $draft = "\xd2\x84".txBstr($protected)."\xa1\x63pad".txBstr('')."\xf6".txBstr(str_repeat("\0", 64));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    txRun(txSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = txDerToRs((string) file_get_contents("{$keys}/sig"), 32);

    // the timestamp: an RFC 3161 token over the CounterSignature structure (sigTst2: the signature; sigTst: the claim)
    $header = $options['header'] ?? 'sigTst2';
    $tokenFor = static function (string $payload) use ($keys, $protected, $options, $TSA): string {
        $countersigned = "\x84\x70CounterSignature".txBstr($protected)."\x40".txBstr($payload);
        $digest = hash('sha256', ($options['over'] ?? 'right') === 'wrong' ? $countersigned.'!' : $countersigned);
        txRun(txSh('openssl', 'ts', '-query', '-digest', $digest, '-sha256', '-cert', '-out', "{$keys}/q.tsq"));
        file_put_contents("{$keys}/tsa-chain.pem", (string) file_get_contents("{$keys}/tsa-int.pem"));
        $cms = ($options['sign'] ?? 'ts') === 'cms';
        $tsSigner = "{$keys}/tsa-leaf.pem";
        if ($cms) {
            // a helper certificate for the same key with the base TSA extensions, only for openssl ts to make the TSTInfo
            file_put_contents("{$keys}/tsa-helper.cnf", "[v3]\n".implode("\n", $TSA)."\n");
            txRun(txSh('openssl', 'x509', '-req', '-sha256', '-in', "{$keys}/tsa-leaf.csr", '-CA', "{$keys}/tsa-int.pem", '-CAkey', "{$keys}/tsa-int.key", '-set_serial', (string) random_int(1000, 99999999), '-days', '1', '-extfile', "{$keys}/tsa-helper.cnf", '-extensions', 'v3', '-out', "{$keys}/tsa-helper.pem"));
            $tsSigner = "{$keys}/tsa-helper.pem";
        }
        txRun(txSh('openssl', 'ts', '-reply', '-config', "{$keys}/tsa.cnf", '-queryfile', "{$keys}/q.tsq", '-signer', $tsSigner, '-inkey', "{$keys}/tsa-leaf.key", '-chain', "{$keys}/tsa-chain.pem", '-token_out', '-out', "{$keys}/tok.der"));
        if ($cms) {
            // the same TSTInfo, signed again by the probe's TSA certificate, with signingCertificateV2 (-cades)
            txRun(txSh('openssl', 'cms', '-verify', '-inform', 'DER', '-in', "{$keys}/tok.der", '-noverify', '-binary', '-out', "{$keys}/tstinfo.der"));
            txRun(txSh('openssl', 'cms', '-sign', '-binary', '-nodetach', '-cades', '-md', 'sha256', '-econtent_type', 'id-smime-ct-TSTInfo', '-signer', "{$keys}/tsa-leaf.pem", '-inkey', "{$keys}/tsa-leaf.key", '-certfile', "{$keys}/tsa-chain.pem", '-in', "{$keys}/tstinfo.der", '-outform', 'DER', '-out', "{$keys}/tok.der"));
        }
        file_put_contents("{$keys}/digest", $digest);

        return (string) file_get_contents("{$keys}/tok.der");
    };
    $unprotected = [];
    try {
        if ($header === 'sigTst2' || $header === 'both') {
            $unprotected['sigTst2'] = $tokenFor(txBstr($signature));
        }
        if ($header === 'sigTst' || $header === 'both') {
            $unprotected['sigTst'] = $tokenFor($claimBytes);
        }
        $tokenError = '';
    } catch (RuntimeException $e) {
        $tokenError = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
        printf("%-30s openssl ts refused to build the token: %s\n", $probe, substr($tokenError, 0, 160));

        continue;
    }
    // {"sigTst2": {"tstTokens": [{"val": token}]}, …, "pad": h'00…'}
    $map = '';
    foreach ($unprotected as $name => $token) {
        $map .= ($name === 'sigTst2' ? "\x67sigTst2" : "\x66sigTst")."\xa1\x69tstTokens\x81\xa1\x63val".txBstr($token);
    }
    $count = count($unprotected) + 1;
    $fixed = 2 + strlen(txBstr($protected)) + 1 + strlen($map) + 4 + 3 + 1 + strlen(txBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$probe}: the pad would be {$padLength} bytes");
    }
    $cose = "\xd2\x84".txBstr($protected).chr(0xA0 + $count).$map."\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".txBstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$probe}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    file_put_contents("{$out}/{$probe}.png", pngWithStore($png, substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH)));

    // the settings: the signer's root as a "manifest" anchor, the TSA's root as a "tsa" anchor, unless the probe says otherwise
    $signerRoot = (string) file_get_contents("{$keys}/root.pem");
    $tsaRoot = (string) file_get_contents("{$keys}/tsa-root.pem");
    $entryOf = static fn (string $pem, string $kind): array => ['trust_anchors' => $pem, 'trust_kind' => $kind];
    $trust = match ($options['trust'] ?? 'both') {
        'both' => ['anchors' => [$entryOf($signerRoot, 'manifest'), $entryOf($tsaRoot, 'tsa')]],
        'signer-only' => ['anchors' => [$entryOf($signerRoot, 'manifest')]],
        'tsa-as-manifest' => ['anchors' => [$entryOf($signerRoot, 'manifest'), $entryOf($tsaRoot, 'manifest')]],
        'legacy' => ['trust_anchors' => $signerRoot.$tsaRoot],
        default => throw new RuntimeException("{$probe}: unknown trust option"),
    };
    $settings = ['verify' => ['verify_trust' => true], 'trust' => $trust + ['trust_config' => $storeCfg]];
    file_put_contents("{$out}/{$probe}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    // OpenSSL's answer on the token, against the TSA's root, now
    if ($unprotected === []) {
        $ossl = '-';
    } else {
        file_put_contents("{$keys}/tok.der", reset($unprotected));
        $lines = [];
        exec(txSh('openssl', 'ts', '-verify', '-digest', (string) file_get_contents("{$keys}/digest"), '-in', "{$keys}/tok.der", '-token_in', '-CAfile', "{$keys}/tsa-root.pem", '-untrusted', "{$keys}/tsa-int.pem").' 2>&1', $lines, $code);
        $ossl = $code === 0 ? 'OK' : 'refused: '.trim((string) preg_replace('/^.*?(error|Verification): ?/s', '', implode(' ', array_slice($lines, -3))));
    }
    $built[$probe] = substr($ossl, 0, 90);
}

// the short-lived signers must have expired before anyone judges them
if ($waitUntil > time() - 5) {
    $wait = $waitUntil - time() + 10;
    printf("waiting %d s for the short-lived signers to expire\n", $wait);
    sleep(max(0, $wait));
}

$codes = static function (string $json): string {
    $j = json_decode($json, true);
    if (! is_array($j) || ! is_string($j['validation_state'] ?? null)) {
        return 'error: '.substr(trim((string) preg_replace('/\s+/', ' ', $json)), 0, 80);
    }
    $results = is_array($j['validation_results'] ?? null) ? $j['validation_results'] : [];
    $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
    $failures = [];
    $timestamp = [];
    foreach (['success', 'informational', 'failure'] as $kind) {
        foreach (is_array($active[$kind] ?? null) ? $active[$kind] : [] as $st) {
            $code = is_array($st) && is_string($st['code'] ?? null) ? $st['code'] : '?';
            if ($kind === 'failure') {
                $failures[] = $code;
            }
            if (str_starts_with($code, 'timeStamp.')) {
                $timestamp[] = $code;
            }
        }
    }

    return $j['validation_state'].' ['.implode(',', array_values(array_unique($timestamp))).'] '.implode(',', array_values(array_unique($failures)));
};
$tsv = ["probe\tc2patool-0.27.22\tc2patool-0.28.1\topenssl-ts\tthis-verifier"];
foreach ($built as $probe => $ossl) {
    $row = [$probe];
    foreach (['0.27.22' => $old, '0.28.1' => $new] as $v => $tool) {
        $lines = [];
        exec(txSh($tool, "{$out}/{$probe}.png", '--settings', "{$out}/{$probe}.settings.json").' 2>&1', $lines);
        $row[] = $codes(implode("\n", $lines));
        if ($oracles !== null) {
            file_put_contents("{$oracles}/{$probe}--{$v}.json", implode("\n", $lines)."\n");
            $bare = [];
            exec(txSh($tool, "{$out}/{$probe}.png").' 2>&1', $bare);
            file_put_contents("{$oracles}/{$probe}--{$v}--no-settings.json", implode("\n", $bare)."\n");
        }
    }
    $row[] = $ossl;
    $lines = [];
    exec(txSh(PHP_BINARY, $root.'/bin/c2pa-verify', "{$out}/{$probe}.png", '--settings', "{$out}/{$probe}.settings.json").' 2>&1', $lines);
    $row[] = $codes(implode("\n", $lines));
    $tsv[] = implode("\t", $row);
    printf("%-30s %s\n", $probe, implode(' | ', array_slice($row, 1)));
}
if ($set === '') {
    file_put_contents("{$out}/matrix.tsv", implode("\n", $tsv)."\n");
}
