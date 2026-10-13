<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\ContainerException;
use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Hash\DataHashCheck;
use Provemark\C2paVerifier\Hash\ExclusionAllowance;
use Provemark\C2paVerifier\Hash\ExclusionRegion;
use Provemark\C2paVerifier\Hash\JpegExclusionAllowance;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-069: an exclusion beyond the manifest store is allowed only where a
 * measured writer puts one — inside the payload of a JPEG APP1 Exif or XMP
 * segment of the primary image (Google Pixel 10) — and nowhere else, in no
 * other format. The files are tests/Fixtures/exclusion-coverage/ (steps 349,
 * 351, 352, re-signed under a throw-away root) and the Pixel 10 file under
 * tests/Fixtures/writers/.
 */

function spec069Verify(string $relative, ?string $settings = 'exclusion-coverage/throw-away-root.settings.json'): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings === null ? null : TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.$settings)));
}

/** @return list<ValidationStatus> */
function spec069DataHash(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.dataHash.')));
}

/** @return resource a stream over $bytes */
function spec069Memory(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('no memory stream');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/**
 * One JPEG segment: marker, length, payload.
 *
 * @param  int<0, 255>  $marker
 */
function spec069Segment(int $marker, string $payload): string
{
    return "\xff".chr($marker).pack('n', 2 + strlen($payload)).$payload;
}

it('keeps the Pixel 10 file as it was, its four exclusions allowed as APP1 Exif and XMP payloads', function (): void {
    $report = spec069Verify('writers/google-20250919-pixel10-npld-picnic-table.jpg', 'trust/google-pixel-intermediates.settings.json');
    $statuses = spec069DataHash($report);

    expect($report->result->state->value)->toBe('Trusted')
        ->and(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))->toBe([StatusCode::AssertionDataHashMatch, StatusCode::AssertionDataHashAdditionalExclusionsPresent])
        ->and($statuses[1]->explanation)->toContain('[6, 43697], [50643, 1158], [51805, 65458], [117267, 24606]')
        ->and($statuses[1]->explanation)->toContain('inside an allowed region (APP1 Exif payload, APP1 XMP payload)')
        // amendment 1: the allowed bytes are not signed (the Pixel 10 Pro's GPS, Golaszewski et al. 2026)
        ->and($statuses[1]->explanation)->toContain('these bytes are not covered by the signature: what they hold (Exif or XMP metadata such as a GPS position or a capture time) is not verified');
})->group('SPEC-069');

it('reports a PNG exclusion over the image data as a mismatch, without hashing', function (string $name, ?string $settings): void {
    $report = spec069Verify("exclusion-coverage/{$name}.png", $settings);
    $statuses = spec069DataHash($report);

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMismatch)
        ->and($statuses[0]->explanation)->toContain('the exclusion [46111, 1633] (start, length) holds no part of the manifest store and is not allowed where it lies')
        ->and($statuses[0]->explanation)->toContain('PNG allows no exclusion beyond the manifest store')
        ->and($report->result->state->value)->toBe('Invalid');
})->with([
    'IDAT excluded' => ['idat-excluded'],
    'the same, a pixel changed after signing' => ['idat-excluded-pixel-edited'],
])->with([
    'without trust settings' => [null],
    'under the throw-away root' => ['exclusion-coverage/throw-away-root.settings.json'],
])->group('SPEC-069');

it('answers the three-range shape through SPEC-068 and the one-byte floor here', function (): void {
    foreach (['all-but-store', 'all-but-store-pixel-edited'] as $name) {
        $statuses = spec069DataHash(spec069Verify("exclusion-coverage/{$name}.png"));
        expect($statuses)->toHaveCount(1)
            ->and($statuses[0]->explanation)->toContain('leave no byte of the 47773-byte file to hash');
    }
    $report = spec069Verify('exclusion-coverage/all-but-one-byte.png');
    $statuses = spec069DataHash($report);
    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMismatch)
        ->and($statuses[0]->explanation)->toContain('the exclusion [0, 33] (start, length)')
        ->and($statuses[0]->explanation)->toContain('PNG allows no exclusion beyond the manifest store')
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-069');

it('refuses a JPEG exclusion outside the allowed payloads, naming where it falls', function (string $name, string $range, string $falls): void {
    $report = spec069Verify("exclusion-coverage/{$name}.jpg");
    $statuses = spec069DataHash($report);

    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMismatch)
        ->and($statuses[0]->explanation)->toContain("the exclusion {$range} (start, length) holds no part of the manifest store and is not allowed where it lies: it falls in {$falls}")
        ->and($statuses[0]->explanation)->toContain('in JPEG an exclusion beyond the manifest store may lie only wholly inside the payload of an APP1 Exif or XMP segment of the primary image')
        ->and($report->result->state->value)->toBe('Invalid');
    // the deliberate divergence: c2patool honours every one of them
    /** @var array{validation_state: string} $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/exclusion-coverage/{$name}--0.28.2.json"), true, 512, JSON_THROW_ON_ERROR);
    expect($oracle['validation_state'])->toBe('Trusted');
})->with([
    'the scan data' => ['jpeg-scan-excluded', '[95371, 64]', 'the scan data and what follows (from 95271)'],
    'the whole Exif segment, marker and length included' => ['jpeg-app1-header-excluded', '[20, 24]', 'the APP1 Exif segment at 20'],
    'the ICC profile\'s payload' => ['jpeg-icc-excluded', '[48, 142]', 'the APP2 segment at 44'],
])->group('SPEC-069');

it('allows exactly the Exif payload of a JPEG: the positive control', function (): void {
    $report = spec069Verify('exclusion-coverage/jpeg-exif-allowed.jpg');
    $statuses = spec069DataHash($report);

    expect($report->result->state->value)->toBe('Trusted')
        ->and(array_map(static fn (ValidationStatus $s): StatusCode => $s->code, $statuses))->toBe([StatusCode::AssertionDataHashMatch, StatusCode::AssertionDataHashAdditionalExclusionsPresent])
        ->and($statuses[1]->explanation)->toContain('[24, 20]')
        ->and($statuses[1]->explanation)->toContain('(APP1 Exif payload)')
        ->and($statuses[1]->explanation)->toContain('these bytes are not covered by the signature');
})->group('SPEC-069');

it('maps a JPEG\'s segments up to the first SOS, with only the APP1 Exif and XMP payloads allowed (an Exif identifier in APP2 is not)', function (): void {
    $xmp = "http://ns.adobe.com/xap/1.0/\0<x/>";
    $extended = "http://ns.adobe.com/xmp/extension/\0".str_repeat('x', 40);
    $jpeg = "\xff\xd8".spec069Segment(0xE1, "Exif\0\0II*\0")
        .spec069Segment(0xE1, $xmp).spec069Segment(0xE1, $extended)
        .spec069Segment(0xE1, 'http://example.org/other')
        .spec069Segment(0xE2, "Exif\0\0not APP1, so not allowed")
        .spec069Segment(0xDB, str_repeat("\1", 65)).spec069Segment(0xDA, str_repeat("\0", 10))."\x12\x34\xff\xd9";
    $allowed = array_values(array_filter((new JpegExclusionAllowance)->regions(spec069Memory($jpeg)), static fn (ExclusionRegion $r): bool => $r->allowed));

    expect(array_map(static fn (ExclusionRegion $r): array => [$r->start, $r->length, $r->what], $allowed))->toBe([
        [6, 10, 'APP1 Exif payload'],
        [20, strlen($xmp), 'APP1 XMP payload'],
        [24 + strlen($xmp), strlen($extended), 'APP1 XMP payload'],
    ]);
})->group('SPEC-069');

it('refuses to map a JPEG whose segments cannot be walked to the first SOS', function (string $jpeg, string $fault): void {
    expect(fn (): array => (new JpegExclusionAllowance(maxSegments: 8))->regions(spec069Memory($jpeg)))
        ->toThrow(ContainerException::class, $fault);
})->with([
    'no SOI' => ["\xff\xe1\x00\x04ab", 'no SOI'],
    'a length past the end of the file' => ["\xff\xd8\xff\xe1\x01\x00Exif", 'runs past the end of the file'],
    'a marker without 0xFF' => ["\xff\xd8".spec069Segment(0xE0, 'JFIF')."\x12\x34", 'no marker at offset 10'],
    'a length below 2' => ["\xff\xd8\xff\xe1\x00\x01", 'a length of 1'],
    'EOI before any SOS' => ["\xff\xd8".spec069Segment(0xE0, 'JFIF')."\xff\xd9", 'marker 0xFFD9 without a length before the first SOS'],
    'no SOS at all' => ["\xff\xd8".spec069Segment(0xE0, 'JFIF'), 'unexpected end of file'],
    'more segments than the bound' => ["\xff\xd8".str_repeat(spec069Segment(0xFE, 'c'), 9).spec069Segment(0xDA, 'x'), 'more than 8 segments before the first SOS'],
])->group('SPEC-069');

it('refuses, never allows, when the allowed regions cannot be established; a file without extras never asks', function (): void {
    $refusing = new class implements ExclusionAllowance
    {
        public function format(): string
        {
            return 'JPEG';
        }

        public function rule(): string
        {
            return 'none';
        }

        public function regions($stream): array
        {
            throw new ContainerException('the walk broke');
        }
    };
    $open = static function (string $relative): array {
        $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
        $store = $stream === false ? null : (new JpegManifestStoreExtractor)->extract($stream);
        if ($store === null) {
            throw new RuntimeException("no store in {$relative}");
        }

        return [ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active, $stream, $store];
    };

    [$manifest, $stream, $store] = $open('exclusion-coverage/jpeg-exif-allowed.jpg');
    $statuses = (new DataHashCheck)->check($manifest, $stream, $store, allowance: $refusing);
    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionDataHashMismatch)
        ->and($statuses[0]->explanation)->toContain('the walk broke')
        ->and($statuses[0]->explanation)->toContain('never allowed by default');

    [$manifest, $stream, $store] = $open('fixture-signed.jpg');
    expect((new DataHashCheck)->check($manifest, $stream, $store, allowance: $refusing)[0]->code)->toBe(StatusCode::AssertionDataHashMatch);
})->group('SPEC-069');

it('allows an exclusion only wholly inside an allowed region: one byte past it is refused', function (int $start, int $length, bool $allowed): void {
    $region = new class($start, $length) implements ExclusionAllowance
    {
        public function __construct(private int $start, private int $length) {}

        public function format(): string
        {
            return 'JPEG';
        }

        public function rule(): string
        {
            return 'a test region';
        }

        public function regions($stream): array
        {
            return [new ExclusionRegion($this->start, $this->length, 'a test region', true)];
        }
    };
    $stream = fopen(Corpus::fixtures().'/exclusion-coverage/jpeg-exif-allowed.jpg', 'rb');
    $store = $stream === false ? null : (new JpegManifestStoreExtractor)->extract($stream);
    if ($store === null) {
        throw new RuntimeException('no store in jpeg-exif-allowed.jpg');
    }
    // the file's extra exclusion is [24, 20]
    $statuses = (new DataHashCheck)->check(ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active, $stream, $store, allowance: $region);

    expect($statuses[0]->code)->toBe($allowed ? StatusCode::AssertionDataHashMatch : StatusCode::AssertionDataHashMismatch);
})->with([
    'exactly the region' => [24, 20, true],
    'inside a larger region' => [20, 30, true],
    'the region one byte short at the end' => [24, 19, false],
    'the region starting one byte late' => [25, 19, false],
])->group('SPEC-069');
