<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;
use Provemark\C2paVerifier\Container\WavManifestStoreExtractor;

/*
 * SPEC-055: WAV RIFF C2PA → manifest store bytes, the extractor's criteria.
 * The fixture and the 21 variants were measured against c2patool 0.27.22 and
 * 0.28.1 in notes/step-204 and tests/Fixtures/wav/README.md. Each criterion
 * follows its SPEC-003 twin (tests/Unit/Container/WebpManifestStoreExtractorTest.php);
 * the verifier's criteria are in tests/Unit/Verifier/WavTest.php.
 */

const SPEC055_STORE_LENGTH = 13463;

const SPEC055_STORE_SHA256 = '622fdd9b14027f9fdf915890c4f004b2ca0fe7d5eeb15db05fe67df85939b318';

/** @return resource */
function spec055Stream(string $name)
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$name;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return $stream;
}

function spec055Extract(string $name, ?WavManifestStoreExtractor $extractor = null): ?ManifestStoreBytes
{
    return ($extractor ?? new WavManifestStoreExtractor)->extract(spec055Stream($name));
}

/** The store's bytes, or '' when there is none — so a null result fails the hash check, not the type check. */
function spec055Bytes(string $name): string
{
    return spec055Extract($name)->bytes ?? '';
}

it('AC1: extracts the store from the fixture, byte-exact, without the pad byte, with its range', function (): void {
    $store = spec055Extract('fixture-signed.wav');

    expect($store)->toBeInstanceOf(ManifestStoreBytes::class);
    assert($store instanceof ManifestStoreBytes);

    expect(strlen($store->bytes))->toBe(SPEC055_STORE_LENGTH)
        ->and(hash('sha256', $store->bytes))->toBe(SPEC055_STORE_SHA256)
        ->and(bin2hex(substr($store->bytes, 0, 8)))->toBe('000034976a756d62')
        ->and($store->ranges)->toBe([['start' => 16078, 'length' => 13471]]);
})->group('SPEC-055');

it('AC2: a WAV without C2PA yields null, not an error', function (): void {
    expect(spec055Extract('fixture-unsigned.wav'))->toBeNull();
})->group('SPEC-055');

it('AC3: a RIFF file whose form type is not WAVE is an error naming both, before any chunk', function (): void {
    $stream = spec055Stream('wav/riff-form-xxxx.wav');

    expect(fn () => (new WavManifestStoreExtractor)->extract($stream))
        ->toThrow(ContainerException::class, 'not a WAV: expected form type WAVE at offset 8, found XXXX');

    expect(ftell($stream))->toBeLessThanOrEqual(12);
})->group('SPEC-055');

it('AC4: a header size that disagrees with the file is an error naming both, before any chunk header', function (string $variant, int $size, int $inFile): void {
    $stream = spec055Stream("wav/{$variant}.wav");

    expect(fn () => (new WavManifestStoreExtractor)->extract($stream))
        ->toThrow(ContainerException::class, "RIFF size {$size} in the header, {$inFile} bytes in the file");

    expect(ftell($stream))->toBeLessThanOrEqual(12);
})->with([
    // amendment 3: only a size that promises more than the file holds is still an error
    'size +1' => ['riff-size-plus-one', 29543, 29542],
    'truncated in C2PA' => ['truncated-in-c2pa', 29542, 17078],
    'truncated between chunks' => ['truncated-between-chunks', 29542, 16070],
])->group('SPEC-055');

it('AC4: a header size that ends before the C2PA chunk yields null, as c2patool finds no claim (amendment 3)', function (): void {
    expect(spec055Extract('wav/riff-size-excludes-c2pa.wav'))->toBeNull();
})->group('SPEC-055');

it('AC4: bytes after the RIFF chunk leave the store as it is (amendment 3)', function (string $variant): void {
    expect(hash('sha256', spec055Bytes("wav/{$variant}.wav")))->toBe(SPEC055_STORE_SHA256);
})->with(['trailing-bytes', 'second-riff'])->group('SPEC-055');

it('AC5: a chunk that overruns the file is an error naming the chunk offset and its declared length', function (): void {
    expect(fn () => spec055Extract('wav/chunk-overruns-file.wav'))
        ->toThrow(ContainerException::class, 'C2PA chunk at offset 16078 declares 14463 bytes, past the end of the file at 29550');
})->group('SPEC-055');

it('AC6: two C2PA chunks are an error naming both offsets', function (): void {
    expect(fn () => spec055Extract('wav/two-c2pa.wav'))
        ->toThrow(ContainerException::class, 'two C2PA chunks at offsets 16078 and 29550; a WAV carries at most one manifest store');
})->group('SPEC-055');

it('AC7: a C2PA that is not the last chunk still yields the same store', function (string $variant): void {
    expect(hash('sha256', spec055Bytes("wav/{$variant}.wav")))->toBe(SPEC055_STORE_SHA256);
})->with(['c2pa-before-data', 'c2pa-first', 'chunk-after-c2pa'])->group('SPEC-055');

it('AC8: an LBox that differs from the chunk length is an error naming both values', function (): void {
    expect(fn () => spec055Extract('wav/lbox-differs.wav'))
        ->toThrow(ContainerException::class, 'LBox 13464 differs from the chunk length 13463');
})->group('SPEC-055');

it('AC9: a chunk length that is off by one is an error naming LBox and the chunk length', function (): void {
    expect(fn () => spec055Extract('wav/length-differs.wav'))
        ->toThrow(ContainerException::class, 'LBox 13463 differs from the chunk length 13464');
})->group('SPEC-055');

it('AC10: a C2PA shorter than a box header is an error naming the length and the 8-byte minimum', function (string $variant, int $length): void {
    expect(fn () => spec055Extract("wav/{$variant}.wav"))
        ->toThrow(ContainerException::class, "C2PA chunk length {$length} is shorter than the 8-byte box header");
})->with([
    'four bytes' => ['c2pa-too-short', 4],
    'empty' => ['c2pa-empty', 0],
])->group('SPEC-055');

it('AC11: a missing pad byte is an error naming the offset where it was expected', function (): void {
    expect(fn () => spec055Extract('wav/pad-missing.wav'))
        ->toThrow(ContainerException::class, 'pad byte expected at offset 29549');
})->group('SPEC-055');

it('AC11: a pad byte that is not zero is an error naming the offset and the byte', function (): void {
    expect(fn () => spec055Extract('wav/pad-nonzero.wav'))
        ->toThrow(ContainerException::class, 'pad byte at offset 29549 is FF, not 00');
})->group('SPEC-055');

it('AC12: an odd-length chunk before C2PA is skipped correctly, pad included', function (): void {
    expect(hash('sha256', spec055Bytes('wav/odd-chunk-before.wav')))->toBe(SPEC055_STORE_SHA256);
})->group('SPEC-055');

it('AC13: a chunk length above the limit is an error before the data is read', function (): void {
    $stream = spec055Stream('fixture-signed.wav');
    $extractor = new WavManifestStoreExtractor(maxChunkLength: 1000);

    expect(fn () => $extractor->extract($stream))
        ->toThrow(ContainerException::class, 'length 13463 exceeds the limit of 1000');

    // The C2PA chunk header (type + length) ends at offset 16078 + 8 = 16086; its data starts there.
    expect(ftell($stream))->toBeLessThanOrEqual(16086);
})->group('SPEC-055');

it('AC13: the default limit is 16 MiB, and the fixture fits it', function (): void {
    $extractor = new WavManifestStoreExtractor;

    expect($extractor->maxChunkLength)->toBe(16 * 1024 * 1024)
        ->and(hash('sha256', $extractor->extract(spec055Stream('fixture-signed.wav'))->bytes ?? ''))->toBe(SPEC055_STORE_SHA256);
})->group('SPEC-055');

it('AC15: a C2PA inside the LIST chunk is not looked for', function (): void {
    expect(spec055Extract('wav/c2pa-in-list.wav'))->toBeNull();
})->group('SPEC-055');
