<?php

declare(strict_types=1);

/*
 * SPEC-001: builds the malformed JPEG variants under tests/Fixtures/jpeg/
 * from tests/Fixtures/fixture-signed.jpg. Each variant moves whole segments
 * or changes one field, nothing else, so that a reader can see exactly what
 * is wrong with each file. Run from the repository root; prints the SHA-256
 * of every file it writes. Tooling, not product code.
 */

/**
 * Splits a baseline JPEG into named chunks: SOI, one per marker segment up
 * to SOS, the scan (SOS through the entropy-coded data, without EOI), EOI.
 *
 * @return array<string, string> name => bytes, in file order
 */
function jpegChunks(string $data): array
{
    if (! str_starts_with($data, "\xFF\xD8")) {
        throw new RuntimeException('not a JPEG');
    }
    $chunks = ['SOI' => "\xFF\xD8"];
    $p = 2;
    $app11 = 0;
    while (true) {
        $marker = ord($data[$p + 1]);
        if ($marker === 0xDA) {
            $chunks['SCAN'] = substr($data, $p, strlen($data) - 2 - $p);
            $chunks['EOI'] = substr($data, -2);

            return $chunks;
        }
        $length = jpegU16($data, $p + 2);
        $name = match ($marker) {
            0xE0 => 'APP0', 0xEB => 'APP11#'.(++$app11), 0xFE => 'COM',
            0xDB => 'DQT', 0xC4 => 'DHT', 0xC0 => 'SOF0',
            default => sprintf('M%02X@%d', $marker, $p),
        };
        $chunks[$name] = substr($data, $p, 2 + $length);
        $p += 2 + $length;
    }
}

function jpegU16(string $data, int $offset): int
{
    return (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
}

/** Overwrites a big-endian field of $width bytes at $offset with $value. */
function jpegPut(string $bytes, int $offset, int $width, int $value): string
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
function jpegJoin(array $chunks, array $order): string
{
    return implode('', array_map(static fn (string $name): string => $chunks[$name], $order));
}

$root = dirname(__DIR__);
$source = (string) file_get_contents($root.'/tests/Fixtures/fixture-signed.jpg');
$c = jpegChunks($source);
$p1 = $c['APP11#1'];
$p2 = $c['APP11#2'];
$normal = ['SOI', 'APP0', 'APP11#1', 'APP11#2', 'COM', 'DQT', 'DHT', 'SOF0', 'SCAN', 'EOI'];
if (array_keys($c) !== $normal) {
    throw new RuntimeException('unexpected segment order: '.implode(' ', array_keys($c)));
}

// In an APP11 segment: marker(2) length(2) CI(2) En(2) Z(4) LBox(4) TBox(4) data.
$enOffset = 6;
$lboxOffset = 12;

$variants = [
    // AC3
    'swapped-pieces.jpg' => jpegJoin($c, ['SOI', 'APP0', 'APP11#2', 'APP11#1', 'COM', 'DQT', 'DHT', 'SOF0', 'SCAN', 'EOI']),
    // AC4
    'gap-between-pieces.jpg' => jpegJoin($c, ['SOI', 'APP0', 'APP11#1', 'COM', 'APP11#2', 'DQT', 'DHT', 'SOF0', 'SCAN', 'EOI']),
    // AC5: cut 1,000 bytes into piece 2
    'truncated-in-piece-2.jpg' => substr($source, 0, strlen($c['SOI'].$c['APP0'].$p1) + 1000),
    // AC6
    'missing-piece-2.jpg' => jpegJoin($c, ['SOI', 'APP0', 'APP11#1', 'COM', 'DQT', 'DHT', 'SOF0', 'SCAN', 'EOI']),
    // AC7: piece 2's LBox + 1
    'lbox-differs.jpg' => jpegJoin(['APP11#2' => jpegPut($p2, $lboxOffset, 4, 94741)] + $c, $normal),
    // AC8: an APP11 whose payload starts with "XX", before piece 1
    'app11-not-jp.jpg' => jpegJoin(['XX' => "\xFF\xEB".pack('n', 10).'XX'.str_repeat("\0", 6)] + $c, ['SOI', 'APP0', 'XX', 'APP11#1', 'APP11#2', 'COM', 'DQT', 'DHT', 'SOF0', 'SCAN', 'EOI']),
    // AC10
    'not-a-jpeg.bin' => "This is not a JPEG. It does not start with FF D8.\n",
    // AC11: piece 2's En 529 -> 530
    'two-instance-numbers.jpg' => jpegJoin(['APP11#2' => jpegPut($p2, $enOffset, 2, 530)] + $c, $normal),
    // AC13: both pieces after the scan, before EOI
    'pieces-after-sos.jpg' => jpegJoin($c, ['SOI', 'APP0', 'COM', 'DQT', 'DHT', 'SOF0', 'SCAN', 'APP11#1', 'APP11#2', 'EOI']),
    // AC14 (amendment 1): cut 12 bytes in, inside APP0 (offset 2, length 16), before any piece
    'truncated-in-app0.jpg' => substr($source, 0, 12),
    // AC15 (amendment 1): a bare RST0 marker (no length field) between APP0 and piece 1
    'rst-before-sos.jpg' => jpegJoin(['RST0' => "\xFF\xD0"] + $c, ['SOI', 'APP0', 'RST0', 'APP11#1', 'APP11#2', 'COM', 'DQT', 'DHT', 'SOF0', 'SCAN', 'EOI']),
];

$dir = $root.'/tests/Fixtures/jpeg';
if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
    throw new RuntimeException("cannot create {$dir}");
}
foreach ($variants as $name => $bytes) {
    file_put_contents("{$dir}/{$name}", $bytes);
    printf("%s  %7d  %s\n", hash('sha256', $bytes), strlen($bytes), $name);
}
