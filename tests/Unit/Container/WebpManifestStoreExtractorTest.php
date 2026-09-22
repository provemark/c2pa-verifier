<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\WebpManifestStoreExtractor;

/*
 * SPEC-003: WebP RIFF C2PA → manifest store bytes. The fixture and every
 * variant were measured against c2patool 0.27.22 in notes/step-06 and
 * tests/Fixtures/webp/README.md; the criteria copy that behaviour where
 * the spec says so, and are stricter where it says so.
 */

const SPEC003_STORE_LENGTH = 100635;

const SPEC003_STORE_SHA256 = '5062cb0a602aa10a8d826370d27107284fcc9957dc93c9f1b025f7a380c13999';

/** @return resource */
function spec003Stream(string $name)
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$name;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return $stream;
}

function spec003Extract(string $name, ?WebpManifestStoreExtractor $extractor = null): ?ManifestStoreBytes
{
    return ($extractor ?? new WebpManifestStoreExtractor)->extract(spec003Stream($name));
}

/** The store's bytes, or '' when there is none — so a null result fails the hash check, not the type check. */
function spec003Bytes(string $name): string
{
    return spec003Extract($name)->bytes ?? '';
}

it('AC1: extracts the store from the fixture, byte-exact, without the pad byte', function (): void {
    expect(spec003Extract('fixture-signed.webp'))->toBeInstanceOf(ManifestStoreBytes::class);

    $bytes = spec003Bytes('fixture-signed.webp');

    expect(strlen($bytes))->toBe(SPEC003_STORE_LENGTH)
        ->and(hash('sha256', $bytes))->toBe(SPEC003_STORE_SHA256)
        ->and(bin2hex(substr($bytes, 0, 8)))->toBe('0001891b6a756d62');
})->group('SPEC-003');

it('AC2: a WebP without C2PA yields null, not an error', function (): void {
    expect(spec003Extract('fixture-unsigned.webp'))->toBeNull();
})->group('SPEC-003');

it('AC3: a stream that does not start with RIFF is an error', function (): void {
    expect(fn () => spec003Extract('webp/not-a-riff.bin'))
        ->toThrow(ContainerException::class, 'expected RIFF at offset 0');
})->group('SPEC-003');

it('AC4: a RIFF file whose form type is not WEBP is an error naming both', function (): void {
    $stream = spec003Stream('webp/riff-not-webp.webp');

    expect(fn () => (new WebpManifestStoreExtractor)->extract($stream))
        ->toThrow(ContainerException::class, 'expected form type WEBP at offset 8, found WAVE');

    // The twelve-byte header is all that may have been read.
    expect(ftell($stream))->toBeLessThanOrEqual(12);
})->group('SPEC-003');

it('AC5: a header size +1 is an error naming the header size and the file length', function (): void {
    expect(fn () => spec003Extract('webp/riff-size-plus-one.webp'))
        ->toThrow(ContainerException::class, 'RIFF size 100949 in the header, 100948 bytes in the file');
})->group('SPEC-003');

it('AC5: a header size that excludes the C2PA chunk is an error, not "no store"', function (): void {
    expect(fn () => spec003Extract('webp/riff-size-excludes-c2pa.webp'))
        ->toThrow(ContainerException::class, 'RIFF size 304 in the header, 100948 bytes in the file');
})->group('SPEC-003');

it('AC5: a file truncated inside the C2PA chunk is an error naming the header size and the file length', function (): void {
    expect(fn () => spec003Extract('webp/truncated-in-c2pa.webp'))
        ->toThrow(ContainerException::class, 'RIFF size 100948 in the header, 1312 bytes in the file');
})->group('SPEC-003');

it('AC5: a file truncated between chunks is an error naming the header size and the file length', function (): void {
    expect(fn () => spec003Extract('webp/truncated-between-chunks.webp'))
        ->toThrow(ContainerException::class, 'RIFF size 100948 in the header, 304 bytes in the file');
})->group('SPEC-003');

it('AC6: a chunk that overruns the file is an error naming the chunk offset and its declared length', function (): void {
    expect(fn () => spec003Extract('webp/chunk-overruns-file.webp'))
        ->toThrow(ContainerException::class, 'chunk at offset 312 declares 101635 bytes');
})->group('SPEC-003');

it('AC7: two C2PA chunks are an error naming both offsets', function (): void {
    expect(fn () => spec003Extract('webp/two-c2pa.webp'))
        ->toThrow(ContainerException::class, 'offsets 312 and 100956');
})->group('SPEC-003');

it('AC8: a C2PA before the image data still yields the same store', function (): void {
    expect(hash('sha256', spec003Bytes('webp/c2pa-before-vp8l.webp')))->toBe(SPEC003_STORE_SHA256);
})->group('SPEC-003');

it('AC9: an LBox that differs from the chunk length is an error naming both values', function (): void {
    expect(fn () => spec003Extract('webp/lbox-differs.webp'))
        ->toThrow(ContainerException::class, 'LBox 100636 differs from the chunk length 100635');
})->group('SPEC-003');

it('AC10: a chunk length that is off by one is an error naming LBox and the chunk length', function (): void {
    expect(fn () => spec003Extract('webp/length-differs.webp'))
        ->toThrow(ContainerException::class, 'LBox 100635 differs from the chunk length 100636');
})->group('SPEC-003');

it('AC11: a C2PA of 4 bytes is an error naming the length and the 8-byte minimum', function (): void {
    expect(fn () => spec003Extract('webp/c2pa-too-short.webp'))
        ->toThrow(ContainerException::class, 'length 4 is shorter than the 8-byte box header');
})->group('SPEC-003');

it('AC11: an empty C2PA is an error, not "no store"', function (): void {
    expect(fn () => spec003Extract('webp/c2pa-empty.webp'))
        ->toThrow(ContainerException::class, 'length 0 is shorter than the 8-byte box header');
})->group('SPEC-003');

it('AC12: a missing pad byte is an error naming the offset where it was expected', function (): void {
    expect(fn () => spec003Extract('webp/pad-missing.webp'))
        ->toThrow(ContainerException::class, 'pad byte expected at offset 100955');
})->group('SPEC-003');

it('AC12: a pad byte that is not zero is an error naming the offset and the byte', function (): void {
    expect(fn () => spec003Extract('webp/pad-nonzero.webp'))
        ->toThrow(ContainerException::class, 'pad byte at offset 100955 is FF, not 00');
})->group('SPEC-003');

it('AC13: an odd-length chunk before C2PA is skipped correctly, pad included', function (): void {
    expect(hash('sha256', spec003Bytes('webp/odd-chunk-before.webp')))->toBe(SPEC003_STORE_SHA256);
})->group('SPEC-003');

it('AC14: a chunk length above the limit is an error before the data is read', function (): void {
    $stream = spec003Stream('fixture-signed.webp');
    $extractor = new WebpManifestStoreExtractor(maxChunkLength: 1000);

    expect(fn () => $extractor->extract($stream))
        ->toThrow(ContainerException::class, 'length 100635 exceeds the limit of 1000');

    // The C2PA chunk header (type + length) ends at offset 312 + 8 = 320; its data starts there.
    expect(ftell($stream))->toBeLessThanOrEqual(320);
})->group('SPEC-003');

it('AC15: the default limit is 16 MiB', function (): void {
    $extractor = new WebpManifestStoreExtractor;

    expect($extractor->maxChunkLength)->toBe(16 * 1024 * 1024)   // SPEC-024 amendment
        ->and(hash('sha256', $extractor->extract(spec003Stream('fixture-signed.webp'))->bytes ?? ''))->toBe(SPEC003_STORE_SHA256);
})->group('SPEC-003');

it('AC16: the header size is checked against the file before any chunk header is read', function (): void {
    $stream = spec003Stream('webp/riff-size-excludes-c2pa.webp');

    expect(fn () => (new WebpManifestStoreExtractor)->extract($stream))
        ->toThrow(ContainerException::class);

    // Only the twelve-byte header may have been read; the file length comes from a seek, not a read.
    expect(ftell($stream))->toBeLessThanOrEqual(12);
})->group('SPEC-003');
