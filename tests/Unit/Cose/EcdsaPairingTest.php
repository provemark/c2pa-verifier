<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-009 amendment 5: an ECDSA algorithm under a key on another curve (ES256 under P-384, …) is
 * signingCredential.invalid, as c2pa-rs refuses it (C2PA 2.5 §12.3.1: "Validators may reject other
 * pairings"). The probes come from bin/make-ecdsa-pairing-variants.php under tests/Fixtures/ecdsa-pairing/;
 * c2patool 0.28.2's and 0.28.3's answers are under tests/Fixtures/c2patool/ecdsa-pairing/.
 */

function spec009Pairing(string $probe, bool $withSettings = true): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/ecdsa-pairing/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }
    $settings = $withSettings ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/ecdsa-pairing/{$probe}.settings.json")) : null;

    return (new Verifier)->verify($stream, $settings);
}

function spec009PairingOracle(string $probe, string $version): string
{
    /** @var array{validation_state: string} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/ecdsa-pairing/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $json['validation_state'];
}

it('AC13: a crossed ECDSA pairing is Invalid with signingCredential.invalid and no claimSignature.mismatch, as c2patool 0.28.2 and 0.28.3 say Invalid', function (): void {
    foreach (['es256-p384', 'es384-p256', 'es512-p384'] as $probe) {
        foreach ([true, false] as $withSettings) {
            $codes = array_map(
                static fn (ValidationStatus $s): string => $s->code->value,
                array_filter(spec009Pairing($probe, $withSettings)->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null),
            );
            expect(spec009Pairing($probe, $withSettings)->result->state->value)->toBe('Invalid', $probe)
                ->and($codes)->toContain('signingCredential.invalid')
                ->and(in_array('claimSignature.mismatch', $codes, true))->toBeFalse($probe);
        }
        expect(spec009PairingOracle($probe, '0.28.3'))->toBe('Invalid', $probe)
            ->and(spec009PairingOracle($probe, '0.28.2'))->toBe('Invalid', $probe);
    }
})->group('SPEC-009');

it('AC13: the matching pairings stay Trusted, as in c2patool 0.28.3', function (): void {
    foreach (['es256-p256', 'es384-p384', 'es512-p521'] as $probe) {
        expect(spec009Pairing($probe)->result->state->value)->toBe('Trusted', $probe)
            ->and(spec009PairingOracle($probe, '0.28.3'))->toBe('Trusted', $probe);
    }
})->group('SPEC-009');
