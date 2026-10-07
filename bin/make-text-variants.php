<?php

declare(strict_types=1);

/*
 * Step 265: builds the plain-text variants under tests/Fixtures/text/ from
 * tests/Fixtures/fixture-signed.txt, for the measurement that comes before a
 * text spec. C2PA 2.4 §A.8 appends the store to the visible text as a
 * C2PATextManifestWrapper: U+FEFF, then one variation selector per byte
 * (0–15 as U+FE00–FE0F, 16–255 as U+E0100–E01EF) of `C2PATXT\0`, version 1,
 * a big-endian 32-bit length, the store and optional padding. Each variant
 * changes the text, the wrapper or their order, nothing else. Run from the
 * repository root; prints the SHA-256 of every file it writes. Tooling, not
 * product code.
 */

const MARKER = "\u{FEFF}";

function byteToSelector(int $b): string
{
    return mb_chr($b <= 15 ? 0xFE00 + $b : 0xE0100 + $b - 16, 'UTF-8');
}

/** @return list<int> the bytes of a run of variation selectors */
function selectorsToBytes(string $run): array
{
    $bytes = [];
    foreach (mb_str_split($run, 1, 'UTF-8') as $c) {
        $cp = mb_ord($c, 'UTF-8');
        $bytes[] = match (true) {
            $cp >= 0xFE00 && $cp <= 0xFE0F => $cp - 0xFE00,
            $cp >= 0xE0100 && $cp <= 0xE01EF => $cp - 0xE0100 + 16,
            default => throw new RuntimeException(sprintf('not a selector: U+%04X', $cp)),
        };
    }

    return $bytes;
}

/** @param  list<int>  $bytes */
function bytesToSelectors(array $bytes): string
{
    return implode('', array_map(byteToSelector(...), $bytes));
}

/**
 * Splits the signed text at its one wrapper: the visible text, the wrapper's
 * decoded bytes (header, store and padding).
 *
 * @return array{text: string, frame: list<int>}
 */
function textParts(string $d): array
{
    $at = strpos($d, MARKER) ?: throw new RuntimeException('no U+FEFF');
    $run = substr($d, $at + strlen(MARKER));

    return ['text' => substr($d, 0, $at), 'frame' => selectorsToBytes($run)];
}

/** @param  list<int>  $frame */
function wrapper(array $frame): string
{
    return MARKER.bytesToSelectors($frame);
}

/**
 * @param  list<int>  $frame
 * @return list<int> $frame with byte $i set to $v
 */
function withByte(array $frame, int $i, int $v): array
{
    return array_values(array_replace($frame, [$i => $v]));
}

/** @param  list<int>  $frame */
function storeLength(array $frame): int
{
    return ($frame[9] << 24) | ($frame[10] << 16) | ($frame[11] << 8) | $frame[12];
}

$root = dirname(__DIR__).'/tests/Fixtures';
$signed = file_get_contents("{$root}/fixture-signed.txt") ?: throw new RuntimeException('no signed fixture');
$p = textParts($signed);
$text = $p['text'];
$frame = $p['frame'];
$store = storeLength($frame);
$w = wrapper($frame);

if ($text.$w !== $signed || array_slice($frame, 0, 8) !== array_values(unpack('C*', "C2PATXT\0") ?: [])) {
    throw new RuntimeException('the signed fixture does not split into text and one wrapper');
}

$nfd = strtr($text, ['é' => "e\u{0301}", 'è' => "e\u{0300}", 'ï' => "i\u{0308}"]);
$utf16 = mb_convert_encoding($signed, 'UTF-16LE', 'UTF-8');

$variants = [
    // the visible text changed
    'crlf' => str_replace("\n", "\r\n", $text).$w,
    'bom-front' => "\u{FEFF}".$text.$w,
    'flip-text' => substr_replace($text, 'K', 0, 1).$w,
    'nfd-text' => $nfd.$w,
    // the wrapper changed
    'no-wrapper' => $text,
    'no-marker' => $text.bytesToSelectors($frame),
    'magic-other' => $text.wrapper(withByte($frame, 6, ord('U'))),
    'version-2' => $text.wrapper(withByte($frame, 8, 2)),
    'length-too-long' => $text.wrapper(withByte($frame, 12, ($frame[12] + 1) & 0xFF)),
    'cut-in-store' => $text.wrapper(array_slice($frame, 0, 13 + intdiv($store, 2))),
    'no-padding' => $text.wrapper(array_slice($frame, 0, 13 + $store)),
    'more-padding' => $text.wrapper([...$frame, 0, 0, 0]),
    'letter-in-run' => $text.MARKER.bytesToSelectors(array_slice($frame, 0, 100)).'x'.bytesToSelectors(array_slice($frame, 100)),
    'flip-store' => $text.wrapper(withByte($frame, 13 + $store - 40, $frame[13 + $store - 40] ^ 0x01)),
    // where the wrapper is
    'two-wrappers' => $text.$w.$w,
    'bad-then-good' => $text.wrapper(withByte($frame, 8, 9)).$w,
    'text-after' => $text.$w."One more line.\n",
    'wrapper-first' => $w.$text,
    'only-wrapper' => $w,
    // the encoding
    'utf16le' => $utf16,
];

@mkdir("{$root}/text");
foreach ($variants as $name => $bytes) {
    $path = "{$root}/text/{$name}.txt";
    file_put_contents($path, $bytes);
    printf("%s  text/%s.txt\n", hash('sha256', $bytes), $name);
}
