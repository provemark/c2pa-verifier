<?php

declare(strict_types=1);

/*
 * Step 256: builds the GIF variants under tests/Fixtures/gif/ from
 * tests/Fixtures/fixture-signed.gif and fixture-unsigned.gif, for the
 * measurement that comes before a GIF spec. C2PA 2.4 §A.3.8 puts the store in
 * one Application Extension block (`21 FF 0B`, identifier `C2PA_GIF`,
 * authentication code `01 00 00` as the block's version), split over data
 * sub-blocks of at most 255 bytes, after the header and before the first image
 * descriptor. Each variant moves whole blocks or changes one field, nothing
 * else. Run from the repository root; prints the SHA-256 of every file it
 * writes. Tooling, not product code.
 */

/**
 * Splits a GIF into its parts: the head (header, logical screen descriptor and
 * global colour table), the blocks in file order (each whole: an extension
 * from its introducer to its terminator, an image from its descriptor to the
 * end of its data), and what follows the last block (the trailer and anything
 * after it).
 *
 * @return array{head: string, blocks: list<array{kind: string, bytes: string}>, tail: string}
 */
function gifParts(string $d): array
{
    if (! str_starts_with($d, 'GIF89a') && ! str_starts_with($d, 'GIF87a')) {
        throw new RuntimeException('not a GIF');
    }
    $flags = ord($d[10]);
    $p = 13 + (($flags & 0x80) !== 0 ? 3 * (2 ** (($flags & 7) + 1)) : 0);
    $head = substr($d, 0, $p);
    $blocks = [];
    while ($p < strlen($d) && $d[$p] !== "\x3B") {
        $start = $p;
        if ($d[$p] === "\x21") {
            $label = ord($d[$p + 1]);
            $q = $p + 2;
            $kind = $label === 0xFF ? 'app:'.substr($d, $q + 1, 8) : sprintf('ext:%02x', $label);
            $q += 1 + ord($d[$q]);
        } elseif ($d[$p] === "\x2C") {
            $lf = ord($d[$p + 9]);
            $q = $p + 10 + (($lf & 0x80) !== 0 ? 3 * (2 ** (($lf & 7) + 1)) : 0) + 1;
            $kind = 'image';
        } else {
            throw new RuntimeException(sprintf('unexpected byte 0x%02x at %d', ord($d[$p]), $p));
        }
        while ($d[$q] !== "\0") {
            $q += 1 + ord($d[$q]);
        }
        $p = $q + 1;
        $blocks[] = ['kind' => $kind, 'bytes' => substr($d, $start, $p - $start)];
    }

    return ['head' => $head, 'blocks' => $blocks, 'tail' => substr($d, $p)];
}

/** @param  array{head: string, blocks: list<array{kind: string, bytes: string}>, tail: string}  $parts */
function gifJoin(array $parts): string
{
    return $parts['head'].implode('', array_column($parts['blocks'], 'bytes')).$parts['tail'];
}

/**
 * An Application Extension with $identifier and $auth around $payload, split into sub-blocks of $chunk bytes.
 *
 * @param  int<1, 255>  $chunk
 */
function gifAppExtension(string $identifier, string $auth, string $payload, int $chunk = 255): string
{
    $sub = '';
    foreach ($payload === '' ? [] : str_split($payload, $chunk) as $piece) {
        $sub .= chr(min(255, strlen($piece))).$piece;
    }

    return "\x21\xFF\x0B".$identifier.$auth.$sub."\0";
}

/** The payload of an extension: its sub-blocks joined. */
function gifPayload(string $extension): string
{
    $q = 2;
    $q += 1 + ord($extension[$q]);
    $payload = '';
    while ($extension[$q] !== "\0") {
        $payload .= substr($extension, $q + 1, ord($extension[$q]));
        $q += 1 + ord($extension[$q]);
    }

    return $payload;
}

/**
 * The file of $head, $blocks and $tail joined.
 *
 * @param  array<array{kind: string, bytes: string}>  $blocks
 */
function gifWith(string $head, array $blocks, string $tail): string
{
    return gifJoin(['head' => $head, 'blocks' => array_values($blocks), 'tail' => $tail]);
}

$root = dirname(__DIR__);
$signed = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.gif');
$unsigned = (string) file_get_contents($root.'/tests/Fixtures/fixture-unsigned.gif');
$parts = gifParts($signed);
$c2pa = array_values(array_filter(array_keys($parts['blocks']), static fn (int $i): bool => $parts['blocks'][$i]['kind'] === 'app:C2PA_GIF'));
if (count($c2pa) !== 1 || $c2pa[0] !== 0) {
    throw new RuntimeException('the signed fixture is not one C2PA_GIF extension as its first block');
}
$ext = $parts['blocks'][0]['bytes'];
$store = gifPayload($ext);
if (substr($store, 4, 4) !== 'jumb') {
    throw new RuntimeException('the C2PA_GIF payload is not a JUMBF box');
}
$imageAt = array_search('image', array_column($parts['blocks'], 'kind'), true);
if (! is_int($imageAt)) {
    throw new RuntimeException('no image block');
}
$imageOffset = strlen($parts['head']) + array_sum(array_map(strlen(...), array_column(array_slice($parts['blocks'], 0, $imageAt), 'bytes')));
$extOffset = strlen($parts['head']);

$others = array_slice($parts['blocks'], 1);
$c2paBlock = $parts['blocks'][0];

$variants = [
    // ---- where the block is, and how many ----
    // a second, identical C2PA_GIF block right after the first
    'two-c2pa.gif' => gifWith($parts['head'], [$c2paBlock, $c2paBlock, ...$others], $parts['tail']),
    // the block moved after NETSCAPE2.0 and the graphic control extension, still before the image (§A.3.8 allows it)
    'c2pa-after-netscape.gif' => gifWith($parts['head'], [...array_slice($others, 0, $imageAt - 1), $c2paBlock, ...array_slice($others, $imageAt - 1)], $parts['tail']),
    // the block moved after the image, before the trailer (§A.3.8 says before the first image descriptor)
    'c2pa-after-image.gif' => gifWith($parts['head'], [...$others, $c2paBlock], $parts['tail']),
    // ---- the block's own fields ----
    // authentication code 02 00 00: a version 2.0 block
    'auth-2-0.gif' => substr_replace($signed, "\x02\x00\x00", $extOffset + 11, 3),
    // authentication code 01 01 00: version 1.1
    'auth-1-1.gif' => substr_replace($signed, "\x01\x01\x00", $extOffset + 11, 3),
    // identifier C2PA_GIX: an application extension of some other application
    'ident-other.gif' => substr_replace($signed, 'C2PA_GIX', $extOffset + 3, 8),
    // block size 0x0C instead of 0x0B: identifier and code are no longer where the size says
    'block-size-12.gif' => substr_replace($signed, "\x0C", $extOffset + 2, 1),
    // ---- the sub-blocks ----
    // the store re-split into sub-blocks of 100 bytes: the same store, a block of another length
    'rechunked-100.gif' => gifWith($parts['head'], [['kind' => 'app:C2PA_GIF', 'bytes' => gifAppExtension('C2PA_GIF', "\x01\x00\x00", $store, 100)], ...$others], $parts['tail']),
    // the 10th sub-block's size byte set to 0: the block ends early, the rest reads as stray bytes
    'early-terminator.gif' => substr_replace($signed, "\0", $extOffset + 14 + 9 * 256, 1),
    // a C2PA_GIF block with no sub-blocks at all
    'c2pa-empty.gif' => gifWith($parts['head'], [['kind' => 'app:C2PA_GIF', 'bytes' => gifAppExtension('C2PA_GIF', "\x01\x00\x00", '')], ...$others], $parts['tail']),
    // a C2PA_GIF block whose payload is not JUMBF
    'c2pa-not-jumbf.gif' => gifWith($parts['head'], [['kind' => 'app:C2PA_GIF', 'bytes' => gifAppExtension('C2PA_GIF', "\x01\x00\x00", str_repeat("\xAB", 600))], ...$others], $parts['tail']),
    // ---- the file around it ----
    // cut inside the C2PA_GIF block
    'truncated-in-c2pa.gif' => substr($signed, 0, $extOffset + 5000),
    // cut right after the C2PA_GIF block
    'truncated-after-c2pa.gif' => substr($signed, 0, $extOffset + strlen($ext)),
    // no trailer byte
    'no-trailer.gif' => substr($signed, 0, -1),
    // 16 bytes after the trailer
    'trailing-bytes.gif' => $signed.str_repeat("\0", 16),
    // the header made GIF87a, which has no extensions
    'gif87a.gif' => substr_replace($signed, 'GIF87a', 0, 6),
    // ---- one byte ----
    // one byte of image data flipped
    'flip-image.gif' => substr_replace($signed, chr(ord($signed[$imageOffset + 20]) ^ 0x01), $imageOffset + 20, 1),
    // one byte of the store flipped (in the claim's thumbnail data, far into the payload)
    'flip-store.gif' => substr_replace($signed, chr(ord($signed[$extOffset + 14 + 300 * 256 + 7]) ^ 0x01), $extOffset + 14 + 300 * 256 + 7, 1),
];

$dir = $root.'/tests/Fixtures/gif';
if (! is_dir($dir) && ! mkdir($dir, 0o755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
