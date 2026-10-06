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
 * tests/Fixtures/c2patool/gif/.
 */

function spec059File(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$relative);
}

/** @return resource */
function spec059Stream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/**
 * @param  array<string, mixed>  $tree
 * @return list<string> the active manifest's codes of one kind, sorted
 */
function spec059Codes(array $tree, string $kind): array
{
    $results = $tree['validation_results'] ?? [];
    assert(is_array($results));
    $active = $results['activeManifest'] ?? [];
    assert(is_array($active));
    $entries = $active[$kind] ?? [];
    assert(is_array($entries));
    $codes = [];
    foreach ($entries as $entry) {
        assert(is_array($entry) && is_string($entry['code']));
        $codes[] = $entry['code'];
    }
    sort($codes);

    return $codes;
}

/**
 * The verifier's report on a fixture, as a tree; with the test roots when $trusted.
 *
 * @return array<string, mixed>
 */
function spec059Verify(string $relative, bool $trusted = false): array
{
    $settings = $trusted ? TrustSettings::fromJson(spec059File('trust/full.settings.json')) : null;
    $report = (new Verifier)->verify(spec059Stream(spec059File($relative)), $settings);
    /** @var array<string, mixed> $tree */
    $tree = $report->toArray();
    $tree['@hasManifest'] = $report->hasManifest;

    return $tree;
}

/** The extractor's fault on a fixture, or null when it threw none. */
function spec059Fault(string $relative): ?ContainerException
{
    try {
        (new GifManifestStoreExtractor)->extract(spec059Stream(spec059File($relative)));
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
    $store = (new GifManifestStoreExtractor)->extract(spec059Stream(spec059File('fixture-signed.gif')));

    expect($store)->not->toBeNull()
        ->and(strlen((string) $store?->bytes))->toBe(130155)
        ->and(substr((string) $store?->bytes, 0, 8))->toBe("\x00\x01\xfc\x6bjumb")
        ->and(hash('sha256', (string) $store?->bytes))->toBe('00d82920219fd023f49fe1be8755e62c6aa1683527a17bb07967a66af1afc469')
        ->and($store?->ranges)->toBe([['start' => 781, 'length' => 130681]]);
})->group('SPEC-059');

it('AC2: no C2PA_GIF block is no manifest', function (string $relative): void {
    $tree = spec059Verify($relative);

    expect((new GifManifestStoreExtractor)->extract(spec059Stream(spec059File($relative))))->toBeNull()
        ->and($tree['format'])->toBe('gif')
        ->and($tree['@hasManifest'])->toBeFalse()
        ->and(spec059Codes($tree, 'failure'))->toBe([]);
})->with(['fixture-unsigned.gif', 'gif/ident-other.gif', 'gif/c2pa-empty.gif'])->group('SPEC-059');

it('AC3: a block of another version is not a store', function (string $relative): void {
    expect((new GifManifestStoreExtractor)->extract(spec059Stream(spec059File($relative))))->toBeNull()
        ->and(spec059Verify($relative)['@hasManifest'])->toBeFalse();
})->with(['gif/auth-2-0.gif', 'gif/auth-1-1.gif'])->group('SPEC-059');

it('AC4: two C2PA_GIF blocks are an error (stricter than c2patool, named)', function (): void {
    $fault = spec059Fault('gif/two-c2pa.gif');
    $tree = spec059Verify('gif/two-c2pa.gif');

    expect($fault?->getMessage())->toContain('781')->toContain('131462')
        ->and($tree['format'])->toBe('gif')
        ->and($tree['@hasManifest'])->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec059Codes($tree, 'failure'))->toBe(['general.error']);
})->group('SPEC-059');

it('AC5: a malformed block is an error', function (string $relative, bool $reached): void {
    $tree = spec059Verify($relative);

    expect(spec059Fault($relative))->toBeInstanceOf(ContainerException::class)
        ->and($tree['format'])->toBe('gif')
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec059Codes($tree, 'failure'))->toBe(['general.error'])
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
        ->and(spec059Codes($tree, 'failure'))->toBe(['general.error']);
})->group('SPEC-059');

it('AC7: what the hash judges is read and left to the hash, as c2patool says', function (string $relative): void {
    $tree = spec059Verify($relative);

    expect($tree['format'])->toBe('gif')
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec059Codes($tree, 'success'))->toContain('claimSignature.validated')
        ->and(spec059Codes($tree, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec059Codes($tree, 'failure'))->not->toContain('general.error');
})->with(['gif/rechunked-100.gif', 'gif/c2pa-after-netscape.gif', 'gif/truncated-after-c2pa.gif', 'gif/no-trailer.gif', 'gif/trailing-bytes.gif'])->group('SPEC-059');

it('AC8: the range is the whole block', function (): void {
    expect((new GifManifestStoreExtractor)->extract(spec059Stream(spec059File('gif/rechunked-100.gif')))?->ranges)->toBe([['start' => 781, 'length' => 131472]]);
})->group('SPEC-059');

it('AC9: nothing after the first image is read', function (): void {
    // a stream that records the furthest byte read
    $bytes = spec059File('fixture-signed.gif');
    $imageAt = 131489;   // the image descriptor, step 256
    $stream = spec059Stream($bytes);
    $store = (new GifManifestStoreExtractor)->extract($stream);
    $furthest = ftell($stream);

    expect((new GifManifestStoreExtractor)->extract(spec059Stream(spec059File('gif/c2pa-after-image.gif'))))->toBeNull()
        ->and($store)->not->toBeNull()
        ->and($furthest)->toBeLessThanOrEqual($imageAt + 10);
})->group('SPEC-059');

it('AC10: the bounds apply before memory is spent', function (): void {
    $comment = "\x21\xFE\x01x\x00";   // a comment extension of one byte
    $many = spec059Gif(str_repeat($comment, GifManifestStoreExtractor::MAX_BLOCKS + 1));
    $big = spec059Gif("\x21\xFF\x0BC2PA_GIF\x01\x00\x00".str_repeat("\xFF".str_repeat('x', 255), 8)."\x00");

    $blocks = null;
    try {
        (new GifManifestStoreExtractor)->extract(spec059Stream($many));
    } catch (ContainerException $e) {
        $blocks = $e->getMessage();
    }
    $store = null;
    try {
        (new GifManifestStoreExtractor(1024))->extract(spec059Stream($big));
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
    $unknown = (new Verifier)->verify(spec059Stream("GIF88a\x01\x00\x01\x00\x00\x00\x00\x3B"));

    expect($detector->detect(spec059Stream(spec059File('fixture-signed.gif'))))->toBe('gif')
        ->and($detector->detect(spec059Stream(spec059File('gif/gif87a.gif'))))->toBe('gif')
        ->and($detector->detect(spec059Stream(spec059File('fixture-signed.png'))))->toBe('png')
        ->and($detector->detect(spec059Stream("GIF88a\x01\x00\x01\x00\x00\x00\x00\x3B")))->toBeNull()
        ->and($gif87['validation_state'])->toBe('Invalid')
        ->and(spec059Codes($gif87, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(implode(' ', array_map(static fn ($s): string => $s->explanation, $unknown->result->statuses)))->toContain('GIF');
})->group('SPEC-059');

it('AC12: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say', function (string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec059File("c2patool/gif/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $ours = spec059Verify('fixture-signed.gif', $trusted);

    expect($ours['format'])->toBe('gif')
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec059Codes($ours, 'success'))->toBe(spec059Codes($oracle, 'success'))
        ->and(spec059Codes($ours, 'failure'))->toBe(spec059Codes($oracle, 'failure'));
})->with([
    '0.27.22' => ['fixture-signed', false],
    '0.27.22 trusted' => ['fixture-signed.trusted', true],
    '0.28.1' => ['fixture-signed.0.28.1', false],
    '0.28.1 trusted' => ['fixture-signed.0.28.1.trusted', true],
])->group('SPEC-059');

it('AC12: a flipped image byte and a flipped store byte fail as c2patool says', function (): void {
    expect(spec059Codes(spec059Verify('gif/flip-image.gif'), 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec059Codes(spec059Verify('gif/flip-store.gif'), 'failure'))->toContain('assertion.hashedURI.mismatch');
})->group('SPEC-059');

it('AC4 (amendment 1): an empty C2PA_GIF block before a full one is two blocks', function (): void {
    $fault = spec059Fault('gif/empty-then-c2pa.gif');
    $tree = spec059Verify('gif/empty-then-c2pa.gif');

    expect($fault?->getMessage())->toContain('two C2PA_GIF blocks')->toContain('781')
        ->and($tree['@hasManifest'])->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec059Codes($tree, 'failure'))->toBe(['general.error']);
})->group('SPEC-059');

it('AC13 (amendment 1): every extension is sub-blocks after its label: an empty comment is read past', function (): void {
    expect(spec059Verify('gif/signed-empty-comment.gif')['validation_state'])->toBe('Valid')
        ->and(spec059Verify('gif/signed-empty-comment.gif', true)['validation_state'])->toBe('Trusted');

    // a comment whose data holds the bytes of a C2PA_GIF block is a comment
    $fake = "\x21\xFF\x0BC2PA_GIF\x01\x00\x00\x08\x00\x00\x00\x08jumb\x00";
    $comment = "\x21\xFE".chr(strlen($fake)).$fake."\x00";

    expect((new GifManifestStoreExtractor)->extract(spec059Stream(spec059Gif($comment))))->toBeNull()
        ->and((new GifManifestStoreExtractor)->extract(spec059Stream(spec059Gif("\x21\xFE\x00".$comment))))->toBeNull();
})->group('SPEC-059');

it('AC14 (amendment 1): 1-byte sub-blocks cost neither the time limit nor the memory', function (): void {
    $ones = static fn (int $n, string $byte): string => str_repeat("\x01".$byte, $n);
    $other = "\x21\xFF\x0BOTHERAPP1.0".$ones(4 * 1024 * 1024, 'o')."\x00";
    $store = "\x21\xFF\x0BC2PA_GIF\x01\x00\x00".$ones(1024 * 1024, 'x')."\x00";
    $gif = spec059Gif($other.$store);
    unset($other, $store);
    $stream = spec059Stream($gif);
    unset($gif);

    $before = memory_get_usage();
    memory_reset_peak_usage();
    $started = hrtime(true);
    $fault = null;
    try {
        (new GifManifestStoreExtractor)->extract($stream);
    } catch (ContainerException $e) {
        $fault = $e->getMessage();
    }
    $seconds = (hrtime(true) - $started) / 1e9;
    $held = memory_get_peak_usage() - $before;

    expect($fault)->toContain('LBox')
        ->and($seconds)->toBeLessThan(3.0)
        ->and($held)->toBeLessThan(16 * 1024 * 1024);
})->group('SPEC-059');
