<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-015 amendment 11: a signer certificate signed with id-Ed25519 carries no algorithm parameters
 * (C2PA 2.4 §14.5.1.1, RFC 8410 §3), as c2pa 0.91.3's certificate profile refuses them on the end-entity
 * certificate. The probes come from bin/make-ed25519-params-variants.php under tests/Fixtures/profile-ed25519/;
 * c2patool 0.28.2's and 0.28.3's answers are under tests/Fixtures/c2patool/profile-ed25519/.
 */

function spec015Ed25519(string $probe, bool $withSettings = true): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/profile-ed25519/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }
    $settings = $withSettings ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/profile-ed25519/{$probe}.settings.json")) : null;

    return (new Verifier)->verify($stream, $settings);
}

function spec015Ed25519Oracle(string $probe, string $version): string
{
    /** @var array{validation_state: string} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/profile-ed25519/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);

    return $json['validation_state'];
}

it('AC15: a signer certificate whose Ed25519 signatureAlgorithm carries parameters is signingCredential.invalid, as c2patool 0.28.3', function (): void {
    foreach ([true, false] as $withSettings) {
        $report = spec015Ed25519('leaf-signature-params', $withSettings);
        $invalid = implode(' | ', array_map(
            static fn (ValidationStatus $s): string => $s->explanation,
            array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'signingCredential.invalid' && $s->ingredientUri === null),
        ));

        expect($report->result->state->value)->toBe('Invalid')
            ->and($invalid)->toContain('id-Ed25519 with parameters');
    }
    expect(spec015Ed25519Oracle('leaf-signature-params', '0.28.3'))->toBe('Invalid')
        ->and(spec015Ed25519Oracle('leaf-signature-params', '0.28.2'))->toBe('Trusted');
})->group('SPEC-015');

it('AC15: the control and an intermediate with the parameters stay Trusted, as in c2patool 0.28.3; a leaf key with them is Invalid in both', function (): void {
    foreach (['control', 'intermediate-signature-params'] as $probe) {
        expect(spec015Ed25519($probe)->result->state->value)->toBe('Trusted', $probe)
            ->and(spec015Ed25519Oracle($probe, '0.28.3'))->toBe('Trusted', $probe);
    }
    expect(spec015Ed25519('leaf-spki-params')->result->state->value)->toBe('Invalid')
        ->and(spec015Ed25519Oracle('leaf-spki-params', '0.28.3'))->toBe('Invalid');
})->group('SPEC-015');
