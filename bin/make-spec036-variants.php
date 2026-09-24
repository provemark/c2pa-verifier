<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-036 (step 130a): the PNG fixture whose claim gains a
 * redacted_assertions naming a hard-binding assertion, on the route of
 * bin/make-ingredient-manifest-variants.php's `redacted` (whose helpers are copied here, so that
 * running this script regenerates nothing of step 56): the claim map grows one pair, the data hash
 * is rebound, the claim is re-signed under a throw-away root. Keys live outside the repository for
 * the run and are deleted before it ends. Both c2patool versions then judge every variant.
 *
 *   hash-data-relative     self#jumbf=c2pa.assertions/c2pa.hash.data           (AC1)
 *   hash-data-absolute     self#jumbf=/c2pa/<label>/c2pa.assertions/c2pa.hash.data (AC2)
 *   hash-boxes-relative    self#jumbf=c2pa.assertions/c2pa.hash.boxes          (AC3)
 *   hash-bmff-relative     self#jumbf=c2pa.assertions/c2pa.hash.bmff.v2        (AC3)
 *   hash-collection-relative self#jumbf=c2pa.assertions/c2pa.hash.collection.data (AC3)
 *
 * Usage: php bin/make-spec036-variants.php <c2patool-0.28.0> <c2patool-0.27.22>
 * Writes:
 *   tests/Fixtures/hard-binding-redacted/<variant>.png, probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/hard-binding-redacted/<variant>--<version>.json (or .error.txt)
 * Tooling.
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
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.0' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec036-variants.php <c2patool-0.28.0> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = sys_get_temp_dir().'/c2pa-spec036-'.bin2hex(random_bytes(6));
if (! mkdir($keys, 0o700)) {
    throw new RuntimeException("cannot create {$keys}");
}
register_shutdown_function(static function () use ($keys): void {
    foreach (glob("{$keys}/*") ?: [] as $file) {
        if (str_ends_with($file, '.key')) {
            file_put_contents($file, str_repeat("\0", (int) filesize($file)));
        }
        unlink($file);
    }
    if (is_dir($keys)) {
        rmdir($keys);
    }
    if (file_exists($keys)) {
        fwrite(STDERR, "a key directory survived: {$keys}\n");
        exit(1);
    }
    echo "keys deleted\n";
});

function imRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function imSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A byte string to be encoded as CBOR major type 2 (a PHP string encodes as text). */
final class ImBytes
{
    public function __construct(public string $bytes) {}
}

/** The CBOR head for major type $mt and argument $n. */
function imHead(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, bytes (ImBytes), null, bool, lists and text-keyed maps in the order given. */
function imCbor(mixed $v): string
{
    return match (true) {
        $v instanceof ImBytes => imHead(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => imHead(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? imHead(0, $v) : imHead(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) && array_is_list($v) => imHead(4, count($v)).implode('', array_map('imCbor', $v)),
        is_array($v) => imHead(5, count($v)).implode('', array_map(static fn (string $k, mixed $x): string => imCbor($k).imCbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/** An assertion superbox: jumd (cbor UUID, toggles 3: requestable + label) and a cbor content box. */
function imAssertionBox(string $label, string $payload): string
{
    $uuid = (string) hex2bin('63626f72001100108000'.'00aa00389b71');
    $jumd = 'jumd'.$uuid."\x03".$label."\0";
    $jumd = pack('N', 4 + strlen($jumd)).$jumd;
    $cbor = pack('N', 8 + strlen($payload)).'cbor'.$payload;
    $box = 'jumb'.$jumd.$cbor;

    return pack('N', 4 + strlen($box)).$box;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function imDerToRs(string $der, int $curveBytes): string
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
 * The hash of $bytes with the [start, length] ranges skipped — what c2pa.hash.data covers.
 *
 * @param  list<array{0: int, 1: int}>  $exclusions
 */
function imDataHash(string $bytes, array $exclusions): string
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
function imEntryFor(string $s, int $list, string $label): ?int
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

/** The PNG fixture's hard binding re-bound after the store grew by $grown bytes before the hash.data box. */
function imRebind(string $store, string $png, int $grown, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox + $grown;
    $hd = bMapPairs($store, $box + 80);
    $ex = bMapPairs($store, $hd['exclusions'][1] + 1);
    $lengthValue = $ex['length'][1];
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $digest = imDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $uri = hash('sha256', substr($store, $box + 8, bU32($store, $box) - 8), true);
    $claim = bMapPairs($store, $claimAt + $grown);
    $entry = imEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data');
    if ($entry === null) {
        throw new RuntimeException('no c2pa.hash.data entry in the claim');
    }
    $claimHash = bMapPairs($store, $entry)['hash'][1] + 2;

    return bReplace($store, $claimHash, substr($store, $claimHash, 32), $uri);
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$root = dirname(__DIR__);
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
imRun(imSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
imRun(imSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (SPEC-036)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
imRun(imSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
imRun(imSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=SPEC-036 probes', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
imRun(imSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$out = $root.'/tests/Fixtures/hard-binding-redacted';
$oracles = $root.'/tests/Fixtures/c2patool/hard-binding-redacted';
foreach ([$out, $oracles] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
        throw new RuntimeException("cannot create {$dir}");
    }
}
file_put_contents("{$out}/probe-root.pem", $rootPem);
$settings = "{$out}/probe-root.settings.json";
file_put_contents($settings, json_encode([
    'trust' => ['trust_anchors' => $rootPem, 'trust_config' => rtrim((string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg'))."\n"],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

/** Sign $claimBytes with the throw-away leaf and pad the COSE_Sign1 to exactly $length bytes. */
function imCose(string $claimBytes, int $length, string $leafDer, string $rootDer, string $keys, string $name): string
{
    $protected = "\xa2\x01\x26\x18\x21\x82".imCbor(new ImBytes($leafDer)).imCbor(new ImBytes($rootDer));
    $draft = "\xd2\x84".imCbor(new ImBytes($protected))."\xa1\x63pad".imCbor(new ImBytes(''))."\xf6".imCbor(new ImBytes(str_repeat("\0", 64)));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    imRun(imSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = imDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(imCbor(new ImBytes($protected))) + 5 + 3 + 1 + strlen(imCbor(new ImBytes($signature)));
    $padLength = $length - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".imCbor(new ImBytes($protected))."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".imCbor(new ImBytes($signature));
    if (strlen($cose) !== $length) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$length}");
    }
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }

    return $cose;
}

// ---- the variants, from the PNG fixture (the offsets measured in step 09, as step 56 uses them) ----
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$pngStream = fopen($root.'/tests/Fixtures/fixture-signed.png', 'rb');
if ($pngStream === false) {
    throw new RuntimeException('cannot open the PNG fixture');
}
$pngExtracted = (new PngManifestStoreExtractor)->extract($pngStream);
if ($pngExtracted === null || strlen($pngExtracted->bytes) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$p = $pngExtracted->bytes;
$label = ManifestStore::fromTree((new JumbfParser)->parse($p))->active->label;
$HASH_DATA_BOX = 32831;
$CLAIM = 33081;
$claimBox = [0, 38, 33026, 33073];
$claimEnd = bCborEnd($p, $CLAIM);

$variants = [
    'hash-data-relative' => 'self#jumbf=c2pa.assertions/c2pa.hash.data',
    'hash-data-absolute' => "self#jumbf=/c2pa/{$label}/c2pa.assertions/c2pa.hash.data",
    'hash-boxes-relative' => 'self#jumbf=c2pa.assertions/c2pa.hash.boxes',
    'hash-bmff-relative' => 'self#jumbf=c2pa.assertions/c2pa.hash.bmff.v2',
    'hash-collection-relative' => 'self#jumbf=c2pa.assertions/c2pa.hash.collection.data',
];
foreach (glob("{$out}/*.png") ?: [] as $stale) {
    unlink($stale);
}
foreach ($variants as $name => $entry) {
    // the claim grows one pair (a map of 7 becomes 8); the hash box sits before the claim, so it does not move
    $store = bSplice($p, $claimEnd, 0, imCbor('redacted_assertions').imCbor([$entry]), $claimBox);
    $store = bReplace($store, $CLAIM, "\xa7", "\xa8");
    $store = imRebind($store, $png, 0, $HASH_DATA_BOX, $CLAIM);
    $manifest = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $coseAt = strpos($store, $manifest->signatureBytes());
    if ($coseAt === false) {
        throw new RuntimeException("{$name}: no COSE_Sign1");
    }
    $length = strlen($manifest->signatureBytes());
    $cose = imCose($manifest->claimBytes(), $length, $leafDer, $rootDer, $keys, $name);
    $store = substr($store, 0, $coseAt).$cose.substr($store, $coseAt + $length);
    file_put_contents("{$out}/{$name}.png", pngWithStore($png, $store));
}

// ---- what each c2patool says, with the root as anchor ----
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (array_keys($variants) as $name) {
    foreach (['0.28.0' => $new, '0.27.22' => $old] as $tag => $tool) {
        $lines = [];
        exec(imSh($tool, "{$out}/{$name}.png", '--settings', $settings).' 2>&1', $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$tag}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        printf("%-26s %-8s %s\n", $name, $tag, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
    }
}
