<?php

declare(strict_types=1);

/*
 * Byte-surgery helpers shared by the variant scripts under bin/ (step 23's
 * make-binding-variants.php, step 24's make-hashed-uri-variants.php): big-endian
 * reads, splicing with every enclosing JUMBF LBox adjusted, definite-length
 * CBOR boundaries, and the PNG fixture with another store in its caBX chunk.
 * Tooling, not product code; nothing here is used by src/.
 */

function bU32(string $bytes, int $offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('N', $bytes, $offset);

    return $u[1];
}

/**
 * @param  list<int>  $enclosing
 */
function bSplice(string $bytes, int $at, int $length, string $with, array $enclosing): string
{
    $delta = strlen($with) - $length;
    foreach ($enclosing as $lboxOffset) {
        $bytes = substr($bytes, 0, $lboxOffset).pack('N', bU32($bytes, $lboxOffset) + $delta).substr($bytes, $lboxOffset + 4);
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + $length);
}

function bReplace(string $bytes, int $at, string $expect, string $with): string
{
    if (substr($bytes, $at, strlen($expect)) !== $expect || strlen($with) !== strlen($expect)) {
        throw new RuntimeException(sprintf('expected %s at %d, found %s', bin2hex($expect), $at, bin2hex(substr($bytes, $at, strlen($expect)))));
    }

    return substr($bytes, 0, $at).$with.substr($bytes, $at + strlen($expect));
}

function bFind(string $bytes, string $needle, int $from, int $to): int
{
    $at = strpos($bytes, $needle, $from);
    if ($at === false || $at >= $to) {
        throw new RuntimeException(sprintf('%s not found between %d and %d', bin2hex($needle), $from, $to));
    }

    return $at;
}

/** The end offset of the definite-length CBOR item at $p. */
function bCborEnd(string $d, int $p): int
{
    $ib = ord($d[$p]);
    $mt = $ib >> 5;
    $ai = $ib & 0x1F;
    $q = $p + 1;
    $arg = $ai;
    if ($ai >= 24 && $ai <= 27) {
        $w = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai];
        $arg = (int) hexdec(bin2hex(substr($d, $q, $w)));
        $q += $w;
    }

    return match ($mt) {
        0, 1, 7 => $q,
        2, 3 => $q + $arg,
        4 => array_reduce(range(1, max($arg, 0)), static fn (int $e): int => bCborEnd($d, $e), $q),
        5 => array_reduce(range(1, max($arg * 2, 0)), static fn (int $e): int => bCborEnd($d, $e), $q),
        default => bCborEnd($d, $q),
    };
}

/** @return array<string, array{0: int, 1: int, 2: int}> text key => [keyStart, valueStart, valueEnd] */
function bMapPairs(string $d, int $p): array
{
    $count = ord($d[$p]) & 0x1F;
    $q = $p + 1;
    $pairs = [];
    for ($i = 0; $i < $count; $i++) {
        $keyEnd = bCborEnd($d, $q);
        $key = substr($d, $q + 1, $keyEnd - $q - 1);
        $valueEnd = bCborEnd($d, $keyEnd);
        $pairs[$key] = [$q, $keyEnd, $valueEnd];
        $q = $valueEnd;
    }

    return $pairs;
}

function pngWithStore(string $png, string $store): string
{
    $oldLength = bU32($png, 33);
    $chunk = pack('N', strlen($store)).'caBX'.$store.pack('N', crc32('caBX'.$store));

    return substr($png, 0, 33).$chunk.substr($png, 33 + 12 + $oldLength);
}

/** The PNG with one data byte of its IDAT chunk changed and the chunk's CRC recomputed: a clean pixel edit. */
function pngPixelChanged(string $png): string
{
    $p = bFind($png, 'IDAT', 40, strlen($png)) - 4;
    $length = bU32($png, $p);
    $data = substr($png, $p + 8, $length);
    $data[100] = chr(ord($data[100]) ^ 0x01);
    $chunk = pack('N', $length).'IDAT'.$data.pack('N', crc32('IDAT'.$data));

    return substr($png, 0, $p).$chunk.substr($png, $p + 12 + $length);
}
