<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\PlainTextManifestStoreExtractor;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-060: plain text — the C2PATextManifestWrapper (C2PA 2.4 §A.8), opt-in.
 * The fixtures and the variants were measured in step 265 against c2patool
 * 0.28.1 built with `unstable_plain_text` (tests/Fixtures/text/README.md); the
 * recordings are under tests/Fixtures/c2patool/text/.
 */

function spec060File(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$relative);
}

/** @return resource */
function spec060Stream(string $bytes)
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
function spec060Codes(array $tree, string $kind): array
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
 * The verifier's report on some bytes, as a tree; text on unless $text is false,
 * with the test roots when $trusted.
 *
 * @return array<string, mixed>
 */
function spec060VerifyBytes(string $bytes, bool $text = true, bool $trusted = false): array
{
    $settings = $trusted ? TrustSettings::fromJson(spec060File('trust/full.settings.json')) : null;
    $verifier = $text ? new Verifier(text: new PlainTextManifestStoreExtractor) : new Verifier;
    $report = $verifier->verify(spec060Stream($bytes), $settings);
    /** @var array<string, mixed> $tree */
    $tree = $report->toArray();
    $tree['@hasManifest'] = $report->hasManifest;

    return $tree;
}

/** @return array<string, mixed> */
function spec060Verify(string $relative, bool $text = true, bool $trusted = false): array
{
    return spec060VerifyBytes(spec060File($relative), $text, $trusted);
}

/** The extractor's fault on some bytes, or null when it threw none. */
function spec060Fault(string $bytes, int $maxStoreLength = PlainTextManifestStoreExtractor::DEFAULT_MAX_STORE_LENGTH): ?ContainerException
{
    try {
        (new PlainTextManifestStoreExtractor($maxStoreLength))->extract(spec060Stream($bytes));
    } catch (ContainerException $e) {
        return $e;
    }

    return null;
}

/** One variation selector per byte, as §A.8 maps them. */
function spec060Selectors(string $bytes): string
{
    $out = '';
    foreach (str_split($bytes) as $b) {
        $out .= mb_chr(ord($b) <= 15 ? 0xFE00 + ord($b) : 0xE0100 + ord($b) - 16, 'UTF-8');
    }

    return $out;
}

/** A wrapper: the marker, the magic, version 1, the length field $declared, then $body. */
function spec060Wrapper(int $declared, string $body): string
{
    return "\u{FEFF}".spec060Selectors("C2PATXT\0\x01".pack('N', $declared).$body);
}

it('AC1: the fixture yields the store, byte-exact, with the wrapper as its range', function (): void {
    $store = (new PlainTextManifestStoreExtractor)->extract(spec060Stream(spec060File('fixture-signed.txt')));

    expect($store)->not->toBeNull()
        ->and(strlen((string) $store?->bytes))->toBe(3526)
        ->and(substr((string) $store?->bytes, 0, 8))->toBe("\x00\x00\x0d\xc6jumb")
        ->and(hash('sha256', (string) $store?->bytes))->toBe('7e129aa47672c12089009251dd9a24ad52f48430a9c9fca06e246497d866cd3a')
        ->and($store?->ranges)->toBe([['start' => 60, 'length' => 14165]]);
})->group('SPEC-060');

it('AC2: text off is today\'s answer', function (string $relative): void {
    $tree = spec060Verify($relative, text: false);

    expect($tree['format'])->toBe('unknown')
        ->and($tree['@hasManifest'])->toBeFalse()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec060Codes($tree, 'failure'))->toBe(['general.error']);
})->with(fn (): array => array_map(
    static fn (string $path): string => substr($path, strlen(dirname(__DIR__, 2).'/Fixtures/')),
    [dirname(__DIR__, 2).'/Fixtures/fixture-signed.txt', dirname(__DIR__, 2).'/Fixtures/fixture-unsigned.txt', ...(glob(dirname(__DIR__, 2).'/Fixtures/text/*.txt') ?: [])],
))->group('SPEC-060');

it('AC3: no wrapper is no manifest', function (string $relative): void {
    $tree = spec060Verify($relative);

    expect((new PlainTextManifestStoreExtractor)->extract(spec060Stream(spec060File($relative))))->toBeNull()
        ->and($tree['format'])->toBe('text')
        ->and($tree['@hasManifest'])->toBeFalse()
        ->and(spec060Codes($tree, 'failure'))->toBe([]);
})->with([
    'fixture-unsigned.txt', 'text/nfd-emoji-unsigned.txt', 'text/no-wrapper.txt',
    'text/no-marker.txt', 'text/magic-other.txt',   // amendment 1 B: letter-in-run is AC6
])->group('SPEC-060');

it('AC4: another version is not a wrapper', function (): void {
    expect((new PlainTextManifestStoreExtractor)->extract(spec060Stream(spec060File('text/version-2.txt'))))->toBeNull()
        ->and(spec060Verify('text/version-2.txt')['@hasManifest'])->toBeFalse();
})->group('SPEC-060');

it('AC5: two wrappers are an error (stricter than the oracle, named)', function (): void {
    $fault = spec060Fault(spec060File('text/two-wrappers.txt'));
    $tree = spec060Verify('text/two-wrappers.txt');

    expect($fault?->getMessage())->toContain('60')->toContain('14225')
        ->and($tree['format'])->toBe('text')
        ->and($tree['@hasManifest'])->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec060Codes($tree, 'failure'))->toBe(['general.error']);
})->group('SPEC-060');

it('AC6: a wrapper whose store does not fit is an error (stricter than the oracle, named)', function (string $relative, string $declared, string $available): void {
    $fault = spec060Fault(spec060File($relative));
    $tree = spec060Verify($relative);

    expect($fault?->getMessage())->toContain($declared)->toContain($available)
        ->and($tree['format'])->toBe('text')
        ->and($tree['@hasManifest'])->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec060Codes($tree, 'failure'))->toBe(['general.error']);
})->with([
    // the length field longer than the run: 3,526 declared, 1,763 after the header
    'the store cut' => ['text/cut-in-store.txt', '3526', '1763'],
    // the length field one more than the store's LBox (the oracle: Valid)
    'the length one too large' => ['text/length-too-long.txt', '3527', '3526'],
    // amendment 1 B: the run ends at an `x` after 87 store bytes
    'a letter in the run' => ['text/letter-in-run.txt', '3526', '87'],
])->group('SPEC-060');

it('AC7: a candidate that is not version 1 is skipped, as the oracle does', function (): void {
    $tree = spec060Verify('text/bad-then-good.txt');

    expect($tree['@hasManifest'])->toBeTrue()
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec060Codes($tree, 'success'))->toContain('claimSignature.validated')
        ->and(spec060Codes($tree, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec060Codes($tree, 'failure'))->not->toContain('general.error');
})->group('SPEC-060');

it('AC8: what the hash judges is read and left to the hash, as the oracle says', function (string $relative): void {
    $tree = spec060Verify($relative);

    expect($tree['format'])->toBe('text')
        ->and($tree['validation_state'])->toBe('Invalid')
        ->and(spec060Codes($tree, 'success'))->toContain('claimSignature.validated')
        ->and(spec060Codes($tree, 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec060Codes($tree, 'failure'))->not->toContain('general.error');
})->with([
    'text/crlf.txt', 'text/bom-front.txt', 'text/flip-text.txt', 'text/nfd-text.txt',
    'text/no-padding.txt', 'text/more-padding.txt', 'text/text-after.txt',
    'text/wrapper-first.txt', 'text/only-wrapper.txt',
])->group('SPEC-060');

it('AC9: the range is the wrapper, from the marker to the end of the run', function (string $relative, int $start, int $length): void {
    expect((new PlainTextManifestStoreExtractor)->extract(spec060Stream(spec060File($relative)))?->ranges)
        ->toBe([['start' => $start, 'length' => $length]]);
})->with([
    'the fixture' => ['fixture-signed.txt', 60, 14165],
    'three more padding selectors' => ['text/more-padding.txt', 60, 14174],
    'the wrapper first' => ['text/wrapper-first.txt', 0, 14165],
])->group('SPEC-060');

it('AC10: text that is not UTF-8 is not text', function (string $bytes): void {
    $tree = spec060VerifyBytes($bytes);

    expect($tree['format'])->toBe('unknown')
        ->and($tree['@hasManifest'])->toBeFalse()
        ->and(spec060Codes($tree, 'failure'))->toBe(['general.error']);
})->with([
    'UTF-16LE' => [spec060File('text/utf16le.txt')],
    'one invalid byte after the wrapper' => [spec060File('fixture-signed.txt')."\xFF"],
    // E2 82 starts a three-byte sequence across the reader's 64 KiB piece; `A` breaks it
    'a broken sequence across two pieces' => [str_repeat('a', 65535)."\xE2\x82A"],
])->group('SPEC-060');

it('AC10: a valid sequence across two pieces is text', function (): void {
    $tree = spec060VerifyBytes(str_repeat('a', PlainTextManifestStoreExtractor::PIECE - 1).'€ and more');

    expect($tree['format'])->toBe('text')
        ->and($tree['@hasManifest'])->toBeFalse();
})->group('SPEC-060');

it('AC11: selectors that belong to the text are not a wrapper', function (): void {
    $plain = "A lone \u{FEFF} mark, a smiley \u{263A}\u{FE0F}, \u{FEFF}\u{FE0F} and \u{FEFF}\u{FE00}\u{FE01}.\n";

    expect(spec060Verify('text/nfd-emoji-signed.txt')['validation_state'])->toBe('Valid')
        ->and((new PlainTextManifestStoreExtractor)->extract(spec060Stream($plain)))->toBeNull()
        ->and(spec060VerifyBytes($plain)['format'])->toBe('text');
})->group('SPEC-060');

it('AC12: the bounds apply before memory is spent', function (): void {
    // a length field that declares more than the bound
    $declared = spec060Fault('text '.spec060Wrapper(2000, str_repeat('x', 2000)), 1024);
    // a run of selectors far longer than the bound's store and its padding
    $long = spec060Fault('text '.spec060Wrapper(16, "\x00\x00\x00\x10jumb".str_repeat('y', 8).str_repeat("\x00", 4000)), 1024);

    expect($declared?->getMessage())->toContain('1024')
        ->and($long?->getMessage())->toContain('1024')
        ->and(PlainTextManifestStoreExtractor::DEFAULT_MAX_STORE_LENGTH)->toBe(16 * 1024 * 1024)
        ->and(PlainTextManifestStoreExtractor::PIECE)->toBe(65536);   // AC10's dataset cuts at 65,536
})->group('SPEC-060');

it('AC12: a large text without a wrapper is read in pieces', function (): void {
    // 64 MiB written in pieces to a stream that keeps at most 1 MiB in memory
    $stream = fopen('php://temp/maxmemory:1048576', 'r+b') ?: throw new RuntimeException('cannot open php://temp');
    $piece = str_repeat("Plain text, line after line.\n", intdiv(1024 * 1024, 29));
    for ($i = 0; $i < 64; $i++) {
        fwrite($stream, $piece);
    }
    rewind($stream);
    unset($piece);

    $before = memory_get_usage();
    memory_reset_peak_usage();
    $store = (new PlainTextManifestStoreExtractor)->extract($stream);
    $held = memory_get_peak_usage() - $before;

    expect($store)->toBeNull()
        ->and($held)->toBeLessThan(16 * 1024 * 1024);
})->group('SPEC-060');

it('AC13: the other formats come first', function (string $relative): void {
    $on = spec060Verify($relative);
    $off = spec060Verify($relative, text: false);
    unset($on['@hasManifest'], $off['@hasManifest']);

    expect($on['format'])->not->toBe('text')
        ->and($on)->toBe($off);
})->with(['fixture-signed.png', 'fixture-signed.jpg', 'fixture-signed.gif'])->group('SPEC-060');

it('AC14: the signed fixtures verify as the oracle says', function (string $relative, string $recorded, bool $trusted): void {
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode(spec060File("c2patool/text/{$recorded}.json"), true, 512, JSON_THROW_ON_ERROR);
    $ours = spec060Verify($relative, trusted: $trusted);

    expect($ours['format'])->toBe('text')
        ->and($ours['validation_state'])->toBe($trusted ? 'Trusted' : 'Valid')
        ->and($ours['validation_state'])->toBe($oracle['validation_state'])
        ->and(spec060Codes($ours, 'success'))->toBe(spec060Codes($oracle, 'success'))
        ->and(spec060Codes($ours, 'failure'))->toBe(spec060Codes($oracle, 'failure'));
})->with([
    'the fixture' => ['fixture-signed.txt', 'fixture-signed', false],
    'the fixture, trusted' => ['fixture-signed.txt', 'fixture-signed.trusted', true],
    'signed from NFD' => ['text/nfd-emoji-signed.txt', 'nfd-emoji-signed', false],
    'signed from NFD, trusted' => ['text/nfd-emoji-signed.txt', 'nfd-emoji-signed.trusted', true],
])->group('SPEC-060');

it('AC14: a changed letter and a flipped signature byte fail as the oracle says', function (): void {
    expect(spec060Codes(spec060Verify('text/flip-text.txt'), 'failure'))->toContain('assertion.dataHash.mismatch')
        ->and(spec060Codes(spec060Verify('text/flip-store.txt'), 'failure'))->toContain('claimSignature.mismatch');
})->group('SPEC-060');
