<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\IsobmffManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Tests\Support\Corpus;

/*
 * SPEC-026: the ISOBMFF container. Step 73 measured a signed MP4 keeping its
 * manifest store in one top-level `uuid` box with twenty-one bytes between the
 * UUID and the JUMBF — version and flags, a null-terminated purpose, and an
 * eight-byte merkle offset — and showed that peeling those off lets every layer
 * built since SPEC-005 read the store unchanged.
 *
 * The malformed variants are built by bin/make-isobmff-variants.php, each
 * breaking exactly one thing, and c2patool 0.27.22's answer to each is recorded
 * in tests/Fixtures/c2patool/isobmff/ where it produced a report at all.
 */

const SPEC026_STORE_LENGTH = 13533;

/** @return resource */
function spec026Stream(string $relative)
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

function spec026Extract(string $relative): ?string
{
    $bytes = (new IsobmffManifestStoreExtractor)->extract(spec026Stream($relative));

    return $bytes?->bytes;
}

/** @return array<string, mixed> */
function spec026Oracle(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

it('AC1: a signed MP4 gives the store, and the existing stack reads it', function (): void {
    $bytes = spec026Extract('fixture-signed.mp4');

    expect($bytes)->not->toBeNull();
    assert($bytes !== null);
    expect(strlen($bytes))->toBe(SPEC026_STORE_LENGTH)
        ->and(bin2hex(substr($bytes, 0, 8)))->toBe('000034dd6a756d62');

    // the point of the whole slice: nothing above the container needed changing
    $store = ManifestStore::fromTree((new JumbfParser)->parse($bytes));
    expect(count($store->manifests))->toBe(1)
        ->and($store->active->claim->version)->toBe(2)
        ->and($store->active->label)->toBe(spec026Oracle('mp4')['active_manifest'])
        ->and(array_keys($store->active->assertions))->toBe(['c2pa.actions.v2', 'c2pa.hash.bmff.v3']);
})->group('SPEC-026');

it('AC2: an ISOBMFF file with no C2PA box yields null, not an error', function (): void {
    expect(spec026Extract('fixture-unsigned.mp4'))->toBeNull()
        // and a uuid box that is not ours is not ours: same answer, no guessing
        ->and(spec026Extract('isobmff/uuid-not-c2pa.mp4'))->toBeNull();
})->group('SPEC-026');

it('AC3: ftyp is detected as isobmff, and nothing else is guessed', function (): void {
    $detector = new FormatDetector;

    expect($detector->detect(spec026Stream('fixture-signed.mp4')))->toBe('isobmff')
        ->and($detector->detect(spec026Stream('fixture-unsigned.mp4')))->toBe('isobmff')
        ->and($detector->detect(spec026Stream('fixture-signed.avif')))->toBe('isobmff')
        // the three older formats keep their answers
        ->and($detector->detect(spec026Stream('fixture-signed.jpg')))->toBe('jpeg')
        ->and($detector->detect(spec026Stream('fixture-signed.png')))->toBe('png')
        ->and($detector->detect(spec026Stream('fixture-signed.webp')))->toBe('webp');
})->group('SPEC-026');

it('AC4: two C2PA boxes are an error naming both offsets', function (): void {
    expect(fn () => spec026Extract('isobmff/two-c2pa-boxes.mp4'))
        ->toThrow(ContainerException::class);

    try {
        spec026Extract('isobmff/two-c2pa-boxes.mp4');
    } catch (ContainerException $e) {
        expect($e->getMessage())->toContain('32')
            ->and($e->getMessage())->toContain('13610');
    }
    // c2patool refuses it too, with a message of its own
    expect(file_exists(Corpus::fixtures().'/c2patool/isobmff/two-c2pa-boxes.json'))->toBeFalse();
})->group('SPEC-026');

it('AC5: a purpose this verifier does not read is an error naming it', function (): void {
    foreach (['purpose-merkle' => 'merkle', 'purpose-unknown' => 'nonsense'] as $variant => $purpose) {
        try {
            spec026Extract("isobmff/{$variant}.mp4");
            expect(false)->toBeTrue("{$variant} did not throw");
        } catch (ContainerException $e) {
            // not toContain($purpose, $variant): Pest reads the second argument as
            // another needle rather than a message (the ninth time in this project)
            expect(str_contains($e->getMessage(), $purpose))->toBeTrue("{$variant}: {$e->getMessage()}");
        }
    }

    // and a purpose with no terminator before the end of the box is malformed too
    expect(fn () => spec026Extract('isobmff/purpose-unterminated.mp4'))
        ->toThrow(ContainerException::class);
})->group('SPEC-026');

it('AC6: a box header that does not fit is an error, and nothing is read past the end', function (): void {
    foreach (['size-below-header', 'size-past-end', 'largesize-missing'] as $variant) {
        try {
            spec026Extract("isobmff/{$variant}.mp4");
            expect(false)->toBeTrue("{$variant} did not throw");
        } catch (ContainerException $e) {
            expect($e->getMessage())->not->toBe('', $variant);
        }
    }
})->group('SPEC-026');

it('AC7: size zero runs to the end of the stream, and cannot be caught here', function (): void {
    // SPEC-026 amendment 1: the declaration is what makes a box the last one, so a
    // box that swallows what followed it reads as one long box and nothing in the
    // container betrays it. c2patool reads both variants the same way.
    $last = spec026Extract('isobmff/size-zero-last.mp4');
    expect($last)->not->toBeNull();
    assert($last !== null);
    expect(strlen($last))->toBe(SPEC026_STORE_LENGTH);

    // the other variant swallows the boxes after it: read, longer, and left to the
    // hard binding to catch — which is why c2patool answers Invalid rather than refusing
    $swallowed = spec026Extract('isobmff/size-zero-not-last.mp4');
    expect($swallowed)->not->toBeNull();
    assert($swallowed !== null);
    expect(strlen($swallowed))->toBeGreaterThan(SPEC026_STORE_LENGTH)
        ->and(spec026Oracle('isobmff/size-zero-not-last')['validation_state'])->toBe('Invalid');
})->group('SPEC-026');

it('AC8: the bounds of SPEC-024 apply here too', function (): void {
    $extractor = new IsobmffManifestStoreExtractor(maxBoxLength: 1024);

    expect(fn () => $extractor->extract(spec026Stream('fixture-signed.mp4')))
        ->toThrow(ContainerException::class);

    try {
        $extractor->extract(spec026Stream('fixture-signed.mp4'));
    } catch (ContainerException $e) {
        expect($e->getMessage())->toContain('1024');
    }
})->group('SPEC-026');

it('AC9: AVIF is the same container, measured rather than assumed', function (): void {
    $bytes = spec026Extract('fixture-signed.avif');

    expect($bytes)->not->toBeNull();
    assert($bytes !== null);

    $store = ManifestStore::fromTree((new JumbfParser)->parse($bytes));
    expect($store->active->label)->toBe(spec026Oracle('avif')['active_manifest'])
        ->and(array_keys($store->active->assertions))->toBe(['c2pa.actions.v2', 'c2pa.hash.bmff.v3'])
        ->and(spec026Extract('fixture-unsigned.avif'))->toBeNull();
})->group('SPEC-026');
