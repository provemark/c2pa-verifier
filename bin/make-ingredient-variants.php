<?php

declare(strict_types=1);

/*
 * Step 54 (SPEC-020 AC2, AC4): signed variants of the PNG fixture with one
 * ingredient assertion *added* — an assertion box in the assertion store,
 * an entry in the claim's created_assertions, the hard binding re-bound and
 * the claim re-signed with a throw-away P-256 hierarchy, exactly as
 * bin/make-absence-variants.php (step 48) does. Keys live outside the
 * repository for the run and are deleted before it ends; the public root
 * goes into a settings file. Decided by Maurice van Loon on 2026-09-21:
 * tooling may sign with throw-away keys; the product never signs.
 *
 * Variants (each measured against c2patool 0.27.22 in the note):
 *   componentof             a well-formed v3 ingredient without a manifest: the control (ingredient.unknownProvenance)
 *   inputto                 the same with relationship inputTo (AC4: no unknownProvenance)
 *   no-relationship         v3 without relationship                                   (AC2 a)
 *   relationship-childof    relationship "childOf"                                    (AC2 b)
 *   relationship-int        relationship 42                                           (AC2 c)
 *   v4                      label c2pa.ingredient.v4                                  (AC2 d)
 *   v1-no-title             c2pa.ingredient without dc:title                          (AC2 e)
 *   manifest-no-results     v3 with activeManifest and no validationResults           (AC2 f)
 *   manifest-and-dst        v3 with activeManifest next to digitalSourceType          (AC2 g)
 *   hash-text               v3 whose activeManifest hash is a text string             (AC2 h)
 *   data-array              the assertion's content is a CBOR array                   (AC2 i)
 *
 * Usage: php bin/make-ingredient-variants.php <scratch-dir>. Tooling.
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
$scratch = $argv[1] ?? null;
if (! is_string($scratch) || ! is_dir($scratch)) {
    fwrite(STDERR, "usage: php bin/make-ingredient-variants.php <scratch-dir outside the repository>\n");
    exit(1);
}
$keys = rtrim($scratch, '/').'/ingredient-keys-'.bin2hex(random_bytes(4));
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

function ingRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function ingSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A byte string to be encoded as CBOR major type 2 (a PHP string encodes as text). */
final class IngBytes
{
    public function __construct(public string $bytes) {}
}

/** The CBOR head for major type $mt and argument $n. */
function ingHead(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, bytes (IngBytes), null, bool, lists and text-keyed maps in the order given. */
function ingCbor(mixed $v): string
{
    return match (true) {
        $v instanceof IngBytes => ingHead(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => ingHead(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? ingHead(0, $v) : ingHead(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) && array_is_list($v) => ingHead(4, count($v)).implode('', array_map('ingCbor', $v)),
        is_array($v) => ingHead(5, count($v)).implode('', array_map(static fn (string $k, mixed $x): string => ingCbor($k).ingCbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/** An assertion superbox: jumd (cbor UUID, toggles 3: requestable + label) and a cbor content box. */
function ingAssertionBox(string $label, string $payload): string
{
    $uuid = hex2bin('63626f72001100108000'.'00aa00389b71');
    $jumd = 'jumd'.$uuid."\x03".$label."\0";
    $jumd = pack('N', 4 + strlen($jumd)).$jumd;
    $cbor = pack('N', 8 + strlen($payload)).'cbor'.$payload;
    $box = 'jumb'.$jumd.$cbor;

    return pack('N', 4 + strlen($box)).$box;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function ingDerToRs(string $der, int $curveBytes): string
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

/**
 * The hash of $bytes with the [start, length] ranges skipped — what c2pa.hash.data covers (C2PA 2.4 §15.12.1).
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function ingDataHash(string $bytes, array $exclusions): string
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
function ingEntryFor(string $s, int $list, string $label): ?int
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
 * The hard binding re-bound after the store grew by $grown bytes before the hash.data box and the claim: the
 * exclusion re-lengthened, the data hash recomputed over the PNG that will carry the store, the claim's hashed
 * URI for it recomputed (as bin/make-absence-variants.php rebind()).
 */
function ingRebind(string $store, string $png, int $grown, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox + $grown;
    $payload = $box + 80;
    $hd = bMapPairs($store, $payload);
    $exclusionMap = $hd['exclusions'][1] + 1;
    $ex = bMapPairs($store, $exclusionMap);
    $lengthValue = $ex['length'][1];
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $digest = ingDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $boxLength = bU32($store, $box);
    $uri = hash('sha256', substr($store, $box + 8, $boxLength - 8), true);
    $claim = bMapPairs($store, $claimAt + $grown);
    $entry = ingEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data');
    if ($entry === null) {
        throw new RuntimeException('no c2pa.hash.data entry in the claim');
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
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
$FIRST_ASSERTION_BOX = 166;  // the thumbnail: the new box goes in front of it, so every later offset moves by its length
$HASH_DATA_BOX = 32831;      // the c2pa.hash.data assertion box, 195 bytes
$CLAIM = 33081;              // the claim's CBOR map (7 pairs, 591 bytes)
$storeBox = [0, 38, 117];    // the boxes enclosing an assertion: store superbox, c2pa manifest superbox, assertion store superbox
$claimBox = [0, 38, 33026, 33073];
if (substr($s, $HASH_DATA_BOX + 4, 4) !== 'jumb' || ord($s[$CLAIM]) !== 0xA7 || substr($s, $FIRST_ASSERTION_BOX + 4, 4) !== 'jumb') {
    throw new RuntimeException('the store is not laid out as step 09 measured');
}
$claim = bMapPairs($s, $CLAIM);
[, $createdStart, $createdEnd] = $claim['created_assertions'];     // 81 { c2pa.hash.data }
if ($s[$createdStart] !== "\x81") {
    throw new RuntimeException('created_assertions is not the one-entry list measured');
}

$missing = 'self#jumbf=/c2pa/urn:c2pa:00000000-0000-0000-0000-000000000000:nowhere';
$manifestRef = ['url' => $missing, 'hash' => new IngBytes(str_repeat("\x11", 32))];
$results = ['activeManifest' => ['success' => [], 'informational' => [], 'failure' => []], 'ingredientDeltas' => []];
$v3 = ['dc:title' => 'ingredient.png', 'dc:format' => 'image/png', 'instanceID' => 'xmp:iid:00000000-0000-0000-0000-000000000001'];

/** @var array<string, array{0: string, 1: string}> label => [label, payload] */
$variants = [
    'componentof' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 'componentOf'] + $v3)],
    'inputto' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 'inputTo'] + $v3)],
    'no-relationship' => ['c2pa.ingredient.v3', ingCbor($v3)],
    'relationship-childof' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 'childOf'] + $v3)],
    'relationship-int' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 42] + $v3)],
    'v4' => ['c2pa.ingredient.v4', ingCbor(['relationship' => 'componentOf'] + $v3)],
    'v1-no-title' => ['c2pa.ingredient', ingCbor(['dc:format' => 'image/png', 'instanceID' => $v3['instanceID'], 'relationship' => 'componentOf'])],
    'manifest-no-results' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 'componentOf', 'activeManifest' => $manifestRef] + $v3)],
    'manifest-and-dst' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 'componentOf', 'activeManifest' => $manifestRef, 'validationResults' => $results, 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia'] + $v3)],
    'hash-text' => ['c2pa.ingredient.v3', ingCbor(['relationship' => 'componentOf', 'activeManifest' => ['url' => $missing, 'hash' => 'not bytes'], 'validationResults' => $results] + $v3)],
    'data-array' => ['c2pa.ingredient.v3', ingCbor([1, 2])],
];

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$cnf = <<<'CNF'
[req]
distinguished_name = dn
[dn]
[v3_root]
basicConstraints = critical, CA:TRUE
keyUsage = critical, keyCertSign, cRLSign, digitalSignature
subjectKeyIdentifier = hash
[good]
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, nonRepudiation
extendedKeyUsage = emailProtection
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);
ingRun(ingSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
ingRun(ingSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (ingredients)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
ingRun(ingSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
ingRun(ingSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=ingredients', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
ingRun(ingSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$dir = $root.'/tests/Fixtures/ingredient';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
file_put_contents("{$dir}/throw-away-root.pem", $rootPem);
$settings = ['trust' => ['trust_anchors' => $rootPem, 'trust_config' => (string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg')], 'verify' => ['verify_trust' => true]];
file_put_contents("{$dir}/throw-away-root.settings.json", json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

foreach ($variants as $name => [$label, $payload]) {
    // 1. the claim entry: created_assertions = [hash.data, the new assertion]; the claim grows, the enclosing boxes with it
    $box = ingAssertionBox($label, $payload);
    $entry = ingCbor(['url' => 'self#jumbf=c2pa.assertions/'.$label, 'hash' => new IngBytes(hash('sha256', substr($box, 8), true))]);
    $edited = bSplice($s, $createdStart, $createdEnd - $createdStart, "\x82".substr($s, $createdStart + 1, $createdEnd - $createdStart - 1).$entry, $claimBox);
    // 2. the assertion box, in front of the thumbnail: everything after it moves by its length
    $edited = bSplice($edited, $FIRST_ASSERTION_BOX, 0, $box, $storeBox);
    $grown = strlen($box);
    // 3. the hard binding re-bound to the new store
    $edited = ingRebind($edited, $png, $grown, $HASH_DATA_BOX, $CLAIM);

    // 4. the claim bytes and the COSE box, re-signed
    $claimAt = $CLAIM + $grown;
    $claimBytes = substr($edited, $claimAt, bCborEnd($edited, $claimAt) - $claimAt);
    $COSE = strpos($edited, "\xd2\x84", $claimAt);
    if ($COSE === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1");
    }
    $COSE_LENGTH = bU32($edited, $COSE - 8) - 8;
    $protected = "\xa2\x01\x26\x18\x21\x82".ingCbor(new IngBytes($leafDer)).ingCbor(new IngBytes($rootDer));
    $draft = "\xd2\x84".ingCbor(new IngBytes($protected))."\xa1\x63pad".ingCbor(new IngBytes(''))."\xf6".ingCbor(new IngBytes(str_repeat("\0", 64)));
    $sigStructure = CoseSign1::fromBytes($draft)->sigStructure($claimBytes);
    file_put_contents("{$keys}/tbs", $sigStructure);
    ingRun(ingSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = ingDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(ingCbor(new IngBytes($protected))) + 5 + 3 + 1 + strlen(ingCbor(new IngBytes($signature)));
    $padLength = $COSE_LENGTH - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".ingCbor(new IngBytes($protected))."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".ingCbor(new IngBytes($signature));
    if (strlen($cose) !== $COSE_LENGTH) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$COSE_LENGTH}");
    }
    $store = substr($edited, 0, $COSE).$cose.substr($edited, $COSE + $COSE_LENGTH);
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }
    // the store still parses (the assertion's content is read lazily by SPEC-020, so even data-array parses here)
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    if (! array_key_exists($label, $manifest->assertions)) {
        throw new RuntimeException("{$name}: the store does not carry {$label}");
    }
    file_put_contents("{$dir}/{$name}.bin", $store);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %d bytes  %-22s %s (%d-byte content)\n", hash('sha256', $store), strlen($store), $name, $label, strlen($payload));
}
