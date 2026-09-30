<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Tests\Support\ShortReadStream;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\FragmentedVerifier;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-050: a short read is not the end of the file. PHP's fread() may
 * return fewer bytes than asked on a stream that is not a plain file; the
 * AWS SDK's S3 stream wrapper does (measured in the WordPress plugin,
 * 2026-09-30), and a genuine file came out Invalid. The wrapper in
 * tests/Support/ShortReadStream.php gives at most seven bytes per read, so
 * every read the verifier makes is short unless it asks again.
 */

function spec050Settings(): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json'));
}

/** @return resource */
function spec050Disk(string $relative)
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return $stream;
}

/** @param resource $stream */
function spec050Verify($stream): VerificationReport
{
    try {
        return (new Verifier)->verify($stream, spec050Settings());
    } finally {
        fclose($stream);
    }
}

/** @return list<string> "code: explanation", in order */
function spec050Statuses(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value.': '.$s->explanation, $report->result->statuses);
}

/**
 * @param  list<string>  $names  under bmff-fragmented/
 * @return Generator<string, resource>
 */
function spec050Fragments(array $names, bool $short): Generator
{
    foreach ($names as $name) {
        $path = Corpus::fixtures().'/bmff-fragmented/'.$name;
        $stream = $short ? ShortReadStream::open($path) : spec050Disk('bmff-fragmented/'.$name);
        try {
            yield $name => $stream;
        } finally {
            fclose($stream);
        }
    }
}

it('AC1: every format verifies the same through short reads', function (string $file): void {
    $fromDisk = spec050Verify(spec050Disk($file));
    $throughShortReads = spec050Verify(ShortReadStream::open(Corpus::fixtures().'/'.$file));

    // the file is genuine, so the comparison is about something
    expect($fromDisk->result->state)->not->toBe(ValidationState::Invalid)
        ->and(spec050Statuses($throughShortReads))->toBe(spec050Statuses($fromDisk))
        ->and($throughShortReads->toArray())->toBe($fromDisk->toArray());
})->with([
    'fixture-signed.jpg',
    'fixture-signed.png',
    'fixture-signed.webp',
    'fixture-signed.mp4',
    'fixture-signed.mov',
    'fixture-signed.avif',
    'fixture-signed.heic',
])->group('SPEC-050');

it('AC2: a fragmented stream verifies the same through short reads', function (): void {
    $five = ['seg_1.m4s', 'seg_2.m4s', 'seg_3.m4s', 'seg_4.m4s', 'seg_5.m4s'];
    $dir = Corpus::fixtures().'/bmff-fragmented/';

    $fromDisk = (new FragmentedVerifier)->verify(spec050Disk('bmff-fragmented/init.mp4'), spec050Fragments($five, false), spec050Settings());
    $throughShortReads = (new FragmentedVerifier)->verify(ShortReadStream::open($dir.'init.mp4'), spec050Fragments($five, true), spec050Settings());

    expect($fromDisk->result->state)->toBe(ValidationState::Trusted)
        ->and(spec050Statuses($throughShortReads))->toBe(spec050Statuses($fromDisk))
        ->and($throughShortReads->toArray())->toBe($fromDisk->toArray());
})->group('SPEC-050');

it('AC3: a truncated file is still truncated through short reads', function (string $file): void {
    $fromDisk = spec050Verify(spec050Disk($file));
    $throughShortReads = spec050Verify(ShortReadStream::open(Corpus::fixtures().'/'.$file));

    expect($fromDisk->result->state)->toBe(ValidationState::Invalid)
        ->and($throughShortReads->result->state)->toBe(ValidationState::Invalid)
        ->and(spec050Statuses($throughShortReads))->toBe(spec050Statuses($fromDisk));
})->with([
    'jpeg/truncated-in-piece-2.jpg',
    'png/truncated-in-cabx.png',
    'webp/chunk-overruns-file.webp',
])->group('SPEC-050');

it('AC4: a stream that stops giving bytes ends the read', function (): void {
    // 1,000 bytes in is inside the manifest store's first APP11 segment
    $report = spec050Verify(ShortReadStream::stalling(Corpus::fixtures().'/fixture-signed.jpg', 1000));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(implode(' | ', spec050Statuses($report)))->toContain('unexpected end of file');
})->group('SPEC-050');
