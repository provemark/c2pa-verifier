<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\Id3ManifestStoreExtractor;
use Provemark\C2paVerifier\Container\ManifestStoreBytes;

/*
 * SPEC-056: an ID3v2 tag → the C2PA GEOB's object. The fixture and the 25
 * variants were measured against c2patool 0.27.22 and 0.28.1 in step 225
 * (tests/Fixtures/mp3/README.md); the criteria copy that behaviour where
 * the spec says so, and are stricter where it says so.
 */

const SPEC056_STORE_LENGTH = 13462;

const SPEC056_STORE_SHA256 = 'aa19d53907b32e1dd479e081f39622c700f46c1492b3e1b6d2ddbda45126319f';

function spec056Fixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$name);
}

/** @return resource */
function spec056Memory(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

function spec056Extract(string $bytes, ?Id3ManifestStoreExtractor $extractor = null): ?ManifestStoreBytes
{
    return ($extractor ?? new Id3ManifestStoreExtractor)->extract(spec056Memory($bytes));
}

/** The ContainerException the bytes raise, or null when they raise none. */
function spec056Fault(string $bytes, ?Id3ManifestStoreExtractor $extractor = null): ?ContainerException
{
    try {
        spec056Extract($bytes, $extractor);
    } catch (ContainerException $e) {
        return $e;
    }

    return null;
}

it('AC1: extracts the store from the fixture, byte-exact, with its range', function (): void {
    $store = spec056Extract(spec056Fixture('fixture-signed.mp3'));

    expect($store)->toBeInstanceOf(ManifestStoreBytes::class);
    assert($store instanceof ManifestStoreBytes);
    expect(strlen($store->bytes))->toBe(SPEC056_STORE_LENGTH)
        ->and(hash('sha256', $store->bytes))->toBe(SPEC056_STORE_SHA256)
        ->and(bin2hex(substr($store->bytes, 0, 8)))->toBe('000034966a756d62')
        ->and($store->ranges)->toBe([['start' => 86, 'length' => 13462]]);
})->group('SPEC-056');

it('AC2: no C2PA GEOB is an outcome, not an error; the MIME type is matched exactly', function (string $name): void {
    expect(spec056Extract(spec056Fixture($name)))->toBeNull();
})->with(['fixture-unsigned.mp3', 'mp3/store-in-appended-tag.mp3', 'mp3/mime-octet-stream.mp3', 'mp3/mime-upper-case.mp3'])->group('SPEC-056');

it('AC3: the tag is read in all its shapes', function (string $variant): void {
    expect(hash('sha256', spec056Extract(spec056Fixture("mp3/{$variant}.mp3"))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256);
})->with(['encoding-latin1', 'encoding-utf16', 'encoding-utf16be', 'version-2-3', 'flag-extended-header', 'flag-footer', 'padding-after', 'other-geob-first'])->group('SPEC-056');

it('AC4: a tag header that contradicts itself is a fault before any frame is read', function (string $case): void {
    $signed = spec056Fixture('fixture-signed.mp3');
    [$bytes, $names] = match ($case) {
        'size not syncsafe' => [spec056Fixture('mp3/tag-size-not-syncsafe.mp3'), 'not syncsafe'],
        'version 2' => [substr_replace($signed, "\x02", 3, 1), 'ID3v2.2'],
        'version 5' => [substr_replace($signed, "\x05", 3, 1), 'ID3v2.5'],
        default => throw new InvalidArgumentException($case),
    };
    $stream = spec056Memory($bytes);

    try {
        (new Id3ManifestStoreExtractor)->extract($stream);
        $caught = null;
    } catch (ContainerException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ContainerException::class)
        ->and($caught?->getMessage())->toContain($names)
        ->and($caught?->storeReached)->toBeFalse()
        ->and(ftell($stream))->toBeLessThanOrEqual(10);
})->with(['size not syncsafe', 'version 2', 'version 5'])->group('SPEC-056');

it('AC5: a tag that promises more than the file holds is a fault, and says the store was there', function (): void {
    $caught = spec056Fault(spec056Fixture('mp3/truncated-in-store.mp3'));

    expect($caught)->toBeInstanceOf(ContainerException::class)
        ->and($caught?->getMessage())->toContain('13548')
        ->and($caught?->getMessage())->toContain('1086')
        ->and($caught?->storeReached)->toBeTrue();
})->group('SPEC-056');

it('AC6: two C2PA GEOBs are a fault naming both offsets', function (): void {
    $caught = spec056Fault(spec056Fixture('mp3/two-geob.mp3'));

    expect($caught?->getMessage())->toContain('offsets 33 and 13548')
        ->and($caught?->storeReached)->toBeTrue();
})->group('SPEC-056');

it('AC7: LBox must equal the object length', function (): void {
    expect(spec056Fault(spec056Fixture('mp3/lbox-differs.mp3'))?->getMessage())->toContain('LBox 13463 differs from the object length 13462');
})->group('SPEC-056');

it('AC8: the C2PA GEOB must fit the tag', function (): void {
    $caught = spec056Fault(spec056Fixture('mp3/frame-overruns-tag.mp3'));

    expect($caught?->getMessage())->toContain('frame at offset 33 declares 14505 bytes')
        ->and($caught?->storeReached)->toBeTrue();
})->group('SPEC-056');

it('AC9: an object shorter than a box header is a fault', function (string $variant, int $length): void {
    expect(spec056Fault(spec056Fixture("mp3/{$variant}.mp3"))?->getMessage())->toContain("object length {$length} is shorter than the 8-byte box header");
})->with([
    'four bytes' => ['object-too-short', 4],
    'empty' => ['object-empty', 0],
])->group('SPEC-056');

it('AC10: a C2PA GEOB that cannot be read as it stands is a fault; the flag alone is not', function (): void {
    $compressed = spec056Fault(spec056Fixture('mp3/frame-flags-compressed.mp3'));
    $unsynchronised = spec056Fault(spec056Fixture('mp3/flag-unsynchronisation.mp3'));
    $unsigned = spec056Fixture('fixture-unsigned.mp3');

    expect($compressed?->getMessage())->toContain('format flags')
        ->and($compressed?->storeReached)->toBeTrue()
        ->and($unsynchronised?->getMessage())->toContain('unsynchronisation')
        ->and($unsynchronised?->storeReached)->toBeTrue()
        // the unsigned fixture with the flag set: no C2PA GEOB, so nothing to refuse
        ->and(spec056Extract(substr_replace($unsigned, chr(ord($unsigned[5]) | 0x80), 5, 1)))->toBeNull();
})->group('SPEC-056');

it('AC11: what lies outside the tag is not the container\'s concern', function (): void {
    expect(hash('sha256', spec056Extract(spec056Fixture('mp3/id3v1-appended.mp3'))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256)
        ->and(hash('sha256', spec056Extract(spec056Fixture('mp3/tag-size-plus-one.mp3'))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256)
        ->and(spec056Extract(spec056Fixture('mp3/unsigned-id3v1.mp3')))->toBeNull();
})->group('SPEC-056');

it('AC12: the bounds apply', function (): void {
    $limited = spec056Fault(spec056Fixture('fixture-signed.mp3'), new Id3ManifestStoreExtractor(maxObjectLength: 1000));
    $frames = str_repeat('TXXX'."\0\0\0\1"."\0\0".'x', 4097);
    $many = 'ID3'."\x04\0\0".chr((strlen($frames) >> 21) & 0x7F).chr((strlen($frames) >> 14) & 0x7F).chr((strlen($frames) >> 7) & 0x7F).chr(strlen($frames) & 0x7F).$frames;

    expect($limited?->getMessage())->toContain('13462 exceeds the limit of 1000')
        ->and((new Id3ManifestStoreExtractor)->maxObjectLength)->toBe(16 * 1024 * 1024)
        ->and(spec056Fault($many)?->getMessage())->toContain('more than 4096 frames');
})->group('SPEC-056');

it('AC15: the legacy JUMBF media type is a C2PA GEOB too (amendment 2)', function (): void {
    expect(hash('sha256', spec056Extract(spec056Fixture('mp3/mime-legacy.mp3'))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256)
        // c2pa-ts writes application/x-c2pa-manifest-store
        ->and(spec056Extract(spec056Fixture('mp3-writers/c2pa-ts-signed.mp3')))->toBeInstanceOf(ManifestStoreBytes::class);
})->group('SPEC-056');

it('AC18: an iTunes frame size is read as a plain integer; an invalid frame id is a fault (amendment 2)', function (): void {
    $caught = spec056Fault(spec056Fixture('mp3/frame-id-invalid.mp3'));

    expect(hash('sha256', spec056Extract(spec056Fixture('mp3/tsse-plain-size.mp3'))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256)
        ->and($caught?->getMessage())->toContain('frame id')
        ->and($caught?->storeReached)->toBeFalse();
})->group('SPEC-056');

it('AC19: unsynchronisation with FF 00 inside the tag is a fault before the frames are walked (amendment 2)', function (): void {
    $caught = spec056Fault(spec056Fixture('mp3/unsync-ff00.mp3'));

    expect($caught?->getMessage())->toContain('FF 00')
        ->and($caught?->storeReached)->toBeFalse();
})->group('SPEC-056');

it('AC20: text fields of any length, grouped frames, v2.3 header bit 0x10 (amendment 2)', function (): void {
    expect(hash('sha256', spec056Extract(spec056Fixture('mp3/long-description.mp3'))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256)
        ->and(spec056Extract(spec056Fixture('mp3/grouped-geob-v24.mp3')))->toBeNull()
        ->and(spec056Extract(spec056Fixture('mp3/grouped-geob-v23.mp3')))->toBeNull()
        ->and(hash('sha256', spec056Extract(spec056Fixture('mp3/footer-bit-v23.mp3'))->bytes ?? ''))->toBe(SPEC056_STORE_SHA256);
})->group('SPEC-056');

it('AC21: a GEOB frame header at the very end of the file is a ContainerException or null, never another error (amendment 2)', function (int $tagSize): void {
    $syncsafe = static fn (int $v): string => chr(($v >> 21) & 0x7F).chr(($v >> 14) & 0x7F).chr(($v >> 7) & 0x7F).chr($v & 0x7F);
    $bytes = 'ID3'."\x04\0\0".$syncsafe($tagSize).'GEOB'.$syncsafe(5)."\0\0";

    try {
        $result = spec056Extract($bytes);
        $thrown = null;
    } catch (Throwable $e) {
        $result = null;
        $thrown = $e;
    }

    expect($thrown === null || $thrown instanceof ContainerException)->toBeTrue($thrown === null ? '' : $thrown::class.': '.$thrown->getMessage())
        ->and($result)->toBeNull();
})->with(['the tag ends there' => [10], 'the tag promises more' => [100]])->group('SPEC-056');

it('AC20: unterminated text fields are refused in linear time (amendment 3)', function (): void {
    $syncsafe = static fn (int $v): string => chr(($v >> 21) & 0x7F).chr(($v >> 14) & 0x7F).chr(($v >> 7) & 0x7F).chr($v & 0x7F);
    $body = "\x01application/c2pa\0".str_repeat('A', 8 * 1024 * 1024);   // UTF-16, no two-byte NUL anywhere
    $geob = 'GEOB'.$syncsafe(strlen($body))."\0\0".$body;
    $start = microtime(true);
    $caught = spec056Fault('ID3'."\x04\0\0".$syncsafe(strlen($geob)).$geob);

    expect($caught?->getMessage())->toContain('text fields do not end')
        ->and(microtime(true) - $start)->toBeLessThan(5.0);   // step 232: 13 s before the fix
})->group('SPEC-056');

it('AC22: the grouping flag on a GEOB that still reads as C2PA is a fault (amendment 3)', function (string $variant): void {
    $caught = spec056Fault(spec056Fixture("mp3/{$variant}.mp3"));

    expect($caught?->getMessage())->toContain('format flags')
        ->and($caught?->storeReached)->toBeTrue();
})->with(['group-flag-only', 'group-flag-only-v23'])->group('SPEC-056');

it('AC23: a tag that runs past the end of the file says the store was there, extended header or not (amendment 3)', function (string $variant): void {
    $caught = spec056Fault(spec056Fixture("mp3/{$variant}.mp3"));

    expect($caught?->getMessage())->toContain('past the end of the file')
        ->and($caught?->storeReached)->toBeTrue();
})->with(['tag-past-eof', 'tag-past-eof-extended'])->group('SPEC-056');
