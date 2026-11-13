<?php

declare(strict_types=1);

/*
 * Build the fixtures of SPEC-090 (the general box hash for JPEG, step 533).
 *
 * Two kinds:
 *  - bing-*.jpg: the Bing Image Creator file with its asset bytes changed outside the store. Its claim
 *    and signature are untouched, so only the box hash can fail.
 *  - written-*.jpg: this project's own box-hash JPEG, built from fixture-signed.jpg with an APP1 Exif
 *    segment added: the c2pa.hash.data assertion replaced by a c2pa.hash.boxes one, the claim
 *    re-encoded, and the claim signed under a throw-away P-256 root. The C2PA box is not hashed, so
 *    the store may take any length; no padding is needed.
 *
 * Both c2patool versions judge every variant: the bing ones without settings, the written ones with
 * the throw-away root as anchor.
 *
 * Usage: php bin/make-spec090-variants.php <c2patool-0.28.6> <c2patool-0.27.22>
 * Writes:
 *   tests/Fixtures/box-hash/<variant>.jpg, probe-root.pem, probe-root.settings.json
 *   tests/Fixtures/c2patool/box-hash/<variant>--<version>.json (or .error.txt)
 * Tooling.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Cbor\CborDecoder;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Cose\CoseSign1;
use Provemark\C2paVerifier\Cose\SignatureVerifier;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Jumbf\Superbox;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$new, $old] = [$argv[1] ?? '', $argv[2] ?? ''];
$version = static function (string $tool): string {
    exec(escapeshellarg($tool).' --version 2>&1', $lines);

    return $lines[0] ?? '';
};
if (! is_file($new) || ! is_file($old) || $version($new) !== 'c2patool 0.28.6' || $version($old) !== 'c2patool 0.27.22') {
    fwrite(STDERR, "usage: php bin/make-spec090-variants.php <c2patool-0.28.6> <c2patool-0.27.22>\n");
    exit(1);
}
$keys = sys_get_temp_dir().'/c2pa-spec090-'.bin2hex(random_bytes(6));
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

function s90Run(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command.' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ({$code}): {$command}\n".implode("\n", $lines));
    }
}

function s90Sh(string ...$parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** The CBOR head for major type $mt and argument $n. */
function s90Head(int $mt, int $n): string
{
    $mt <<= 5;

    return match (true) {
        $n < 24 => pack('C', $mt | $n),
        $n < 256 => pack('CC', $mt | 24, $n),
        $n < 65536 => pack('Cn', $mt | 25, $n),
        default => pack('CN', $mt | 26, $n),
    };
}

/** A small CBOR encoder: ints, text, bytes (CborBytes), null, bool, lists and text-keyed maps in the order given. */
function s90Cbor(mixed $v): string
{
    return match (true) {
        $v instanceof CborBytes => s90Head(2, strlen($v->bytes)).$v->bytes,
        is_string($v) => s90Head(3, strlen($v)).$v,
        is_int($v) => $v >= 0 ? s90Head(0, $v) : s90Head(1, -1 - $v),
        $v === null => "\xf6",
        is_bool($v) => $v ? "\xf5" : "\xf4",
        is_array($v) && array_is_list($v) && $v !== [] => s90Head(4, count($v)).implode('', array_map('s90Cbor', $v)),
        $v === [] => "\x80",
        is_array($v) => s90Head(5, count($v)).implode('', array_map(static fn (string $k, mixed $x): string => s90Cbor($k).s90Cbor($x), array_keys($v), array_values($v))),
        default => throw new RuntimeException('cannot encode '.get_debug_type($v)),
    };
}

/** A JUMBF box: LBox, TBox, payload. */
function s90Box(string $type, string $payload): string
{
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

/** A superbox's description box, copied as it is written. */
function s90Jumd(string $store, Superbox $box): string
{
    $at = $box->offset + 8;

    return substr($store, $at, s90Uint($store, $at, 4));
}

/** A big-endian unsigned integer of $width (2 or 4) bytes at $at. */
function s90Uint(string $bytes, int $at, int $width): int
{
    $value = unpack($width === 2 ? 'n' : 'N', $bytes, $at);
    if ($value === false || ! is_int($value[1])) {
        throw new RuntimeException("no {$width}-byte integer at {$at}");
    }

    return $value[1];
}

/** The bytes of a superbox, copied as they are written. */
function s90Raw(string $store, Superbox $box): string
{
    return substr($store, $box->offset, $box->length);
}

/** An assertion superbox: jumd (cbor UUID, toggles 3: requestable + label) and a cbor content box. */
function s90AssertionBox(string $label, string $payload): string
{
    $uuid = (string) hex2bin('63626f72001100108000'.'00aa00389b71');

    return s90Box('jumb', s90Box('jumd', $uuid."\x03".$label."\0").s90Box('cbor', $payload));
}

/** A DER ECDSA signature as R‖S of 2 × $curveBytes (what COSE carries). */
function s90DerToRs(string $der, int $curveBytes): string
{
    if ($der[0] !== "\x30") {
        throw new RuntimeException('not a DER ECDSA signature');
    }
    $p = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
    $rs = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$p + 1]);
        $rs .= str_pad(ltrim(substr($der, $p + 2, $len), "\0"), $curveBytes, "\0", STR_PAD_LEFT);
        $p += 2 + $len;
    }

    return $rs;
}

/**
 * The marker segments of a JPEG in file order: [name, start, length]. SOS runs to the next marker that is
 * neither a stuffed FF00 nor RSTn (C2PA 2.4 §18.7.3.1: SOS includes the entropy-coded data); bytes after
 * EOI are not a segment.
 *
 * @return list<array{0: string, 1: int, 2: int}>
 */
function s90Segments(string $jpeg): array
{
    $names = [0xD8 => 'SOI', 0xD9 => 'EOI', 0xDA => 'SOS', 0xDB => 'DQT', 0xC4 => 'DHT', 0xC0 => 'SOF0', 0xFE => 'COM', 0xDD => 'DRI'];
    $out = [['SOI', 0, 2]];
    $p = 2;
    while ($p < strlen($jpeg)) {
        if ($jpeg[$p] !== "\xFF") {
            throw new RuntimeException("no marker at {$p}");
        }
        $marker = ord($jpeg[$p + 1]);
        $name = $names[$marker] ?? ($marker >= 0xE0 && $marker <= 0xEF ? 'APP'.($marker - 0xE0) : throw new RuntimeException(sprintf('marker %02X', $marker)));
        if ($marker === 0xD9) {
            $out[] = ['EOI', $p, 2];
            break;
        }
        $end = $p + 2 + s90Uint($jpeg, $p + 2, 2);
        if ($marker === 0xDA) {
            while (true) {
                $end = strpos($jpeg, "\xFF", $end);
                if ($end === false) {
                    throw new RuntimeException('no marker after the scan');
                }
                $next = ord($jpeg[$end + 1]);
                if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                    $end += 2;

                    continue;
                }
                break;
            }
        }
        $out[] = [$name, $p, $end - $p];
        $p = $end;
    }

    return $out;
}

/**
 * The first segment named $name, or the $nth (0-based).
 *
 * @return array{0: string, 1: int, 2: int}
 */
function s90Segment(string $jpeg, string $name, int $nth = 0): array
{
    foreach (s90Segments($jpeg) as $segment) {
        if ($segment[0] === $name && $nth-- === 0) {
            return $segment;
        }
    }
    throw new RuntimeException("no {$name}");
}

/** The JPEG with $with inserted at $at. */
function s90Insert(string $jpeg, int $at, string $with): string
{
    return substr($jpeg, 0, $at).$with.substr($jpeg, $at);
}

/**
 * A marker segment: FF, marker, length, payload.
 *
 * @param  int<0, 255>  $marker
 */
function s90MarkerSegment(int $marker, string $payload): string
{
    return "\xFF".chr($marker).pack('n', 2 + strlen($payload)).$payload;
}

/** The store as APP11 segments, as c2pa-rs's compose_manifest writes them (CI JP, En 0x0211, Z from 1). */
function s90App11(string $store): string
{
    $out = '';
    foreach (str_split($store, 64000) as $i => $chunk) {
        $out .= s90MarkerSegment(0xEB, 'JP'."\x02\x11".pack('N', $i + 1).($i > 0 ? substr($store, 0, 8) : '').$chunk);
    }

    return $out;
}

/**
 * The sha256 (or $alg) over [$start, $start + $length) of $bytes with the absolute [start, length]
 * ranges skipped.
 *
 * @param  list<array{0: int, 1: int}>  $skip
 */
function s90Hash(string $bytes, int $start, int $length, array $skip = [], string $alg = 'sha256'): string
{
    $ctx = hash_init($alg);
    $p = $start;
    foreach ($skip as [$from, $n]) {
        hash_update($ctx, substr($bytes, $p, $from - $p));
        $p = $from + $n;
    }
    hash_update($ctx, substr($bytes, $p, $start + $length - $p));

    return hash_final($ctx, true);
}

// ---- the throw-away hierarchy: a P-256 root and a leaf on the C2PA profile ----
$root = dirname(__DIR__);
// both identifiers spelled out: OpenSSL 4 no longer adds them to a leaf by default (step 531)
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
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid
CNF;
file_put_contents("{$keys}/ext.cnf", $cnf);
s90Run(s90Sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/root.key"));
s90Run(s90Sh('openssl', 'req', '-x509', '-new', '-key', "{$keys}/root.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=Throw-away Root (SPEC-090)', '-days', '3650', '-config', "{$keys}/ext.cnf", '-extensions', 'v3_root', '-out', "{$keys}/root.pem"));
s90Run(s90Sh('openssl', 'ecparam', '-genkey', '-name', 'prime256v1', '-noout', '-out', "{$keys}/leaf.key"));
s90Run(s90Sh('openssl', 'req', '-new', '-key', "{$keys}/leaf.key", '-subj', '/O=C2PA Verifier throw-away hierarchy/OU=FOR TESTING ONLY/CN=SPEC-090 probes', '-config', "{$keys}/ext.cnf", '-out', "{$keys}/leaf.csr"));
s90Run(s90Sh('openssl', 'x509', '-req', '-in', "{$keys}/leaf.csr", '-CA', "{$keys}/root.pem", '-CAkey', "{$keys}/root.key", '-set_serial', (string) random_int(1000, 999999), '-days', '3650', '-extfile', "{$keys}/ext.cnf", '-extensions', 'good', '-out', "{$keys}/leaf.pem"));
$pemToDer = static fn (string $pem): string => (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
$rootPem = (string) file_get_contents("{$keys}/root.pem");
$rootDer = $pemToDer($rootPem);
$leafDer = $pemToDer((string) file_get_contents("{$keys}/leaf.pem"));

$out = $root.'/tests/Fixtures/box-hash';
$oracles = $root.'/tests/Fixtures/c2patool/box-hash';
foreach ([$out, $oracles] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
        throw new RuntimeException("cannot create {$dir}");
    }
}
foreach ([...glob("{$out}/*.jpg") ?: [], ...glob("{$oracles}/*") ?: []] as $stale) {
    unlink($stale);
}
file_put_contents("{$out}/probe-root.pem", $rootPem);
$settings = "{$out}/probe-root.settings.json";
file_put_contents($settings, json_encode([
    'trust' => ['trust_anchors' => $rootPem, 'trust_config' => rtrim((string) file_get_contents($root.'/tests/Fixtures/trust/store.cfg'))."\n"],
    'verify' => ['verify_trust' => true],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ---- the Bing variants: asset bytes changed, store and signature untouched ----
$bing = (string) file_get_contents($root.'/tests/Fixtures/writers/microsoft-20260609-bing-fast-heartbeat.jpg');
$bingVariants = [];

[, $sos, $sosLength] = s90Segment($bing, 'SOS');
$at = $sos + intdiv($sosLength, 2);
while (in_array($bing[$at], ["\xFF", "\xFE"], true) || $bing[$at - 1] === "\xFF") {
    $at++;
}
$bingVariants['bing-pixel-changed'] = substr_replace($bing, chr(ord($bing[$at]) ^ 0x01), $at, 1);

[, $dqt] = s90Segment($bing, 'DQT');
$bingVariants['bing-unlisted-com'] = s90Insert($bing, $dqt, s90MarkerSegment(0xFE, 'SPEC-090: a comment no box names'));

$bingVariants['bing-after-eoi'] = $bing.'%PDF-1.4 SPEC-090 trailer';

[, $dqt0] = s90Segment($bing, 'DQT');
[, $sof, $sofLength] = s90Segment($bing, 'SOF0');
$bingVariants['bing-out-of-order'] = substr($bing, 0, $dqt0).substr($bing, $sof, $sofLength).substr($bing, $dqt0, $sof - $dqt0).substr($bing, $sof + $sofLength);

// an APP11 segment that is not a JPEG XT piece (no CI "JP"): not part of the store, and named by no box
// (SPEC-090 amendment 1: a JP piece of another box is refused by the extractor, SPEC-001 AC11); longer
// than 28 bytes, below which c2pa-rs cannot parse an APP11 ("Invalid JPEG CAI JUMBF block", step 533)
$bingVariants['bing-foreign-app11'] = s90Insert($bing, $dqt, s90MarkerSegment(0xEB, 'SPEC-090: an APP11 segment that is no JPEG XT piece'));

foreach ($bingVariants as $name => $bytes) {
    file_put_contents("{$out}/{$name}.jpg", $bytes);
}

// ---- the written variants, from fixture-signed.jpg ----
$jpeg = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.jpg');
$stream = fopen($root.'/tests/Fixtures/fixture-signed.jpg', 'rb');
if ($stream === false) {
    throw new RuntimeException('cannot open the JPEG fixture');
}
$extracted = (new JpegManifestStoreExtractor)->extract($stream) ?? throw new RuntimeException('no store in the JPEG fixture');
$store = $extracted->bytes;
// the asset without its APP11 pieces, with an APP1 Exif segment after APP0 (big-endian TIFF header, an empty IFD0, then padding)
$range = $extracted->ranges[0];
$exif = s90MarkerSegment(0xE1, "Exif\0\0".'MM'."\x00\x2A".pack('N', 8)."\x00\x00".pack('N', 0).str_repeat("\0", 32));
$asset = substr($jpeg, 0, $range['start']).$exif.substr($jpeg, $range['start'] + $range['length']);

$tree = (new JumbfParser)->parse($store);
$manifest = $tree->superboxes()[0];
$assertionStore = $manifest->child('c2pa.assertions') ?? throw new RuntimeException('no assertion store');
$claimBox = $manifest->child('c2pa.claim.v2') ?? throw new RuntimeException('no claim');
$signatureBox = $manifest->child('c2pa.signature') ?? throw new RuntimeException('no signature');
$claim = (new CborDecoder)->decode($claimBox->contentBoxes()[0]->data);
assert(is_array($claim));

/**
 * The written JPEG for a box-map: the store rebuilt around a c2pa.hash.boxes assertion with $boxMap as its
 * payload, the claim re-signed, the store placed after APP0.
 *
 * @param  array<string, mixed>  $claim
 */
$write = static function (array $boxMap, array $claim, string $name) use ($store, $tree, $manifest, $assertionStore, $claimBox, $signatureBox, $asset, $leafDer, $rootDer, $keys): string {
    // c2pa-rs refuses a box-hash-map without pad ("missing field `pad`", step 533) although the CDDL makes it
    // optional; every map gets an empty one, except in the variant that records the refusal
    if (isset($boxMap['boxes']) && is_array($boxMap['boxes']) && $name !== 'written-no-pad') {
        $padded = [];
        foreach ($boxMap['boxes'] as $map) {
            $padded[] = is_array($map) ? $map + ['pad' => new CborBytes('')] : $map;
        }
        $boxMap['boxes'] = $padded;
    }
    $hashBox = s90AssertionBox('c2pa.hash.boxes', s90Cbor($boxMap));
    $kept = '';
    foreach ($assertionStore->superboxes() as $assertion) {
        if ($assertion->description->label !== 'c2pa.hash.data') {
            $kept .= s90Raw($store, $assertion);
        }
    }
    $claim['created_assertions'] = [[
        'url' => 'self#jumbf=c2pa.assertions/c2pa.hash.boxes',
        'hash' => new CborBytes(hash('sha256', substr($hashBox, 8), true)),
    ]];
    $claimBytes = s90Cbor($claim);

    $protected = "\xa2\x01\x26\x18\x21\x82".s90Cbor(new CborBytes($leafDer)).s90Cbor(new CborBytes($rootDer));
    $unsigned = "\xd2\x84".s90Cbor(new CborBytes($protected))."\xa1\x63pad".s90Cbor(new CborBytes(''))."\xf6";
    file_put_contents("{$keys}/tbs", CoseSign1::fromBytes($unsigned.s90Cbor(new CborBytes(str_repeat("\0", 64))))->sigStructure($claimBytes));
    s90Run(s90Sh('openssl', 'dgst', '-sha256', '-sign', "{$keys}/leaf.key", '-out', "{$keys}/sig", "{$keys}/tbs"));
    $cose = $unsigned.s90Cbor(new CborBytes(s90DerToRs((string) file_get_contents("{$keys}/sig"), 32)));
    if (! (new SignatureVerifier)->verify(CoseSign1::fromBytes($cose), $claimBytes)) {
        throw new RuntimeException("{$name}: the new signature does not verify under its own leaf");
    }

    $newStore = s90Box('jumb', s90Jumd($store, $tree).s90Box('jumb', s90Jumd($store, $manifest)
        .s90Box('jumb', s90Jumd($store, $assertionStore).$kept.$hashBox)
        .s90Box('jumb', s90Jumd($store, $claimBox).s90Box('cbor', $claimBytes))
        .s90Box('jumb', s90Jumd($store, $signatureBox).s90Box('cbor', $cose))));
    [, $app0, $app0Length] = s90Segment($asset, 'APP0');

    return s90Insert($asset, $app0 + $app0Length, s90App11($newStore));
};

/**
 * The hash of the boxes $names of $asset (a range from the first to the last) with the absolute $skip ranges left out.
 *
 * @param  array<string, array{0: int, 1: int}>  $byName
 * @param  non-empty-list<string>  $names
 * @param  list<array{0: int, 1: int}>  $skip
 */
function s90BoxHash(string $asset, array $byName, array $names, array $skip = [], string $alg = 'sha256'): CborBytes
{
    $start = $byName[$names[0]][0];
    $last = $byName[$names[count($names) - 1]];

    return new CborBytes(s90Hash($asset, $start, $last[0] + $last[1] - $start, $skip, $alg));
}

// the boxes of the written asset as c2pa-rs and §18.7.3.1 name them, C2PA after APP0
$segments = s90Segments($asset);
/** @var array<string, array{0: int, 1: int}> $byName */
$byName = [];
foreach ($segments as [$segName, $start, $length]) {
    $byName[$segName] ??= [$start, $length];
}
[$exifAt, $exifLength] = $byName['APP1'];
$c2pa = ['names' => ['C2PA'], 'hash' => new CborBytes("\0"), 'pad' => new CborBytes('')];
$boxes = static fn (array $app1, array $range): array => [
    ['names' => ['SOI', 'APP0'], 'alg' => 'sha384', 'hash' => s90BoxHash($asset, $byName, ['SOI', 'APP0'], [], 'sha384'), 'pad' => new CborBytes("\0\0")],
    $c2pa,
    $app1,
    ['names' => ['COM'], 'hash' => s90BoxHash($asset, $byName, ['COM']), 'pad2' => new CborBytes("\0\0\0")],
    $range,
    ['names' => ['SOS'], 'hash' => s90BoxHash($asset, $byName, ['SOS'])],
    ['names' => ['EOI'], 'hash' => s90BoxHash($asset, $byName, ['EOI'])],
];
$app1 = ['names' => ['APP1'], 'hash' => s90BoxHash($asset, $byName, ['APP1'])];
$range = ['names' => ['DQT', 'DHT', 'SOF0'], 'hash' => s90BoxHash($asset, $byName, ['DQT', 'DHT', 'SOF0'])];
$written = ['alg' => 'sha256', 'boxes' => $boxes($app1, $range)];
$withBoxes = static fn (array $list): array => ['alg' => 'sha256', 'boxes' => $list];
$noAlgClaim = $claim;
unset($noAlgClaim['alg']);
$noAlgBoxes = $boxes($app1, $range);
unset($noAlgBoxes[0]['alg']);
$noAlgBoxes[0]['hash'] = s90BoxHash($asset, $byName, ['SOI', 'APP0']);
[$dqtAt] = $byName['DQT'];

$writtenVariants = [
    'written' => [$written, $claim],
    'written-no-pad' => [['alg' => 'sha256', 'boxes' => array_map(static function (array $map): array {
        unset($map['pad']);

        return $map;
    }, $boxes($app1, $range))], $claim],
    'written-no-boxes' => [['alg' => 'sha256'], $claim],
    'written-no-names' => [$withBoxes(array_replace($boxes($app1, $range), [3 => ['hash' => s90BoxHash($asset, $byName, ['COM'])]])), $claim],
    'written-empty-exclusions' => [$withBoxes($boxes($app1 + ['exclusions' => []], $range)), $claim],
    'written-box-index-beyond' => [$withBoxes($boxes(['names' => ['APP1'], 'hash' => s90BoxHash($asset, $byName, ['APP1'], [[$exifAt + 20, 4]]), 'exclusions' => [['start' => 20, 'length' => 4, 'boxIndex' => 1]]], $range)), $claim],
    'written-overlapping-exclusions' => [$withBoxes($boxes(['names' => ['APP1'], 'hash' => s90BoxHash($asset, $byName, ['APP1'], [[$exifAt + 20, 12]]), 'exclusions' => [['start' => 20, 'length' => 8], ['start' => 24, 'length' => 8]]], $range)), $claim],
    'written-c2pa-shared' => [$withBoxes(array_values(array_filter(array_replace($boxes($app1, $range), [1 => ['names' => ['C2PA', 'APP1'], 'hash' => new CborBytes("\0")], 2 => null])))), $claim],
    'written-no-alg' => [['boxes' => $noAlgBoxes], $noAlgClaim],
    'written-exif-exclusion' => [$withBoxes($boxes(['names' => ['APP1'], 'hash' => s90BoxHash($asset, $byName, ['APP1'], [[$exifAt + 20, 8]]), 'exclusions' => [['start' => 20, 'length' => 8]]], $range)), $claim],
    'written-dqt-exclusion' => [$withBoxes($boxes($app1, ['names' => ['DQT', 'DHT', 'SOF0'], 'hash' => s90BoxHash($asset, $byName, ['DQT', 'DHT', 'SOF0'], [[$dqtAt + 10, 4]]), 'exclusions' => [['start' => 10, 'length' => 4, 'boxIndex' => 0]]])), $claim],
    'written-past-end-exclusion' => [$withBoxes($boxes(['names' => ['APP1'], 'hash' => s90BoxHash($asset, $byName, ['APP1'], [[$exifAt + $exifLength - 4, 4]]), 'exclusions' => [['start' => $exifLength - 4, 'length' => 8]]], $range)), $claim],
    'written-excluded' => [$withBoxes($boxes(['names' => ['APP1'], 'hash' => new CborBytes("\0"), 'excluded' => true], $range)), $claim],
];
foreach ($writtenVariants as $name => [$boxMap, $variantClaim]) {
    file_put_contents("{$out}/{$name}.jpg", $write($boxMap, $variantClaim, $name));
}
// a trailer after EOI that the list names as c2pa.after and hashes correctly (SPEC-090 open question 4)
$trailer = '%PDF-1.4 SPEC-090 trailer';
$listedAfter = $boxes($app1, $range);
$listedAfter[] = ['names' => ['c2pa.after'], 'hash' => new CborBytes(hash('sha256', $trailer, true))];
file_put_contents("{$out}/written-listed-after.jpg", $write($withBoxes($listedAfter), $claim, 'written-listed-after').$trailer);

// ---- what each c2patool says ----
foreach (glob("{$out}/*.jpg") ?: [] as $file) {
    $name = basename($file, '.jpg');
    foreach (['0.28.6' => $new, '0.27.22' => $old] as $tag => $tool) {
        $lines = [];
        $command = str_starts_with($name, 'written') ? s90Sh($tool, $file, '--settings', $settings) : s90Sh($tool, $file);
        exec($command.' 2>&1', $lines, $code);
        file_put_contents("{$oracles}/{$name}--{$tag}".($code === 0 ? '.json' : '.error.txt'), implode("\n", $lines)."\n");
        $report = $code === 0 ? json_decode(implode("\n", $lines), true) : null;
        $failures = [];
        $results = is_array($report) && is_array($report['validation_results'] ?? null) ? $report['validation_results'] : [];
        $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
        foreach (is_array($active['failure'] ?? null) ? $active['failure'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['code'] ?? null)) {
                $failures[] = $entry['code'];
            }
        }
        printf("%-34s %-8s %-8s %s\n", $name, $tag, is_array($report) && is_string($report['validation_state'] ?? null) ? $report['validation_state'] : "exit {$code}", implode(' ', array_unique($failures)));
    }
}
