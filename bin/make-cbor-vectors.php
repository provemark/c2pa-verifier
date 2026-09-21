<?php

declare(strict_types=1);

/*
 * SPEC-006: builds four claim-level CBOR variants of the PNG fixture's store
 * and re-embeds each in the PNG (CRC recomputed) so that c2patool can be
 * asked what it makes of a float, an indefinite-length array, a duplicate map
 * key, and a non-shortest integer inside a signed manifest. Each variant
 * splices a few bytes into one CBOR box and adjusts every enclosing LBox;
 * nothing else changes. Writes tests/Fixtures/cbor/<name>.cbor (the changed
 * box's data) and tests/Fixtures/cbor/<name>.png. Run from the repository
 * root; prints the SHA-256 of every file it writes. Tooling, not product code.
 *
 * Offsets are those measured in notes/step-09 for the PNG store; the script
 * checks the bytes it expects before it changes them.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

function cborU32(string $bytes, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $offset);

    return $u[1];
}

/** Overwrites a big-endian 4-byte field. */
function cborPutU32(string $bytes, int $offset, int $value): string
{
    return substr($bytes, 0, $offset).pack('N', $value).substr($bytes, $offset + 4);
}

/**
 * Replaces $expect at $at with $with (lengths may differ) and adds the size
 * difference to the LBox at every offset in $enclosing.
 *
 * @param  list<int>  $enclosing
 */
function cborSplice(string $bytes, int $at, string $expect, string $with, array $enclosing): string
{
    if (substr($bytes, $at, strlen($expect)) !== $expect) {
        throw new RuntimeException(sprintf('expected %s at %d, found %s', bin2hex($expect), $at, bin2hex(substr($bytes, $at, strlen($expect)))));
    }
    $delta = strlen($with) - strlen($expect);
    foreach ($enclosing as $lboxOffset) {
        $bytes = cborPutU32($bytes, $lboxOffset, cborU32($bytes, $lboxOffset) + $delta);
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + strlen($expect));
}

/** The one offset of $needle inside [$from, $to); an error if it is absent or ambiguous. */
function cborFind(string $bytes, string $needle, int $from, int $to): int
{
    $first = strpos($bytes, $needle, $from);
    if ($first === false || $first >= $to) {
        throw new RuntimeException(sprintf('%s not found between %d and %d', bin2hex($needle), $from, $to));
    }
    $second = strpos($bytes, $needle, $first + 1);
    if ($second !== false && $second < $to) {
        throw new RuntimeException(sprintf('%s found twice between %d and %d', bin2hex($needle), $from, $to));
    }

    return $first;
}

/** The fixture PNG with $store in place of its caBX chunk's data, CRC recomputed. */
function pngWithStore(string $png, string $store): string
{
    $cabxOffset = 33;
    $oldLength = cborU32($png, $cabxOffset);
    if (substr($png, $cabxOffset + 4, 4) !== 'caBX') {
        throw new RuntimeException('caBX chunk not at offset 33');
    }
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, $cabxOffset).$chunk.substr($png, $cabxOffset + 12 + $oldLength);
}

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

// Enclosing LBoxes (step 09): root 0, manifest 38; claim superbox 33026 and its cbor box 33073;
// assertion store 117, hash.data superbox 32831 and its cbor box 32903.
$claimBox = [0, 38, 33026, 33073];
$claimData = [33081, 33081 + 591];
$hashBox = [0, 38, 117, 32831, 32903];
$hashData = [32911, 32911 + 115];

$variants = [
    // created_assertions: a definite array of one (0x81) becomes an indefinite one (0x9f ... 0xff)
    'claim-indefinite-array' => static function () use ($s, $claimBox, $claimData): string {
        $arrayAt = cborFind($s, "\x72created_assertions", $claimData[0], $claimData[1]) + 19;
        $s2 = cborSplice($s, $arrayAt, "\x81", "\x9f", $claimBox);
        // the array's one element ends where the next key, gathered_assertions, begins
        $elementEnd = cborFind($s2, "\x73gathered_assertions", $arrayAt, $claimData[1]);

        return cborSplice($s2, $elementEnd, '', "\xff", $claimBox);
    },
    // claim_generator_info.version "0.0.0" (text, 6 bytes) becomes the float 0.0 (0xfa 00000000, 5 bytes)
    'claim-float' => static fn (): string => cborSplice($s, cborFind($s, "\x65\x30.0.0", $claimData[0], $claimData[1]), "\x65\x30.0.0", "\xfa\x00\x00\x00\x00", $claimBox),
    // the key "alg" becomes a second "dc:title": a duplicate map key
    'claim-duplicate-key' => static fn (): string => cborSplice($s, cborFind($s, "\x63alg\x66sha256", $claimData[0], $claimData[1]), "\x63alg", "\x68dc:title", $claimBox),
    // in c2pa.hash.data, the exclusion start 33 (0x18 0x21, shortest) becomes 0x19 0x0021 (two-byte, non-shortest)
    'hashdata-nonshortest-int' => static fn (): string => cborSplice($s, cborFind($s, "\x65start\x18\x21", $hashData[0], $hashData[1]) + 6, "\x18\x21", "\x19\x00\x21", $hashBox),
];

$dir = $root.'/tests/Fixtures/cbor';
foreach ($variants as $name => $build) {
    $store = $build();
    $boxOffset = str_starts_with($name, 'claim') ? 33073 : 32903;
    $data = substr($store, $boxOffset + 8, cborU32($store, $boxOffset) - 8);
    file_put_contents("{$dir}/{$name}.cbor", $data);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $store));
    printf("%s  %5d  %s.cbor\n", hash('sha256', $data), strlen($data), $name);
}
