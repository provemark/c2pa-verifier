<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\FormatDetector;
use Provemark\C2paVerifier\Container\GifManifestStoreExtractor;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-059: GIF — the `C2PA_GIF` Application Extension (C2PA 2.4 §A.3.8). The
 * fixture and the variants were measured against c2patool 0.27.22 and 0.28.1
 * in step 256 (tests/Fixtures/gif/README.md); the recordings are under
 * tests/Fixtures/c2patool/gif/. The helpers are AviTest's (spec058*).
 */

/** The verifier's report on a fixture, as a tree; with the test roots when $trusted. */
function spec059Verify(string $relative, bool $trusted = false): array
{
    $settings = $trusted ? TrustSettings::fromJson(spec058File('trust/full.settings.json')) : null;
    $report = (new Verifier)->verify(spec058Stream(spec058File($relative)), $settings);
    $tree = spec058Report($report);
    $tree['@hasManifest'] = $report->hasManifest;

    return $tree;
}

/** The extractor's fault on a fixture, or null when it threw none. */
function spec059Fault(string $relative): ?ContainerException
{
    try {
        (new GifManifestStoreExtractor)->extract(spec058Stream(spec058File($relative)));
    } catch (ContainerException $e) {
        return $e;
    }

    return null;
}

/** A GIF head (header, screen descriptor, no colour table), $blocks, one 1×1 image and the trailer. */
function spec059Gif(string $blocks): string
{
    return "GIF89a\x01\x00\x01\x00\x00\x00\x00".$blocks."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B";
}

it('AC1: the fixture yields the store, byte-exact, with the block as its range', function (): void {
    $store = (new GifManifestStoreExtractor)->extract(spec058Stream(spec058File('fixture-signed.gif')));

    expect($store)->not->toBeNull()
        ->and(strlen((string) $store?->bytes))->toBe(130155)
        ->and(substr((string) $store?->bytes, 0, 8))->toBe("\x00\x01\xfc\x6bjumb")
        ->and(hash('sha256', (string) $store?->bytes))->toBe('00d82920219fd023f49fe1be8755e62c6aa1683527a17bb07967a66af1afc469')
        ->and($store?->ranges)->toBe([[781, 130681]]);
})->group('SPEC-059');

it('AC2: no C2PA_GIF block is no manifest', function (string $relative): void {
    $tree = spec059Verify($relative);

    expect((new GifManifestStoreExtractor)->extract(spec058Stream(spec058File($relative))))->toBeNull()
        ->and($tree['format'])->toBe('gif')
        ->and($tree['@hasManifest'])->toBeFalse()
        ->and(spec058Codes($tree, 'failure'))->toBe([]);
})->with(['fixture-unsigned.gif', 'gif/ident-other.gif', 'gif/c2pa-empty.gif'])->group('SPEC-059');

it('AC3: a block of another version is not a store', function (string $relative): void {
    expect((new GifManifestStoreExtractor)->extract(spec058Stream(spec058File($relative))))->toBeNull()
        ->and(spec059Verify($relative)['@hasManifest'])->toBeFalse();
})->with(['gif/auth-2-0.gif', 'gif/auth-1-1.gif'])->group('SPEC-059');

it('AC4: two C2PA_GIF blocks are an error (stricter than c2patool, named)', function (): void {
    $fault = spec059Fault('gif/two-c2pa.gif');
    $tree = spec059Verify('gif/two-c2pa.gif');

    expect($fault?->getMessage())->toContain('781')->toContain('131462')
        ->and($tree['format'])->toBe('gif')
        ->and($tree['@hasManifest'])->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($tree, 'failure'))->toBe(['general.error']);
})->group('SPEC-059');

it('AC5: a malformed block is an error', function (string $relative, bool $reached): void {
    $tree = spec059Verify($relative);

    expect(spec059Fault($relative))->toBeInstanceOf(ContainerException::class)
        ->and($tree['format'])->toBe('gif')
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($tree, 'failure'))->toBe(['general.error'])
        ->and($tree['@hasManifest'])->toBe($reached);
})->with([
    // OQ3: the block is reached once its size, identifier and version are read
    'a block size of 12' => ['gif/block-size-12.gif', false],
    'a payload that is not JUMBF' => ['gif/c2pa-not-jumbf.gif', true],
    'stray bytes after an early terminator' => ['gif/early-terminator.gif', true],
])->group('SPEC-059');

it('AC6: a file cut inside the block is an error, after the store was reached', function (): void {
    $tree = spec059Verify('gif/truncated-in-c2pa.gif');

    expect(spec059Fault('gif/truncated-in-c2pa.gif'))->toBeInstanceOf(ContainerException::class)
        ->and($tree['@hasManifest'])->toBeTrue()
        ->and(spec058Codes($tree, 'failure'))->toBe(['general.error']);
})->group('SPEC-059');

it('AC7: what the hash judges is read and left to the hash, as c2patool says', function (string $relative): void {
    $tree = spec059Verify($relative);

    expect($tree['format'])->toBe('gif')
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($tree, 'success'))->toContain('claimSignature.validated')
        ->and(spec058Codes($tree, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec058Codes($tree, 'failure'))->not->toContain('general.error');
})->with(['gif/rechunked-100.gif', 'gif/c2pa-after-netscape.gif', 'gif/truncated-after-c2pa.gif', 'gif/no-trailer.gif', 'gif/trailing-bytes.gif'])->group('SPEC-059');

it('AC8: the range is the whole block', function (): void {
    expect((new GifManifestStoreExtractor)->extract(spec058Stream(spec058File('gif/rechunked-100.gif')))?->ranges)->toBe([[781, 131472]]);
})->group('SPEC-059');

it('AC9: nothing after the first image is read', function (): void {
    // a stream that records the furthest byte read
    $bytes = spec058File('fixture-signed.gif');
    $imageAt = 131489;   // the image descriptor, step 256
    $stream = spec058Stream($bytes);
    $store = (new GifManifestStoreExtractor)->extract($stream);
    $furthest = ftell($stream);

    expect((new GifManifestStoreExtractor)->extract(spec058Stream(spec058File('gif/c2pa-after-image.gif'))))->toBeNull()
        ->and($store)->not->toBeNull()
        ->and($furthest)->toBeLessThanOrEqual($imageAt + 10);
})->group('SPEC-059');

it('AC10: the bounds apply before memory is spent', function (): void {
    $comment = "\x21\xFE\x01x\x00";   // a comment extension of one byte
    $many = spec059Gif(str_repeat($comment, GifManifestStoreExtractor::MAX_BLOCKS + 1));
    $big = spec059Gif("\x21\xFF\x0BC2PA_GIF\x01\x00\x00".str_repeat("\xFF".str_repeat('x', 255), 8)."\x00");

    $blocks = null;
    try {
        (new GifManifestStoreExtractor)->extract(spec058Stream($many));
    } catch (ContainerException $e) {
        $blocks = $e->getMessage();
    }
    $store = null;
    try {
        (new GifManifestStoreExtractor(1024))->extract(spec058Stream($big));
    } catch (ContainerException $e) {
        $store = $e->getMessage();
    }

    expect($blocks)->toContain((string) GifManifestStoreExtractor::MAX_BLOCKS)
        ->and($store)->toContain('1024')
        ->and(GifManifestStoreExtractor::DEFAULT_MAX_STORE_LENGTH)->toBe(16 * 1024 * 1024);
})->group('SPEC-059');

it('AC11: detection: GIF87a and GIF89a are gif, nothing else is guessed', function (): void {
    $detector = new FormatDetector;
    $gif87 = spec059Verify('gif/gif87a.gif');
    $unknown = (new Verifier)->verify(spec058Stream("GIF88a\x01\x00\x01\x00\x00\x00\x00\x3B"));

    expect($detector->detect(spec058Stream(spec058File('fixture-signed.gif'))))->toBe('gif')
        ->and($detector->detect(spec058Stream(spec058File('gif/gif87a.gif'))))->toBe('gif')
        ->and($detector->detect(spec058Stream(spec058File('fixture-signed.png'))))->toBe('png')
        ->and($detector->detect(spec058Stream("GIF88a\x01\x00\x01\x00\x00\x00\x00\x3B")))->toBeNull()
        ->and($gif87['validation_state'])->toBe('Invalid')
        ->and(spec058Codes($gif87, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(implode(' ', array_map(static fn ($s): string => $s->explanation, $unknown->result->statuses)))->toContain('GIF');
})->group('SPEC-059');

it('AC12: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec058File("c2patool/gif/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $ours = spec059Verify('fixture-signed.gif', $trusted);

    expect($ours['format'])->toBe('gif')
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec058Codes($ours, 'success'))->toBe(spec058Codes($oracle, 'success'))
        ->and(spec058Codes($ours, 'failure'))->toBe(spec058Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['fixture-signed', false],
    '0.27.22 trusted' => ['fixture-signed.trusted', true],
    '0.28.1' => ['fixture-signed.0.28.1', false],
    '0.28.1 trusted' => ['fixture-signed.0.28.1.trusted', true],
])->group('SPEC-059');

it('AC12: a flipped image byte and a flipped store byte fail as c2patool says', function (): void {
    expect(spec058Codes(spec059Verify('gif/flip-image.gif'), 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec058Codes(spec059Verify('gif/flip-store.gif'), 'failure'))->toContain('assertion.hashedURI.mismatch');
})->group('SPEC-059');
