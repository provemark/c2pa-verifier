<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-052: an ingredient reference hashes with one of the three algorithms. The variants in
 * tests/Fixtures/spec052/ (bin/make-spec052-variants.php) carry the hash their algorithm really gives
 * and a valid signature over every edit, so a refusal can only come from the algorithm's name.
 */

const SPEC052_ACTIVE_INGREDIENT = 'self#jumbf=/c2pa/urn:c2pa:fff4c43d-ffb0-4f23-bd0b-f47f1cf82057:contentauth/c2pa.assertions/c2pa.ingredient.v3';

/** The redacted parent in crc32b-claim-signature.png: claimSignature()'s statuses carry its URL. */
const SPEC052_PARENT = 'self#jumbf=/c2pa/urn:c2pa:d16361e1-edff-44c5-b4aa-2b68163727d1';

function spec052Verify(string $name): VerificationReport
{
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/spec052/throw-away-root.settings.json'));
    $stream = fopen(Corpus::fixtures().'/spec052/'.$name, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }
    try {
        return (new Verifier)->verify($stream, $settings);
    } finally {
        fclose($stream);
    }
}

/** @return list<ValidationStatus> */
function spec052Coded(VerificationReport $report, StatusCode $code): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === $code));
}

/** @return array<string, mixed> */
function spec052Oracle(string $name): array
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/spec052/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($json));

    /** @var array<string, mixed> */
    return $json;
}

/** @return list<string> the codes this check produces, from every ingredient delta of c2patool's answer */
function spec052OracleCodes(string $name): array
{
    $codes = [];
    $results = spec052Oracle($name)['validation_results'] ?? [];
    assert(is_array($results));
    foreach ((array) ($results['ingredientDeltas'] ?? []) as $delta) {
        assert(is_array($delta) && is_array($delta['validationDeltas']));
        foreach (['success', 'failure'] as $kind) {
            foreach ((array) ($delta['validationDeltas'][$kind] ?? []) as $status) {
                assert(is_array($status) && is_string($status['code']));
                if (str_starts_with($status['code'], 'ingredient.manifest.') || str_starts_with($status['code'], 'ingredient.claimSignature.')) {
                    $codes[] = $status['code'];
                }
            }
        }
    }
    sort($codes);

    return $codes;
}

/** @return list<string> */
function spec052OwnCodes(VerificationReport $report): array
{
    $codes = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri !== null && (str_starts_with($status->code->value, 'ingredient.manifest.') || str_starts_with($status->code->value, 'ingredient.claimSignature.'))) {
            $codes[] = $status->code->value;
        }
    }
    sort($codes);

    return $codes;
}

it('AC1: a crc32b reference is a mismatch even when it matches', function (): void {
    $report = spec052Verify('crc32b-reference.jpg');
    $mismatch = array_values(array_filter(
        spec052Coded($report, StatusCode::IngredientManifestMismatch),
        static fn (ValidationStatus $s): bool => $s->ingredientUri === SPEC052_ACTIVE_INGREDIENT,
    ));

    expect($mismatch)->toHaveCount(1)
        ->and($mismatch[0]->explanation)->toContain('crc32b')
        ->and($mismatch[0]->explanation)->toContain('§13.1')
        ->and(spec052Coded($report, StatusCode::IngredientManifestValidated))->toBe([])
        ->and($report->result->state)->toBe(ValidationState::Invalid);
    // the refusal is not a broken signature: the active claim's is valid
    $active = array_values(array_filter(
        spec052Coded($report, StatusCode::ClaimSignatureValidated),
        static fn (ValidationStatus $s): bool => $s->ingredientUri === null,
    ));
    expect($active)->toHaveCount(1);
})->group('SPEC-052');

it('AC2: the claim-signature route is a mismatch too', function (): void {
    $report = spec052Verify('crc32b-claim-signature.png');
    $mismatch = array_values(array_filter(
        spec052Coded($report, StatusCode::IngredientClaimSignatureMismatch),
        static fn (ValidationStatus $s): bool => $s->url === SPEC052_PARENT && $s->ingredientUri !== null,
    ));

    expect($mismatch)->toHaveCount(1)
        ->and($mismatch[0]->explanation)->toContain('crc32b')
        ->and($mismatch[0]->explanation)->toContain('§13.1')
        ->and(spec052Coded($report, StatusCode::IngredientClaimSignatureValidated))->toBe([])
        ->and(spec052Coded($report, StatusCode::IngredientManifestValidated))->toBe([])
        ->and($report->result->state)->toBe(ValidationState::Invalid);
})->group('SPEC-052');

it('AC3: a sha384 or sha512 reference is a mismatch, as at c2patool', function (string $name): void {
    $report = spec052Verify($name);

    expect(spec052Coded($report, StatusCode::IngredientManifestMismatch))->toHaveCount(1)
        ->and(spec052Coded($report, StatusCode::IngredientManifestValidated))->toBe([])
        ->and(spec052Coded($report, StatusCode::AlgorithmUnsupported))->toBe([]);
})->with(['sha384-reference.jpg', 'sha512-reference.jpg'])->group('SPEC-052');

it('AC5: the state and this check\'s ingredient codes are c2patool 0.27.22\'s', function (string $name, string $file): void {
    $oracle = spec052Oracle($name);
    $report = spec052Verify($file);

    expect($oracle['validation_state'] ?? null)->toBe('Invalid')
        ->and($report->result->state->value)->toBe($oracle['validation_state'])
        ->and(spec052OwnCodes($report))->toBe(spec052OracleCodes($name));
})->with([
    ['crc32b-reference', 'crc32b-reference.jpg'],
    ['sha384-reference', 'sha384-reference.jpg'],
    ['sha512-reference', 'sha512-reference.jpg'],
    ['crc32b-claim-signature', 'crc32b-claim-signature.png'],
])->group('SPEC-052');
