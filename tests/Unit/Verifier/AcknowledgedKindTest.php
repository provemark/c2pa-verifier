<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Cbor\CborBytes;
use Provemark\C2paVerifier\Manifest\HashedUri;
use Provemark\C2paVerifier\Manifest\IngredientAssertion;
use Provemark\C2paVerifier\Manifest\Relationship;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\IngredientManifestCheck;

/*
 * SPEC-070 amendment 2: in a v3 ingredient assertion, the list a status was
 * recorded in counts (C2PA 2.4 §18.16.12.4.3: type, code and url). A failure
 * filed under success or informational is not an acknowledgement, so it stays a
 * failure. A v1/v2 validationStatus has no lists; its kind is the code's own.
 * The files are bin/make-acknowledged-kind-variants.php's: the same broken
 * ingredient signature, recorded in a different list each time.
 */

const SPEC070_KIND_SETTINGS = 'acknowledged-kind/throw-away-root.settings.json';

/**
 * The codes under the ingredient deltas' list $kind.
 *
 * @param  array<string, mixed>  $array  VerificationReport::toArray()
 * @return list<string>
 */
function spec070DeltaCodes(array $array, string $kind): array
{
    $codes = [];
    $results = $array['validation_results'] ?? null;
    assert(is_array($results));
    foreach ((array) ($results['ingredientDeltas'] ?? []) as $delta) {
        assert(is_array($delta) && is_array($delta['validationDeltas']));
        foreach ((array) ($delta['validationDeltas'][$kind] ?? []) as $status) {
            assert(is_array($status) && is_string($status['code']));
            $codes[] = $status['code'];
        }
    }

    return $codes;
}

/**
 * A v3 ingredient assertion of manifest A about manifest B, with $results as its validationResults.
 *
 * @param  array<string, mixed>  $results
 */
function spec070V3(array $results): IngredientAssertion
{
    return new IngredientAssertion(
        'c2pa.ingredient.v3', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient.v3', 3,
        Relationship::ParentOf, 'x', 'image/jpeg', null, 'xmp:iid:1',
        new HashedUri('self#jumbf=/c2pa/B', new CborBytes(str_repeat("\x00", 32)), null),
        null, null, null, $results, null, [],
    );
}

// AC5
it('AC5 (amendment 2): a failure recorded under success or informational is not acknowledged', function (string $list, ?string $settings, ValidationState $without): void {
    $report = spec020Verify("acknowledged-kind/recorded-{$list}.jpg", $settings);
    $array = $report->toArray();

    expect($report->result->state)->toBe(ValidationState::Invalid)
        ->and(spec070DeltaCodes($array, 'failure'))->toBe(['claimSignature.mismatch'])
        ->and(spec070DeltaCodes($array, 'informational'))->not->toContain('claimSignature.mismatch')
        ->and(array_column((array) ($array['validation_status'] ?? []), 'code'))->toContain('claimSignature.mismatch');
    // the control: the same file with the record under failure reads what it read before
    expect(spec020Verify('acknowledged-kind/recorded-failure.jpg', $settings)->result->state)->toBe($without);
    // the difference by design (docs/comparison.md): c2patool 0.28.2 drops the mismatch wherever it was filed
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/acknowledged-kind/recorded-{$list}--0.28.2.json"), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($oracle));
    expect($oracle['validation_state'])->toBe('Trusted');
})->with([
    'under success, trusted' => ['success', SPEC070_KIND_SETTINGS, ValidationState::Trusted],
    'under success, no settings' => ['success', null, ValidationState::Valid],
    'under informational, trusted' => ['informational', SPEC070_KIND_SETTINGS, ValidationState::Trusted],
    'under informational, no settings' => ['informational', null, ValidationState::Valid],
])->group('SPEC-070');

// AC6
it('AC6 (amendment 2): the same failure recorded under failure is still acknowledged', function (): void {
    $report = spec020Verify('acknowledged-kind/recorded-failure.jpg', SPEC070_KIND_SETTINGS);
    $array = $report->toArray();

    expect($report->result->state)->toBe(ValidationState::Trusted)
        ->and(spec070DeltaCodes($array, 'failure'))->toBe([])
        ->and(spec070Informational($array, 'claimSignature.mismatch'))->toHaveCount(1)
        ->and(spec070Informational($array, 'claimSignature.mismatch')[0]['explanation'])->toStartWith('acknowledged by the signer of ');
})->group('SPEC-070');

// the seam: what recorded() keeps
it('amendment 2: a v3 record counts only where its list is the code\'s own kind; a v2 record keeps every entry', function (): void {
    $sig = 'self#jumbf=/c2pa/B/c2pa.signature';
    $entry = static fn (string $code): array => ['code' => $code, 'url' => $sig, 'explanation' => 'x'];
    $v3 = spec070V3(['activeManifest' => [
        'success' => [$entry('claimSignature.validated'), $entry('claimSignature.mismatch')],
        'informational' => [$entry('timeStamp.untrusted'), $entry('signingCredential.untrusted')],
        'failure' => [$entry('signingCredential.expired'), $entry('timeStamp.validated')],
    ]]);

    expect(IngredientManifestCheck::recorded($v3))->toBe([
        "claimSignature.validated {$sig}",
        "timeStamp.untrusted {$sig}",
        "signingCredential.expired {$sig}",
    ]);

    // a v2 validationStatus has no lists: a failure in it is recorded as before (AC7)
    $v2 = new IngredientAssertion(
        'c2pa.ingredient.v2', 'self#jumbf=/c2pa/A/c2pa.assertions/c2pa.ingredient.v2', 2,
        Relationship::ParentOf, 'x', 'image/jpeg', null, 'xmp:iid:1',
        new HashedUri('self#jumbf=/c2pa/B', new CborBytes(str_repeat("\x00", 32)), null),
        null, null, [$entry('claimSignature.mismatch'), $entry('claimSignature.validated')], null, null, [],
    );
    expect(IngredientManifestCheck::recorded($v2))->toBe(["claimSignature.mismatch {$sig}", "claimSignature.validated {$sig}"]);
})->group('SPEC-070');

it('amendment 2: the same holds for the deltas a v3 record carries for its own ingredients', function (): void {
    $sig = 'self#jumbf=/c2pa/C/c2pa.signature';
    $entry = static fn (string $code): array => ['code' => $code, 'url' => $sig, 'explanation' => 'x'];
    $v3 = spec070V3(['activeManifest' => ['success' => [], 'informational' => [], 'failure' => []], 'ingredientDeltas' => [[
        'ingredientAssertionURI' => 'self#jumbf=/c2pa/B/c2pa.assertions/c2pa.ingredient.v3',
        'validationDeltas' => [
            'success' => [$entry('claimSignature.mismatch')],
            'informational' => [$entry('assertion.hashedURI.mismatch')],
            'failure' => [$entry('signingCredential.expired')],
        ],
    ]]]);

    expect(IngredientManifestCheck::recorded($v3))->toBe(["signingCredential.expired {$sig}"]);
})->group('SPEC-070');

it('amendment 2: a code this verifier does not know is kept, whatever list it is in', function (): void {
    $sig = 'self#jumbf=/c2pa/B/c2pa.signature';
    $v3 = spec070V3(['activeManifest' => [
        'success' => [['code' => 'com.example.custom', 'url' => $sig, 'explanation' => 'x']],
        'failure' => [['code' => 'com.example.other', 'url' => $sig, 'explanation' => 'x']],
    ]]);

    expect(IngredientManifestCheck::recorded($v3))->toBe(["com.example.custom {$sig}", "com.example.other {$sig}"]);
})->group('SPEC-070');
