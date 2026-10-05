<?php

declare(strict_types=1);

/*
 * Step 234: builds the FLAC variants under tests/Fixtures/flac/ from
 * tests/Fixtures/fixture-signed.flac and fixture-unsigned.flac, for the
 * measurement that comes before the FLAC spec. c2patool puts an ID3v2.4 tag
 * with the C2PA GEOB in front of the FLAC stream (C2PA 2.4 §A.3.4 names FLAC);
 * the tag itself is MP3's, measured in steps 225–232, so these variants are
 * about what is FLAC's own: where the stream marker `fLaC` stands. Unsigned
 * sources marked "source" are signed with c2patool by hand
 * (notes/step-234-flac-measured.md). Run from the repository root; prints the
 * SHA-256 of every file it writes. Tooling, not product code.
 */

function flacSyncsafe(int $value): string
{
    return chr(($value >> 21) & 0x7F).chr(($value >> 14) & 0x7F).chr(($value >> 7) & 0x7F).chr($value & 0x7F);
}

function flacTagEnd(string $data): int
{
    $size = 0;
    foreach (str_split(substr($data, 6, 4)) as $byte) {
        $size = ($size << 7) | (ord($byte) & 0x7F);
    }

    return 10 + $size;
}

$root = dirname(__DIR__);
$signed = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.flac');
$unsigned = (string) file_get_contents($root.'/tests/Fixtures/fixture-unsigned.flac');
if (! str_starts_with($signed, 'ID3') || ! str_starts_with($unsigned, 'fLaC')) {
    throw new RuntimeException('unexpected fixtures');
}
$end = flacTagEnd($signed);
$tag = substr($signed, 0, $end);
$stream = substr($signed, $end);
if ($stream !== $unsigned) {
    throw new RuntimeException('the signed file is not the tag followed by the unsigned stream');
}
// a small ID3v2.4 tag with one TIT2 frame, as a ripper might write before signing
$tit2 = "\x03".'Een titel';
$sourceTag = 'ID3'."\x04\0\0".flacSyncsafe(10 + strlen($tit2)).'TIT2'.flacSyncsafe(strlen($tit2))."\0\0".$tit2;

$variants = [
    // 16 zero bytes between the tag and the stream marker
    'zeros-after-tag.flac' => $tag.str_repeat("\0", 16).$stream,
    // the stream marker damaged: fLaD
    'marker-damaged.flac' => $tag.'fLaD'.substr($stream, 4),
    // the tag followed by MPEG audio's sync instead of FLAC (an ID3 tag on another stream)
    'tag-then-other.flac' => $tag.'XXXX'.substr($stream, 4),
    // the C2PA tag moved to the end of the file, the stream first
    'tag-at-end.flac' => $stream.$tag,
    // sources, signed by hand with c2patool:
    // an unsigned FLAC that already carries an ID3 tag
    'unsigned-with-id3.flac' => $sourceTag.$unsigned,
    // an unsigned FLAC with 16 zero bytes after an ID3 tag
    'unsigned-zeros-after-tag.flac' => $sourceTag.str_repeat("\0", 16).$unsigned,
];

$dir = $root.'/tests/Fixtures/flac';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
