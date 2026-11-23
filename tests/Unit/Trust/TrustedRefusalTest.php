<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Trust\AnchorEvidence;
use Provemark\C2paVerifier\Trust\ChainCheck;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-014 amendment 11: signingCredential.trusted is withdrawn where the certificate profile failed or the
 * signer is revoked, as c2pa 0.92.0 does even when the chain reaches an anchor. The oracle is c2patool 0.29.0.
 */

const SPEC014R_TRUSTED = 'signingCredential.trusted';

/** @return list<string> the active manifest's codes of a report */
function spec014rCodes(VerificationReport $report): array
{
    return array_values(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null)));
}

/** @return list<string> the active manifest's codes of a recorded report */
function spec014rOracleCodes(string $relative): array
{
    $oracle = spec020Oracle($relative);
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

/** The active manifest's evidence: the anchor its signer reached, or null. */
function spec014rSigner(VerificationReport $report): ?AnchorEvidence
{
    return $report->evidence()[$report->store?->active->label ?? '']->signer ?? null;
}

/**
 * @param  array{ingredientAssertionURI: string, validationDeltas: array{success: list<array<string, string>>, informational: list<array<string, string>>, failure: list<array<string, string>>}}  $delta
 * @return list<string>
 */
function spec014rDeltaCodes(array $delta, string $kind): array
{
    $list = $kind === 'failure' ? $delta['validationDeltas']['failure'] : $delta['validationDeltas']['success'];

    return array_map(static fn (array $entry): string => $entry['code'] ?? '', $list);
}

/**
 * @param  list<ValidationStatus>  $statuses
 * @return list<string>
 */
function spec014rLines(array $statuses): array
{
    return array_map(static fn (ValidationStatus $s): string => $s->code->value.' '.$s->url, $statuses);
}

it('AC15: a profile fault takes trusted away', function (): void {
    $cases = [
        ['profile/eku-any.png', 'profile/throw-away-root.settings.json', 'profile/eku-any--0.29.0.json', 'signingCredential.invalid'],
        ['trust/chain-matrix/leaf-expired.png', 'trust/chain-matrix/leaf-expired.settings.json', 'chain-matrix/leaf-expired--0.29.0.json', 'signingCredential.expired'],
    ];
    foreach ($cases as [$file, $settings, $oracle, $fault]) {
        $theirs = spec014rOracleCodes($oracle);
        expect($theirs)->toContain($fault)
            ->and($theirs)->not->toContain(SPEC014R_TRUSTED);
        $report = spec020Verify($file, $settings);
        $ours = spec014rCodes($report);
        expect(in_array($fault, $ours, true))->toBeTrue($file)
            ->and(in_array(SPEC014R_TRUSTED, $ours, true))->toBeFalse($file)
            ->and($report->result->state->value)->toBe('Invalid')
            ->and(spec014rSigner($report))->toBeNull();   // the evidence follows the report (SPEC-082 AC7; Maurice, 2026-11-23)
    }
})->group('SPEC-014');

it('AC16: a revoked signer alike', function (): void {
    $theirs = spec014rOracleCodes('revocation/cs-own--0.29.0.json');
    expect($theirs)->toContain('signingCredential.ocsp.revoked')
        ->and($theirs)->not->toContain(SPEC014R_TRUSTED);
    $report = spec020Verify('revocation/cs-own.png', 'revocation/throw-away-root.settings.json');
    $ours = spec014rCodes($report);
    expect(in_array('signingCredential.ocsp.revoked', $ours, true))->toBeTrue()
        ->and(in_array(SPEC014R_TRUSTED, $ours, true))->toBeFalse()
        ->and($report->result->state->value)->toBe('Invalid')
        ->and(spec014rSigner($report))->toBeNull();
})->group('SPEC-014');

it('AC17: ingredient manifests alike', function (): void {
    // an ingredient signer revoked by its own staple, under its anchor: 0.29.0's delta has no trusted
    $theirs = spec020Deltas(spec020Oracle('ingredient-staple/child-staple-revoked--0.29.0.json'));
    $ours = spec020Deltas(spec020Verify('ingredient-staple/child-staple-revoked.png', 'ingredient-staple/throw-away-root.settings.json')->toArray());
    expect($theirs)->toHaveCount(1)
        ->and($ours)->toHaveCount(1);
    foreach ([$theirs[0], $ours[0]] as $side => $delta) {
        expect(spec014rDeltaCodes($delta, 'failure'))->toContain('signingCredential.ocsp.revoked')
            ->and(in_array(SPEC014R_TRUSTED, spec014rDeltaCodes($delta, 'success'), true))->toBeFalse((string) $side);
    }

    // and across the multi-manifest corpus, never trusted beside a refusal
    foreach (spec020Multi() as $name => $relative) {
        foreach (spec020Deltas(spec020Verify($relative, SPEC021_SETTINGS)->toArray()) as $delta) {
            $refusal = array_intersect(['signingCredential.invalid', 'signingCredential.expired', 'signingCredential.ocsp.revoked'], spec014rDeltaCodes($delta, 'failure')) !== [];
            expect($refusal && in_array(SPEC014R_TRUSTED, spec014rDeltaCodes($delta, 'success'), true))->toBeFalse("{$name}: {$delta['ingredientAssertionURI']}");
        }
    }
})->group('SPEC-014');

it('AC18: a sound signer keeps it', function (): void {
    expect(spec014rOracleCodes('chain-matrix/control--0.29.0.json'))->toContain(SPEC014R_TRUSTED);
    $report = spec020Verify('trust/chain-matrix/control.png', 'trust/chain-matrix/control.settings.json');
    expect(in_array(SPEC014R_TRUSTED, spec014rCodes($report), true))->toBeTrue()
        ->and($report->result->state->value)->toBe('Trusted')
        ->and(spec014rSigner($report))->not->toBeNull();
})->group('SPEC-014');

it("AC19: only the profile's own faults count", function (): void {
    $a = 'self#jumbf=/c2pa/a/c2pa.signature';
    $b = 'self#jumbf=/c2pa/b/c2pa.signature';
    $base = [new ValidationStatus(StatusCode::SigningCredentialTrusted, $a, 'ok'), new ValidationStatus(StatusCode::SigningCredentialTrusted, $b, 'ok')];

    // an invalid from elsewhere (not the profile): nothing withdrawn
    $other = [...$base, new ValidationStatus(StatusCode::SigningCredentialInvalid, $a, 'key does not fit')];
    expect(spec014rLines(ChainCheck::withoutRefused($other, [])))->toBe(spec014rLines($other));

    // a profile fault, or a revoked staple, on url a: withdrawn on a only
    foreach ([StatusCode::SigningCredentialInvalid, StatusCode::SigningCredentialExpired] as $fault) {
        $profile = [new ValidationStatus($fault, $a, 'profile')];
        expect(spec014rLines(ChainCheck::withoutRefused([...$base, ...$profile], $profile)))->toBe([SPEC014R_TRUSTED.' '.$b, $fault->value.' '.$a]);
    }
    $revoked = [...$base, new ValidationStatus(StatusCode::SigningCredentialOcspRevoked, $a, 'revoked')];
    expect(spec014rLines(ChainCheck::withoutRefused($revoked, [])))->toBe([SPEC014R_TRUSTED.' '.$b, 'signingCredential.ocsp.revoked '.$a]);
})->group('SPEC-014');
