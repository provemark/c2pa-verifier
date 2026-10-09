<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-015 amendment 8: a leaf signed with RSASSA-PSS names SHA-256, SHA-384 or SHA-512 as its hash and the same
 * hash for MGF1, and its signatureAlgorithm equals tbsCertificate's signature (RFC 5280 §4.1.1.2). The probes are
 * the trust matrix's (SPEC-061 amendment 3): an RSA intermediate signs the leaf with the parameters each names.
 */

function spec015Pss(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/trust/chain-matrix/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/chain-matrix/{$probe}.settings.json")));
}

function spec015PssInvalid(VerificationReport $report): string
{
    return implode(' | ', array_map(
        static fn (ValidationStatus $s): string => $s->explanation,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'signingCredential.invalid' && $s->ingredientUri === null),
    ));
}

it('AC13: PSS over SHA-256 or SHA-384 with the same MGF1 hash, any salt length, stays Trusted', function (): void {
    foreach (['leaf-pss-control', 'leaf-pss-sha384', 'leaf-pss-saltlen-20'] as $probe) {
        expect(spec015Pss($probe)->result->state->value)->toBe('Trusted', $probe);
    }
})->group('SPEC-015');

it('AC13: a PSS hash outside SHA-256, SHA-384 and SHA-512 is signingCredential.invalid', function (): void {
    $report = spec015Pss('leaf-pss-sha224');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec015PssInvalid($report))->toContain('RSASSA-PSS over SHA-224');
})->group('SPEC-015');

it('AC13: an MGF1 hash that differs from the PSS hash is signingCredential.invalid', function (): void {
    foreach (['leaf-pss-mgf1-sha384' => 'MGF1 over SHA-384', 'leaf-pss-mgf1-sha1' => 'MGF1 over SHA-1'] as $probe => $named) {
        $report = spec015Pss($probe);

        expect($report->result->state->value)->toBe('Invalid', $probe)
            ->and(spec015PssInvalid($report))->toContain($named.', not over the PSS hash SHA-256');
    }
})->group('SPEC-015');

it('AC13: a signatureAlgorithm that differs from tbsCertificate\'s signature is signingCredential.invalid', function (): void {
    foreach (['leaf-pss-outer-mgf1-sha384', 'leaf-pss-outer-mgf1-unknown'] as $probe) {
        $report = spec015Pss($probe);

        expect($report->result->state->value)->toBe('Invalid', $probe)
            ->and(spec015PssInvalid($report))->toContain('signatureAlgorithm differs from the signature field of tbsCertificate (RFC 5280 §4.1.1.2)');
    }
})->group('SPEC-015');
