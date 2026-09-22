<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Hash\BmffHashCheck;
use Provemark\C2paVerifier\Hash\HashException;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-027: the hard binding for ISOBMFF. Step 77 measured the rule by patching
 * two eprintln! lines into c2pa-rs's hashing loop and running it against this
 * repository's own fixtures:
 *
 *   for each top-level box no exclusion matches, in file order, hash the box's
 *   own offset as a big-endian uint64 and then the box's bytes.
 *
 * Recomputed by hand that reproduces both stored digests exactly. The offsets
 * are the point: a box whose bytes are untouched but which has moved changes
 * the digest, which is what `bmff/box-moved.mp4` exists to prove.
 *
 * The variants are built by bin/make-bmff-variants.php and c2patool 0.27.22's
 * answer to each is recorded in tests/Fixtures/c2patool/bmff/.
 *
 * AC5 and AC6 name a `HashException` that does not exist yet. Hash is the only
 * layer in this verifier without an exception of its own — Asn1, Cbor, Container,
 * Cose, Jumbf, Manifest, Timestamp and Trust all have one, and SPEC-013 turns
 * each into a status before the public boundary. Reading an assertion this
 * verifier cannot honour is a parse fault of exactly that kind, so 78b adds it
 * (marked `@internal`, as SPEC-025 requires of every layer exception).
 */

function spec027Verify(string $relative, bool $trusted = true): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }
    $settings = $trusted
        ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json'))
        : null;

    return (new Verifier)->verify($stream, $settings);
}

/** @return list<string> the failure codes, unique and sorted */
function spec027Failures(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure()) {
            $codes[$status->code->value] = true;
        }
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/** @return list<string> c2patool's failure codes for a recorded file */
function spec027OracleFailures(string $name): array
{
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    // c2patool drops `validation_status` when every failure it has is scoped to a
    // manifest, and reports them under validation_results instead. Both shapes occur
    // among the recorded oracles here, so both are read.
    $codes = [];
    foreach ((array) ($oracle['validation_status'] ?? []) as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[$status['code']] = true;
    }
    /** @var array<string, mixed> $results */
    $results = (array) ($oracle['validation_results'] ?? []);
    /** @var array<string, mixed> $active */
    $active = (array) ($results['activeManifest'] ?? []);
    foreach ((array) ($active['failure'] ?? []) as $status) {
        assert(is_array($status) && is_string($status['code']));
        $codes[$status['code']] = true;
    }
    $codes = array_keys($codes);
    sort($codes);

    return $codes;
}

/** @return list<string> every status code in a report, success and failure alike */
function spec027Codes(VerificationReport $report): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
}

it('AC1: the two fixtures verify, and the refusal SPEC-026 left behind is gone', function (): void {
    foreach (['fixture-signed.mp4' => 'mp4', 'fixture-signed.avif' => 'avif'] as $file => $oracle) {
        $report = spec027Verify($file);

        expect($report->result->state)->toBe(ValidationState::Trusted, $file)
            ->and(in_array(StatusCode::AssertionBmffHashMatch->value, spec027Codes($report), true))->toBeTrue($file)
            ->and(spec027Failures($report))->toBe([], $file)
            // the message SPEC-026 produced must be gone, not merely outvoted
            ->and(str_contains(implode(' ', array_map(
                static fn (ValidationStatus $s): string => $s->explanation,
                $report->result->statuses,
            )), 'not supported yet'))->toBeFalse($file);
    }
})->group('SPEC-027');

it('AC2: one changed byte of mdat is a mismatch, as at c2patool', function (): void {
    $report = spec027Verify('bmff/mdat-byte-changed.mp4', trusted: false);

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(in_array(StatusCode::AssertionBmffHashMismatch->value, spec027Codes($report), true))->toBeTrue()
        ->and(spec027Failures($report))->toBe(spec027OracleFailures('bmff/mdat-byte-changed'));
})->group('SPEC-027');

it('AC3: a box that moved is a mismatch even though every hashed byte is identical', function (): void {
    // the evidence first: what the hash covers is byte for byte the same file
    $whole = (string) file_get_contents(Corpus::fixtures().'/fixture-signed.mp4');
    $moved = (string) file_get_contents(Corpus::fixtures().'/bmff/box-moved.mp4');
    $original = substr($whole, 13610, 945).substr($whole, 14563, 1878);   // moov, mdat
    $shifted = substr($moved, 13618, 945).substr($moved, 14571, 1878);    // the same, eight bytes later

    expect($shifted)->toBe($original)
        ->and(strlen($moved))->toBe(strlen($whole) + 8);

    // and yet
    $report = spec027Verify('bmff/box-moved.mp4', trusted: false);
    expect(in_array(StatusCode::AssertionBmffHashMismatch->value, spec027Codes($report), true))->toBeTrue()
        ->and($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec027Failures($report))->toBe(spec027OracleFailures('bmff/box-moved'));
})->group('SPEC-027');

it('AC4: the data filter excludes our own box and no other uuid box', function (): void {
    $c2pa = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";
    $boxes = [
        ['offset' => 0, 'length' => 32, 'type' => 'ftyp'],
        ['offset' => 32, 'length' => 13578, 'type' => 'uuid'],
        ['offset' => 13610, 'length' => 945, 'type' => 'moov'],
    ];
    $exclusions = [
        ['xpath' => '/uuid', 'data' => [['offset' => 8, 'value' => $c2pa]]],
        ['xpath' => '/ftyp'],
    ];

    // the uuid box carries the C2PA UUID at offset 8: excluded
    $ours = BmffHashCheck::included($boxes, $exclusions, static fn (int $at, int $length): string => $at === 40 ? $c2pa : str_repeat("\x00", $length));
    expect($ours)->toBe([['offset' => 13610, 'length' => 945]]);

    // another UUID at the same place: the exclusion does not match, so it is hashed
    $theirs = BmffHashCheck::included($boxes, $exclusions, static fn (int $at, int $length): string => str_repeat("\x11", $length));
    expect($theirs)->toBe([
        ['offset' => 32, 'length' => 13578],
        ['offset' => 13610, 'length' => 945],
    ]);
})->group('SPEC-027');

it('AC5: an exclusion this verifier cannot honour is refused, not ignored', function (): void {
    // a nested xpath, in a real file: /free became /a/b, four bytes inside the assertion
    $report = spec027Verify('bmff/xpath-nested.mp4', trusted: false);
    $explanations = implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses));

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(str_contains($explanations, '/a/b'))->toBeTrue($explanations);

    // and at the seam, the filters c2pa-rs has and no fixture here carries
    foreach ([
        ['xpath' => '/free', 'subset' => [['offset' => 0, 'length' => 4]]],
        ['xpath' => '/free', 'length' => 8],
        ['xpath' => '/free', 'version' => 0],
        ['xpath' => '/free', 'flags' => "\x00\x00\x00"],
    ] as $exclusion) {
        expect(fn () => BmffHashCheck::included(
            [['offset' => 0, 'length' => 8, 'type' => 'free']],
            [$exclusion],
            static fn (int $at, int $length): string => str_repeat("\x00", $length),
        ))->toThrow(HashException::class);
    }
})->group('SPEC-027');

it('AC6: the assertion\'s own shape is checked before a digest is computed', function (): void {
    foreach ([
        'no hash' => ['alg' => 'sha256', 'exclusions' => []],
        'unknown alg' => ['alg' => 'sha3-512', 'hash' => 'x', 'exclusions' => []],
        'exclusions not a list' => ['alg' => 'sha256', 'hash' => 'x', 'exclusions' => 'everything'],
    ] as $name => $data) {
        expect(fn () => (new BmffHashCheck)->assertionOf($data))
            ->toThrow(HashException::class, '', $name);
    }
})->group('SPEC-027');

it('AC7: no image fixture changes its answer because ISOBMFF gained a hard binding', function (): void {
    foreach (['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp'] as $file) {
        $report = spec027Verify($file);

        expect($report->result->state)->toBe(ValidationState::Trusted, $file)
            ->and(spec027Failures($report))->toBe([], $file)
            ->and(in_array(StatusCode::AssertionBmffHashMatch->value, spec027Codes($report), true))->toBeFalse($file);
    }
})->group('SPEC-027');
