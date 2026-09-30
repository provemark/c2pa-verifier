<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Hash\HashException;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\FragmentedVerifier;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-051: the merkle map names one of the three hash algorithms. The
 * map's alg went to hash_init() unchecked, so a six-byte edit of init.mp4
 * made verify() throw a ValueError (measured 2026-09-30 on v0.2.7). Every
 * variant here is init.mp4 with the map's alg edited in place: the first
 * "sha256" after the text "merkle", so no box size changes.
 */

const SPEC051_FRAGMENTS = ['seg_1.m4s', 'seg_2.m4s', 'seg_3.m4s', 'seg_4.m4s', 'seg_5.m4s'];

function spec051Settings(): TrustSettings
{
    return TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json'));
}

/** init.mp4 with the seven bytes of the map's alg (CBOR head and "sha256") replaced */
function spec051Init(string $replacement): string
{
    $bytes = (string) file_get_contents(Corpus::fixtures().'/bmff-fragmented/init.mp4');
    $at = strpos($bytes, 'sha256', (int) strpos($bytes, 'merkle'));
    if ($at === false || $bytes[$at - 1] !== "\x66" || strlen($replacement) !== 7) {
        throw new RuntimeException('the merkle map alg is not where SPEC-051 expects it');
    }

    return substr_replace($bytes, $replacement, $at - 1, 7);
}

/** @return resource */
function spec051Stream(string $bytes)
{
    $stream = fopen('php://memory', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('cannot open php://memory');
    }
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** @return Generator<string, resource> */
function spec051Fragments(): Generator
{
    foreach (SPEC051_FRAGMENTS as $name) {
        $stream = fopen(Corpus::fixtures().'/bmff-fragmented/'.$name, 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$name}");
        }
        try {
            yield $name => $stream;
        } finally {
            fclose($stream);
        }
    }
}

/** @return array{whole: VerificationReport, fragmented: VerificationReport} */
function spec051Verify(string $init): array
{
    return [
        'whole' => (new Verifier)->verify(spec051Stream($init), spec051Settings()),
        'fragmented' => (new FragmentedVerifier)->verify(spec051Stream($init), spec051Fragments(), spec051Settings()),
    ];
}

/** @return list<ValidationStatus> */
function spec051Bmff(VerificationReport $report): array
{
    return array_values(array_filter(
        $report->result->statuses,
        static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionBmffHashMismatch || $s->code === StatusCode::AssertionBmffHashMatch,
    ));
}

it('AC1: an unknown name in the merkle map is refused, not thrown', function (string $route): void {
    $report = spec051Verify(spec051Init("\x66fooooo"))[$route];
    $bmff = spec051Bmff($report);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and($bmff)->toHaveCount(1)
        ->and($bmff[0]->code)->toBe(StatusCode::AssertionBmffHashMismatch)
        ->and($bmff[0]->explanation)->toContain('fooooo');
})->with(['whole', 'fragmented'])->group('SPEC-051');

it('AC2: a name PHP knows but C2PA does not is refused', function (string $route): void {
    expect(hash_algos())->toContain('crc32b');

    $report = spec051Verify(spec051Init("\x66crc32b"))[$route];
    $bmff = spec051Bmff($report);

    expect($bmff)->toHaveCount(1)
        ->and($bmff[0]->code)->toBe(StatusCode::AssertionBmffHashMismatch)
        ->and($bmff[0]->explanation)->toContain('crc32b');
})->with(['whole', 'fragmented'])->group('SPEC-051');

it('AC3: a merkle map alg that is not text is refused, not replaced', function (string $route): void {
    // 0x46: a byte string of six bytes, where the map had a text string of six
    $report = spec051Verify(spec051Init("\x46sha256"))[$route];
    $bmff = spec051Bmff($report);

    expect($bmff)->toHaveCount(1)
        ->and($bmff[0]->code)->toBe(StatusCode::AssertionBmffHashMismatch)
        ->and($bmff[0]->explanation)->toContain('not text');
})->with(['whole', 'fragmented'])->group('SPEC-051');

it('AC4: the range digest refuses an algorithm outside the three', function (): void {
    $stream = spec051Stream(str_repeat("\x00", 64));
    $digest = Closure::bind(
        fn (string $alg): string => $this->digest($stream, [['offset' => 0, 'length' => 64]], $alg),
        new BmffHashCheck,
        BmffHashCheck::class,
    );

    expect(strlen($digest('sha256')))->toBe(32)
        ->and(fn () => $digest('md5'))->toThrow(HashException::class)
        ->and(fn () => $digest('fooooo'))->toThrow(HashException::class);
})->group('SPEC-051');

it('AC5: the genuine fragmented set still matches', function (): void {
    // a guard: this passed before SPEC-051 and must after; the corpus-wide
    // before/after comparison is measured with bin/fuzz.php, as SPEC-050 was
    $report = spec051Verify(spec051Init("\x66sha256"))['fragmented'];
    $bmff = spec051Bmff($report);

    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and($bmff)->toHaveCount(1)
        ->and($bmff[0]->code)->toBe(StatusCode::AssertionBmffHashMatch);
})->group('SPEC-051');
