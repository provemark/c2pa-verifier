<?php

declare(strict_types=1);

/*
 * SPEC-003: builds the malformed WebP variants under tests/Fixtures/webp/
 * from tests/Fixtures/fixture-signed.webp. Each variant moves whole chunks
 * or changes one field, nothing else, so that a reader can see exactly what
 * is wrong with each file. Run from the repository root; prints the SHA-256
 * of every file it writes. Tooling, not product code.
 */

/**
 * Splits a RIFF/WebP file into named chunks: one entry per chunk (type, or
 * type#n when a type repeats), each entry the whole chunk — type, length,
 * data, and the pad byte when the length is odd. The 12-byte RIFF header is
 * not a chunk; riffJoin() rebuilds it.
 *
 * @return array<string, string> name => bytes, in file order
 */
function riffChunks(string $data): array
{
    if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') {
        throw new RuntimeException('not a WebP');
    }
    $chunks = [];
    $p = 12;
    $seen = [];
    while ($p < strlen($data)) {
        $length = riffU32($data, $p + 4);
        $type = substr($data, $p, 4);
        $seen[$type] = ($seen[$type] ?? 0) + 1;
        $name = $seen[$type] > 1 ? $type.'#'.$seen[$type] : $type;
        $chunks[$name] = substr($data, $p, 8 + $length + ($length & 1));
        $p += 8 + $length + ($length & 1);
    }

    return $chunks;
}

/** Little-endian, as RIFF is. */
function riffU32(string $data, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('V', $data, $offset);

    return $u[1];
}

/** A whole chunk from its type and data, padded to an even length as RIFF requires. */
function riffChunk(string $type, string $data): string
{
    return $type.pack('V', strlen($data)).$data.(strlen($data) & 1 ? "\0" : '');
}

/** Overwrites a little-endian field of $width bytes at $offset with $value. */
function riffPut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = 0; $i < $width; $i++) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/** Overwrites a big-endian field (LBox inside the box is big-endian, unlike RIFF). */
function bePut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = $width - 1; $i >= 0; $i--) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/**
 * Joins chunks under a fresh RIFF header whose size field is correct
 * (file length minus 8), unless $riffSize says otherwise.
 *
 * @param  array<string, string>  $chunks
 * @param  list<string>  $order
 */
function riffJoin(array $chunks, array $order, ?int $riffSize = null, string $form = 'WEBP'): string
{
    $body = $form.implode('', array_map(static fn (string $name): string => $chunks[$name], $order));

    return 'RIFF'.pack('V', $riffSize ?? strlen($body)).$body;
}

$root = dirname(__DIR__);
$source = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.webp');
$c = riffChunks($source);
$normal = ['VP8L', 'C2PA'];
if (array_keys($c) !== $normal) {
    throw new RuntimeException('unexpected chunk order: '.implode(' ', array_keys($c)));
}
if (riffJoin($c, $normal) !== $source) {
    throw new RuntimeException('riffJoin does not reproduce the source');
}
$c2pa = $c['C2PA'];
$c2paLength = riffU32($c2pa, 4);
$c2paData = substr($c2pa, 8, $c2paLength);
$c2paOffset = 12 + strlen($c['VP8L']); // 312: where the C2PA chunk starts
$normalSize = strlen($source) - 8;

// In a chunk: type(4) length(4, LE) data pad. In the C2PA data: LBox(4, BE) TBox(4) ...
$variants = [
    // not a RIFF file at all
    'not-a-riff.bin' => "This is not a WebP. It does not start with RIFF.\n",
    // a RIFF file whose form type is not WEBP
    'riff-not-webp.webp' => riffJoin($c, $normal, null, 'WAVE'),
    // cut 1,000 bytes into the C2PA data
    'truncated-in-c2pa.webp' => substr($source, 0, $c2paOffset + 8 + 1000),
    // cut where the C2PA chunk header should start (RIFF size still claims the full file)
    'truncated-between-chunks.webp' => substr($source, 0, $c2paOffset),
    // the same C2PA chunk twice
    'two-c2pa.webp' => riffJoin(['C2PA#2' => $c2pa] + $c, ['VP8L', 'C2PA', 'C2PA#2']),
    // C2PA before the image data
    'c2pa-before-vp8l.webp' => riffJoin($c, ['C2PA', 'VP8L']),
    // the chunk length field +1, data untouched (the pad byte becomes data; the file is now one byte short)
    'length-differs.webp' => riffJoin(['C2PA' => riffPut($c2pa, 4, 4, $c2paLength + 1)] + $c, $normal),
    // LBox inside the box +1, chunk length untouched
    'lbox-differs.webp' => riffJoin(['C2PA' => bePut($c2pa, 8, 4, $c2paLength + 1)] + $c, $normal),
    // RIFF size in the header +1
    'riff-size-plus-one.webp' => riffJoin($c, $normal, $normalSize + 1),
    // RIFF size in the header as if the C2PA chunk were not there (the unsigned file's size)
    'riff-size-excludes-c2pa.webp' => riffJoin($c, $normal, 4 + strlen($c['VP8L'])),
    // a C2PA chunk of 4 bytes, shorter than a box header
    'c2pa-too-short.webp' => riffJoin(['C2PA' => riffChunk('C2PA', "\0\0\0\4")] + $c, $normal),
    // an empty C2PA chunk (length 0)
    'c2pa-empty.webp' => riffJoin(['C2PA' => riffChunk('C2PA', '')] + $c, $normal),
    // the odd-length C2PA chunk without its pad byte (file ends one byte early; RIFF size says so too)
    'pad-missing.webp' => riffJoin(['C2PA' => substr($c2pa, 0, -1)] + $c, $normal),
    // the pad byte is FF instead of 00
    'pad-nonzero.webp' => riffJoin(['C2PA' => substr($c2pa, 0, -1)."\xFF"] + $c, $normal),
    // an unknown odd-length chunk (3 bytes + pad) before C2PA: a reader that forgets the pad reads C2PA one byte off
    'odd-chunk-before.webp' => riffJoin(['XXXX' => riffChunk('XXXX', 'abc')] + $c, ['VP8L', 'XXXX', 'C2PA']),
];

$dir = $root.'/tests/Fixtures/webp';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
