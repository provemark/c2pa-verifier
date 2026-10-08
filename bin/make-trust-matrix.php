<?php

declare(strict_types=1);

/*
 * The trust matrix (step 283): one valid chain, leaf <- intermediate <- anchor, and
 * variants in which exactly one property of one certificate differs. Each probe is the
 * PNG fixture's store with its claim re-signed by the probe's leaf (the store's own
 * COSE_Sign1 replaced, its length kept), so no signer's own checks decide what can be
 * built. Every probe is judged by c2patool 0.27.22 and 0.28.1, by `openssl verify`
 * and by this verifier, under settings that hold only the probe's anchor.
 *
 * Keys live in a scratch directory for the run and are deleted before it ends; nothing
 * here writes into tests/Fixtures. Tooling, not the verification path.
 *
 * Usage: php bin/make-trust-matrix.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22> [<set> <probe>…]
 * Without a set: every probe into <scratch-dir>/trust-matrix/ (<probe>.png,
 * <probe>.settings.json, the public certificates, matrix.tsv). With a set: only the
 * named probes, as fixtures, into tests/Fixtures/trust/<set>/, with both c2patool
 * versions' JSON in tests/Fixtures/c2patool/<set>/<probe>--<version>.json.
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
    fwrite(STDERR, "usage: php bin/make-trust-matrix.php <scratch-dir> <c2patool-0.28.1> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/trust-matrix-keys-'.bin2hex(random_bytes(4));
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

function tmRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function tmSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function tmBstr(string $b): string
{
    $n = strlen($b);

    return ($n < 24 ? chr(0x40 + $n) : ($n < 256 ? "\x58".chr($n) : "\x59".pack('n', $n))).$b;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function tmDerToRs(string $der, int $curveBytes): string
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
function tmAbsenceDataHash(string $bytes, array $exclusions): string
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
function tmEntryFor(string $s, int $list, string $label): ?int
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
function tmRebind(string $store, string $png, int $shift, int $hashDataBox, int $claimAt): string
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
    $digest = tmAbsenceDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt - $shift);
    $entry = tmEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data') ?? tmEntryFor($store, $claim['gathered_assertions'][1], 'c2pa.hash.data');
    if ($entry === null) {
        throw new RuntimeException('no c2pa.hash.data entry in the claim');
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

/** The claim's hashed URI for the assertion box at $box recomputed (sha256 over the box minus its 8-byte header, §8.4.2.3). */
function tmRehashEntry(string $store, int $box, int $claimAt, string $label): string
{
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt);
    $entry = tmEntryFor($store, $claim['created_assertions'][1], $label) ?? tmEntryFor($store, $claim['gathered_assertions'][1], $label);
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
function matrixReplace(array $ext, string $prefix, ?string $with): array
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
function matrixCert(array $ext, string $key = 'p256', string $md = 'sha256', ?array $validity = null): array
{
    return ['ext' => $ext, 'key' => $key, 'md' => $md, 'validity' => $validity];
}

// ---- the matrix ----------------------------------------------------------------------------------

$out = $set === '' ? rtrim((string) $scratch, '/').'/trust-matrix' : $root.'/tests/Fixtures/trust/'.$set;
$oracles = $set === '' ? null : $root.'/tests/Fixtures/c2patool/'.$set;
if ($oracles !== null && ! is_dir($oracles) && ! mkdir($oracles, 0755, true)) {
    throw new RuntimeException("cannot create {$oracles}");
}
if (! is_dir($out) && ! mkdir($out, 0755, true)) {
    throw new RuntimeException("cannot create {$out}");
}
$storeCfg = (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg');
$manifest = ManifestStore::fromTree((new JumbfParser)->parse($s))->active;
$claimBytes = $manifest->claimBytes();
$COSE = strpos($s, $manifest->signatureBytes());
if ($COSE === false) {
    throw new RuntimeException('no COSE_Sign1 in the store');
}
$COSE_LENGTH = strlen($manifest->signatureBytes());

$CA = ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign', 'subjectKeyIdentifier=hash'];
$LEAF = ['basicConstraints=critical,CA:FALSE', 'keyUsage=critical,digitalSignature', 'extendedKeyUsage=emailProtection', 'subjectKeyIdentifier=hash', 'authorityKeyIdentifier=keyid'];
$PAST = ['20200101000000Z', '20210101000000Z'];
$FUTURE = ['20900101000000Z', '21000101000000Z'];
$int = [...$CA, 'authorityKeyIdentifier=keyid'];
$base = ['anchor' => matrixCert($CA), 'int' => matrixCert($int), 'leaf' => matrixCert($LEAF)];
/** @var array<string, array{0: 'anchor'|'int'|'leaf'|null, 1: 'ext'|'key'|'md'|'validity'|null, 2: list<string>|string|null}> $variants  probe => [position, field, value] */
$variants = [
    'control' => [null, null, null],
    // the leaf
    'leaf-expired' => ['leaf', 'validity', $PAST],
    'leaf-not-yet-valid' => ['leaf', 'validity', $FUTURE],
    'leaf-ca-true' => ['leaf', 'ext', matrixReplace($LEAF, 'basicConstraints', 'critical,CA:TRUE')],
    'leaf-no-basic-constraints' => ['leaf', 'ext', matrixReplace($LEAF, 'basicConstraints', null)],
    'leaf-no-key-usage' => ['leaf', 'ext', matrixReplace($LEAF, 'keyUsage', null)],
    'leaf-ku-key-encipherment' => ['leaf', 'ext', matrixReplace($LEAF, 'keyUsage', 'critical,keyEncipherment')],
    'leaf-ku-cert-sign' => ['leaf', 'ext', matrixReplace($LEAF, 'keyUsage', 'critical,digitalSignature,keyCertSign')],
    'leaf-no-eku' => ['leaf', 'ext', matrixReplace($LEAF, 'extendedKeyUsage', null)],
    'leaf-eku-any' => ['leaf', 'ext', matrixReplace($LEAF, 'extendedKeyUsage', 'anyExtendedKeyUsage')],
    'leaf-eku-time-stamping' => ['leaf', 'ext', matrixReplace($LEAF, 'extendedKeyUsage', 'critical,timeStamping')],
    'leaf-eku-server-auth' => ['leaf', 'ext', matrixReplace($LEAF, 'extendedKeyUsage', 'serverAuth')],
    'leaf-eku-unknown-oid' => ['leaf', 'ext', matrixReplace($LEAF, 'extendedKeyUsage', '1.3.6.1.4.1.99999.7')],
    'leaf-critical-unknown-ext' => ['leaf', 'ext', [...$LEAF, '1.3.6.1.4.1.99999.8=critical,ASN1:NULL']],
    'leaf-sha1' => ['leaf', 'md', 'sha1'],
    'leaf-p384' => ['leaf', 'key', 'p384'],
    'leaf-rsa2048' => ['leaf', 'key', 'rsa2048'],
    'leaf-rsa1024' => ['leaf', 'key', 'rsa1024'],
    'leaf-ed25519' => ['leaf', 'key', 'ed25519'],
    // the intermediate
    'int-expired' => ['int', 'validity', $PAST],
    'int-not-yet-valid' => ['int', 'validity', $FUTURE],
    'int-ca-false' => ['int', 'ext', matrixReplace($int, 'basicConstraints', 'critical,CA:FALSE')],
    'int-no-basic-constraints' => ['int', 'ext', matrixReplace($int, 'basicConstraints', null)],
    'int-no-key-usage' => ['int', 'ext', matrixReplace($int, 'keyUsage', null)],
    'int-ku-no-cert-sign' => ['int', 'ext', matrixReplace($int, 'keyUsage', 'critical,digitalSignature,cRLSign')],
    'int-pathlen-0' => ['int', 'ext', matrixReplace($int, 'basicConstraints', 'critical,CA:TRUE,pathlen:0')],
    'int-eku-time-stamping' => ['int', 'ext', [...$int, 'extendedKeyUsage=timeStamping']],
    'int-eku-email' => ['int', 'ext', [...$int, 'extendedKeyUsage=emailProtection']],
    'int-critical-unknown-ext' => ['int', 'ext', [...$int, '1.3.6.1.4.1.99999.8=critical,ASN1:NULL']],
    'int-sha1' => ['int', 'md', 'sha1'],
    'int-rsa1024' => ['int', 'key', 'rsa1024'],
    'int-rsa2048' => ['int', 'key', 'rsa2048'],
    // the anchor
    'anchor-expired' => ['anchor', 'validity', $PAST],
    'anchor-not-yet-valid' => ['anchor', 'validity', $FUTURE],
    'anchor-ca-false' => ['anchor', 'ext', matrixReplace($CA, 'basicConstraints', 'critical,CA:FALSE')],
    'anchor-no-basic-constraints' => ['anchor', 'ext', matrixReplace($CA, 'basicConstraints', null)],
    'anchor-no-key-usage' => ['anchor', 'ext', matrixReplace($CA, 'keyUsage', null)],
    'anchor-ku-no-cert-sign' => ['anchor', 'ext', matrixReplace($CA, 'keyUsage', 'critical,digitalSignature,cRLSign')],
    'anchor-pathlen-0' => ['anchor', 'ext', matrixReplace($CA, 'basicConstraints', 'critical,CA:TRUE,pathlen:0')],
    'anchor-critical-unknown-ext' => ['anchor', 'ext', [...$CA, '1.3.6.1.4.1.99999.8=critical,ASN1:NULL']],
    'anchor-sha1-self-signed' => ['anchor', 'md', 'sha1'],
    'anchor-rsa1024' => ['anchor', 'key', 'rsa1024'],
];

$genKey = static function (string $path, string $kind): void {
    match ($kind) {
        'p256' => tmRun(tmSh('openssl', 'genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-256', '-out', $path)),
        'p384' => tmRun(tmSh('openssl', 'genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:P-384', '-out', $path)),
        'rsa2048' => tmRun(tmSh('openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $path)),
        'rsa1024' => tmRun(tmSh('openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:1024', '-out', $path)),
        'ed25519' => tmRun(tmSh('openssl', 'genpkey', '-algorithm', 'ED25519', '-out', $path)),
        default => throw new RuntimeException("unknown key kind {$kind}"),
    };
};
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$state = static function (string $json): string {
    $j = json_decode($json, true);
    if (! is_array($j) || ! is_string($j['validation_state'] ?? null)) {
        return 'error';
    }
    $results = is_array($j['validation_results'] ?? null) ? $j['validation_results'] : [];
    $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
    $codes = [];
    foreach (is_array($active['failure'] ?? null) ? $active['failure'] : [] as $f) {
        $codes[] = is_array($f) && is_string($f['code'] ?? null) ? $f['code'] : '?';
    }

    return $j['validation_state'].' '.implode(',', array_values(array_unique($codes)));
};

$tsv = ["probe\tc2patool-0.27.22\tc2patool-0.28.1\topenssl\tthis-verifier"];
if ($set !== '') {
    $unknown = array_diff($only, array_keys($variants));
    if ($only === [] || $unknown !== []) {
        throw new RuntimeException('name the probes of the set; unknown: '.implode(', ', $unknown));
    }
    $variants = array_intersect_key($variants, array_flip($only));
}
foreach ($variants as $probe => [$position, $field, $value]) {
    $recipe = $base;
    if ($position !== null && $field !== null && $value !== null) {
        $was = $recipe[$position];
        $recipe[$position] = match ($field) {
            'ext' => matrixCert(is_array($value) ? $value : [$value], $was['key'], $was['md'], $was['validity']),
            'validity' => matrixCert($was['ext'], $was['key'], $was['md'], is_array($value) && count($value) === 2 ? [$value[0], $value[1]] : throw new RuntimeException("{$probe}: a validity is two dates")),
            'key' => matrixCert($was['ext'], is_string($value) ? $value : throw new RuntimeException("{$probe}: a key is a name"), $was['md'], $was['validity']),
            'md' => matrixCert($was['ext'], $was['key'], is_string($value) ? $value : throw new RuntimeException("{$probe}: a digest is a name"), $was['validity']),
        };
    }
    $cn = ['anchor' => "Matrix Anchor ({$probe})", 'int' => "Matrix Intermediate ({$probe})", 'leaf' => "Matrix Signer ({$probe})"];
    $issuer = ['anchor' => null, 'int' => 'anchor', 'leaf' => 'int'];
    foreach (['anchor', 'int', 'leaf'] as $who) {
        $r = $recipe[$who];
        $genKey("{$keys}/{$who}.key", $r['key']);
        file_put_contents("{$keys}/{$who}.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN={$cn[$who]}\nO=c2pa-verifier trust matrix\nC=NL\n[v3]\n".implode("\n", $r['ext'])."\n");
        $dates = $r['validity'] === null ? ['-days', '3650'] : ['-not_before', $r['validity'][0], '-not_after', $r['validity'][1]];
        // the digest is the issuer's choice; an Ed25519 issuer signs without one
        $issuerKey = $issuer[$who] === null ? $r['key'] : $recipe[$issuer[$who]]['key'];
        $md = $issuerKey === 'ed25519' ? [] : ['-'.$r['md']];
        if ($issuer[$who] === null) {
            tmRun(tmSh(...array_merge(['openssl', 'req', '-new', '-x509'], $md, ['-key', "{$keys}/{$who}.key", '-config', "{$keys}/{$who}.cnf", '-extensions', 'v3'], $dates, ['-out', "{$keys}/{$who}.pem"])));
        } else {
            tmRun(tmSh('openssl', 'req', '-new', '-key', "{$keys}/{$who}.key", '-config', "{$keys}/{$who}.cnf", '-out', "{$keys}/{$who}.csr"));
            tmRun(tmSh(...array_merge(['openssl', 'x509', '-req'], $md, ['-in', "{$keys}/{$who}.csr", '-CA', "{$keys}/{$issuer[$who]}.pem", '-CAkey', "{$keys}/{$issuer[$who]}.key", '-set_serial', (string) random_int(1000, 99999999)], $dates, ['-extfile', "{$keys}/{$who}.cnf", '-extensions', 'v3', '-out', "{$keys}/{$who}.pem"])));
        }
        if ($set === '' || $who === 'anchor') {
            copy("{$keys}/{$who}.pem", "{$out}/{$probe}.{$who}.pem");
        }
    }

    // the COSE_Sign1: {1: alg, 33: [leaf, intermediate]}, the anchor left out, signed over the claim
    $leafKey = $recipe['leaf']['key'];
    $alg = ['p256' => "\x26", 'p384' => "\x38\x22", 'rsa2048' => "\x38\x24", 'rsa1024' => "\x38\x24", 'ed25519' => "\x27"][$leafKey];
    $protected = "\xa2\x01".$alg."\x18\x21\x82".tmBstr($pemToDer((string) file_get_contents("{$keys}/leaf.pem"))).tmBstr($pemToDer((string) file_get_contents("{$keys}/int.pem")));
    $sigLength = ['p256' => 64, 'p384' => 96, 'rsa2048' => 256, 'rsa1024' => 128, 'ed25519' => 64][$leafKey];
    $draft = "\xd2\x84".tmBstr($protected)."\xa1\x63pad".tmBstr('')."\xf6".tmBstr(str_repeat("\0", $sigLength));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    match ($leafKey) {
        'p256', 'p384' => tmRun(tmSh('openssl', 'dgst', $leafKey === 'p256' ? '-sha256' : '-sha384', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs")),
        'rsa2048', 'rsa1024' => tmRun(tmSh('openssl', 'dgst', '-sha256', '-sigopt', 'rsa_padding_mode:pss', '-sigopt', 'rsa_pss_saltlen:32', '-sigopt', 'rsa_mgf1_md:sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs")),
        'ed25519' => tmRun(tmSh('openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', "{$keys}/leaf.key", '-in', "{$keys}/tbs", '-out', "{$keys}/sig")),
        default => throw new RuntimeException("unknown key kind {$leafKey}"),
    };
    $raw = (string) file_get_contents("{$keys}/sig");
    $signature = in_array($leafKey, ['p256', 'p384'], true) ? tmDerToRs($raw, $leafKey === 'p256' ? 32 : 48) : $raw;
    $fixed = 2 + strlen(tmBstr($protected)) + 5 + 3 + 1 + strlen(tmBstr($signature));
    $padLength = $COSE_LENGTH - $fixed;
    $cose = "\xd2\x84".tmBstr($protected)."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".tmBstr($signature);
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$probe}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    $store = substr($s, 0, $COSE).$cose.substr($s, $COSE + $COSE_LENGTH);
    file_put_contents("{$out}/{$probe}.png", pngWithStore($png, $store));
    $settings = ['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => (string) file_get_contents("{$keys}/anchor.pem"), 'trust_config' => $storeCfg]];
    file_put_contents("{$out}/{$probe}.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    // the judges
    $row = [$probe];
    foreach (['0.27.22' => $old, '0.28.1' => $new] as $v => $tool) {
        $lines = [];
        exec(tmSh($tool, "{$out}/{$probe}.png", '--settings', "{$out}/{$probe}.settings.json").' 2>&1', $lines);
        $row[] = $state(implode("\n", $lines));
        if ($oracles !== null) {
            file_put_contents("{$oracles}/{$probe}--{$v}.json", implode("\n", $lines)."\n");
        }
    }
    $lines = [];
    exec(tmSh('openssl', 'verify', '-x509_strict', '-partial_chain', '-CAfile', "{$keys}/anchor.pem", '-untrusted', "{$keys}/int.pem", "{$keys}/leaf.pem").' 2>&1', $lines, $code);
    $row[] = $code === 0 ? 'OK' : 'refused: '.trim((string) preg_replace('/^.*?error \d+ at \d+ depth lookup: /s', '', implode(' ', $lines)));
    $lines = [];
    exec(tmSh(PHP_BINARY, $root.'/bin/c2pa-verify', "{$out}/{$probe}.png", '--settings', "{$out}/{$probe}.settings.json").' 2>&1', $lines);
    $row[] = $state(implode("\n", $lines));
    $tsv[] = implode("\t", $row);
    printf("%-30s %s\n", $probe, implode(' | ', array_slice($row, 1)));
}
if ($set === '') {
    file_put_contents("{$out}/matrix.tsv", implode("\n", $tsv)."\n");
}
