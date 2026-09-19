<?php

declare(strict_types=1);

/*
 * SPEC-002: builds the malformed PNG variants under tests/Fixtures/png/
 * from tests/Fixtures/fixture-signed.png. Each variant moves whole chunks
 * or changes one field, nothing else, so that a reader can see exactly what
 * is wrong with each file. Run from the repository root; prints the SHA-256
 * of every file it writes. Tooling, not product code.
 */

const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

/**
 * Splits a PNG into named chunks: SIG, then one entry per chunk (type, or
 * type#n when a type repeats), each entry the whole chunk — length, type,
 * data, CRC.
 *
 * @return array<string, string> name => bytes, in file order
 */
function pngChunks(string $data): array
{
    if (! str_starts_with($data, PNG_SIGNATURE)) {
        throw new RuntimeException('not a PNG');
    }
    $chunks = ['SIG' => PNG_SIGNATURE];
    $p = 8;
    $seen = [];
    while ($p < strlen($data)) {
        $length = pngU32($data, $p);
        $type = substr($data, $p + 4, 4);
        $seen[$type] = ($seen[$type] ?? 0) + 1;
        $name = $seen[$type] > 1 ? $type.'#'.$seen[$type] : $type;
        $chunks[$name] = substr($data, $p, 12 + $length);
        $p += 12 + $length;
    }

    return $chunks;
}

function pngU32(string $data, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $data, $offset);

    return $u[1];
}

/** A whole chunk from its type and data, with the CRC computed as the PNG spec says. */
function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** Overwrites a big-endian field of $width bytes at $offset with $value. */
function pngPut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = $width - 1; $i >= 0; $i--) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/**
 * @param  array<string, string>  $chunks
 * @param  list<string>  $order
 */
function pngJoin(array $chunks, array $order): string
{
    return implode('', array_map(static fn (string $name): string => $chunks[$name], $order));
}

$root = dirname(__DIR__);
$source = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.png');
$c = pngChunks($source);
$normal = ['SIG', 'IHDR', 'caBX', 'pHYs', 'IDAT', 'IEND'];
if (array_keys($c) !== $normal) {
    throw new RuntimeException('unexpected chunk order: '.implode(' ', array_keys($c)));
}
$cabx = $c['caBX'];
$cabxData = substr($cabx, 8, -4);
$cabxOffset = strlen($c['SIG'].$c['IHDR']); // 33: where the caBX chunk starts

// In a chunk: length(4) type(4) data CRC(4). In the caBX data: LBox(4) TBox(4) ...
$variants = [
    // not a PNG at all
    'not-a-png.bin' => "This is not a PNG. It does not start with the eight signature bytes.\n",
    // cut 1,000 bytes into the caBX data
    'truncated-in-cabx.png' => substr($source, 0, $cabxOffset + 8 + 1000),
    // caBX after IDAT
    'cabx-after-idat.png' => pngJoin($c, ['SIG', 'IHDR', 'pHYs', 'IDAT', 'caBX', 'IEND']),
    // caBX before IHDR (the PNG spec requires IHDR first)
    'cabx-before-ihdr.png' => pngJoin($c, ['SIG', 'caBX', 'IHDR', 'pHYs', 'IDAT', 'IEND']),
    // the same caBX chunk twice
    'two-cabx.png' => pngJoin(['caBX#2' => $cabx] + $c, ['SIG', 'IHDR', 'caBX', 'caBX#2', 'pHYs', 'IDAT', 'IEND']),
    // one bit flipped in the CRC of caBX (data untouched)
    'crc-wrong.png' => pngJoin(['caBX' => pngPut($cabx, strlen($cabx) - 1, 1, ord($cabx[strlen($cabx) - 1]) ^ 0x01)] + $c, $normal),
    // the chunk length field +1, data and CRC untouched (so the CRC no longer matches either)
    'length-differs.png' => pngJoin(['caBX' => pngPut($cabx, 0, 4, strlen($cabxData) + 1)] + $c, $normal),
    // LBox inside the box +1, chunk length untouched, CRC recomputed (a box that claims to be longer than its chunk)
    'lbox-differs.png' => pngJoin(['caBX' => pngChunk('caBX', pngPut($cabxData, 0, 4, strlen($cabxData) + 1))] + $c, $normal),
    // a caBX chunk of 4 bytes, shorter than a box header
    'cabx-too-short.png' => pngJoin(['caBX' => pngChunk('caBX', "\0\0\0\4")] + $c, $normal),
    // an empty caBX chunk (length 0)
    'cabx-empty.png' => pngJoin(['caBX' => pngChunk('caBX', '')] + $c, $normal),
];

$dir = $root.'/tests/Fixtures/png';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
