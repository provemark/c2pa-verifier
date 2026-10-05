<?php

declare(strict_types=1);

/*
 * Step 225: builds the MP3 variants under tests/Fixtures/mp3/ from
 * tests/Fixtures/fixture-signed.mp3 and fixture-unsigned.mp3, for the
 * measurement that comes before the MP3 spec. The store sits in an ID3v2
 * tag, as the encapsulated object of a GEOB frame (C2PA 2.4 §A.3.4). Each
 * variant changes one thing about the tag or the frame and rebuilds the
 * sizes around it, unless the variant is about a size. Run from the
 * repository root; prints the SHA-256 of every file it writes. Tooling,
 * not product code.
 */

/** A syncsafe integer: 7 bits per byte, the top bit always 0 (ID3v2.4 sizes). */
function mp3Syncsafe(int $value): string
{
    return chr(($value >> 21) & 0x7F).chr(($value >> 14) & 0x7F).chr(($value >> 7) & 0x7F).chr($value & 0x7F);
}

function mp3Unsyncsafe(string $bytes): int
{
    $value = 0;
    foreach (str_split($bytes) as $byte) {
        $value = ($value << 7) | (ord($byte) & 0x7F);
    }

    return $value;
}

/**
 * The frames of an ID3v2.4 tag at offset 0, by name (id, or id#n), each
 * the whole frame: id, syncsafe size, flags, body.
 *
 * @return array{frames: array<string, string>, end: int}
 */
function mp3Frames(string $data): array
{
    if (substr($data, 0, 3) !== 'ID3' || ord($data[3]) !== 4) {
        throw new RuntimeException('not an ID3v2.4 tag');
    }
    $end = 10 + mp3Unsyncsafe(substr($data, 6, 4));
    $frames = [];
    $seen = [];
    $p = 10;
    while ($p + 10 <= $end && substr($data, $p, 4) !== "\0\0\0\0") {
        $id = substr($data, $p, 4);
        $size = mp3Unsyncsafe(substr($data, $p + 4, 4));
        $seen[$id] = ($seen[$id] ?? 0) + 1;
        $frames[$seen[$id] > 1 ? $id.'#'.$seen[$id] : $id] = substr($data, $p, 10 + $size);
        $p += 10 + $size;
    }

    return ['frames' => $frames, 'end' => $end];
}

/** A v2.4 frame from its id, body and flags. */
function mp3Frame(string $id, string $body, string $flags = "\0\0"): string
{
    return $id.mp3Syncsafe(strlen($body)).$flags.$body;
}

/**
 * A GEOB body: encoding byte, MIME, file name, description, object.
 *
 * @param  0|1|2|3  $encoding
 */
function mp3Geob(string $object, string $mime = 'application/c2pa', int $encoding = 3, string $file = 'c2pa', string $description = 'c2pa manifest store'): string
{
    $terminator = $encoding === 1 || $encoding === 2 ? "\0\0" : "\0";
    $text = static fn (string $s): string => $encoding === 1 ? "\xFF\xFE".mb_convert_encoding($s, 'UTF-16LE', 'UTF-8') : ($encoding === 2 ? mb_convert_encoding($s, 'UTF-16BE', 'UTF-8') : $s);

    return chr($encoding).$mime."\0".$text($file).$terminator.$text($description).$terminator.$object;
}

/**
 * A tag (v2.4 unless $version says 3) from frames, padding and header flags,
 * with the size computed unless $size says otherwise.
 *
 * @param  list<string>  $frames
 * @param  int<0, 255>  $flags
 * @param  3|4  $version
 */
function mp3Tag(array $frames, string $padding = '', int $flags = 0, ?int $size = null, int $version = 4): string
{
    $body = implode('', $frames).$padding;

    return 'ID3'.chr($version)."\0".chr($flags).mp3Syncsafe($size ?? strlen($body)).$body;
}

/** The GEOB frame's object (the store), from a whole frame. */
function mp3GeobObject(string $frame): string
{
    $body = substr($frame, 10);
    $q = strpos($body, "\0", 1) + 1;   // MIME
    $q = strpos($body, "\0", $q) + 1;  // file name (UTF-8)
    $q = strpos($body, "\0", $q) + 1;  // description (UTF-8)

    return substr($body, $q);
}

$root = dirname(__DIR__);
$signed = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.mp3');
$unsigned = (string) file_get_contents($root.'/tests/Fixtures/fixture-unsigned.mp3');
$t = mp3Frames($signed);
$f = $t['frames'];
if (array_keys($f) !== ['TSSE', 'GEOB']) {
    throw new RuntimeException('unexpected frames: '.implode(' ', array_keys($f)));
}
$audio = substr($signed, $t['end']);
if (mp3Tag([$f['TSSE'], $f['GEOB']]).$audio !== $signed) {
    throw new RuntimeException('mp3Tag does not reproduce the source');
}
$store = mp3GeobObject($f['GEOB']);
$tsse = $f['TSSE'];
$geob = $f['GEOB'];
$u = mp3Frames($unsigned);
$unsignedAudio = substr($unsigned, $u['end']);

// an ID3v2.3 frame: the same, with a plain big-endian size
$v3 = static fn (string $frame): string => substr($frame, 0, 4).pack('N', strlen($frame) - 10).substr($frame, 8);

$footerTag = mp3Tag([$tsse, $geob], '', 0x10);

$variants = [
    // the same GEOB frame twice
    'two-geob.mp3' => mp3Tag([$tsse, $geob, $geob]).$audio,
    // the GEOB's MIME type is not application/c2pa (§A.3.4: it shall be)
    'mime-octet-stream.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'application/octet-stream'))]).$audio,
    // the MIME type upper-case
    'mime-upper-case.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'APPLICATION/C2PA'))]).$audio,
    // another GEOB (not C2PA) before the C2PA one
    'other-geob-first.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob('hello', 'text/plain', 3, 'note', 'a note')), $geob]).$audio,
    // text encoding 0 (ISO-8859-1): one-byte terminators
    'encoding-latin1.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'application/c2pa', 0))]).$audio,
    // text encoding 1 (UTF-16 with BOM): two-byte terminators
    'encoding-utf16.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'application/c2pa', 1))]).$audio,
    // text encoding 2 (UTF-16BE without BOM)
    'encoding-utf16be.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'application/c2pa', 2))]).$audio,
    // the tag as ID3v2.3: frame sizes as plain integers
    'version-2-3.mp3' => mp3Tag([$v3($tsse), $v3($geob)], '', 0, null, 3).$audio,
    // the header's unsynchronisation flag set, the bytes left as they are
    'flag-unsynchronisation.mp3' => mp3Tag([$tsse, $geob], '', 0x80).$audio,
    // the extended-header flag set with a minimal v2.4 extended header (size 6, one flag byte, no flags)
    'flag-extended-header.mp3' => mp3Tag([mp3Syncsafe(6)."\x01\x00".$tsse, $geob], '', 0x40).$audio,
    // the footer flag set and a footer ("3DI") after the tag
    'flag-footer.mp3' => $footerTag.'3DI'.substr($footerTag, 3, 7).$audio,
    // padding (zeros) after the GEOB frame, inside the tag
    'padding-after.mp3' => mp3Tag([$tsse, $geob], str_repeat("\0", 64)).$audio,
    // the GEOB frame's data-length-indicator and compression flags set (v2.4 format flags 0x09)
    'frame-flags-compressed.mp3' => mp3Tag([$tsse, substr($geob, 0, 8)."\x00\x09".substr($geob, 10)]).$audio,
    // LBox inside the store +1, frame untouched
    'lbox-differs.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob(substr_replace($store, pack('N', strlen($store) + 1), 0, 4)))]).$audio,
    // a GEOB whose object is 4 bytes, shorter than a box header
    'object-too-short.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob("\0\0\0\4"))]).$audio,
    // a GEOB with an empty object
    'object-empty.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob(''))]).$audio,
    // the GEOB frame's size +1000, running past the tag
    'frame-overruns-tag.mp3' => mp3Tag([$tsse, 'GEOB'.mp3Syncsafe(strlen($geob) - 10 + 1000).substr($geob, 8)]).$audio,
    // the tag size field +1 (one byte of audio is read as tag)
    'tag-size-plus-one.mp3' => mp3Tag([$tsse, $geob], '', 0, strlen($tsse) + strlen($geob) + 1).$audio,
    // a tag size field byte with its top bit set (not syncsafe)
    'tag-size-not-syncsafe.mp3' => substr_replace($signed, chr(ord($signed[6]) | 0x80), 6, 1),
    // the file cut 1,000 bytes into the store
    'truncated-in-store.mp3' => substr($signed, 0, 86 + 1000),
    // 128-byte ID3v1 tag appended
    'id3v1-appended.mp3' => $signed.'TAG'.str_repeat("\0", 125),
    // the C2PA GEOB in a second ID3v2 tag appended at the end, the first tag without it
    'store-in-appended-tag.mp3' => mp3Tag([$tsse]).$audio.mp3Tag([$geob]),
    // 16 bytes of junk before the tag
    'junk-before-tag.mp3' => str_repeat("\0", 16).$signed,
    // the unsigned file, its tag removed: plain MPEG frames
    'unsigned-no-tag.mp3' => $unsignedAudio,
    // the unsigned file with a 128-byte ID3v1 tag appended
    'unsigned-id3v1.mp3' => $unsigned.'TAG'.str_repeat("\0", 125),

    // step 230 (SPEC-056 amendment 2)
    // the legacy JUMBF media type, which c2patool accepts too
    'mime-legacy.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'application/x-c2pa-manifest-store'))]).$audio,
    // a 200-byte TSSE whose v2.4 size is written as a plain integer (00 00 00 C8), as iTunes writes it
    'tsse-plain-size.mp3' => mp3Tag(['TSSE'."\x00\x00\x00\xC8"."\0\0"."\x03".str_repeat('x', 199), $geob]).$audio,
    // a frame whose id is not four capitals or digits, before the GEOB
    'frame-id-invalid.mp3' => mp3Tag(["x\x01ab".mp3Syncsafe(4)."\0\0".'abcd', $geob]).$audio,
    // the unsynchronisation flag, and FF 00 inside the tag (in a TXXX before the GEOB)
    'unsync-ff00.mp3' => mp3Tag([$tsse, mp3Frame('TXXX', "\x03k\0a\xFF\x00b"), $geob], '', 0x80).$audio,
    // a C2PA GEOB whose description is 5,000 bytes long
    'long-description.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', mp3Geob($store, 'application/c2pa', 3, 'c2pa', str_repeat('d', 5000)))]).$audio,
    // a v2.4 GEOB with the grouping flag (0x40) and its group byte
    'grouped-geob-v24.mp3' => mp3Tag([$tsse, mp3Frame('GEOB', "\x01".mp3Geob($store), "\0\x40")]).$audio,
    // a v2.3 GEOB with the grouping flag (0x20) and its group byte
    'grouped-geob-v23.mp3' => mp3Tag([$v3($tsse), $v3(mp3Frame('GEOB', "\x01".mp3Geob($store), "\0\x20"))], '', 0, null, 3).$audio,
    // a v2.3 tag with header flag bit 0x10 set (a footer only exists in v2.4)
    'footer-bit-v23.mp3' => mp3Tag([$v3($tsse), $v3($geob)], '', 0x10, null, 3).$audio,
    // unsigned sources, signed with c2patool by hand (notes/step-230-mp3-review.md)
    'unsigned-zeros-after-tag.mp3' => substr($unsigned, 0, $u['end']).str_repeat("\0", 16).$unsignedAudio,
    'unsigned-second-empty-tag.mp3' => substr($unsigned, 0, $u['end'])."ID3\x04\0\0".mp3Syncsafe(0).$unsignedAudio,
];

$dir = $root.'/tests/Fixtures/mp3';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %8d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
