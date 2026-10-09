<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-061: the trust matrix as a drift alarm. The probes come from bin/make-trust-matrix.php
 * (fixture mode, set "chain-matrix") under tests/Fixtures/trust/chain-matrix/; both c2patool versions'
 * answers are under tests/Fixtures/c2patool/chain-matrix/<probe>--<version>.json.
 */

/** Where this verifier is stricter than c2patool 0.28.1 on purpose, and the rule that makes it so. */
const SPEC061_STRICTER = [
    'leaf-eku-time-stamping' => 'step 152: a leaf whose only extended key usage is Time Stamping signs nothing',
    'int-sha1' => 'SPEC-048: no SHA-1 signature between the anchor and the leaf',
    'leaf-serial-negative' => 'SPEC-015 amendment 7: a serial number is a positive integer (RFC 5280 §4.1.2.2)',
    'leaf-serial-zero' => 'SPEC-015 amendment 7: a serial number is a positive integer (RFC 5280 §4.1.2.2)',
    'int-serial-negative' => 'SPEC-015 amendment 7: a serial number is a positive integer (RFC 5280 §4.1.2.2)',
];

/**
 * The same state as 0.28.1, other failure codes: probe => [0.28.1's, 0.27.22's, this verifier's]. 0.28.1 adds
 * signingCredential.untrusted to an expired leaf; both versions add it to a leaf with keyCertSign, whose chain
 * OpenSSL refuses (amendment 1).
 */
const SPEC061_CODES_DIFFER = [
    'leaf-expired' => [['signingCredential.expired', 'signingCredential.untrusted'], ['signingCredential.expired'], ['signingCredential.expired']],
    'leaf-not-yet-valid' => [['signingCredential.expired', 'signingCredential.untrusted'], ['signingCredential.expired'], ['signingCredential.expired']],
    'leaf-ku-cert-sign' => [['signingCredential.invalid', 'signingCredential.untrusted'], ['signingCredential.invalid', 'signingCredential.untrusted'], ['signingCredential.invalid']],
];

const SPEC061_RANK = ['Invalid' => 0, 'Valid' => 1, 'Trusted' => 2];

/** @return list<string> the probes the generator defines, read from its $variants list by name */
function spec061GeneratorProbes(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/bin/make-trust-matrix.php');
    $start = strpos($source, '$variants = [');
    $end = $start === false ? false : strpos($source, "\n];", $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('no $variants list in the generator');
    }
    preg_match_all("/^\\s+'([a-z0-9-]+)' => \\[/m", substr($source, $start, $end - $start), $m);

    return $m[1];
}

/** @return list<string> the probes with a fixture */
function spec061FixtureProbes(): array
{
    $probes = array_map(static fn (string $p): string => basename($p, '.png'), glob(Corpus::fixtures().'/trust/chain-matrix/*.png') ?: []);
    sort($probes);

    return $probes;
}

function spec061Verify(string $probe, bool $withSettings = true): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/trust/chain-matrix/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }
    $settings = $withSettings ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/chain-matrix/{$probe}.settings.json")) : null;

    return (new Verifier)->verify($stream, $settings);
}

/** @return array{state: string, failures: list<string>} */
function spec061Oracle(string $probe, string $version): array
{
    /** @var array{validation_state: string, validation_results?: array{activeManifest?: array{failure?: list<array{code: string}>}}} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/chain-matrix/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_values(array_unique(array_column($json['validation_results']['activeManifest']['failure'] ?? [], 'code')));
    sort($codes);

    return ['state' => $json['validation_state'], 'failures' => $codes];
}

/** @return list<string> */
function spec061Failures(VerificationReport $report): array
{
    $codes = array_values(array_unique(array_map(
        static fn (ValidationStatus $s): string => $s->code->value,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure() && $s->ingredientUri === null),
    )));
    sort($codes);

    return $codes;
}

it('AC6: every probe the generator defines has a fixture and both answers, and no fixture is left over', function (): void {
    $defined = spec061GeneratorProbes();
    sort($defined);

    expect($defined)->toHaveCount(45)
        ->and(spec061FixtureProbes())->toBe($defined);
    foreach ($defined as $probe) {
        foreach (['0.27.22', '0.28.1'] as $version) {
            expect(is_file(Corpus::fixtures()."/c2patool/chain-matrix/{$probe}--{$version}.json"))->toBeTrue("{$probe} {$version}");
        }
        expect(is_file(Corpus::fixtures()."/trust/chain-matrix/{$probe}.settings.json"))->toBeTrue($probe);
    }
})->group('SPEC-061');

it('AC1, AC2, AC3: every probe as c2patool 0.28.1 judges it, stricter only by name, never more lenient', function (): void {
    $probes = spec061FixtureProbes();
    expect($probes)->not->toBe([]);
    $stricter = [];
    foreach ($probes as $probe) {
        $mine = spec061Verify($probe)->result->state->value;
        $theirs = spec061Oracle($probe, '0.28.1')['state'];
        $older = spec061Oracle($probe, '0.27.22')['state'];

        // AC2: never above 0.28.1, and Trusted only where both versions say Trusted
        expect(SPEC061_RANK[$mine])->toBeLessThanOrEqual(SPEC061_RANK[$theirs], "{$probe}: {$mine} here, {$theirs} in 0.28.1");
        if ($mine === 'Trusted') {
            expect([$theirs, $older])->toBe(['Trusted', 'Trusted'], $probe);
        }
        if ($mine !== $theirs) {
            $stricter[] = $probe;
        }
    }
    sort($stricter);
    $named = array_keys(SPEC061_STRICTER);
    sort($named);

    // AC1 and AC3: the differences are exactly the named ones
    expect($stricter)->toBe($named);
})->group('SPEC-061');

it('AC4, AC3: where the state agrees, the failure codes agree, except the named probes', function (): void {
    $differ = [];
    foreach (spec061FixtureProbes() as $probe) {
        if (isset(SPEC061_STRICTER[$probe])) {
            continue;
        }
        $oracle = spec061Oracle($probe, '0.28.1');
        if (spec061Failures(spec061Verify($probe)) !== $oracle['failures']) {
            $differ[] = $probe;
        }
    }
    $named = array_keys(SPEC061_CODES_DIFFER);
    sort($named);

    expect($differ)->toBe($named);
    foreach (SPEC061_CODES_DIFFER as $probe => [$new, $old, $mine]) {
        expect(spec061Oracle($probe, '0.28.1')['failures'])->toBe($new, $probe)
            ->and(spec061Oracle($probe, '0.27.22')['failures'])->toBe($old, $probe)
            ->and(spec061Failures(spec061Verify($probe)))->toBe($mine, $probe);
    }
})->group('SPEC-061');

it('AC5: without settings no probe is trusted', function (): void {
    $probes = spec061FixtureProbes();
    expect($probes)->not->toBe([]);
    foreach ($probes as $probe) {
        $report = spec061Verify($probe, false);
        $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);

        expect($report->result->state->value)->not->toBe('Trusted', $probe)
            ->and($report->result->state->value === 'Invalid' || in_array('signingCredential.untrusted', $codes, true))->toBeTrue($probe);
    }
})->group('SPEC-061');
