<?php

declare(strict_types=1);

/*
 * M3 (SPEC-008…): builds signature-level variants of the PNG fixture's store
 * — a claim byte changed, a signature byte changed, the alg in the protected
 * header changed — each the same length as the original so that nothing but
 * the bytes named moves; and a PNG carrier per variant for c2patool. Writes
 * tests/Fixtures/cose/<name>.bin and .png; prints each .bin's SHA-256. Run
 * from the repository root. Tooling, not product code.
 */

require __DIR__.'/../vendor/autoload.php';

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

function coseU32(string $bytes, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $offset);

    return $u[1];
}

/** Replaces $expect at $at with $with of the same length, after checking what is there. */
function coseReplace(string $bytes, int $at, string $expect, string $with): string
{
    if (substr($bytes, $at, strlen($expect)) !== $expect || strlen($with) !== strlen($expect)) {
        throw new RuntimeException(sprintf('expected %s at %d, found %s', bin2hex($expect), $at, bin2hex(substr($bytes, $at, strlen($expect)))));
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + strlen($expect));
}

/** The one offset of $needle inside [$from, $to). */
function coseFind(string $bytes, string $needle, int $from, int $to): int
{
    $at = strpos($bytes, $needle, $from);
    if ($at === false || $at >= $to) {
        throw new RuntimeException(sprintf('%s not found between %d and %d', bin2hex($needle), $from, $to));
    }

    return $at;
}

function pngWithStore(string $png, string $store): string
{
    $oldLength = coseU32($png, 33);
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, 33).$chunk.substr($png, 33 + 12 + $oldLength);
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

// Step 09 offsets: the claim's cbor data at 33081 (591 bytes); the signature's cbor data at 33728 (12,297 bytes):
// d2 84 | 59 05 05 <1285 bytes protected: a2 01 26 18 21 82 ...> | a1 63 pad 59 2a b4 <10932> | f6 | 58 40 <64 bytes>.
$claimData = 33081;
$sigData = 33728;
$protected = $sigData + 5;                                   // after d2 84 59 05 05
$titleText = coseFind($s, "\x68dc:title\x72fixture-signed.png", $claimData, $claimData + 591) + 10;   // first byte of the title's text
$signatureBytes = $sigData + 12297 - 64;                     // the last 64 bytes of the box: the ES256 R||S

$variants = [
    // one byte of the claim changed: 'f' of the title → 'g'
    'claim-title-changed' => coseReplace($s, $titleText, 'f', 'g'),
    // one byte of the signature changed
    'signature-changed' => coseReplace($s, $signatureBytes, $s[$signatureBytes], chr(ord($s[$signatureBytes]) ^ 0x01)),
    // the protected header's alg −7 (ES256, 0x26) → −35 (ES384, 0x38 22): one byte longer, so the map's
    // encoded form must keep its length — use −8 (0x27, EdDSA) instead, same length, a key of the wrong kind for it
    'alg-eddsa-with-ec-key' => coseReplace($s, $protected, "\xa2\x01\x26", "\xa2\x01\x27"),
];

$dir = $root.'/tests/Fixtures/cose';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}.bin", $bytes);
    file_put_contents("{$dir}/{$name}.png", pngWithStore($png, $bytes));
    printf("%s  %6d  %s.bin\n", hash('sha256', $bytes), strlen($bytes), $name);
}
