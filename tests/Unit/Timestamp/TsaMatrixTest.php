<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-062: the timestamp matrix as a drift alarm. The probes come from bin/make-tsa-matrix.php
 * (fixture mode, set "tsa-matrix") under tests/Fixtures/timestamp/tsa-matrix/; both c2patool versions'
 * answers, with the probe's settings and without any, are under tests/Fixtures/c2patool/tsa-matrix/.
 */

/** Where this verifier is stricter than c2patool 0.28.1 on the state on purpose, and the rule that makes it so. */
const SPEC062_STRICTER = [];

/**
 * The same state as 0.28.1, other codes: probe => [rule, [0.28.1's timeStamp codes, failures], [this verifier's]].
 */
const SPEC062_CODES_DIFFER = [
    'tsa-root-as-manifest' => [
        'SPEC-031: a "manifest" anchor vouches for signers only',
        [['timeStamp.trusted', 'timeStamp.validated'], []],
        [['timeStamp.untrusted', 'timeStamp.validated'], []],
    ],
    'header-both' => [
        'SPEC-016: two timestamp headers are malformed',
        [['timeStamp.trusted', 'timeStamp.validated'], []],
        [['timeStamp.malformed'], []],
    ],
    'tsa-leaf-eku-email-only' => [
        'SPEC-017 AC7: a leaf without timeStamping is no time stamping authority',
        [['timeStamp.trusted', 'timeStamp.validated'], ['signingCredential.invalid']],
        [['timeStamp.untrusted', 'timeStamp.validated'], ['signingCredential.invalid']],
    ],
    'expired-signer-untrusted-tsa' => [
        '0.28.1 adds signingCredential.untrusted to signingCredential.expired',
        [['timeStamp.untrusted', 'timeStamp.validated'], ['signingCredential.expired', 'signingCredential.untrusted']],
        [['timeStamp.untrusted', 'timeStamp.validated'], ['signingCredential.expired']],
    ],
    'expired-signer-no-timestamp' => [
        '0.28.1 adds signingCredential.untrusted to signingCredential.expired',
        [[], ['signingCredential.expired', 'signingCredential.untrusted']],
        [[], ['signingCredential.expired']],
    ],
];

const SPEC062_RANK = ['Invalid' => 0, 'Valid' => 1, 'Trusted' => 2];

/** @return list<string> the probes the generator defines, read from its $variants list by name */
function spec062GeneratorProbes(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/bin/make-tsa-matrix.php');
    $start = strpos($source, '$variants = [');
    $end = $start === false ? false : strpos($source, "\n];", $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('no $variants list in the generator');
    }
    preg_match_all("/^\\s+'([a-z0-9-]+)' => \\[/m", substr($source, $start, $end - $start), $m);
    $probes = $m[1];
    sort($probes);

    return $probes;
}

/** @return list<string> the probes with a fixture */
function spec062FixtureProbes(): array
{
    $probes = array_map(static fn (string $p): string => basename($p, '.png'), glob(Corpus::fixtures().'/timestamp/tsa-matrix/*.png') ?: []);
    sort($probes);

    return $probes;
}

function spec062Verify(string $probe, bool $withSettings = true): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/timestamp/tsa-matrix/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }
    $settings = $withSettings ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/timestamp/tsa-matrix/{$probe}.settings.json")) : null;

    return (new Verifier)->verify($stream, $settings);
}

/** @return array{state: string, codes: array{0: list<string>, 1: list<string>}} the timeStamp codes and the failure codes */
function spec062Oracle(string $probe, string $version): array
{
    /** @var array{validation_state: string, validation_results?: array{activeManifest?: array<string, list<array{code: string}>>}} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/tsa-matrix/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
    $active = $json['validation_results']['activeManifest'] ?? [];
    $all = [];
    foreach (['success', 'informational', 'failure'] as $kind) {
        $all = [...$all, ...array_column($active[$kind] ?? [], 'code')];
    }
    $timestamp = array_values(array_unique(array_filter($all, static fn (string $c): bool => str_starts_with($c, 'timeStamp.'))));
    $failures = array_values(array_unique(array_column($active['failure'] ?? [], 'code')));
    sort($timestamp);
    sort($failures);

    return ['state' => $json['validation_state'], 'codes' => [$timestamp, $failures]];
}

/** @return array{0: list<string>, 1: list<string>} the active manifest's timeStamp codes and failure codes */
function spec062Codes(VerificationReport $report): array
{
    $active = array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null);
    $timestamp = array_values(array_unique(array_filter(array_map(static fn (ValidationStatus $s): string => $s->code->value, $active), static fn (string $c): bool => str_starts_with($c, 'timeStamp.'))));
    $failures = array_values(array_unique(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($active, static fn (ValidationStatus $s): bool => $s->code->isFailure()))));
    sort($timestamp);
    sort($failures);

    return [$timestamp, $failures];
}

it('AC7: every probe the generator defines has a fixture and four answers, and no fixture is left over', function (): void {
    $defined = spec062GeneratorProbes();

    expect($defined)->toHaveCount(29)
        ->and(spec062FixtureProbes())->toBe($defined);
    foreach ($defined as $probe) {
        foreach (['0.27.22', '0.28.1'] as $version) {
            foreach (['', '--no-settings'] as $suffix) {
                expect(is_file(Corpus::fixtures()."/c2patool/tsa-matrix/{$probe}--{$version}{$suffix}.json"))->toBeTrue("{$probe} {$version}{$suffix}");
            }
        }
        expect(is_file(Corpus::fixtures()."/timestamp/tsa-matrix/{$probe}.settings.json"))->toBeTrue($probe);
    }
})->group('SPEC-062');

it('AC1, AC2, AC3: every probe as c2patool 0.28.1 judges it, stricter only by name, never more lenient', function (): void {
    $probes = spec062FixtureProbes();
    expect($probes)->not->toBe([]);
    $stricter = [];
    foreach ($probes as $probe) {
        $report = spec062Verify($probe);
        $mine = $report->result->state->value;
        $oracle = spec062Oracle($probe, '0.28.1');

        // AC2: never above 0.28.1, and timeStamp.trusted only where 0.28.1 reports it
        expect(SPEC062_RANK[$mine])->toBeLessThanOrEqual(SPEC062_RANK[$oracle['state']], "{$probe}: {$mine} here, {$oracle['state']} in 0.28.1");
        if (in_array('timeStamp.trusted', spec062Codes($report)[0], true)) {
            expect($oracle['codes'][0])->toContain('timeStamp.trusted');
        }
        if ($mine !== $oracle['state']) {
            $stricter[] = $probe;
        }
    }
    $named = array_keys(SPEC062_STRICTER);
    sort($named);

    // AC1 and AC3: the differences are exactly the named ones
    expect($stricter)->toBe($named);
})->group('SPEC-062');

it('AC4, AC3: where the state agrees, the timeStamp and failure codes agree, except the named probes', function (): void {
    $differ = [];
    foreach (spec062FixtureProbes() as $probe) {
        $report = spec062Verify($probe);
        $oracle = spec062Oracle($probe, '0.28.1');
        if ($report->result->state->value !== $oracle['state']) {
            continue;
        }
        if (spec062Codes($report) !== $oracle['codes']) {
            $differ[] = $probe;
        }
    }
    $named = array_keys(SPEC062_CODES_DIFFER);
    sort($named);

    expect($differ)->toBe($named);
    foreach (SPEC062_CODES_DIFFER as $probe => [$rule, $theirs, $mine]) {
        expect(spec062Oracle($probe, '0.28.1')['codes'])->toBe($theirs, "{$probe} ({$rule})")
            ->and(spec062Codes(spec062Verify($probe)))->toBe($mine, "{$probe} ({$rule})");
    }
})->group('SPEC-062');

it('AC5: without settings no probe is trusted and no timestamp is trusted', function (): void {
    $probes = spec062FixtureProbes();
    expect($probes)->not->toBe([]);
    foreach ($probes as $probe) {
        $report = spec062Verify($probe, false);

        expect($report->result->state->value)->not->toBe('Trusted', $probe)
            ->and(spec062Codes($report)[0])->not->toContain('timeStamp.trusted');
    }
})->group('SPEC-062');

it('AC6: an expired signer is kept only by a trusted timestamp', function (): void {
    $kept = spec062Verify('expired-signer-trusted-tsa');
    expect($kept->result->state->value)->toBe('Trusted')
        ->and(spec062Codes($kept)[0])->toContain('timeStamp.trusted');

    foreach (['expired-signer-untrusted-tsa', 'expired-signer-no-timestamp'] as $probe) {
        $report = spec062Verify($probe);
        expect($report->result->state->value)->toBe('Invalid', $probe)
            ->and(spec062Codes($report)[1])->toContain('signingCredential.expired');
    }
})->group('SPEC-062');
