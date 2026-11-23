<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cose\ClaimSignatureCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-010 amendment 7: claimSignature.validated is withheld on a refused credential, as c2pa's
 * verify_internal does since 0.91.11 (C2PA 2.4 §15.7, "if the claim has not yet been rejected").
 * signingCredential.untrusted is not a refusal, as in c2pa. The oracle is c2patool 0.29.0.
 */

const SPEC010R_VALIDATED = 'claimSignature.validated';
const SPEC010R_REFUSALS = ['signingCredential.invalid', 'signingCredential.ocsp.revoked', 'signingCredential.expired', 'algorithm.unsupported'];

/** @return list<string> the active manifest's codes of a report, every list */
function spec010rCodes(VerificationReport $report): array
{
    return array_values(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null)));
}

/**
 * @param  array<string, mixed>  $oracle
 * @return list<string> the active manifest's codes of a recorded report, every list
 */
function spec010rOracleCodes(array $oracle): array
{
    $results = $oracle['validation_results'] ?? [];
    assert(is_array($results));
    $active = $results['activeManifest'] ?? [];
    assert(is_array($active));
    $codes = [];
    foreach (['success', 'informational', 'failure'] as $kind) {
        foreach ((array) ($active[$kind] ?? []) as $entry) {
            $codes[] = is_array($entry) && is_string($entry['code'] ?? null) ? $entry['code'] : '';
        }
    }

    return $codes;
}

it('AC11: a refused credential: no claimSignature.validated', function (): void {
    foreach (['profile/eku-any.png' => ['profile/eku-any-no-settings--0.29.0.json', 'signingCredential.invalid'], 'profile/expired.png' => ['inside-validity/profile-expired-no-settings--0.29.0.json', 'signingCredential.expired']] as $file => [$oracle, $refusal]) {
        $theirs = spec010rOracleCodes(spec020Oracle($oracle));
        expect($theirs)->toContain($refusal)
            ->and($theirs)->not->toContain(SPEC010R_VALIDATED);
        $report = spec020Verify($file);
        $ours = spec010rCodes($report);
        expect(in_array($refusal, $ours, true))->toBeTrue($file)
            ->and(in_array(SPEC010R_VALIDATED, $ours, true))->toBeFalse($file)
            ->and($report->result->state->value)->toBe('Invalid');
    }
})->group('SPEC-010');

it('AC12: a revoked signer alike', function (): void {
    $theirs = spec010rOracleCodes(spec020Oracle('revocation/cs-own--0.29.0.json'));
    expect($theirs)->toContain('signingCredential.ocsp.revoked')
        ->and($theirs)->not->toContain(SPEC010R_VALIDATED);
    $report = spec020Verify('revocation/cs-own.png', 'revocation/throw-away-root.settings.json');
    $ours = spec010rCodes($report);
    expect(in_array('signingCredential.ocsp.revoked', $ours, true))->toBeTrue()
        ->and(in_array(SPEC010R_VALIDATED, $ours, true))->toBeFalse()
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-010');

it('AC13: ingredient manifests alike', function (): void {
    $refused = 0;
    $kept = 0;
    foreach (spec020Multi() as $name => $relative) {
        $report = spec020Verify($relative, SPEC021_SETTINGS);
        $oracle = spec020Oracle(DRIFT_ORACLE.'/'.(string) preg_replace('/\.[a-z]+$/', '.json', $relative));
        foreach ([spec020Deltas($report->toArray()), spec020Deltas($oracle)] as $side => $deltas) {
            foreach ($deltas as $delta) {
                $success = array_column($delta['validationDeltas']['success'], 'code');
                $failure = array_column($delta['validationDeltas']['failure'], 'code');
                $verified = in_array('claimSignature.insideValidity', $success, true) || in_array('signingCredential.expired', $failure, true);
                if (! $verified && ! in_array(SPEC010R_VALIDATED, $success, true)) {
                    continue;   // no verified signature in this delta: nothing to withhold
                }
                $refusal = array_intersect(SPEC010R_REFUSALS, $failure) !== [];
                expect(in_array(SPEC010R_VALIDATED, $success, true))->toBe(! $refusal, "{$name} ({$side}): {$delta['ingredientAssertionURI']}");
                $refused += $refusal ? 1 : 0;
                $kept += $refusal ? 0 : 1;
            }
        }
    }
    expect($refused)->toBeGreaterThan(0)   // an expired ingredient signer is among them (c2pa-rs/ocsp.jpg)
        ->and($kept)->toBeGreaterThan(0);
})->group('SPEC-010');

it('AC14: an untrusted signer keeps it', function (): void {
    $theirs = spec010rOracleCodes(spec020Oracle(DRIFT_ORACLE.'/png.json'));
    expect($theirs)->toContain('signingCredential.untrusted')
        ->and($theirs)->toContain(SPEC010R_VALIDATED);
    $report = spec020Verify('fixture-signed.png');
    $ours = spec010rCodes($report);
    expect(in_array('signingCredential.untrusted', $ours, true))->toBeTrue()
        ->and(in_array(SPEC010R_VALIDATED, $ours, true))->toBeTrue()
        ->and($report->result->state->value)->toBe('Valid');
})->group('SPEC-010');

it('AC15: the refusals, per signature', function (): void {
    $a = 'self#jumbf=/c2pa/a/c2pa.signature';
    $b = 'self#jumbf=/c2pa/b/c2pa.signature';
    foreach ([StatusCode::AlgorithmUnsupported, StatusCode::SigningCredentialInvalid, StatusCode::SigningCredentialExpired, StatusCode::SigningCredentialOcspRevoked] as $refusal) {
        $statuses = [
            new ValidationStatus(StatusCode::ClaimSignatureValidated, $a, 'ok'),
            new ValidationStatus(StatusCode::ClaimSignatureValidated, $b, 'ok'),
            new ValidationStatus(StatusCode::SigningCredentialUntrusted, $b, 'untrusted'),
            new ValidationStatus($refusal, $a, 'refused'),
        ];
        $left = array_map(static fn (ValidationStatus $s): string => $s->code->value.' '.$s->url, ClaimSignatureCheck::withRefusals($statuses));
        expect($left)->toBe([SPEC010R_VALIDATED.' '.$b, 'signingCredential.untrusted '.$b, $refusal->value.' '.$a], $refusal->value);
    }
})->group('SPEC-010');
