<?php

declare(strict_types=1);

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
 * SPEC-028: a fragmented stream — a DASH init segment and N fragments — as one
 * verdict. Step 82 measured all three rules against the recorded values: the
 * init's `initHash` and each fragment's leaf hash are SPEC-027's digest applied
 * to another file, and the tree puts the largest power of two smaller than the
 * leaf count on the left, with sha256(left ‖ right).
 *
 * The fragments arrive one open stream at a time (SPEC-028 open question 1,
 * decided 2026-09-22): fifty fragments must never mean fifty open handles. The
 * generator below is what a caller would write, and closing is the caller's.
 *
 * c2patool 0.27.22 answers the broken cases with a text line rather than JSON
 * ("Error validating segments: … assertion.bmffHash.mismatch") and does not say
 * which file failed. Those answers are recorded in
 * tests/Fixtures/c2patool/bmff-fragmented/.
 */

function spec028Dir(): string
{
    return Corpus::fixtures().'/bmff-fragmented';
}

/** @return resource */
function spec028Init(string $name = 'init.mp4')
{
    $stream = fopen(spec028Dir().'/'.$name, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return $stream;
}

/**
 * The fragments, one open stream at a time, as a caller would offer them.
 *
 * @param  list<string>  $names  fixture-relative paths under bmff-fragmented/
 * @return Generator<string, resource>
 */
function spec028Fragments(array $names): Generator
{
    foreach ($names as $name) {
        $stream = fopen(spec028Dir().'/'.$name, 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$name}");
        }
        try {
            yield basename($name) => $stream;
        } finally {
            fclose($stream);
        }
    }
}

/** @return list<string> the five fragments in order */
function spec028Five(): array
{
    return ['seg_1.m4s', 'seg_2.m4s', 'seg_3.m4s', 'seg_4.m4s', 'seg_5.m4s'];
}

/** @param list<string> $fragments */
function spec028Verify(array $fragments, string $init = 'init.mp4', bool $trusted = true): VerificationReport
{
    $settings = $trusted
        ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json'))
        : null;

    return (new FragmentedVerifier)->verify(spec028Init($init), spec028Fragments($fragments), $settings);
}

/** @return list<string> */
function spec028Codes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
}

function spec028Explanations(VerificationReport $report): string
{
    return implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses));
}

it('AC1: an init segment and its fragments verify', function (): void {
    $report = spec028Verify(spec028Five());

    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(spec028Codes($report))->toContain(StatusCode::AssertionBmffHashMatch->value)
        ->and(count(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionBmffHashMatch)))->toBe(1)
        ->and(array_filter(spec028Codes($report), static fn (string $c): bool => str_ends_with($c, '.mismatch')))->toBe([]);
})->group('SPEC-028');

it('AC2: the init segment is bound, and the explanation says it was the init', function (): void {
    $report = spec028Verify(spec028Five(), init: 'broken/init-byte-changed.mp4', trusted: false);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec028Codes($report))->toContain(StatusCode::AssertionBmffHashMismatch->value)
        // which file failed is the whole point: a stream is many files
        ->and(strtolower(spec028Explanations($report)))->toContain('init');
})->group('SPEC-028');

it('AC3: a tampered fragment is caught and named', function (): void {
    $fragments = spec028Five();
    $fragments = [...array_slice($fragments, 0, 2), 'broken/seg_3-byte-changed.m4s', ...array_slice($fragments, 3)];
    $report = spec028Verify($fragments, trusted: false);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec028Codes($report))->toContain(StatusCode::AssertionBmffHashMismatch->value)
        ->and(spec028Explanations($report))->toContain('seg_3-byte-changed.m4s');
})->group('SPEC-028');

it('AC4: a fragment of another stream does not pass, though it is valid in its own', function (): void {
    // foreign-seg_3.m4s is seg_3 of the seven-fragment stream of step 82: correctly
    // signed material from a real stream, belonging to a different tree. This is the
    // substitution a Merkle root exists to prevent, and c2patool answers
    // assertion.bmffHash.mismatch on the same set (recorded, step 83a).
    $fragments = spec028Five();
    $fragments = [...array_slice($fragments, 0, 2), 'foreign-seg_3.m4s', ...array_slice($fragments, 3)];
    $report = spec028Verify($fragments, trusted: false);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec028Codes($report))->toContain(StatusCode::AssertionBmffHashMismatch->value)
        ->and(spec028Explanations($report))->toContain('foreign-seg_3.m4s');
})->group('SPEC-028');

it('AC5: the count is part of the promise', function (): void {
    $short = spec028Five();
    array_pop($short);
    $repeated = [...spec028Five(), 'seg_5.m4s'];

    foreach (['four of five' => $short, 'one offered twice' => $repeated] as $name => $fragments) {
        $report = spec028Verify($fragments, trusted: false);

        // not toContain($needle, $name): Pest reads the second argument as another
        // needle rather than a message — the tenth time in this project
        $explanations = strtolower(spec028Explanations($report));
        expect($report->result->state)->toBe(ValidationState::Invalid, $name)
            ->and(str_contains($explanations, 'fragment'))->toBeTrue("{$name}: {$explanations}")
            // the numbers, so the caller can see what was expected against what came
            ->and(str_contains($explanations, '5'))->toBeTrue($name);
    }
})->group('SPEC-028');

it('AC6: more than one merkle map is refused by name', function (): void {
    $assertion = [
        'alg' => 'sha256',
        'exclusions' => [],
        'merkle' => [
            ['uniqueId' => 1, 'localId' => 1, 'count' => 5],
            ['uniqueId' => 1, 'localId' => 2, 'count' => 5],
        ],
    ];

    expect(fn () => FragmentedVerifier::merkleMapOf($assertion))
        ->toThrow(HashException::class);
})->group('SPEC-028');

it('AC7: a whole file still behaves exactly as it did', function (): void {
    // the second kind of input may not move the answer for the first
    foreach (['fixture-signed.mp4', 'fixture-signed.avif', 'fixture-signed.mov', 'fixture-signed.heic'] as $file) {
        $stream = fopen(Corpus::fixtures().'/'.$file, 'rb');
        if ($stream === false) {
            throw new RuntimeException("cannot open {$file}");
        }
        $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json'));
        $report = (new Verifier)->verify($stream, $settings);

        expect($report->result->state)->toBe(ValidationState::Trusted, $file)
            ->and(in_array(StatusCode::AssertionBmffHashMatch->value, spec028Codes($report), true))->toBeTrue($file);
    }
})->group('SPEC-028');
