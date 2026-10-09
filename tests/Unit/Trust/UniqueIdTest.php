<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-015 amendment 9: a signer certificate carries no issuerUniqueID or subjectUniqueID (C2PA 2.4 §14.5.1.1,
 * RFC 5280 §4.1.2.8), as c2pa-rs's certificate profile refuses them. The probes are the trust matrix's
 * (SPEC-061 amendment 4): the field inserted into the tbsCertificate, which was then signed again.
 */

function spec015Uid(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/trust/chain-matrix/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/trust/chain-matrix/{$probe}.settings.json")));
}

it('AC14: a leaf with an issuerUniqueID or a subjectUniqueID is signingCredential.invalid, naming the field', function (): void {
    foreach (['leaf-issuer-unique-id' => 'issuerUniqueID', 'leaf-subject-unique-id' => 'subjectUniqueID'] as $probe => $field) {
        $report = spec015Uid($probe);
        $invalid = implode(' | ', array_map(
            static fn (ValidationStatus $s): string => $s->explanation,
            array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'signingCredential.invalid' && $s->ingredientUri === null),
        ));

        expect($report->result->state->value)->toBe('Invalid', $probe)
            ->and($invalid)->toContain('the tbsCertificate carries '.$field);
    }
})->group('SPEC-015');

it('AC14: an intermediate with a unique ID and the control stay Trusted, as in c2patool', function (): void {
    expect(spec015Uid('int-subject-unique-id')->result->state->value)->toBe('Trusted')
        ->and(spec015Uid('control')->result->state->value)->toBe('Trusted');
})->group('SPEC-015');
