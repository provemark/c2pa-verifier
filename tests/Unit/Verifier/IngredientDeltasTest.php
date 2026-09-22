<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationResult;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;

/*
 * SPEC-020 AC3, AC6, AC8, AC9: the report's second half — ingredientDeltas
 * as c2patool prints them on the sixteen single-manifest corpus files with
 * ingredients, the missing manifest of E-clm-CAICAI, the `ingredients`
 * rendering on every corpus file with ingredients, and the shape rules of
 * ValidationResult. AC10 (the corpus verdicts unchanged) is SPEC-013's
 * drift alarms and SPEC-019 AC11, which keep running.
 */

const SPEC020_SINGLE = [
    'public-testfiles/adobe-20220124-CA', 'public-testfiles/adobe-20220124-CAI', 'public-testfiles/adobe-20220124-CI', 'public-testfiles/adobe-20220124-CII',
    'public-testfiles/adobe-20220124-E-dat-CA', 'public-testfiles/adobe-20220124-E-sig-CA', 'public-testfiles/adobe-20220124-E-uri-CA',
    'public-testfiles/adobe-20220124-XCA', 'public-testfiles/adobe-20220124-XCI',
    'c2pa-rs/CA', 'c2pa-rs/CA_ct', 'c2pa-rs/E-sig-CA', 'c2pa-rs/XCA', 'c2pa-rs/boxhash', 'c2pa-rs/cloud',
    'writers/adobe-20260304-photoshop-remote-manifest', 'writers/adobe-20260425-lightroom-classic-church',
];

/** @return array{0: string, 1: array<string, mixed>} the fixture path and the oracle JSON for a corpus name */
function spec020Corpus(string $name): array
{
    [$dir, $base] = explode('/', $name, 2);
    $path = glob(Corpus::fixtures()."/{$dir}/{$base}.*")[0] ?? throw new RuntimeException("no file for {$name}");
    $files = array_values(array_filter(glob(Corpus::fixtures()."/{$dir}/{$base}.*") ?: [], static fn (string $f): bool => ! str_ends_with($f, '.json')));
    /** @var array<string, mixed> $oracle */
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

    return ["{$dir}/".basename($files[0] ?? $path), $oracle];
}

// AC3
it('AC3: the sixteen single-manifest files: ingredientDeltas equal c2patool\'s, informational, in order', function (): void {
    $remote = [...SPEC013_RS_REMOTE, ...array_map(static fn (string $n): string => $n, SPEC013_WRITERS_REMOTE)];
    foreach (SPEC020_SINGLE as $name) {
        [$relative, $oracle] = spec020Corpus($name);
        $report = spec020Verify($relative, 'trust/full.settings.json');
        $array = $report->toArray();
        assert(is_array($oracle['validation_results']));

        expect($array['validation_results']['ingredientDeltas'] ?? null)->toBe($oracle['validation_results']['ingredientDeltas'], $name);
        foreach ($array['validation_results']['activeManifest']['informational'] as $status) {
            expect(str_starts_with($status['code'], 'ingredient.'))->toBeFalse("{$name}: {$status['code']} under activeManifest");
        }
        foreach ($report->result->statuses as $status) {
            if ($status->code->isFailure()) {
                expect(str_starts_with($status->code->value, 'ingredient.'))->toBeFalse("{$name}: a new failure {$status->code->value}");
            }
        }
        if (! in_array(basename($name), $remote, true)) {
            expect($array['validation_state'])->toBe($oracle['validation_state'], $name);
        }
    }
})->group('SPEC-020');

// AC6
it('AC6: E-clm-CAICAI: ingredient.manifest.missing with the bare label, scoped, next to the amendment-5 refusal', function (): void {
    $report = spec020Verify('public-testfiles/adobe-20220124-E-clm-CAICAI.jpg', 'trust/full.settings.json');
    $missing = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::IngredientManifestMissing));
    $refusals = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::GeneralError));

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->url)->toBe('contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019')
        ->and($missing[0]->ingredientUri)->toBe('self#jumbf=/c2pa/contentauth:urn:uuid:a4ec0a2e-2a4a-4652-bede-762c0362b236/c2pa.assertions/c2pa.ingredient__1')
        ->and($refusals)->toHaveCount(1)
        ->and($report->result->state)->toBe(ValidationState::Invalid);
    $array = $report->toArray();
    $codes = array_column($array['validation_status'], 'code');
    expect(array_values(array_unique($codes)))->toContain('ingredient.manifest.missing')
        ->and(count(array_keys($codes, 'ingredient.manifest.missing', true)))->toBe(1);
    // c2patool: the same code and url in the delta of the same assertion
    [, $oracle] = spec020Corpus('public-testfiles/adobe-20220124-E-clm-CAICAI');
    $delta = null;
    foreach ($oracle['validation_results']['ingredientDeltas'] as $d) {
        if ($d['ingredientAssertionURI'] === $missing[0]->ingredientUri) {
            $delta = $d;
        }
    }
    expect($delta)->not->toBeNull()
        ->and($delta['validationDeltas']['failure'][0]['code'] ?? null)->toBe('ingredient.manifest.missing')
        ->and($delta['validationDeltas']['failure'][0]['url'] ?? null)->toBe($missing[0]->url);
})->group('SPEC-020');

// AC8
it('AC8: ingredients render as c2patool prints them, and leave the assertions list', function (): void {
    $keys = ['title', 'format', 'document_id', 'instance_id', 'relationship', 'active_manifest', 'label', 'manifest_data', 'thumbnail', 'metadata', 'validation_status'];
    $names = [...SPEC020_SINGLE, ...array_keys(spec020Multi())];
    $compared = 0;
    foreach ($names as $name) {
        [$relative, $oracle] = spec020Corpus($name);
        $store = Corpus::manifestStore($relative) ?? throw new RuntimeException($relative);
        $array = $store->toArray();
        foreach ($oracle['manifests'] as $label => $manifest) {
            $ours = $array['manifests'][$label] ?? null;
            expect($ours)->toBeArray("{$name}: {$label}");
            $expected = $manifest['ingredients'] ?? [];
            $rendered = $ours['ingredients'] ?? [];
            expect(count($rendered))->toBe(count($expected), "{$name}: {$label}: ingredient count");
            foreach ($expected as $n => $theirs) {
                $mine = $rendered[$n];
                foreach ($keys as $key) {
                    expect(array_key_exists($key, $mine))->toBe(array_key_exists($key, $theirs), "{$name}: {$label}[{$n}].{$key} presence");
                    if (array_key_exists($key, $theirs)) {
                        expect($mine[$key])->toBe($theirs[$key], "{$name}: {$label}[{$n}].{$key}");
                    }
                }
                expect(array_key_exists('validation_results', $mine))->toBe(array_key_exists('validation_results', $theirs), "{$name}: {$label}[{$n}].validation_results presence");
                if (array_key_exists('validation_results', $theirs)) {
                    expect(array_keys($mine['validation_results']))->toBe(array_keys($theirs['validation_results']), "{$name}: {$label}[{$n}].validation_results keys");
                }
                $compared++;
            }
            $labels = array_column($ours['assertions'], 'label');
            foreach ($labels as $assertionLabel) {
                expect(str_starts_with($assertionLabel, 'c2pa.ingredient') || str_starts_with($assertionLabel, 'c2pa.thumbnail.ingredient'))->toBeFalse("{$name}: {$label}: {$assertionLabel} still listed");
            }
            expect(count($labels))->toBe(count($manifest['assertions']), "{$name}: {$label}: assertion count");
        }
    }
    expect($compared)->toBeGreaterThan(60);
})->group('SPEC-020');

// AC9
it('AC9: scoped statuses land in ingredientDeltas, the flat list and the state follow c2pa-rs', function (): void {
    $active = 'self#jumbf=/c2pa/urn:c2pa:active';
    $a = "{$active}/c2pa.assertions/c2pa.ingredient.v3";
    $b = "{$active}/c2pa.assertions/c2pa.ingredient.v3__1";
    $validated = new ValidationStatus(StatusCode::ClaimSignatureValidated, "{$active}/c2pa.signature", 'ok');
    $inside = new ValidationStatus(StatusCode::ClaimSignatureInsideValidity, "{$active}/c2pa.signature", 'ok');
    $trusted = new ValidationStatus(StatusCode::SigningCredentialTrusted, "{$active}/c2pa.signature", 'ok');
    $untrustedActive = new ValidationStatus(StatusCode::SigningCredentialUntrusted, "{$active}/c2pa.signature", 'no');
    $unknownA = new ValidationStatus(StatusCode::IngredientUnknownProvenance, $a, 'x: ingredient does not have provenance', $a);
    $untrustedB = new ValidationStatus(StatusCode::SigningCredentialUntrusted, 'self#jumbf=/c2pa/urn:c2pa:ingredient/c2pa.signature', 'no', $b);
    $missingB = new ValidationStatus(StatusCode::IngredientManifestMissing, 'urn:c2pa:gone', 'ingredient not found', $b);

    // no scoped status: no key
    $none = ValidationResult::fromStatuses([$validated, $inside, $trusted], ['signature'])->toArray();
    expect(array_key_exists('ingredientDeltas', $none['validation_results']))->toBeFalse()
        ->and($none['validation_state'])->toBe('Trusted');

    // scoped: grouped by URI in first-seen order, kinds separated, active untouched
    $result = ValidationResult::fromStatuses([$validated, $inside, $trusted, $untrustedB, $unknownA, $missingB], ['signature']);
    $array = $result->toArray();
    expect(array_column($array['validation_results']['activeManifest']['success'], 'code'))->toBe(['claimSignature.validated', 'claimSignature.insideValidity', 'signingCredential.trusted'])
        ->and($array['validation_results']['ingredientDeltas'])->toBe([
            ['ingredientAssertionURI' => $b, 'validationDeltas' => ['success' => [], 'informational' => [], 'failure' => [$untrustedB->toArray(), $missingB->toArray()]]],
            ['ingredientAssertionURI' => $a, 'validationDeltas' => ['success' => [], 'informational' => [$unknownA->toArray()], 'failure' => []]],
        ])
        ->and($array['validation_status'])->toBe([$untrustedB->toArray(), $missingB->toArray()])
        ->and($array['validation_state'])->toBe('Invalid');

    // the state rule: a scoped untrusted alone keeps Valid, and denies Trusted
    expect(ValidationResult::fromStatuses([$validated, $inside, $trusted, $untrustedB], [])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$validated, $inside, $untrustedActive, $untrustedB], [])->state)->toBe(ValidationState::Valid)
        ->and(ValidationResult::fromStatuses([$validated, $inside, $trusted, $unknownA], [])->state)->toBe(ValidationState::Trusted)
        ->and(ValidationResult::fromStatuses([$validated, $inside, $trusted, $missingB], [])->state)->toBe(ValidationState::Invalid);
    // the flat list: active failures first, then the deltas' in order
    $mixed = ValidationResult::fromStatuses([$validated, $inside, $missingB, $untrustedActive], [])->toArray();
    expect(array_column($mixed['validation_status'], 'code'))->toBe(['signingCredential.untrusted', 'ingredient.manifest.missing']);
    // ValidationStatus::toArray() stays three keys: the scope is where it renders, not what it says
    expect(array_keys($missingB->toArray()))->toBe(['code', 'url', 'explanation']);
})->group('SPEC-020');
