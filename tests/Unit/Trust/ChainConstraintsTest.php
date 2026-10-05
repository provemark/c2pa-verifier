<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-046: name constraints and critical extensions in the chain. Fixtures from
 * bin/make-chain-constraint-variants.php (step 164) under tests/Fixtures/chain-constraints/, verified
 * with their throw-away root; both c2patool versions' answers under
 * tests/Fixtures/c2patool/chain-constraints/.
 */

const SPEC046_SETTINGS = 'chain-constraints/root.settings.json';

function spec046Verify(string $name): VerificationReport
{
    return spec020Verify("chain-constraints/{$name}.png", SPEC046_SETTINGS);
}

/** @return array<string, mixed> */
function spec046Oracle(string $name, string $version): array
{
    return spec020Oracle("chain-constraints/{$name}--{$version}.json");
}

/** @return list<string> the oracle's active-manifest failure codes, sorted */
function spec046OracleFailures(string $name, string $version): array
{
    $results = spec046Oracle($name, $version)['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $codes = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']));
        $codes[] = $entry['code'];
    }
    sort($codes);

    return $codes;
}

/** @return list<string> the report's failure codes, sorted */
function spec046Failures(VerificationReport $report): array
{
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null && $s->code->isFailure())));
    sort($codes);

    return $codes;
}

/** The explanations of the report's statuses with this code, joined. */
function spec046Explained(VerificationReport $report, string $code): string
{
    return implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code))));
}

it('AC1: a leaf outside a name constraint is untrusted', function (): void {
    $report = spec046Verify('nc-outside');
    foreach (['0.28.0', '0.27.22'] as $version) {
        expect(spec046Oracle('nc-outside', $version)['validation_state'])->toBe('Valid', $version)
            ->and(spec046OracleFailures('nc-outside', $version))->toBe(['signingCredential.untrusted'], $version);
    }
    expect($report->result->state->value)->toBe('Valid')
        ->and(spec046Failures($report))->toBe(['signingCredential.untrusted'])
        ->and(spec046Explained($report, 'signingCredential.untrusted'))->toContain('Other Org')
        ->and(spec046Explained($report, 'signingCredential.untrusted'))->toContain('name constraint');
})->group('SPEC-046');

it('AC2: a leaf inside it is trusted', function (): void {
    // a guard, green before and after
    expect(spec046Verify('nc-inside')->result->state->value)->toBe('Trusted')
        ->and(spec046Oracle('nc-inside', '0.28.0')['validation_state'])->toBe('Trusted')
        ->and(spec046Oracle('nc-inside', '0.27.22')['validation_state'])->toBe('Trusted');
})->group('SPEC-046');

it('AC3: an unknown critical extension in the leaf', function (): void {
    $report = spec046Verify('critical-leaf');
    foreach (['0.28.0', '0.27.22'] as $version) {
        expect(spec046Oracle('critical-leaf', $version)['validation_state'])->toBe('Invalid', $version)
            ->and(spec046OracleFailures('critical-leaf', $version))->toBe(['signingCredential.invalid', 'signingCredential.untrusted'], $version);
    }
    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec046Failures($report))->toBe(['signingCredential.invalid', 'signingCredential.untrusted'])
        ->and(spec046Explained($report, 'signingCredential.invalid'))->toContain('1.3.6.1.4.1.99999.7');
})->group('SPEC-046');

it('AC4: an unknown critical extension in an intermediate', function (): void {
    $report = spec046Verify('critical-intermediate');
    foreach (['0.28.0', '0.27.22'] as $version) {
        expect(spec046Oracle('critical-intermediate', $version)['validation_state'])->toBe('Valid', $version)
            ->and(spec046OracleFailures('critical-intermediate', $version))->toBe(['signingCredential.untrusted'], $version);
    }
    expect($report->result->state->value)->toBe('Valid')
        ->and(spec046Failures($report))->toBe(['signingCredential.untrusted'])
        ->and(spec046Explained($report, 'signingCredential.untrusted'))->toContain('1.3.6.1.4.1.99999.7')
        ->and(spec046Explained($report, 'signingCredential.untrusted'))->toContain('Intermediate with an unknown critical extension');
})->group('SPEC-046');

it('AC5: name forms that are not evaluated fail closed', function (): void {
    // stricter than OpenSSL and both c2patool versions, which accept a leaf without a DNS name
    expect(spec046Oracle('nc-dns', '0.28.0')['validation_state'])->toBe('Trusted')
        ->and(spec046Oracle('nc-dns', '0.27.22')['validation_state'])->toBe('Trusted');
    $report = spec046Verify('nc-dns');
    expect($report->result->state->value)->toBe('Valid')
        ->and(spec046Failures($report))->toBe(['signingCredential.untrusted'])
        ->and(spec046Explained($report, 'signingCredential.untrusted'))->toContain('dNSName');
})->group('SPEC-046');

it('AC6: nothing else moves', function (): void {
    // a guard: the plain leaf and the policy leaf stay Trusted, as in both c2patool versions
    foreach (['plain', 'policy-required'] as $name) {
        expect(spec046Verify($name)->result->state->value)->toBe('Trusted', $name)
            ->and(spec046Oracle($name, '0.28.0')['validation_state'])->toBe('Trusted', $name);
    }
})->group('SPEC-046');

it('AC7: a name that is not UTF-8 is compared byte for byte (amendment 1, step 247)', function (): void {
    $outside = spec020Verify('name-encoding/t61-outside.png', 'name-encoding/root.settings.json');
    $inside = spec020Verify('name-encoding/t61-inside.png', 'name-encoding/root.settings.json');
    $oracle = static fn (string $name): array => spec020Oracle("name-encoding/{$name}--0.28.1.json");

    // c2patool 0.28.1, and OpenSSL's path validation ("permitted subtree violation", step 247)
    expect($oracle('t61-outside')['validation_state'])->toBe('Valid')
        ->and($oracle('t61-inside')['validation_state'])->toBe('Trusted');

    expect($outside->result->state->value)->toBe('Valid')
        ->and(spec046Failures($outside))->toBe(['signingCredential.untrusted'])
        ->and(spec046Explained($outside, 'signingCredential.untrusted'))->toContain('name constraint')
        ->and($inside->result->state->value)->toBe('Trusted');
})->group('SPEC-046');
