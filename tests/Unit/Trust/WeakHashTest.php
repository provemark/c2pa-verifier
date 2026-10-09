<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-048: no SHA-1 or MD5 signature in the certificate path. Fixtures from
 * bin/make-chain-constraint-variants.php (steps 168 and 169a) under tests/Fixtures/chain-constraints/,
 * verified with their throw-away root; both c2patool versions' answers under
 * tests/Fixtures/c2patool/chain-constraints/. Every oracle calls every file here Trusted: each
 * refusal below is stricter than c2patool, as docs/comparison.md names it.
 */

function spec048Verify(string $name): VerificationReport
{
    return spec020Verify("chain-constraints/{$name}.png", 'chain-constraints/root.settings.json');
}

/** @return list<string> the report's active-manifest failure codes, sorted */
function spec048Failures(VerificationReport $report): array
{
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null && $s->code->isFailure())));
    sort($codes);

    return $codes;
}

function spec048Explained(VerificationReport $report, string $code): string
{
    return implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code))));
}

/**
 * Both c2patool versions' verdict on a file.
 *
 * @return list<mixed>
 */
function spec048Oracles(string $name): array
{
    return [spec020Oracle("chain-constraints/{$name}--0.28.0.json")['validation_state'], spec020Oracle("chain-constraints/{$name}--0.27.22.json")['validation_state']];
}

it('AC1: a SHA-1 intermediate is untrusted', function (): void {
    expect(spec048Oracles('sha1-intermediate'))->toBe(['Trusted', 'Trusted']);
    $report = spec048Verify('sha1-intermediate');
    expect($report->result->state->value)->toBe('Valid')
        ->and(spec048Failures($report))->toBe(['signingCredential.untrusted'])
        ->and(spec048Explained($report, 'signingCredential.untrusted'))->toContain('ecdsa-with-SHA1')
        ->and(spec048Explained($report, 'signingCredential.untrusted'))->toContain('Intermediate signed with SHA-1');
})->group('SPEC-048');

it('AC2: an MD5 intermediate is untrusted', function (): void {
    expect(spec048Oracles('md5-intermediate'))->toBe(['Trusted', 'Trusted']);
    $report = spec048Verify('md5-intermediate');
    expect($report->result->state->value)->toBe('Valid')
        ->and(spec048Failures($report))->toBe(['signingCredential.untrusted'])
        ->and(spec048Explained($report, 'signingCredential.untrusted'))->toContain('md5WithRSAEncryption');
})->group('SPEC-048');

it('AC3: RSASSA-PSS with its default hash is weak', function (): void {
    expect(spec048Oracles('pss-sha1-intermediate'))->toBe(['Trusted', 'Trusted'])
        ->and(spec048Oracles('pss-sha1-leaf'))->toBe(['Trusted', 'Trusted']);
    $intermediate = spec048Verify('pss-sha1-intermediate');
    expect($intermediate->result->state->value)->toBe('Valid')
        ->and(spec048Failures($intermediate))->toBe(['signingCredential.untrusted'])
        ->and(spec048Explained($intermediate, 'signingCredential.untrusted'))->toContain('SHA-1');
    $leaf = spec048Verify('pss-sha1-leaf');
    expect($leaf->result->state->value)->toBe('Invalid')
        // its intermediate is RSA-1024, refused since SPEC-014 amendment 8 (amendment 3)
        ->and(spec048Failures($leaf))->toBe(['signingCredential.invalid', 'signingCredential.untrusted'])
        ->and(spec048Explained($leaf, 'signingCredential.invalid'))->toContain('SHA-1');
})->group('SPEC-048');

it('AC4: what stays as it was', function (): void {
    // a guard: the plain leaf
    expect(spec048Verify('plain')->result->state->value)->toBe('Trusted')
        ->and(spec048Oracles('plain'))->toBe(['Trusted', 'Trusted']);
    // the RSA-1024 intermediate, once Trusted as the oracles, is refused for its size since SPEC-014 amendment 8 (amendment 3)
    $small = spec048Verify('rsa1024-intermediate');
    expect($small->result->state->value)->toBe('Valid')
        ->and(spec048Explained($small, 'signingCredential.untrusted'))->toContain('RSA key of 1024 bits')
        ->and(spec048Oracles('rsa1024-intermediate'))->toBe(['Trusted', 'Trusted']);
})->group('SPEC-048');

it('AC5: nothing else moves', function (): void {
    // a guard: a real PS256 file keeps its verdict (matrix/ps256.jpg, a PSS leaf over SHA-256)
    $report = spec020Verify('matrix/ps256.jpg', 'trust/full.settings.json');
    expect(array_filter(spec048Failures($report), static fn (string $c): bool => $c === 'signingCredential.invalid' || $c === 'signingCredential.untrusted'))->toBe([]);
})->group('SPEC-048');
