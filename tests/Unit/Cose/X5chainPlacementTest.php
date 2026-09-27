<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-047: where the certificate chain may be. Fixtures from bin/make-chain-constraint-variants.php
 * (step 164) under tests/Fixtures/chain-constraints/, verified with their throw-away root; both
 * c2patool versions' answers under tests/Fixtures/c2patool/chain-constraints/.
 */

function spec047Verify(string $name): VerificationReport
{
    return spec020Verify("chain-constraints/{$name}.png", 'chain-constraints/root.settings.json');
}

/** @return list<string> the explanations of the report's signingCredential.invalid statuses */
function spec047Invalid(VerificationReport $report): array
{
    return array_values(array_map(static fn (ValidationStatus $s): string => $s->explanation, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'signingCredential.invalid')));
}

/** The oracle's first line, when c2patool refused the file without a report. */
function spec047Refusal(string $name, string $version): string
{
    $path = dirname(__DIR__, 2)."/Fixtures/c2patool/chain-constraints/{$name}--{$version}.error.txt";

    return is_file($path) ? trim((string) file_get_contents($path)) : '';
}

it('AC1: label 33 in the unprotected header is not a chain', function (): void {
    foreach (['0.28.0', '0.27.22'] as $version) {
        expect(spec047Refusal('x5chain-unprotected', $version))->toBe('Error: could not find signing certificate chain in COSE signature', $version);
    }
    $report = spec047Verify('x5chain-unprotected');
    expect($report->result->state->value)->toBe('Invalid')
        ->and(implode(' | ', spec047Invalid($report)))->toContain('no x5chain');
})->group('SPEC-047');

it('AC2: a chain in both headers is refused', function (): void {
    foreach (['0.28.0', '0.27.22'] as $version) {
        expect(spec047Refusal('x5chain-both', $version))->toBe('Error: COSE verifier failure', $version);
    }
    $report = spec047Verify('x5chain-both');
    expect($report->result->state->value)->toBe('Invalid')
        ->and(implode(' | ', spec047Invalid($report)))->toContain('both');
})->group('SPEC-047');

it('AC3: an unprotected chain in a v2 claim is refused', function (): void {
    foreach (['x5chain-text-unprotected', 'x5chain-text-swapped'] as $name) {
        // the recorded difference: both c2patool versions call it Trusted
        expect(spec020Oracle("chain-constraints/{$name}--0.28.0.json")['validation_state'])->toBe('Trusted', $name)
            ->and(spec020Oracle("chain-constraints/{$name}--0.27.22.json")['validation_state'])->toBe('Trusted', $name);
        $report = spec047Verify($name);
        expect($report->result->state->value)->toBe('Invalid', $name)
            ->and(implode(' | ', spec047Invalid($report)))->toContain('protected header')
            ->and(str_contains($report->toJson(), 'Adobe Inc'))->toBeFalse();
    }
})->group('SPEC-047');

it('AC4: a v1 claim keeps the older form', function (): void {
    // a guard, green before and after: the 2022 Adobe file carries its chain unprotected under "x5chain"
    $report = spec020Verify('public-testfiles/adobe-20220124-C.jpg');
    expect($report->result->state->value)->toBe(spec020Oracle('adobe-20220124-C.json')['validation_state'])
        ->and(spec047Invalid($report))->toBe([])
        ->and($report->signatureInfo)->not->toBeNull();
})->group('SPEC-047');

it('AC5: nothing else moves', function (): void {
    // a guard: the protected chain of the plain probe stays Trusted
    expect(spec047Verify('plain')->result->state->value)->toBe('Trusted');
})->group('SPEC-047');
