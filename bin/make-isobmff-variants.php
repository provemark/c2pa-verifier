<?php

declare(strict_types=1);

/*
 * SPEC-026, step 74a: the malformed ISOBMFF variants, built byte by byte from
 * tests/Fixtures/fixture-signed.mp4.
 *
 * Each variant breaks exactly one thing, so that a test failing on it names one
 * cause. They are small — the source is 16 kB — and deterministic, which is why
 * they are committed where SPEC-024's 16 MiB stores were generated in the test
 * instead: a fixture earns its place by being evidence somebody can open.
 *
 * Tooling, outside the Deptrac layers. Run: php bin/make-isobmff-variants.php
 */

$root = dirname(__DIR__);
$source = $root.'/tests/Fixtures/fixture-signed.mp4';
$directory = $root.'/tests/Fixtures/isobmff';

const C2PA_UUID = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";

$bytes = file_get_contents($source);
if ($bytes === false) {
    throw new RuntimeException("cannot read {$source}");
}
if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
    throw new RuntimeException("cannot create {$directory}");
}

/** @return array{offset: int, size: int, type: string, header: int} the top-level boxes */
function boxes(string $bytes): array
{
    $found = [];
    $offset = 0;
    while ($offset + 8 <= strlen($bytes)) {
        /** @var array{1: int} $sizeField */
        $sizeField = unpack('N', substr($bytes, $offset, 4));
        $size = $sizeField[1];
        $type = substr($bytes, $offset + 4, 4);
        $header = 8;
        if ($size === 1) {
            /** @var array{1: int} $large */
            $large = unpack('J', substr($bytes, $offset + 8, 8));
            $size = $large[1];
            $header = 16;
        } elseif ($size === 0) {
            $size = strlen($bytes) - $offset;
        }
        $found[] = ['offset' => $offset, 'size' => $size, 'type' => $type, 'header' => $header];
        $offset += $size;
    }

    return $found;
}

/** @param array{offset: int, size: int, type: string, header: int} $box */
function isC2pa(string $bytes, array $box): bool
{
    return $box['type'] === 'uuid' && substr($bytes, $box['offset'] + $box['header'], 16) === C2PA_UUID;
}

$all = boxes($bytes);
$c2pa = null;
foreach ($all as $box) {
    if (isC2pa($bytes, $box)) {
        $c2pa = $box;
        break;
    }
}
if ($c2pa === null) {
    throw new RuntimeException('no C2PA uuid box in the source');
}

$start = $c2pa['offset'];
$end = $start + $c2pa['size'];
$before = substr($bytes, 0, $start);
$box = substr($bytes, $start, $c2pa['size']);
$after = substr($bytes, $end);

/** The offset of the purpose string inside the C2PA box: header + uuid + version/flags. */
$purposeAt = $c2pa['header'] + 16 + 4;

$written = [];
$write = static function (string $name, string $contents) use ($directory, &$written): void {
    file_put_contents($directory.'/'.$name, $contents);
    $written[$name] = strlen($contents);
};

// AC4: the same store twice. A reader must refuse rather than choose.
$write('two-c2pa-boxes.mp4', $before.$box.$box.$after);

// AC5: a purpose this spec does not read. `merkle` is the one that matters —
// ignoring it would make a fragmented file look like one with no credentials.
$merkle = $box;
$merkle = substr_replace($merkle, "merkle\x00\x00", $purposeAt, 8);
$write('purpose-merkle.mp4', $before.$merkle.$after);

$unknown = substr_replace($box, 'nonsense', $purposeAt, 8);
$write('purpose-unknown.mp4', $before.$unknown.$after);

// AC5: no null terminator before the end of the box — the string runs off.
$unterminated = $box;
for ($i = $purposeAt; $i < strlen($unterminated); $i++) {
    if ($unterminated[$i] === "\x00") {
        $unterminated[$i] = "\x41";
    }
}
$write('purpose-unterminated.mp4', $before.$unterminated.$after);

// AC6: a size smaller than the header it announces.
$tooSmall = substr_replace($box, pack('N', 4), 0, 4);
$write('size-below-header.mp4', $before.$tooSmall.$after);

// AC6: a size that runs past the end of the file.
$tooLarge = substr_replace($box, pack('N', strlen($bytes) + 4096), 0, 4);
$write('size-past-end.mp4', $before.$tooLarge.$after);

// AC6: `size == 1` promises a 64-bit largesize that is not there.
$truncated = substr_replace($box, pack('N', 1), 0, 4);
$write('largesize-missing.mp4', $before.substr($truncated, 0, 8).$after);

// AC7: `size == 0` means "to the end of the file", so a box declaring it that is
// not the last one is a contradiction.
$toEnd = substr_replace($box, pack('N', 0), 0, 4);
$write('size-zero-not-last.mp4', $before.$toEnd.$after);

// AC7: the same declaration on the last box is legal, and the store runs to the end.
$write('size-zero-last.mp4', $before.$after.$toEnd);

// AC2/AC3: an ISOBMFF file whose only C2PA-looking box carries another UUID.
$otherUuid = substr_replace($box, str_repeat("\x11", 16), $c2pa['header'], 16);
$write('uuid-not-c2pa.mp4', $before.$otherUuid.$after);

printf("%d variants in %s\n", count($written), $directory);
foreach ($written as $name => $size) {
    printf("  %-28s %6d bytes\n", $name, $size);
}
