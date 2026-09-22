<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;

/*
 * SPEC-002: PNG caBX → manifest store bytes. The fixture and every variant
 * were measured against c2patool 0.27.22 in notes/step-04 and
 * tests/Fixtures/png/README.md; the criteria copy that behaviour.
 */

const SPEC002_STORE_LENGTH = 46025;

const SPEC002_STORE_SHA256 = '1a018eb892c4b30c112976cd7411df24baa9ce788cfe2904f58a69dec6e057df';

/** @return resource */
function spec002Stream(string $name)
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$name;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return $stream;
}

function spec002Extract(string $name, ?PngManifestStoreExtractor $extractor = null): ?ManifestStoreBytes
{
    return ($extractor ?? new PngManifestStoreExtractor)->extract(spec002Stream($name));
}

/** The store's bytes, or '' when there is none — so a null result fails the hash check, not the type check. */
function spec002Bytes(string $name): string
{
    return spec002Extract($name)->bytes ?? '';
}

it('AC1: extracts the store from the fixture, byte-exact', function (): void {
    expect(spec002Extract('fixture-signed.png'))->toBeInstanceOf(ManifestStoreBytes::class);

    $bytes = spec002Bytes('fixture-signed.png');

    expect(strlen($bytes))->toBe(SPEC002_STORE_LENGTH)
        ->and(hash('sha256', $bytes))->toBe(SPEC002_STORE_SHA256)
        ->and(bin2hex(substr($bytes, 0, 8)))->toBe('0000b3c96a756d62');
})->group('SPEC-002');

it('AC2: a PNG without caBX yields null, not an error', function (): void {
    expect(spec002Extract('fixture-unsigned.png'))->toBeNull();
})->group('SPEC-002');

it('AC3: a stream that does not start with the PNG signature is an error', function (): void {
    expect(fn () => spec002Extract('png/not-a-png.bin'))
        ->toThrow(ContainerException::class, '89 50 4E 47 0D 0A 1A 0A');
})->group('SPEC-002');

it('AC4: a file truncated inside the caBX chunk is an error naming the chunk offset', function (): void {
    expect(fn () => spec002Extract('png/truncated-in-cabx.png'))
        ->toThrow(ContainerException::class, 'offset 33');
})->group('SPEC-002');

it('AC5: two caBX chunks are an error naming both offsets', function (): void {
    expect(fn () => spec002Extract('png/two-cabx.png'))
        ->toThrow(ContainerException::class, 'offsets 33 and 46070');
})->group('SPEC-002');

it('AC6: a CRC that does not match is an error naming the stored and the computed CRC', function (): void {
    expect(fn () => spec002Extract('png/crc-wrong.png'))
        ->toThrow(ContainerException::class, 'stored CRC 83278C6A, computed 83278C6B');
})->group('SPEC-002');

it('AC7: an LBox that differs from the chunk length is an error naming both values', function (): void {
    expect(fn () => spec002Extract('png/lbox-differs.png'))
        ->toThrow(ContainerException::class, 'LBox 46026 differs from the chunk length 46025');
})->group('SPEC-002');

it('AC8: a caBX after IDAT still yields the same store', function (): void {
    expect(hash('sha256', spec002Bytes('png/cabx-after-idat.png')))->toBe(SPEC002_STORE_SHA256);
})->group('SPEC-002');

it('AC9: a caBX before IHDR still yields the same store', function (): void {
    expect(hash('sha256', spec002Bytes('png/cabx-before-ihdr.png')))->toBe(SPEC002_STORE_SHA256);
})->group('SPEC-002');

it('AC10: a chunk length field that is off by one is an error naming LBox and the chunk length', function (): void {
    expect(fn () => spec002Extract('png/length-differs.png'))
        ->toThrow(ContainerException::class, 'LBox 46025 differs from the chunk length 46026');
})->group('SPEC-002');

it('AC11: a caBX of 4 bytes is an error naming the length and the 8-byte minimum', function (): void {
    expect(fn () => spec002Extract('png/cabx-too-short.png'))
        ->toThrow(ContainerException::class, 'length 4 is shorter than the 8-byte box header');
})->group('SPEC-002');

it('AC11: an empty caBX is an error, not "no store"', function (): void {
    expect(fn () => spec002Extract('png/cabx-empty.png'))
        ->toThrow(ContainerException::class, 'length 0 is shorter than the 8-byte box header');
})->group('SPEC-002');

it('AC12: a chunk length above the limit is an error before the data is read', function (): void {
    $stream = spec002Stream('fixture-signed.png');
    $extractor = new PngManifestStoreExtractor(maxChunkLength: 1000);

    expect(fn () => $extractor->extract($stream))
        ->toThrow(ContainerException::class, 'length 46025 exceeds the limit of 1000');

    // The caBX chunk header (length + type) ends at offset 33 + 8 = 41; its data starts there.
    expect(ftell($stream))->toBeLessThanOrEqual(41);
})->group('SPEC-002');

it('AC13: the default limit is 16 MiB', function (): void {
    $extractor = new PngManifestStoreExtractor;

    expect($extractor->maxChunkLength)->toBe(16 * 1024 * 1024)   // SPEC-024 amendment
        ->and(hash('sha256', $extractor->extract(spec002Stream('fixture-signed.png'))->bytes ?? ''))->toBe(SPEC002_STORE_SHA256);
})->group('SPEC-002');

it('AC14: a file that ends before IEND is an error naming the offset where a chunk header was expected, not null', function (): void {
    expect(fn () => spec002Extract('png/truncated-between-chunks.png'))
        ->toThrow(ContainerException::class, 'offset 33');
})->group('SPEC-002');
