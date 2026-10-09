<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Manifest\CloudDataCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-063: the cloud-data assertion's structure (C2PA 2.4 §15.10.3.2.1). The probes come from
 * bin/make-manifest-probe-variants.php under tests/Fixtures/manifest-probes/, with both c2patool versions' answers.
 */

function spec063Probe(string $name): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/manifest-probes/{$name}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/manifest-probes/throw-away-root.settings.json')));
}

/** @return array{state: string, failures: list<string>} */
function spec063Oracle(string $name): array
{
    /** @var array{validation_state: string, validation_results?: array{activeManifest?: array{failure?: list<array{code: string}>}}} $json */
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/manifest-probes/{$name}--0.28.1.json"), true, 512, JSON_THROW_ON_ERROR);
    $failures = array_values(array_unique(array_column($json['validation_results']['activeManifest']['failure'] ?? [], 'code')));
    sort($failures);

    return ['state' => $json['validation_state'], 'failures' => $failures];
}

/** @return list<string> the cloud-data codes the report holds, sorted */
function spec063Codes(VerificationReport $report, bool $ingredients = false): array
{
    $codes = array_values(array_unique(array_map(
        static fn (ValidationStatus $s): string => $s->code->value,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'assertion.cloud-data.') && ($s->ingredientUri !== null) === $ingredients),
    )));
    sort($codes);

    return $codes;
}

/**
 * A cloud-data map as c2patool writes it, with $changes applied (null removes a key).
 *
 * @param  array<string, mixed>  $changes
 * @param  array<string, mixed>  $location
 * @return array<string, mixed>
 */
function spec063Map(array $changes = [], array $location = []): array
{
    $loc = array_filter(array_merge(['url' => 'https://cloud.example.invalid/remote.jumbf', 'alg' => 'sha256', 'hash' => 'qgjD0gMIxHfi'], $location), static fn ($v): bool => $v !== null);

    return array_filter(array_merge(['label' => 'c2pa.metadata', 'size' => 5, 'location' => $loc], $changes), static fn ($v): bool => $v !== null);
}

/** @return list<string> */
function spec063Faults(mixed $data, bool $update = false): array
{
    return array_map(static fn (array $f): string => $f[0]->value.': '.$f[1], CloudDataCheck::faults($data, $update));
}

it('AC1: a hard binding stored as cloud data is refused with hardBinding, as in c2patool 0.28.1', function (): void {
    $report = spec063Probe('cloud-hash-data');

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec063Codes($report))->toBe(['assertion.cloud-data.hardBinding'])
        ->and(spec063Oracle('cloud-hash-data'))->toBe(['state' => 'Invalid', 'failures' => ['assertion.cloud-data.hardBinding']])
        ->and(implode(' ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses)))->toContain('c2pa.hash.data');
})->group('SPEC-063');

it('AC2, AC3, AC4: size 0, an actions label and a missing location are malformed, as in c2patool 0.28.1', function (): void {
    foreach (['cloud-size-zero' => 'size', 'cloud-actions' => 'c2pa.actions.v2', 'cloud-no-location' => 'location'] as $name => $names) {
        $report = spec063Probe($name);
        $explanations = implode(' ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $report->result->statuses));

        expect($report->result->state->value)->toBe('Invalid', $name)
            ->and(spec063Codes($report))->toBe(['assertion.cloud-data.malformed'], $name)
            ->and(spec063Oracle($name))->toBe(['state' => 'Invalid', 'failures' => ['assertion.cloud-data.malformed']], $name)
            ->and($explanations)->toContain($names);
    }
})->group('SPEC-063');

it('AC4: each missing or mistyped field is malformed, naming the field', function (): void {
    $cases = [
        'not a map' => [['x'], 'not a map'],
        'no label' => [spec063Map(['label' => null]), 'label'],
        'empty label' => [spec063Map(['label' => ' ']), 'label'],
        'size as text' => [spec063Map(['size' => '5']), 'size'],
        'negative size' => [spec063Map(['size' => -1]), 'size'],
        'no size' => [spec063Map(['size' => null]), 'size'],
        'location a list' => [spec063Map(['location' => ['x']]), 'location'],
        'no url' => [spec063Map([], ['url' => null]), 'location.url'],
        'empty url' => [spec063Map([], ['url' => '']), 'location.url'],
        'no alg' => [spec063Map([], ['alg' => null]), 'location.alg'],
        'no hash' => [spec063Map([], ['hash' => null]), 'location.hash'],
        'hash an integer' => [spec063Map([], ['hash' => 7]), 'location.hash'],
    ];
    foreach ($cases as $case => [$data, $names]) {
        $faults = spec063Faults($data);

        expect($faults)->toHaveCount(1, $case)
            ->and($faults[0])->toStartWith('assertion.cloud-data.malformed: ', $case)
            ->and($faults[0])->toContain($names);
    }
    expect(spec063Faults(spec063Map()))->toBe([]);
})->group('SPEC-063');

it('AC5: an actions label in an update manifest is cloud-data.actions and malformed; outside one, malformed only', function (): void {
    $codes = static fn (bool $update): array => array_map(static fn (array $f): string => $f[0]->value, CloudDataCheck::faults(spec063Map(['label' => 'c2pa.actions.v2']), $update));

    expect($codes(true))->toBe(['assertion.cloud-data.actions', 'assertion.cloud-data.malformed'])
        ->and($codes(false))->toBe(['assertion.cloud-data.malformed'])
        ->and(array_map(static fn (array $f): string => $f[0]->value, CloudDataCheck::faults(spec063Map(['label' => 'c2pa.hash.bmff.v3']), true)))->toBe(['assertion.cloud-data.hardBinding']);
})->group('SPEC-063');

it('AC6: a well-formed cloud-data assertion passes, its hash as text or bytes, and the report names the check', function (): void {
    foreach (['cloud-ok', 'cloud-hash-bytes'] as $name) {
        $report = spec063Probe($name);

        expect($report->result->state->value)->toBe('Trusted', $name)
            ->and(spec063Codes($report))->toBe([], $name)
            ->and(spec063Oracle($name)['state'])->toBe('Trusted', $name)
            ->and($report->result->checksPerformed)->toContain('cloudData');
    }
    expect(spec063Probe('control')->result->checksPerformed)->not->toContain('cloudData');
    // no network, read in the check's own source: the url is data, never a destination
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Manifest/CloudDataCheck.php');
    foreach (['curl_', 'fsockopen', 'stream_socket', 'file_get_contents', 'fopen', 'get_headers'] as $call) {
        expect(str_contains($source, $call))->toBeFalse($call);
    }
})->group('SPEC-063');

it('AC7: in an ingredient manifest too: an unrecorded failure is a delta, a recorded one is not, as in c2patool 0.28.1', function (): void {
    $unrecorded = spec063Probe('cloud-in-ingredient-unrecorded');
    expect(spec063Codes($unrecorded, true))->toBe(['assertion.cloud-data.hardBinding'])
        ->and(spec063Codes($unrecorded))->toBe([])
        ->and($unrecorded->result->state->value)->toBe('Invalid')
        ->and(spec063Oracle('cloud-in-ingredient-unrecorded')['state'])->toBe('Invalid');

    // signed by 0.28.1, the ingredient assertion records the failure itself: no delta (SPEC-021)
    $recorded = spec063Probe('cloud-in-ingredient');
    expect(spec063Codes($recorded, true))->toBe([])
        ->and($recorded->result->state->value)->toBe('Trusted')
        ->and(spec063Oracle('cloud-in-ingredient')['state'])->toBe('Trusted');
})->group('SPEC-063');

it('AC8: three codes, verbatim, all failures', function (): void {
    foreach (['assertion.cloud-data.malformed', 'assertion.cloud-data.hardBinding', 'assertion.cloud-data.actions'] as $code) {
        expect(StatusCode::from($code)->isFailure())->toBeTrue($code);
    }
})->group('SPEC-063');

it('AC9: assertions §15.10.3.2 lists no validation for are not judged by shape; the differences with c2patool are named (amendment 2)', function (): void {
    $theirs = static function (string $name, string $version): ?string {
        $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/manifest-probes/{$name}--{$version}.json"), true);

        return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
    };
    // probe => 0.28.1's answer (null: it cannot decode the assertion); here every one is Trusted
    $named = [
        'unlisted-control' => 'Trusted',
        'unlisted-metadata-no-context' => null,                 // §15.10.3.2.4: no assertion-specific validation
        'unlisted-certificate-status-no-ocspvals' => null,      // not listed; this verifier reads OCSP from rVals only
        'unlisted-soft-binding-no-blocks' => 'Invalid',         // not listed
        'unlisted-action-when-integer' => null,                 // §15.10.3.2.3 names no field types
    ];
    foreach ($named as $name => $state) {
        expect(spec063Probe($name)->result->state->value)->toBe('Trusted', $name)
            ->and($theirs($name, '0.28.1'))->toBe($state, $name);
    }
    // 0.27.22 still refuses a metadata assertion with @context, an old difference (step 313)
    expect($theirs('unlisted-control', '0.27.22'))->toBe('Invalid');
})->group('SPEC-063');
