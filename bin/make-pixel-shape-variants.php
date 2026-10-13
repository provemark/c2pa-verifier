<?php

declare(strict_types=1);

/*
 * Step 360, SPEC-072 AC2/AC3: files of the Pixel Camera video's shape, for CI. The real Pixel file
 * (c2pa-rs issue #2373) has no licence and stays outside the repository. tests/Fixtures/fixture-signed.mp4
 * gets, inside moov, a QuickTime-style meta (hdlr 'mdta' first, no version and flags), a udta, a free,
 * and two copies of its track, the third with handler 'meta' (timed metadata); its c2pa.hash.bmff.v3
 * gets Pixel's exclusion list. The digest is computed by this verifier's own BMFF hash (the step-133
 * port covers top-level paths only), so the binding is confirmed by an external oracle: c2patool
 * 0.28.2, whose answer is recorded and must say assertion.bmffHash.match. The claim is re-signed under
 * a throw-away root; the keys are deleted at the end. Moved sample offsets make the file unplayable,
 * which no hash check looks at.
 *
 *   pixel-shape              Pixel's list, /moov[1]/trak[3] a timed-metadata track: allowed
 *   pixel-shape-video-track  /moov[1]/trak[1] (handler vide) in its place: refused
 *   pixel-shape-no-track     /moov[1]/trak[9] (no such track): refused
 *
 * Usage: php bin/make-pixel-shape-variants.php <c2patool-0.28.1> <c2patool-0.28.2>
 * Writes tests/Fixtures/pixel-shape/, tests/Fixtures/c2patool/pixel-shape/. Tooling.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/variant-helpers.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
[$old, $new] = [$new, $old];   // here the first argument is the older version
if (! is_file($new) || ! is_file($old) || $version($old) !== 'c2patool 0.28.1' || $version($new) !== 'c2patool 0.28.2') {
    fwrite(STDERR, "usage: php bin/make-pixel-shape-variants.php <c2patool-0.28.1> <c2patool-0.28.2>\n");
    exit(1);
}
$keys = sys_get_temp_dir().'/c2pa-step360-'.bin2hex(random_bytes(6));
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

function pxRun(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function pxSh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** A byte string to be encoded as CBOR major type 2 (a PHP string encodes as text). */
final class PxBytes
{
    public function __construct(public string $bytes) {}
}

/** The CBOR head for major type $mt and argument $n. */
function pxHead(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, bytes (PxBytes), null, bool, lists and text-keyed maps in the order given. */
function pxCbor(mixed $v): string
{
    return match (true) {
        $v instanceof PxBytes => pxHead(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => pxHead(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? pxHead(0, $v) : pxHead(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) && array_is_list($v) => pxHead(4, count($v)).implode('', array_map('pxCbor', $v)),
        is_array($v) => pxHead(5, count($v)).implode('', array_map(static fn (string $k, mixed $x): string => pxCbor($k).pxCbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/** An assertion superbox: jumd (cbor UUID, toggles 3: requestable + label) and a cbor content box. */
function pxAssertionBox(string $label, string $payload): string
{
    $uuid = (string) hex2bin('63626f72001100108000'.'00aa00389b71');
    $jumd = 'jumd'.$uuid."\x03".$label."\0";
    $jumd = pack('N', 4 + strlen($jumd)).$jumd;
    $cbor = pack('N', 8 + strlen($payload)).'cbor'.$payload;
    $box = 'jumb'.$jumd.$cbor;

    return pack('N', 4 + strlen($box)).$box;
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function pxDerToRs(string $der, int $curveBytes): string
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
function pxDataHash(string $bytes, array $exclusions): string
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
function pxEntryFor(string $s, int $list, string $label): ?int
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
function pxRebind(string $store, string $png, int $grown, int $hashDataBox, int $claimAt): string
{
    $box = $hashDataBox + $grown;
    $hd = bMapPairs($store, $box + 80);
    $ex = bMapPairs($store, $hd['exclusions'][1] + 1);
    $lengthValue = $ex['length'][1];
    if ($store[$lengthValue] !== "\x19") {
        throw new RuntimeException('the exclusion length is not a two-byte CBOR uint');
    }
    $store = bReplace($store, $lengthValue + 1, substr($store, $lengthValue + 1, 2), pack('n', 12 + strlen($store)));
    $digest = pxDataHash(pngWithStore($png, $store), [[33, 12 + strlen($store)]]);
    $hashValue = $hd['hash'][1] + 2;
    $store = bReplace($store, $hashValue, substr($store, $hashValue, 32), $digest);
    $uri = hash('sha256', substr($store, $box + 8, bU32($store, $box) - 8), true);
    $claim = bMapPairs($store, $claimAt + $grown);
    $entry = pxEntryFor($store, $claim['created_assertions'][1], 'c2pa.hash.data');
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
pxRun(pxSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
pxRun(pxSh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (step 360)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
pxRun(pxSh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
pxRun(pxSh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=step 360 pixel shape', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
pxRun(pxSh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$out = $root.'/tests/Fixtures/pixel-shape';
$oracles = $root.'/tests/Fixtures/c2patool/pixel-shape';
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
function pxCose(string $claimBytes, int $length, string $leafDer, string $rootDer, string $keys, string $name): string
{
    $protected = "\xa2\x01\x26\x18\x21\x82".pxCbor(new PxBytes($leafDer)).pxCbor(new PxBytes($rootDer));
    $draft = "\xd2\x84".pxCbor(new PxBytes($protected))."\xa1\x63pad".pxCbor(new PxBytes(''))."\xf6".pxCbor(new PxBytes(str_repeat("\0", 64)));
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($draft)->sigStructure($claimBytes));
    pxRun(pxSh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $signature = pxDerToRs((string) file_get_contents("{$keys}/sig"), 32);
    $fixed = 2 + strlen(pxCbor(new PxBytes($protected))) + 5 + 3 + 1 + strlen(pxCbor(new PxBytes($signature)));
    $padLength = $length - $fixed;
    if ($padLength < 256) {
        throw new RuntimeException("{$name}: the pad would be {$padLength} bytes, too short for a 3-byte head");
    }
    $cose = "\xd2\x84".pxCbor(new PxBytes($protected))."\xa1\x63pad\x59".pack('n', $padLength).str_repeat("\0", $padLength)."\xf6".pxCbor(new PxBytes($signature));
    if (strlen($cose) !== $length) {
        throw new RuntimeException("{$name}: COSE is ".strlen($cose)." bytes, not {$length}");
    }
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }

    return $cose;
}

/** An integer read from decoded CBOR, or a refusal: the script trusts no shape it did not check. */
function pxInt(mixed $v): int
{
    return is_int($v) ? $v : throw new RuntimeException('expected an integer, found '.get_debug_type($v));
}

/**
 * A list of CBOR maps, or a refusal.
 *
 * @return list<array<string, mixed>>
 */
function pxMaps(mixed $v): array
{
    if (! is_array($v) || ! array_is_list($v)) {
        throw new RuntimeException('expected a list, found '.get_debug_type($v));
    }
    $out = [];
    foreach ($v as $map) {
        if (! is_array($map) || array_is_list($map)) {
            throw new RuntimeException('expected a map, found '.get_debug_type($map));
        }
        /** @var array<string, mixed> $map */
        $out[] = $map;
    }

    return $out;
}

/**
 * The assertion with a subset on its fourth exclusion, which must be /free.
 *
 * @param  array<mixed>  $d
 * @param  list<array{offset: int, length: int}>  $subset
 * @return array<mixed>
 */
function pxWithFreeSubset(array $d, array $subset): array
{
    $exclusions = pxMaps($d['exclusions'] ?? null);
    if (($exclusions[3]['xpath'] ?? null) !== '/free') {
        throw new RuntimeException('the fourth exclusion is not /free');
    }
    $exclusions[3]['subset'] = $subset;
    $d['exclusions'] = $exclusions;

    return $d;
}

/**
 * An edit that puts $subset on the /free exclusion.
 *
 * @param  list<array{offset: int, length: int}>  $subset
 */
function pxFree(array $subset): Closure
{
    return static fn (array $d): array => pxWithFreeSubset($d, $subset);
}

/**
 * The top-level boxes of an ISOBMFF file: [type, start, length].
 *
 * @return list<array{0: string, 1: int, 2: int}>
 */
function pxTopLevel(string $file): array
{
    $boxes = [];
    for ($at = 0; $at + 8 <= strlen($file);) {
        $size = bU32($file, $at);
        $type = substr($file, $at + 4, 4);
        if ($size === 1) {
            /** @var array{1: int} $u */
            $u = unpack('J', $file, $at + 8);
            $size = $u[1];
        } elseif ($size === 0) {
            $size = strlen($file) - $at;
        }
        $boxes[] = [$type, $at, $size];
        $at += $size;
    }

    return $boxes;
}

/**
 * c2pa-rs's BMFF digest for top-level exclusions (bmff_hash.rs's exclusion walk and hash_utils.rs's
 * hash_stream_by_alg): every top-level box keeps an 8-byte big-endian offset marker unless an
 * exclusion without `subset` takes it out; a marker is hashed where its box starts, before the
 * box's first included byte; a marker whose box has nothing included is kept only when it lies
 * strictly between the first and the last included byte of the file.
 *
 * @param  list<array<string, mixed>>  $exclusions
 */
function pxDigest(string $file, array $exclusions): string
{
    $excluded = [];
    $markers = [];
    foreach (pxTopLevel($file) as [$type, $start, $length]) {
        $markers[$start] = true;
        foreach ($exclusions as $exclusion) {
            if (($exclusion['xpath'] ?? null) !== '/'.$type) {
                continue;
            }
            $matches = true;
            foreach (pxMaps($exclusion['data'] ?? []) as $map) {
                $value = $map['value'] ?? null;
                $value = $value instanceof CborBytes ? $value->bytes : throw new RuntimeException('a data value is not a byte string');
                $matches = $matches && substr($file, $start + pxInt($map['offset'] ?? null), strlen($value)) === $value;
            }
            if (! $matches) {
                continue;
            }
            if (array_key_exists('subset', $exclusion)) {
                foreach (pxMaps($exclusion['subset']) as $subset) {
                    [$offset, $size] = [pxInt($subset['offset'] ?? null), pxInt($subset['length'] ?? null)];
                    if ($offset > $length) {
                        continue;
                    }
                    $excluded[] = [$start + $offset, $size === 0 ? $length - $offset : min($size, $length - $offset)];
                }
            } else {
                $excluded[] = [$start, $length];
                unset($markers[$start]);
            }
        }
    }
    // the included ranges: the whole file minus the exclusions, as inclusive [first, last] pairs
    $keep = array_fill(0, strlen($file), true);
    foreach ($excluded as [$from, $length]) {
        for ($i = $from; $i < $from + $length; $i++) {
            $keep[$i] = false;
        }
    }
    $ranges = [];
    for ($i = 0; $i < strlen($file); $i++) {
        if ($keep[$i]) {
            $j = $i;
            while ($j + 1 < strlen($file) && $keep[$j + 1]) {
                $j++;
            }
            $ranges[] = [$i, $j];
            $i = $j;
        }
    }
    $offsets = array_keys($markers);
    sort($offsets);
    $out = [];
    foreach ($ranges as [$first, $last]) {
        foreach ($offsets as $os) {
            if ($os >= $first && $os <= $last) {
                if ($first !== $os) {
                    $out[] = [$first, $os - 1, false];
                }
                $out[] = [$os, $os, true];
                $first = $os;
            }
        }
        $out[] = [$first, $last, false];
    }
    $before = $out[0][0] ?? 0;
    $after = $out[count($out) - 1][1] ?? strlen($file) - 1;
    foreach ($offsets as $os) {
        $inside = array_filter($out, static fn (array $r): bool => ! $r[2] && $os >= $r[0] && $os <= $r[1]) !== [];
        if (! $inside && $os > $before && $os < $after) {
            $out[] = [$os, $os, true];
        }
    }
    usort($out, static fn (array $a, array $b): int => [$a[0], $b[2] ? 1 : 0] <=> [$b[0], $a[2] ? 1 : 0]);
    $context = hash_init('sha256');
    foreach ($out as [$first, $last, $marker]) {
        hash_update($context, $marker ? pack('J', $first) : substr($file, $first, $last - $first + 1));
    }

    return hash_final($context, true);
}

// ---- the variants, from the MP4 fixture: the bmff.v3 assertion edited, the claim re-hashed and
// re-signed, the COSE padded so that the store keeps its length and no box of the file moves ----
$mp4 = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.mp4');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.mp4', 'rb') ?: throw new RuntimeException('cannot open the MP4 fixture');
$p = (new IsobmffManifestStoreExtractor)->extract($stream)->bytes ?? throw new RuntimeException('no store');
$storeAt = strpos($mp4, $p);
if ($storeAt === false || strpos($mp4, $p, $storeAt + 1) !== false) {
    throw new RuntimeException('the store is not found exactly once in the MP4');
}
$LABEL = 'c2pa.hash.bmff.v3';
$original = (new CborDecoder)->decode((ManifestStore::fromTree((new JumbfParser)->parse($p))->active->assertionStore->child($LABEL) ?? throw new RuntimeException('no bmff assertion'))->contentBoxes()[0]->data);
if (! is_array($original) || ! $original['hash'] instanceof CborBytes || pxDigest($mp4, pxMaps($original['exclusions'] ?? null)) !== $original['hash']->bytes) {
    throw new RuntimeException('the port does not reproduce the digest the fixture was signed with');
}
echo "the port reproduces the signed digest\n";
$toIm = static function (mixed $v) use (&$toIm): mixed {
    if ($v instanceof CborBytes) {
        return new PxBytes($v->bytes);
    }

    return is_array($v) ? array_map($toIm, $v) : $v;
};
/**
 * This verifier's BMFF digest of $file under $exclusions: the steps BmffHashCheck::check() takes
 * (box tree, plan, tail, digest), two of them private and reached by reflection. Tooling: the
 * result is confirmed by c2patool, not trusted on its own.
 *
 * @param  list<array<string, mixed>>  $exclusions
 */
function pxOurDigest(string $file, array $exclusions): string
{
    $stream = fopen('php://memory', 'w+b') ?: throw new RuntimeException('no memory stream');
    fwrite($stream, $file);
    rewind($stream);
    $boxes = (new IsobmffManifestStoreExtractor)->boxTree($stream);
    $readAt = static function (int $at, int $length) use ($stream): string {
        fseek($stream, $at);

        return $length < 1 ? '' : (string) fread($stream, $length);
    };
    $included = BmffHashCheck::plan($boxes, $exclusions, $readAt);
    $class = new ReflectionClass(BmffHashCheck::class);
    $included = $class->getMethod('withTail')->invoke(null, $included, array_values(array_filter($boxes, static fn (array $b): bool => substr_count($b['path'], '/') === 1)), $stream);
    $digest = $class->getMethod('digest')->invoke(new BmffHashCheck, $stream, $included, 'sha256');

    return is_string($digest) ? $digest : throw new RuntimeException('no digest');
}

// ---- the Pixel shape: moov rebuilt with a QuickTime meta, udta, free and two more tracks ----
$moovAt = 13610;
if (substr($mp4, $moovAt + 4, 4) !== 'moov' || bU32($mp4, $moovAt) !== 945) {
    throw new RuntimeException('moov is not where step 358 measured it');
}
$moov = substr($mp4, $moovAt, 945);
$trakAt = strpos($moov, 'trak') - 4;
$trak = substr($moov, $trakAt, bU32($moov, $trakAt));
$hdlrAt = strpos($trak, 'hdlr') - 4;
if (substr($trak, $hdlrAt + 16, 4) !== 'vide') {
    throw new RuntimeException('the track is not a video track');
}
$metaTrak = substr_replace($trak, 'meta', $hdlrAt + 16, 4);
$box = static fn (string $type, string $body): string => pack('N', 8 + strlen($body)).$type.$body;
$qtMeta = $box('meta', $box('hdlr', str_repeat("\0", 8).'mdta'.str_repeat("\0", 13)).$box('keys', "\0\0\0\0\0\0\0\0"));
$udta = $box('udta', $box("\xa9xyz", "\0\x0c\x15\xc7+52.3702+004.8952/"));
$body = substr($moov, 8).$udta.$qtMeta.$box('free', str_repeat("\0", 16)).$trak.$metaTrak;
$mp4 = substr($mp4, 0, $moovAt).$box('moov', $body).substr($mp4, $moovAt + 945);
/** Pixel's exclusion list, with $track as the indexed track. */
$base = $mp4;
$pixel = static fn (string $track): Closure => static function (array $d) use ($track, $base): array {
    $uuid = null;
    foreach (pxMaps($d['exclusions'] ?? null) as $e) {
        if (($e['xpath'] ?? null) === '/uuid') {
            $uuid = $e;
        }
    }
    $d['exclusions'] = [['xpath' => '/ftyp'], $uuid ?? throw new RuntimeException('no uuid exclusion'), ['xpath' => '/mfra'], ['xpath' => '/free'], ['xpath' => '/moov/free'], ['xpath' => '/moov/udta'], ['xpath' => '/moov/meta'], ['xpath' => $track]];
    // the C2PA box is excluded, so the digest of the file with the old store is the digest of the signed one
    $d['hash'] = new CborBytes(pxOurDigest($base, $d['exclusions']));

    return $d;
};
$variants = [
    'pixel-shape' => $pixel('/moov[1]/trak[3]'),
    'pixel-shape-video-track' => $pixel('/moov[1]/trak[1]'),
    'pixel-shape-no-track' => $pixel('/moov[1]/trak[9]'),
];
foreach (glob("{$out}/*.mp4") ?: [] as $stale) {
    unlink($stale);
}
foreach ($variants as $name => $edit) {
    $m = ManifestStore::fromTree((new JumbfParser)->parse($p))->active;
    $box = $m->assertionStore->child($LABEL) ?? throw new RuntimeException('no bmff assertion');
    $data = (new CborDecoder)->decode($box->contentBoxes()[0]->data);
    if (! is_array($data) || (pxMaps($data['exclusions'] ?? null)[3]['xpath'] ?? null) !== '/free') {
        throw new RuntimeException('the bmff assertion is not the one measured');
    }
    $newBox = pxAssertionBox($LABEL, pxCbor($toIm($edit($data))));
    $delta = strlen($newBox) - $box->length;
    $store = bSplice($p, $box->offset, $box->length, $newBox, [0, $m->box->offset, $m->assertionStore->offset]);

    // the claim's hashed URI for the assertion
    $m = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $claimBytes = $m->claimBytes();
    $claimAt = strpos($store, $claimBytes) ?: throw new RuntimeException('the claim is not in the store');
    $claim = bMapPairs($store, $claimAt);
    $entry = pxEntryFor($store, $claim['created_assertions'][1], $LABEL) ?? throw new RuntimeException('no claim entry');
    $hashAt = bMapPairs($store, $entry)['hash'][1] + 2;
    $newBoxNow = $m->assertionStore->child($LABEL) ?? throw new RuntimeException('lost the assertion');
    $store = bReplace($store, $hashAt, substr($store, $hashAt, 32), hash('sha256', $newBoxNow->payload(), true));

    // a new signature, padded to take up what the assertion gained or lost
    $m = ManifestStore::fromTree((new JumbfParser)->parse($store))->active;
    $signatureBox = $m->resolve($m->claim->signatureUri);
    $cbor = $signatureBox->contentBoxes()[0];
    $oldCose = $m->signatureBytes();
    $coseAt = strpos($store, $oldCose) ?: throw new RuntimeException('the COSE_Sign1 is not in the store');
    $cose = pxCose($m->claimBytes(), strlen($oldCose) - $delta, $leafDer, $rootDer, $keys, $name);
    $store = bSplice($store, $coseAt, strlen($oldCose), $cose, [0, $m->box->offset, $signatureBox->offset, $cbor->offset]);
    if (strlen($store) !== strlen($p)) {
        throw new RuntimeException("{$name}: the store changed length");
    }
    ManifestStore::fromTree((new JumbfParser)->parse($store));
    $signed = substr_replace($mp4, $store, $storeAt, strlen($p));
    file_put_contents("{$out}/{$name}.mp4", $signed);
    $written = (new CborDecoder)->decode((ManifestStore::fromTree((new JumbfParser)->parse($store))->active->assertionStore->child($LABEL) ?? throw new RuntimeException('lost the assertion'))->contentBoxes()[0]->data);
    $paths = [];
    foreach (pxMaps(is_array($written) ? ($written['exclusions'] ?? null) : null) as $exclusion) {
        $paths[] = is_string($exclusion['xpath'] ?? null) ? $exclusion['xpath'] : '?';
    }
    printf("%-22s exclusions %s\n", $name, implode(' ', $paths));
}

// ---- what each c2patool says, with the root as anchor ----
foreach (glob("{$oracles}/*") ?: [] as $stale) {
    unlink($stale);
}
foreach (array_keys($variants) as $name) {
    $file = "{$out}/{$name}.mp4";
    foreach (['0.28.1' => $old, '0.28.2' => $new] as $tag => $tool) {
        $lines = [];
        exec(pxSh($tool, $file, '--settings', $settings).' 2>&1', $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$tag}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        printf("%-26s %-8s %s\n", $name, $tag, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}");
    }
    $lines = [];
    exec(pxSh(PHP_BINARY, $root.'/bin/c2pa-verify', '--settings', $settings, $file).' 2>&1', $lines);
    $report = json_decode(implode("\n", $lines), true);
    $codes = [];
    $results = is_array($report) && is_array($report['validation_results'] ?? null) ? $report['validation_results'] : [];
    $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
    foreach (['success', 'informational', 'failure'] as $kind) {
        foreach (is_array($active[$kind] ?? null) ? $active[$kind] : [] as $s) {
            if (is_array($s) && is_string($s['code'] ?? null) && str_starts_with($s['code'], 'assertion.bmffHash')) {
                $codes[] = $s['code'];
            }
        }
    }
    printf("%-26s %-8s %s  %s\n", $name, 'ours', is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : 'error', implode(' ', $codes));
}
