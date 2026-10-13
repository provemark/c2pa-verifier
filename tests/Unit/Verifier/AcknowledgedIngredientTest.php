<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\IngredientManifestCheck;

/*
 * SPEC-070: a status an ingredient assertion recorded (C2PA 2.4
 * §18.16.12.4) is no longer dropped from the report. It is kept, marked as
 * acknowledged by the signer, and rendered under that ingredient's
 * validationDeltas.informational; the verdict ignores it, as before. The
 * files are SPEC-021 AC4's.
 */

/**
 * The entries under the ingredient deltas' informational lists whose code is $code.
 *
 * @param  array<string, mixed>  $array  VerificationReport::toArray()
 * @return list<array{code: string, url: string, explanation: string}>
 */
function spec070Informational(array $array, string $code): array
{
    $found = [];
    $results = $array['validation_results'] ?? null;
    assert(is_array($results));
    foreach ((array) ($results['ingredientDeltas'] ?? []) as $delta) {
        assert(is_array($delta) && is_array($delta['validationDeltas']));
        foreach ((array) ($delta['validationDeltas']['informational'] ?? []) as $status) {
            assert(is_array($status) && is_string($status['code']) && is_string($status['url']) && is_string($status['explanation']));
            if ($status['code'] === $code) {
                $found[] = ['code' => $status['code'], 'url' => $status['url'], 'explanation' => $status['explanation']];
            }
        }
    }

    return $found;
}

it('reports a failure the ingredient assertion recorded as informational, the verdict unchanged', function (string $relative, string $recorded, ?string $settings, ValidationState $state): void {
    $report = spec020Verify($relative, $settings);
    $array = $report->toArray();
    $acknowledged = spec070Informational($array, $recorded);

    expect($report->result->state)->toBe($state, $relative)
        ->and($acknowledged)->toHaveCount(1)
        ->and($acknowledged[0]['explanation'])->toMatch('#^acknowledged by the signer of [a-z]+:urn:uuid:[0-9a-f-]{36} #')
        ->and($acknowledged[0]['explanation'])->toContain('in its ingredient assertion (C2PA 2.4 §18.16.12.4), not counted in the verdict: ');
    // validation_status holds failures only, and the acknowledged line is not one
    $flat = array_column((array) ($array['validation_status'] ?? []), 'code');
    expect(in_array($recorded, $flat, true))->toBeFalse();
    $marked = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->acknowledged));
    expect(array_map(static fn (ValidationStatus $s): string => $s->code->value, $marked))->toContain($recorded);
})->with([
    'the 1.x signature broken, trusted' => ['public-testfiles/adobe-20220124-CIE-sig-CA.jpg', 'claimSignature.mismatch', SPEC021_SETTINGS, ValidationState::Trusted],
    'the 1.x signature broken, no settings' => ['public-testfiles/adobe-20220124-CIE-sig-CA.jpg', 'claimSignature.mismatch', null, ValidationState::Valid],
    'the 2.x signature broken, trusted' => ['c2pa-rs/CIE-sig-CA.jpg', 'claimSignature.mismatch', SPEC021_SETTINGS, ValidationState::Trusted],
    'a recorded hashed-URI mismatch, trusted' => ['c2pa-rs/CACAE-uri-CA.jpg', 'assertion.hashedURI.mismatch', SPEC021_SETTINGS, ValidationState::Trusted],
])->group('SPEC-070');

it('leaves the guard as it was: about the active manifest or the binding manifest, never acknowledged', function (): void {
    $by = 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient';
    $statuses = [
        new ValidationStatus(StatusCode::ClaimSignatureMismatch, 'self#jumbf=/c2pa/A/c2pa.signature', 'about the active', $by),
        new ValidationStatus(StatusCode::AssertionHashedUriMismatch, 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.hash.data', 'about the binding', $by),
        new ValidationStatus(StatusCode::AssertionHashedUriMismatch, 'self#jumbf=/c2pa/C/c2pa.assertions/c2pa.hash.data', 'about another', $by),
        new ValidationStatus(StatusCode::ClaimSignatureMismatch, 'self#jumbf=/c2pa/C/c2pa.signature', 'not recorded', $by),
    ];
    $recorded = [
        'claimSignature.mismatch self#jumbf=/c2pa/A/c2pa.signature',
        'assertion.hashedURI.mismatch self#jumbf=/c2pa/B/c2pa.assertions/c2pa.hash.data',
        'assertion.hashedURI.mismatch self#jumbf=/c2pa/C/c2pa.assertions/c2pa.hash.data',
    ];
    $out = (new IngredientManifestCheck)->acknowledge($statuses, $recorded, 'A', 'B');

    expect($out)->toHaveCount(4)
        ->and(array_map(static fn (ValidationStatus $s): bool => $s->acknowledged, $out))->toBe([false, false, true, false])
        ->and($out[0]->explanation)->toBe('about the active')
        ->and($out[2]->explanation)->toBe('acknowledged by the signer of A in its ingredient assertion (C2PA 2.4 §18.16.12.4), not counted in the verdict: about another')
        ->and($out[2]->ingredientUri)->toBe($by)
        ->and(ValidationResult::fromStatuses($out, ['ingredients'])->state)->toBe(ValidationState::Invalid);
})->group('SPEC-070');

it('lets an acknowledged status count for nothing in the verdict, and renders it as informational', function (): void {
    $trusted = new ValidationStatus(StatusCode::SigningCredentialTrusted, 'self#jumbf=/c2pa/A/c2pa.signature', 'trusted');
    $mismatch = static fn (bool $acknowledged): ValidationStatus => new ValidationStatus(StatusCode::ClaimSignatureMismatch, 'self#jumbf=/c2pa/B/c2pa.signature', 'broken', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient', $acknowledged);

    $marked = ValidationResult::fromStatuses([$trusted, $mismatch(true)], ['signature']);
    expect($marked->state)->toBe(ValidationState::Trusted)
        ->and(ValidationResult::fromStatuses([$trusted, $mismatch(false)], ['signature'])->state)->toBe(ValidationState::Invalid);
    $array = $marked->toArray();
    expect($array)->not->toHaveKey('validation_status')
        ->and(spec070Informational(['validation_results' => $array['validation_results']], 'claimSignature.mismatch'))->toHaveCount(1);
    // an acknowledged signingCredential.untrusted does not hold a Trusted state down either
    $untrusted = new ValidationStatus(StatusCode::SigningCredentialUntrusted, 'self#jumbf=/c2pa/B/c2pa.signature', 'untrusted', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient', true);
    expect(ValidationResult::fromStatuses([$trusted, $untrusted], ['signature'])->state)->toBe(ValidationState::Trusted);
})->group('SPEC-070');
