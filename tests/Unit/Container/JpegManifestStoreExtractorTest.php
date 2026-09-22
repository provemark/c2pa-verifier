<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;

/*
 * SPEC-001: JPEG APP11 → manifest store bytes. The fixture and every
 * variant were measured against c2patool 0.27.22 in notes/step-02 and
 * tests/Fixtures/jpeg/README.md; the criteria copy that behaviour.
 */

const SPEC001_STORE_SHA256 = 'f47af93e8afe0f71912e4c7545184ace2fb2cd5628b4a3ac832e591ac33546a3';
const SPEC001_STORE_LENGTH = 94740;

/** @return resource */
function spec001Stream(string $name)
{
    $path = dirname(__DIR__, 2).'/Fixtures/'.$name;
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$path}");
    }

    return $stream;
}

function spec001Extract(string $name, ?JpegManifestStoreExtractor $extractor = null): ?ManifestStoreBytes
{
    return ($extractor ?? new JpegManifestStoreExtractor)->extract(spec001Stream($name));
}

/** The store's bytes, or '' when there is none — so a null result fails the hash check, not the type check. */
function spec001Bytes(string $name): string
{
    return spec001Extract($name)->bytes ?? '';
}

it('AC1: extracts the store from the fixture, byte-exact', function (): void {
    expect(spec001Extract('fixture-signed.jpg'))->toBeInstanceOf(ManifestStoreBytes::class);

    $bytes = spec001Bytes('fixture-signed.jpg');

    expect(strlen($bytes))->toBe(SPEC001_STORE_LENGTH)
        ->and(hash('sha256', $bytes))->toBe(SPEC001_STORE_SHA256)
        ->and(bin2hex(substr($bytes, 0, 8)))->toBe('000172146a756d62');
})->group('SPEC-001');

it('AC2: a JPEG without APP11 yields null, not an error', function (): void {
    expect(spec001Extract('fixture-unsigned.jpg'))->toBeNull();
})->group('SPEC-001');

it('AC3: pieces out of order are an error naming the expected and found sequence numbers', function (): void {
    expect(fn () => spec001Extract('jpeg/swapped-pieces.jpg'))
        ->toThrow(ContainerException::class, 'expected 1, found 2');
})->group('SPEC-001');

it('AC4: a gap between the pieces still yields the same store', function (): void {
    expect(hash('sha256', spec001Bytes('jpeg/gap-between-pieces.jpg')))->toBe(SPEC001_STORE_SHA256);
})->group('SPEC-001');

it('AC5: a file truncated inside a piece is an error naming the segment offset', function (): void {
    expect(fn () => spec001Extract('jpeg/truncated-in-piece-2.jpg'))
        ->toThrow(ContainerException::class, 'offset 64032');
})->group('SPEC-001');

it('AC6: a missing piece is an error naming LBox and the bytes collected', function (): void {
    expect(fn () => spec001Extract('jpeg/missing-piece-2.jpg'))
        ->toThrow(ContainerException::class, 'LBox 94740 but 64000 bytes collected');
})->group('SPEC-001');

it('AC7: an LBox that differs between pieces is an error naming both values', function (): void {
    expect(fn () => spec001Extract('jpeg/lbox-differs.jpg'))
        ->toThrow(ContainerException::class, 'LBox 94741 in piece 2 differs from 94740');
})->group('SPEC-001');

it('AC8: an APP11 segment without JP is skipped', function (): void {
    expect(hash('sha256', spec001Bytes('jpeg/app11-not-jp.jpg')))->toBe(SPEC001_STORE_SHA256);
})->group('SPEC-001');

it('AC9: an LBox above the limit is an error before any piece data is read', function (): void {
    $stream = spec001Stream('fixture-signed.jpg');
    $extractor = new JpegManifestStoreExtractor(maxLBox: 1000);

    expect(fn () => $extractor->extract($stream))
        ->toThrow(ContainerException::class, 'LBox 94740 exceeds the limit of 1000');

    // Piece 1's header ends at offset 20 + 4 + 16 = 40; its data starts there.
    expect(ftell($stream))->toBeLessThanOrEqual(40);
})->group('SPEC-001');

it('AC9: more pieces than the limit is an error at the piece that exceeds it', function (): void {
    expect(fn () => spec001Extract('fixture-signed.jpg', new JpegManifestStoreExtractor(maxPieces: 1)))
        ->toThrow(ContainerException::class, 'piece 2 exceeds the limit of 1 piece(s)');
})->group('SPEC-001');

it('AC10: a stream that does not start with FF D8 is an error', function (): void {
    expect(fn () => spec001Extract('jpeg/not-a-jpeg.bin'))
        ->toThrow(ContainerException::class, 'FF D8');
})->group('SPEC-001');

it('AC11: two different box instance numbers are an error naming both', function (): void {
    expect(fn () => spec001Extract('jpeg/two-instance-numbers.jpg'))
        ->toThrow(ContainerException::class, 'box instance number 530 in piece 2 differs from 529');
})->group('SPEC-001');

it('AC12: the default limits are 2048 pieces and 16 MiB', function (): void {
    $extractor = new JpegManifestStoreExtractor;

    expect($extractor->maxPieces)->toBe(2048)
        ->and($extractor->maxLBox)->toBe(16 * 1024 * 1024)   // SPEC-024 amendment
        ->and(hash('sha256', $extractor->extract(spec001Stream('fixture-signed.jpg'))->bytes ?? ''))->toBe(SPEC001_STORE_SHA256);
})->group('SPEC-001');

it('AC13: pieces after SOS are not scanned; the result is null', function (): void {
    expect(spec001Extract('jpeg/pieces-after-sos.jpg'))->toBeNull();
})->group('SPEC-001');

it('AC14: a file truncated before the first piece is an error naming the segment offset, not null', function (): void {
    // An exception instance makes Pest compare the whole message: 'offset 2'
    // as a substring would also match 'offset 20', the position after the
    // segment, which is where the scan fails without the probe in skip().
    expect(fn () => spec001Extract('jpeg/truncated-in-app0.jpg'))
        ->toThrow(new ContainerException('unexpected end of file inside the segment at offset 2: it ends at 20, the file at 12'));
})->group('SPEC-001');

it('AC15: a marker without a length field before SOS is an error naming the marker and its offset', function (): void {
    expect(fn () => spec001Extract('jpeg/rst-before-sos.jpg'))
        ->toThrow(ContainerException::class, 'FF D0 at offset 20');
})->group('SPEC-001');

it('AC16: a file that ends exactly on a segment boundary is an error naming the offset where a marker was expected', function (): void {
    expect(fn () => spec001Extract('jpeg/truncated-between-segments.jpg'))
        ->toThrow(ContainerException::class, 'marker of the segment at offset 20');
})->group('SPEC-001');
