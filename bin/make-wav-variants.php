<?php

declare(strict_types=1);

/*
 * Step 204: builds the malformed WAV variants under tests/Fixtures/wav/
 * from tests/Fixtures/fixture-signed.wav, for the measurement that comes
 * before the WAV spec. The RIFF part follows bin/make-webp-variants.php
 * (SPEC-003), so the two formats can be compared case by case; the cases
 * after it are WAV's own (a LIST chunk, RF64, a second RIFF chunk). Each
 * variant moves whole chunks or changes one field, nothing else. Run from
 * the repository root; prints the SHA-256 of every file it writes.
 * Tooling, not product code.
 */

/**
 * Splits a RIFF file into named chunks: one entry per chunk (type, or
 * type#n when a type repeats), each entry the whole chunk — type, length,
 * data, and the pad byte when the length is odd. The 12-byte RIFF header is
 * not a chunk; wavJoin() rebuilds it.
 *
 * @return array<string, string> name => bytes, in file order
 */
function wavChunks(string $data): array
{
    if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
        throw new RuntimeException('not a WAV');
    }
    $chunks = [];
    $p = 12;
    $seen = [];
    while ($p < strlen($data)) {
        $length = wavU32($data, $p + 4);
        $type = substr($data, $p, 4);
        $seen[$type] = ($seen[$type] ?? 0) + 1;
        $name = $seen[$type] > 1 ? $type.'#'.$seen[$type] : $type;
        $chunks[$name] = substr($data, $p, 8 + $length + ($length & 1));
        $p += 8 + $length + ($length & 1);
    }

    return $chunks;
}

/** Little-endian, as RIFF is. */
function wavU32(string $data, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('V', $data, $offset);

    return $u[1];
}

/** A whole chunk from its type and data, padded to an even length as RIFF requires. */
function wavChunk(string $type, string $data): string
{
    return $type.pack('V', strlen($data)).$data.(strlen($data) & 1 ? "\0" : '');
}

/** Overwrites a little-endian field of $width bytes at $offset with $value. */
function wavPut(string $bytes, int $offset, int $width, int $value): string
{
    for ($i = 0; $i < $width; $i++) {
        $bytes[$offset + $i] = chr($value & 0xFF);
        $value >>= 8;
    }

    return $bytes;
}

/** Overwrites a big-endian field (LBox inside the box is big-endian, unlike RIFF). */
function wavBePut(string $bytes, int $offset, int $width, int $value): string
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
function wavJoin(array $chunks, array $order, ?int $riffSize = null, string $form = 'WAVE', string $id = 'RIFF'): string
{
    $body = $form.implode('', array_map(static fn (string $name): string => $chunks[$name], $order));

    return $id.pack('V', $riffSize ?? strlen($body)).$body;
}

$root = dirname(__DIR__);
$source = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.wav');
$c = wavChunks($source);
$normal = ['fmt ', 'LIST', 'data', 'C2PA'];
if (array_keys($c) !== $normal) {
    throw new RuntimeException('unexpected chunk order: '.implode(' ', array_keys($c)));
}
if (wavJoin($c, $normal) !== $source) {
    throw new RuntimeException('wavJoin does not reproduce the source');
}
$c2pa = $c['C2PA'];
$c2paLength = wavU32($c2pa, 4);
if (($c2paLength & 1) !== 1) {
    throw new RuntimeException('the fixture\'s C2PA chunk is expected to be odd, so that it carries a pad byte');
}
$c2paOffset = 12 + strlen($c['fmt ']) + strlen($c['LIST']) + strlen($c['data']);
$normalSize = strlen($source) - 8;

// The LIST chunk: 'LIST' length(4) list-type(4) sub-chunks. The C2PA chunk moved inside it, after its sub-chunks.
$list = $c['LIST'];
$listBody = substr($list, 8, wavU32($list, 4));
$listWithC2pa = wavChunk('LIST', $listBody.$c2pa);

// RF64 (EBU Tech 3306): 'RF64', size FFFFFFFF, form, then a 'ds64' chunk first holding the real sizes.
$ds64 = wavChunk('ds64', pack('P', $normalSize + 36).pack('P', strlen($c['data']) - 8).pack('P', 0).pack('V', 0));

$variants = [
    // a RIFF file whose form type is neither WAVE nor WEBP nor AVI
    'riff-form-xxxx.wav' => wavJoin($c, $normal, null, 'XXXX'),
    // cut 1,000 bytes into the C2PA data
    'truncated-in-c2pa.wav' => substr($source, 0, $c2paOffset + 8 + 1000),
    // cut where the C2PA chunk header should start (RIFF size still claims the full file)
    'truncated-between-chunks.wav' => substr($source, 0, $c2paOffset),
    // the same C2PA chunk twice, both at the end
    'two-c2pa.wav' => wavJoin(['C2PA#2' => $c2pa] + $c, [...$normal, 'C2PA#2']),
    // C2PA before the audio data: not the last chunk (C2PA 2.4 §A.3.7 says it shall be last)
    'c2pa-before-data.wav' => wavJoin($c, ['fmt ', 'LIST', 'C2PA', 'data']),
    // C2PA as the very first chunk
    'c2pa-first.wav' => wavJoin($c, ['C2PA', 'fmt ', 'LIST', 'data']),
    // an unknown chunk appended after C2PA, so C2PA is no longer last
    'chunk-after-c2pa.wav' => wavJoin(['XXXX' => wavChunk('XXXX', 'abcd')] + $c, [...$normal, 'XXXX']),
    // C2PA nested inside the LIST chunk instead of at the top level
    'c2pa-in-list.wav' => wavJoin(['LIST' => $listWithC2pa] + $c, ['fmt ', 'LIST', 'data']),
    // the chunk length field +1, data untouched (the pad byte becomes data)
    'length-differs.wav' => wavJoin(['C2PA' => wavPut($c2pa, 4, 4, $c2paLength + 1)] + $c, $normal),
    // LBox inside the box +1, chunk length untouched
    'lbox-differs.wav' => wavJoin(['C2PA' => wavBePut($c2pa, 8, 4, $c2paLength + 1)] + $c, $normal),
    // RIFF size in the header +1
    'riff-size-plus-one.wav' => wavJoin($c, $normal, $normalSize + 1),
    // RIFF size in the header as if the C2PA chunk were not there (the unsigned file's size)
    'riff-size-excludes-c2pa.wav' => wavJoin($c, $normal, 4 + strlen($c['fmt ']) + strlen($c['LIST']) + strlen($c['data'])),
    // a C2PA chunk of 4 bytes, shorter than a box header
    'c2pa-too-short.wav' => wavJoin(['C2PA' => wavChunk('C2PA', "\0\0\0\4")] + $c, $normal),
    // an empty C2PA chunk (length 0)
    'c2pa-empty.wav' => wavJoin(['C2PA' => wavChunk('C2PA', '')] + $c, $normal),
    // the odd-length C2PA chunk without its pad byte (RIFF size one less)
    'pad-missing.wav' => wavJoin(['C2PA' => substr($c2pa, 0, -1)] + $c, $normal),
    // the pad byte is FF instead of 00 (step 203: the pad byte is hashed, not excluded)
    'pad-nonzero.wav' => wavJoin(['C2PA' => substr($c2pa, 0, -1)."\xFF"] + $c, $normal),
    // an unknown odd-length chunk (3 bytes + pad) before C2PA: a reader that forgets the pad reads C2PA one byte off
    'odd-chunk-before.wav' => wavJoin(['XXXX' => wavChunk('XXXX', 'abc')] + $c, ['fmt ', 'LIST', 'data', 'XXXX', 'C2PA']),
    // the C2PA length field +1,000 with the RIFF size correct for the file
    'chunk-overruns-file.wav' => wavJoin(['C2PA' => wavPut($c2pa, 4, 4, $c2paLength + 1000)] + $c, $normal),
    // 100 bytes after the end of the RIFF chunk, RIFF size unchanged
    'trailing-bytes.wav' => $source.str_repeat("\xAA", 100),
    // a second RIFF chunk after the first, holding a copy of the C2PA chunk (§A.3.7 names the first RIFF chunk)
    'second-riff.wav' => $source.wavJoin(['C2PA' => $c2pa], ['C2PA']),
    // RF64: the 64-bit form of WAV, 'RF64' with size FFFFFFFF and a ds64 chunk first
    'rf64.wav' => wavJoin(['ds64' => $ds64] + $c, ['ds64', ...$normal], 0xFFFFFFFF, 'WAVE', 'RF64'),
];

$dir = $root.'/tests/Fixtures/wav';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
