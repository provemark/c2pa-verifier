<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-032: a `c2pa.created` without `digitalSourceType` (v2 claims), and the
 * external-reference checks of C2PA 2.4 §15.10.3.2.2. Probes from
 * bin/make-spec032-variants.php under tests/Fixtures/assertion-rules/, and
 * both c2patool versions' answers under tests/Fixtures/c2patool/assertion-rules/.
 */

function spec032Verify(string $probe, bool $anchored = true): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/assertion-rules/{$probe}.jpg", 'rb');
    assert($stream !== false);
    $settings = $anchored ? TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/assertion-rules/probe-root.settings.json')) : null;

    return (new Verifier)->verify($stream, $settings);
}

/** @return array<string, mixed> */
function spec032Oracle(string $probe, string $version, bool $anchored = true): array
{
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/assertion-rules/{$probe}--{$version}-".($anchored ? 'root' : 'bare').'.json'), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($oracle));

    /** @var array<string, mixed> $oracle */
    return $oracle;
}

/**
 * The oracle's failures as code => url, with the active manifest's label replaced by this report's,
 * so that a url can be compared across two signings of the same shape.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<array{code: string, url: string}>
 */
function spec032OracleFailures(array $oracle): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']) && is_string($oracle['active_manifest']));
    $failures = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        $failures[] = ['code' => $entry['code'], 'url' => str_replace($oracle['active_manifest'], '<active>', $entry['url'])];
    }

    return $failures;
}

/** @return list<ValidationStatus> */
function spec032Statuses(VerificationReport $report, StatusCode $code): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->ingredientUri === null && $s->code === $code));
}

function spec032Url(VerificationReport $report, ValidationStatus $status): string
{
    return str_replace($report->store?->active->label ?? '', '<active>', $status->url);
}

it('AC1: a c2pa.created without digitalSourceType in a v2 claim is malformed', function (): void {
    foreach ([true, false] as $anchored) {
        $report = spec032Verify('created-without-source-type', $anchored);
        $malformed = spec032Statuses($report, StatusCode::AssertionActionMalformed);
        expect($report->result->state->value)->toBe('Invalid')
            ->and($malformed)->toHaveCount(1)
            ->and(str_contains($malformed[0]->explanation, 'c2pa.created'))->toBeTrue($malformed[0]->explanation)
            ->and(str_contains($malformed[0]->explanation, 'digitalSourceType'))->toBeTrue($malformed[0]->explanation);
        foreach (['0.28.0', '0.27.22'] as $version) {
            $oracle = spec032Oracle('created-without-source-type', $version, $anchored);
            expect($oracle['validation_state'])->toBe('Invalid', $version)
                // only this rule's failures: without settings the oracle also records signingCredential.untrusted
                ->and(array_values(array_filter(spec032OracleFailures($oracle), static fn (array $f): bool => $f['code'] === 'assertion.action.malformed')))
                ->toBe([['code' => 'assertion.action.malformed', 'url' => spec032Url($report, $malformed[0])]], $version);
        }
    }
})->group('SPEC-032');

it('AC2: v1 claims keep their verdicts', function (): void {
    // step 121's scan: eighteen c2pa.created actions without digitalSourceType sit in v1 claims, which
    // c2pa-rs does not check beyond one actions assertion (strict_v1_validation off). None may be refused now.
    $v1 = [
        'public-testfiles/adobe-20220124-C.jpg', 'public-testfiles/adobe-20220124-CI.jpg', 'public-testfiles/adobe-20220124-CII.jpg',
        'public-testfiles/adobe-20220124-CICA.jpg', 'public-testfiles/adobe-20220124-CICACACA.jpg', 'public-testfiles/adobe-20220124-XCI.jpg',
        'public-testfiles/adobe-20220124-CIE-sig-CA.jpg', 'public-testfiles/adobe-20220124-CACAICAICICA.jpg',
        'public-testfiles/adobe-20220124-CAIAIIICAICIICAIICICA.jpg', 'c2pa-rs/update_manifest.jpg',
    ];
    foreach ($v1 as $relative) {
        $stream = fopen(Corpus::fixtures()."/{$relative}", 'rb');
        assert($stream !== false);
        $report = (new Verifier)->verify($stream);
        foreach ($report->result->statuses as $status) {
            expect(str_contains($status->explanation, 'digitalSourceType'))->toBeFalse("{$relative}: {$status->explanation}");
        }
    }
})->group('SPEC-032');

it('AC3: the probe\'s control stays Trusted', function (): void {
    // digitalSourceType on the c2pa.created, none on a later c2pa.edited: no oracle refuses that (step 121)
    $report = spec032Verify('created-with-source-type');
    expect($report->result->state->value)->toBe('Trusted')
        ->and(spec032Statuses($report, StatusCode::AssertionActionMalformed))->toBe([])
        ->and(spec032Oracle('created-with-source-type', '0.28.0')['validation_state'])->toBe('Trusted')
        ->and(spec032Oracle('created-with-source-type', '0.27.22')['validation_state'])->toBe('Trusted');
})->group('SPEC-032');

it('AC4: a forbidden external-reference label is malformed', function (): void {
    $report = spec032Verify('reference-forbidden-label');
    $malformed = spec032Statuses($report, StatusCode::from('assertion.external-reference.malformed'));
    expect($report->result->state->value)->toBe('Invalid')
        ->and($malformed)->toHaveCount(1)
        ->and(str_contains($malformed[0]->explanation, 'c2pa.actions.v2'))->toBeTrue($malformed[0]->explanation)
        ->and(spec032OracleFailures(spec032Oracle('reference-forbidden-label', '0.28.0')))->toBe([['code' => 'assertion.external-reference.malformed', 'url' => spec032Url($report, $malformed[0])]])
        // 0.27.22 did not check it: 0.28.0's rule, named in docs/comparison.md
        ->and(spec032Oracle('reference-forbidden-label', '0.27.22')['validation_state'])->toBe('Trusted');
})->group('SPEC-032');

it('AC5: the location must hold a url, and a hash its algorithm', function (): void {
    // probe => [a word the explanation names, what c2patool 0.28.0 said]
    $cases = [
        'reference-no-location' => ['location', 'Invalid'],
        'reference-no-url' => ['url', 'Invalid'],
        'reference-empty-url' => ['url', 'Invalid'],
        'reference-not-a-map' => ['map', 'Invalid'],
        // §15.10.3.2.2: "one of alg or hash but not both" is malformed. c2pa-rs reads such a location as
        // unhashed and ignores the lone field, so here this verifier is stricter than both oracles, by the text.
        'reference-alg-without-hash' => ['hash', 'Trusted'],
        'reference-hash-without-alg' => ['alg', 'Trusted'],
    ];
    foreach ($cases as $probe => [$word, $oracleState]) {
        $report = spec032Verify($probe);
        $malformed = spec032Statuses($report, StatusCode::from('assertion.external-reference.malformed'));
        expect($report->result->state->value)->toBe('Invalid', $probe)
            ->and($malformed)->toHaveCount(1, $probe)
            ->and(str_contains($malformed[0]->explanation, $word))->toBeTrue("{$probe}: {$malformed[0]->explanation}")
            ->and(spec032Oracle($probe, '0.28.0')['validation_state'])->toBe($oracleState, $probe);
    }
})->group('SPEC-032');

it('AC6: a well-formed external reference passes, and nothing is fetched', function (): void {
    foreach (['reference-unhashed', 'reference-hashed'] as $probe) {
        $report = spec032Verify($probe);
        $any = array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.external-reference'));
        expect($report->result->state->value)->toBe('Trusted', $probe)
            ->and($any)->toBe([], $probe)
            ->and($report->result->checksPerformed)->toContain('externalReferences')
            ->and(spec032Oracle($probe, '0.28.0')['validation_state'])->toBe('Trusted', $probe);
    }
    // open question 4: the check is named only where there is something to check
    expect(spec032Verify('created-with-source-type')->result->checksPerformed)->not->toContain('externalReferences');

    // no network, read in the check's own source: the url is data, never a destination
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Manifest/ExternalReferenceCheck.php');
    foreach (['curl_', 'fsockopen', 'stream_socket', 'file_get_contents', 'fopen', 'get_headers'] as $call) {
        expect(str_contains($source, $call))->toBeFalse($call);
    }
})->group('SPEC-032');

it('AC7: the vocabulary grows by one code, verbatim', function (): void {
    $found = array_values(array_filter(StatusCode::cases(), static fn (StatusCode $c): bool => $c->value === 'assertion.external-reference.malformed'));
    expect($found)->toHaveCount(1)
        ->and(array_map(static fn (StatusCode $c): string => $c->name, $found))->toBe(['AssertionExternalReferenceMalformed'])
        ->and(array_map(static fn (StatusCode $c): bool => $c->isFailure(), $found))->toBe([true]);
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(in_array('Report\StatusCode :: const AssertionExternalReferenceMalformed', $surface, true))->toBeTrue()
        ->and($surface)->toHaveCount(112);
})->group('SPEC-032');
