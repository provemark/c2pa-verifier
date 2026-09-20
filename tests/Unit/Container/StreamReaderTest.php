<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\StreamReader;

/*
 * SPEC-004: one stream reader for the Container layer. AC2–AC5 exercise
 * the reader on in-memory streams; AC1 is the whole suite staying green
 * plus a grep that the extractors no longer carry their own copies.
 */

/**
 * @return resource
 */
function spec004Stream(string $bytes, int $position = 0)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    fseek($stream, $position, SEEK_SET);

    return $stream;
}

it('AC1: no extractor carries a private readExactly, skip or tell any more', function (): void {
    foreach (['Jpeg', 'Png', 'Webp'] as $container) {
        $source = (string) file_get_contents(dirname(__DIR__, 3)."/src/Container/{$container}ManifestStoreExtractor.php");

        expect(preg_match('/private (?:static )?function (?:readExactly|skip|tell|fileEnd|hex)\(/', $source))
            ->toBe(0, "{$container}ManifestStoreExtractor still has a private stream helper");
        expect($source)->toContain('new StreamReader(');
    }
})->group('SPEC-004');

it('AC2: readExactly returns exactly the bytes asked for, or fails naming what, where and how much', function (): void {
    $reader = new StreamReader(spec004Stream('0123456789'), 'chunk');

    expect($reader->readExactly(4, 0, 'the header'))->toBe('0123');

    expect(fn () => $reader->readExactly(8, 4, 'the data'))
        ->toThrow(ContainerException::class, 'the data of the chunk at offset 4: wanted 8 bytes, got 6');
})->group('SPEC-004');

it('AC2: readExactly of zero bytes returns an empty string and does not touch the stream', function (): void {
    $stream = spec004Stream('0123456789', 3);
    $reader = new StreamReader($stream, 'chunk');

    expect($reader->readExactly(0, 3, 'nothing'))->toBe('')
        ->and(ftell($stream))->toBe(3);
})->group('SPEC-004');

it('AC3: skip to exactly the end of the file is not an error; the next read is', function (): void {
    $stream = spec004Stream(str_repeat('x', 20), 8);
    $reader = new StreamReader($stream, 'segment');

    $reader->skip(12, 4);

    expect(ftell($stream))->toBe(20);
    expect(fn () => $reader->readExactly(1, 20, 'a marker'))
        ->toThrow(ContainerException::class, 'a marker of the segment at offset 20');
})->group('SPEC-004');

it('AC3: skip past the end of the file is an error naming the segment, its end and the file end', function (): void {
    $stream = spec004Stream(str_repeat('x', 20), 8);
    $reader = new StreamReader($stream, 'segment');

    expect(fn () => $reader->skip(13, 4))
        ->toThrow(ContainerException::class, 'inside the segment at offset 4: it ends at 21, the file at 20');
    expect(ftell($stream))->toBeLessThanOrEqual(20);
})->group('SPEC-004');

it('AC4: end returns the file length and leaves the position alone', function (): void {
    $stream = spec004Stream(str_repeat('x', 20), 8);
    $reader = new StreamReader($stream, 'segment');

    expect($reader->end())->toBe(20)
        ->and(ftell($stream))->toBe(8)
        ->and($reader->tell())->toBe(8);
})->group('SPEC-004');

it('AC5: hex never shows bytes raw', function (): void {
    expect(StreamReader::hex("\x1B[31m"))->toBe('1B 5B 33 31 6D')
        ->and(StreamReader::hex(''))->toBe('(nothing)');
})->group('SPEC-004');
