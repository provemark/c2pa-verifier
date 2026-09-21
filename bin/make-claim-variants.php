<?php

declare(strict_types=1);

/*
 * SPEC-007: builds the malformed claim and manifest variants under
 * tests/Fixtures/claim/ from the PNG fixture's store (and one from the Adobe
 * store). Each variant changes one thing — a label, a claim field cut or
 * re-typed, a box duplicated — with every enclosing LBox adjusted, nothing
 * else. Next to every `.bin` a `.png` carrier is written for c2patool. Run
 * from the repository root; prints the SHA-256 of every `.bin`. Tooling, not
 * product code.
 *
 * Offsets are those measured in notes/step-09 for the PNG store; the CBOR
 * pairs are located with a minimal definite-length walker; the script checks
 * the bytes it expects before it changes them.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

function claimU32(string $bytes, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $offset);

    return $u[1];
}

/**
 * Replaces $length bytes at $at with $with and adds the size difference to
 * the LBox at every offset in $enclosing.
 *
 * @param  list<int>  $enclosing
 */
function claimSplice(string $bytes, int $at, int $length, string $with, array $enclosing): string
{
    $delta = strlen($with) - $length;
    foreach ($enclosing as $lboxOffset) {
        $bytes = substr($bytes, 0, $lboxOffset).pack('N', claimU32($bytes, $lboxOffset) + $delta).substr($bytes, $lboxOffset + 4);
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + $length);
}

/** Replaces $expect at $at with $with of the same length, after checking what is there. */
function claimReplace(string $bytes, int $at, string $expect, string $with): string
{
    if (substr($bytes, $at, strlen($expect)) !== $expect || strlen($with) !== strlen($expect)) {
        throw new RuntimeException(sprintf('expected %s at %d (found %s)', bin2hex($expect), $at, bin2hex(substr($bytes, $at, strlen($expect)))));
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + strlen($expect));
}

/** The end offset of the definite-length CBOR item that starts at $p. */
function cborEnd(string $d, int $p): int
{
    $ib = ord($d[$p]);
    $mt = $ib >> 5;
    $ai = $ib & 0x1F;
    $q = $p + 1;
    $arg = $ai;
    if ($ai >= 24 && $ai <= 27) {
        $w = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai];
        $arg = (int) hexdec(bin2hex(substr($d, $q, $w)));
        $q += $w;
    } elseif ($ai > 27) {
        throw new RuntimeException("indefinite or reserved at {$p}");
    }

    return match ($mt) {
        0, 1, 7 => $q,
        2, 3 => $q + $arg,
        4 => array_reduce(range(1, max($arg, 0)), static fn (int $e): int => cborEnd($d, $e), $q),
        5 => array_reduce(range(1, max($arg * 2, 0)), static fn (int $e): int => cborEnd($d, $e), $q),
        default => cborEnd($d, $q),   // tag
    };
}

/**
 * The pairs of the CBOR map at $p: text key => [keyStart, valueStart, valueEnd].
 *
 * @return array<string, array{0: int, 1: int, 2: int}>
 */
function cborMapPairs(string $d, int $p): array
{
    $ib = ord($d[$p]);
    if ($ib >> 5 !== 5 || ($ib & 0x1F) > 23) {
        throw new RuntimeException("not a small map at {$p}");
    }
    $count = $ib & 0x1F;
    $q = $p + 1;
    $pairs = [];
    for ($i = 0; $i < $count; $i++) {
        $keyStart = $q;
        $keyEnd = cborEnd($d, $q);
        $key = substr($d, $keyStart + 1, $keyEnd - $keyStart - 1);   // text keys of ≤ 23 bytes
        $valueEnd = cborEnd($d, $keyEnd);
        $pairs[$key] = [$keyStart, $keyEnd, $valueEnd];
        $q = $valueEnd;
    }

    return $pairs;
}

/**
 * The map at $p with the pair $key cut out and the count lowered by one.
 *
 * @param  list<int>  $enclosing
 */
function cborCutPair(string $bytes, int $p, string $key, array $enclosing): string
{
    $pairs = cborMapPairs($bytes, $p);
    [$keyStart, , $valueEnd] = $pairs[$key] ?? throw new RuntimeException("no key {$key}");
    $bytes = claimSplice($bytes, $keyStart, $valueEnd - $keyStart, '', $enclosing);
    $count = ord($bytes[$p]) & 0x1F;
    if ($count < 1) {
        throw new RuntimeException("map at {$p} is already empty");
    }

    return claimReplace($bytes, $p, $bytes[$p], chr(0xA0 | ($count - 1)));
}

/** The fixture PNG with $store in place of its caBX chunk's data, CRC recomputed. */
function pngWithStore(string $png, string $store): string
{
    $oldLength = claimU32($png, 33);
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, 33).$chunk.substr($png, 33 + 12 + $oldLength);
}

/** @return string the manifest store of a fixture */
function claimStore(string $path, JpegManifestStoreExtractor|PngManifestStoreExtractor $extractor): string
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }
    $store = $extractor->extract($stream);
    if ($store === null) {
        throw new RuntimeException("no store in {$path}");
    }

    return $store->bytes;
}

$root = dirname(__DIR__);
$png = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$s = claimStore($root.'/tests/Fixtures/fixture-signed.png', new PngManifestStoreExtractor);
if (strlen($s) !== 46025) {
    throw new RuntimeException('the PNG store is not the one measured in step 09');
}
$adobe = claimStore($root.'/tests/Fixtures/public-testfiles/adobe-20220124-C.jpg', new JpegManifestStoreExtractor);

// Step 09 offsets (PNG store). Superbox header 8; jumd = 8 + uuid 16 + toggles 1 + label.
$ROOT = 0;
$MANIFEST = 38;
$ASSERTIONS = 117;
$ASSERTIONS_LABEL = 125 + 8 + 16 + 1;   // 150: 'c2pa.assertions'
$CLAIM = 33026;
$CLAIM_JUMD = 33034;
$CLAIM_LABEL = $CLAIM_JUMD + 8 + 16 + 1;   // 33059: 'c2pa.claim.v2'
$CLAIM_CBOR = 33073;
$CLAIM_DATA = 33081;   // the a7 map
$claimBox = [$ROOT, $MANIFEST, $CLAIM, $CLAIM_CBOR];
$pairs = cborMapPairs($s, $CLAIM_DATA);
$created = $pairs['created_assertions'];        // [keyStart, valueStart, valueEnd]; value = 81 a2 {url, hash}
$entryPairs = cborMapPairs($s, $created[1] + 1);   // the one entry map after the 0x81
$urlValue = $entryPairs['url'][1];                   // 78 29 'self#jumbf=c2pa.assertions/c2pa.hash.data'
$hashValue = $entryPairs['hash'][1];                 // 58 20 <32 bytes>
$cgi = $pairs['claim_generator_info'];
$cgiPairs = cborMapPairs($s, $cgi[1]);
$manifestLBox = claimU32($s, $MANIFEST);
$claimLBox = claimU32($s, $CLAIM);

$variants = [
    // AC8
    'claim-label-v3' => claimReplace($s, $CLAIM_LABEL, 'c2pa.claim.v2', 'c2pa.claim.v3'),
    // AC9: a required field cut out of the claim map
    'claim-no-signature' => cborCutPair($s, $CLAIM_DATA, 'signature', $claimBox),
    'claim-no-created-assertions' => cborCutPair($s, $CLAIM_DATA, 'created_assertions', $claimBox),
    'claim-no-instanceid' => cborCutPair($s, $CLAIM_DATA, 'instanceID', $claimBox),
    'claim-no-claim-generator-info' => cborCutPair($s, $CLAIM_DATA, 'claim_generator_info', $claimBox),
    // AC10: the hash-data URI pointing nowhere, and at the claim box
    'uri-not-found' => claimReplace($s, $urlValue + 2, 'self#jumbf=c2pa.assertions/c2pa.hash.data', 'self#jumbf=c2pa.assertions/c2pa.hash.datb'),
    'uri-wrong-place' => claimSplice($s, $urlValue, 2 + 41, "\x78\x18".'self#jumbf=c2pa.claim.v2', $claimBox),
    // AC11: the hash re-typed as text, and cut out
    'hash-as-text' => claimSplice($s, $hashValue, 2 + 32, "\x63abc", $claimBox),
    'hash-missing' => cborCutPair($s, $created[1] + 1, 'hash', $claimBox),
    // AC12: structure
    'second-claim' => claimSplice($s, $MANIFEST + $manifestLBox, 0, substr($s, $CLAIM, $claimLBox), [$ROOT, $MANIFEST]),
    'two-cbor-boxes' => claimSplice($s, $CLAIM + $claimLBox, 0, substr($s, $CLAIM_CBOR, claimU32($s, $CLAIM_CBOR)), [$ROOT, $MANIFEST, $CLAIM]),
    'assertion-store-label' => claimReplace($s, $ASSERTIONS_LABEL, 'c2pa.assertions', 'c2pa.assertionz'),
    'no-manifest' => claimReplace($s, $MANIFEST + 8 + 8, (string) hex2bin('63326d61'), (string) hex2bin('63326173')),
    // AC14
    'generator-info-no-name' => claimReplace($s, $cgiPairs['name'][0], "\x64name", "\x64nome"),
];

// AC13: the Adobe store's JSON assertion broken (its json box data begins with '{')
$jsonAt = strpos($adobe, 'json{');
if ($jsonAt === false) {
    throw new RuntimeException('no json box in the Adobe store');
}
$variants['json-broken'] = claimReplace($adobe, $jsonAt + 4, '{', '[');

$dir = $root.'/tests/Fixtures/claim';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.bin", $bytes);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $bytes));
    printf("%s  %6d  %s.bin\n", hash('sha256', $bytes), strlen($bytes), $name);
}
