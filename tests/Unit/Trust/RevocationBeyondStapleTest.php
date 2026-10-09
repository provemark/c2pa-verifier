<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-066: revocation beyond the signer's own staple (C2PA 2.4 §15.9). The probes come from
 * bin/make-revocation-variants.php under tests/Fixtures/revocation/, with both c2patool versions' answers.
 */

function spec066Verify(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/revocation/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/revocation/throw-away-root.settings.json')));
}

function spec066Oracle(string $probe): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/revocation/{$probe}--0.28.1.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<ValidationStatus> statuses of $code, scoped to the active manifest or to an ingredient */
function spec066Of(VerificationReport $report, string $code, bool $ingredient = false): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code && ($s->ingredientUri !== null) === $ingredient));
}

it('AC1: a revoked intermediate leaves the path untrusted', function (): void {
    $report = spec066Verify('ca-revoked');
    $untrusted = spec066Of($report, 'signingCredential.untrusted');

    expect($report->result->state->value)->not->toBe('Trusted')
        ->and($untrusted)->not->toBe([])
        ->and($untrusted[0]->explanation)->toContain('Throw-away Intermediate (revocation)')
        ->and($untrusted[0]->explanation)->toContain('OCSP');
})->group('SPEC-066');

it('AC2: a good, removed, unverifiable or foreign answer about a CA changes nothing', function (string $probe): void {
    $report = spec066Verify($probe);

    expect($report->result->state->value)->toBe('Trusted', $probe)
        ->and(spec066Of($report, 'signingCredential.untrusted'))->toBe([]);
})->with(['ca-control', 'ca-good', 'ca-removed', 'ca-broken', 'ca-other'])->group('SPEC-066');

it('AC3: a certificate-status assertion\'s revoked is a failure for the manifest whose signer it names', function (): void {
    $report = spec066Verify('cs-revoked');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec066Of($report, 'signingCredential.ocsp.revoked', true))->not->toBe([])
        ->and(spec066Of($report, 'signingCredential.ocsp.revoked'))->toBe([]);
})->group('SPEC-066');

it('AC4: a certificate-status assertion\'s good is notRevoked, naming the assertion; the state is unchanged', function (): void {
    $report = spec066Verify('cs-good');
    $notRevoked = spec066Of($report, 'signingCredential.ocsp.notRevoked', true);

    expect($report->result->state->value)->toBe('Trusted')
        ->and($notRevoked)->not->toBe([])
        ->and($notRevoked[0]->explanation)->toContain('c2pa.certificate-status');
})->group('SPEC-066');

it('AC5: several responses: each is tried', function (): void {
    $report = spec066Verify('cs-two');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec066Of($report, 'signingCredential.ocsp.revoked', true))->not->toBe([]);
})->group('SPEC-066');

it('open question 2: an assertion about its own manifest\'s signer is used too', function (): void {
    $report = spec066Verify('cs-own');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec066Of($report, 'signingCredential.ocsp.revoked'))->not->toBe([]);
})->group('SPEC-066');

it('AC6: both c2patool versions ignore all of it; never more lenient than 0.28.1', function (): void {
    foreach (['ca-control', 'ca-revoked', 'ca-good', 'ca-removed', 'ca-broken', 'ca-other', 'cs-parent', 'cs-good', 'cs-revoked', 'cs-two', 'cs-own'] as $probe) {
        expect(spec066Oracle($probe))->toBe('Trusted', $probe);
    }
})->group('SPEC-066');
