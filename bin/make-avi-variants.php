<?php

declare(strict_types=1);

/*
 * Step 209: builds the AVI variants under tests/Fixtures/avi/, for the
 * measurement that comes before the AVI spec. Two sources:
 *
 * - tests/Fixtures/fixture-signed.avi, one RIFF chunk: a short set of the
 *   cases SPEC-003 and SPEC-055 already cover for WebP and WAV, to see that
 *   c2patool treats the form `AVI ` the same, plus `C2PA` nested in `movi`.
 * - tests/Fixtures/avi/signed-avix.avi, two RIFF chunks (`AVI `, then
 *   `AVIX`, as an OpenDML file over 1 GB has): its unsigned source
 *   `unsigned-avix.avi` is written by this script, then signed with
 *   c2patool by hand (notes/step-209-avi-measured.md); when the signed
 *   file is present, the script also builds the variants of it.
 *
 * Each variant moves whole chunks or changes one field, nothing else. Run
 * from the repository root; prints the SHA-256 of every file it writes.
 * Tooling, not product code.
 */

/** Little-endian, as RIFF is. */
function aviU32(string $data, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('V', $data, $offset);

    return $u[1];
}

/** A whole chunk from its type and data, padded to an even length as RIFF requires. */
function aviChunk(string $type, string $data): string
{
    return $type.pack('V', strlen($data)).$data.(strlen($data) & 1 ? "\0" : '');
}

/** A RIFF or LIST chunk: identifier, size, form or list type, the sub-chunks. */
function aviList(string $id, string $form, string $body): string
{
    return $id.pack('V', 4 + strlen($body)).$form.$body;
}

/** Overwrites a little-endian field of $width bytes at $offset with $value. */
function aviPut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = 0; $i < $width; $i++) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/** Overwrites a big-endian field (LBox inside the box is big-endian, unlike RIFF). */
function aviBePut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = $width - 1; $i >= 0; $i--) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/**
 * The sub-chunks of one RIFF chunk starting at $start, by name (type, or
 * type#n when a type repeats), each the whole chunk with its pad byte.
 *
 * @return array{form: string, end: int, chunks: array<string, string>}
 */
function aviRiff(string $data, int $start): array
{
    if (substr($data, $start, 4) !== 'RIFF') {
        throw new RuntimeException("no RIFF chunk at {$start}");
    }
    $end = $start + 8 + aviU32($data, $start + 4);
    $chunks = [];
    $seen = [];
    $p = $start + 12;
    while ($p < $end) {
        $length = aviU32($data, $p + 4);
        $type = substr($data, $p, 4);
        $seen[$type] = ($seen[$type] ?? 0) + 1;
        $chunks[$seen[$type] > 1 ? $type.'#'.$seen[$type] : $type] = substr($data, $p, 8 + $length + ($length & 1));
        $p += 8 + $length + ($length & 1);
    }

    return ['form' => substr($data, $start + 8, 4), 'end' => $end, 'chunks' => $chunks];
}

/**
 * @param  array<string, string>  $chunks
 * @param  list<string>  $order
 */
function aviJoin(array $chunks, array $order, string $form = 'AVI ', ?int $size = null): string
{
    $body = implode('', array_map(static fn (string $name): string => $chunks[$name], $order));

    return 'RIFF'.pack('V', $size ?? 4 + strlen($body)).$form.$body;
}

$root = dirname(__DIR__);
$dir = $root.'/tests/Fixtures/avi';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}

// The unsigned two-RIFF source: the unsigned fixture, then an `AVIX` RIFF chunk holding a
// `movi` list with one 100-byte frame, as an OpenDML continuation does.
$unsigned = (string) file_get_contents($root.'/tests/Fixtures/fixture-unsigned.avi');
$avix = aviList('RIFF', 'AVIX', aviList('LIST', 'movi', aviChunk('00dc', str_repeat("\x11", 100))));
$variants = ['unsigned-avix.avi' => $unsigned.$avix];

// One RIFF chunk: the signed fixture.
$source = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.avi');
$r = aviRiff($source, 0);
$c = $r['chunks'];
$normal = ['LIST', 'LIST#2', 'JUNK', 'LIST#3', 'idx1', 'C2PA'];
if (array_keys($c) !== $normal || $r['end'] !== strlen($source) || aviJoin($c, $normal) !== $source) {
    throw new RuntimeException('unexpected layout: '.implode(' ', array_keys($c)));
}
$c2pa = $c['C2PA'];
$c2paLength = aviU32($c2pa, 4);
$c2paOffset = strlen($source) - strlen($c2pa);
$movi = $c['LIST#3'];
$moviWithC2pa = aviList('LIST', 'movi', substr($movi, 12).$c2pa);

$variants += [
    // the same C2PA chunk twice, both at the end
    'two-c2pa.avi' => aviJoin(['C2PA#2' => $c2pa] + $c, [...$normal, 'C2PA#2']),
    // C2PA before the idx1 index: not the last chunk
    'c2pa-before-idx1.avi' => aviJoin($c, ['LIST', 'LIST#2', 'JUNK', 'LIST#3', 'C2PA', 'idx1']),
    // C2PA nested inside the movi list instead of at the top level
    'c2pa-in-movi.avi' => aviJoin(['LIST#3' => $moviWithC2pa] + $c, ['LIST', 'LIST#2', 'JUNK', 'LIST#3', 'idx1']),
    // LBox inside the box +1, chunk length untouched
    'lbox-differs.avi' => aviJoin(['C2PA' => aviBePut($c2pa, 8, 4, $c2paLength + 1)] + $c, $normal),
    // the chunk length field +1, data untouched (the pad byte becomes data)
    'length-differs.avi' => aviJoin(['C2PA' => aviPut($c2pa, 4, 4, $c2paLength + 1)] + $c, $normal),
    // the pad byte FF instead of 00
    'pad-nonzero.avi' => aviJoin(['C2PA' => substr($c2pa, 0, -1)."\xFF"] + $c, $normal),
    // RIFF size in the header +1
    'riff-size-plus-one.avi' => aviJoin($c, $normal, 'AVI ', strlen($source) - 8 + 1),
    // cut 1,000 bytes into the C2PA data
    'truncated-in-c2pa.avi' => substr($source, 0, $c2paOffset + 8 + 1000),
    // 100 bytes after the end of the RIFF chunk that are not a RIFF chunk
    'trailing-bytes.avi' => $source.str_repeat("\xAA", 100),
];

// Two RIFF chunks: the c2patool-signed two-RIFF file, when it exists.
$signedAvixPath = $dir.'/signed-avix.avi';
if (is_file($signedAvixPath)) {
    $s = (string) file_get_contents($signedAvixPath);
    $first = aviRiff($s, 0);
    $second = aviRiff($s, $first['end']);
    if ($second['form'] !== 'AVIX' || $second['end'] !== strlen($s) || ! isset($first['chunks']['C2PA'])) {
        throw new RuntimeException('signed-avix.avi: unexpected layout');
    }
    $head = substr($s, 0, $first['end']);
    $tail = substr($s, $first['end']);
    $sc = $first['chunks'];
    $sNormal = array_keys($sc);
    $sC2pa = $sc['C2PA'];
    $tailBody = substr($tail, 12);
    $withoutC2pa = array_values(array_filter($sNormal, static fn (string $n): bool => $n !== 'C2PA'));

    $variants += [
        // one byte of the AVIX frame flipped: the second RIFF chunk is hashed
        'avix-byte-flipped.avi' => $head.aviPut($tail, strlen($tail) - 5, 1, ord($tail[strlen($tail) - 5]) ^ 1),
        // the file cut 10 bytes into the AVIX chunk: its size now runs past the end
        'avix-truncated.avi' => substr($s, 0, strlen($s) - 10),
        // the AVIX chunk's size +1, file unchanged
        'avix-size-plus-one.avi' => $head.aviPut($tail, 4, 4, aviU32($tail, 4) + 1),
        // 100 bytes after the AVIX chunk that are not a RIFF chunk
        'avix-trailing-bytes.avi' => $s.str_repeat("\xAA", 100),
        // the second chunk's form AVI instead of AVIX
        'second-form-avi.avi' => $head.substr_replace($tail, 'AVI ', 8, 4),
        // a copy of the C2PA chunk appended inside the AVIX chunk
        'avix-with-c2pa.avi' => $head.aviList('RIFF', 'AVIX', $tailBody.$sC2pa),
        // the C2PA chunk moved out of the first RIFF chunk into the AVIX chunk
        'c2pa-only-in-avix.avi' => aviJoin($sc, $withoutC2pa).aviList('RIFF', 'AVIX', $tailBody.$sC2pa),
    ];
}

foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
