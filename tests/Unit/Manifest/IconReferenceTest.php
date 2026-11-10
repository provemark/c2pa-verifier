<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\IconReferenceCheck;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-034: icon references. Probes from bin/make-spec034-variants.php under
 * tests/Fixtures/icons/ (PNG; the failing ones patched at the same length and
 * re-signed), verified with their throwaway root; both c2patool versions'
 * answers under tests/Fixtures/c2patool/icons/.
 */

function spec034Verify(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/icons/{$probe}.png", 'rb');
    assert($stream !== false);

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/icons/probe-root.settings.json')));
}

/** @return array<string, mixed> */
function spec034Oracle(string $probe, string $version = '0.28.0'): array
{
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/icons/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($oracle));

    /** @var array<string, mixed> $oracle */
    return $oracle;
}

/**
 * The oracle's failures whose url is not the manifest itself ("Failed to load manifest", amendment 2).
 *
 * @param  array<string, mixed>  $oracle
 * @return list<string> "code url"
 */
function spec034OracleIconFaults(array $oracle): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']) && is_string($oracle['active_manifest']));
    $faults = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        if ($entry['url'] !== 'self#jumbf=/c2pa/'.$oracle['active_manifest'] && ! str_starts_with($entry['code'], 'signingCredential.')) {
            $faults[] = $entry['code'].' '.$entry['url'];
        }
    }
    sort($faults);

    return $faults;
}

/** @return list<string>  "code url", failures only, the active manifest's */
function spec034Faults(VerificationReport $report): array
{
    $faults = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && $status->code->isFailure() && ! str_starts_with($status->code->value, 'signingCredential.')) {
            $faults[] = $status->code->value.' '.$status->url;
        }
    }
    sort($faults);

    return $faults;
}

function spec034AsOracle(string $probe): void
{
    $report = spec034Verify($probe);
    $theirs = spec034OracleIconFaults(spec034Oracle($probe));
    expect($theirs)->not->toBe([], "{$probe}: the oracle must have faulted it")
        ->and(spec034Faults($report))->toBe($theirs, $probe)
        ->and($report->result->state->value)->toBe('Invalid', $probe)
        ->and(spec034Oracle($probe, '0.27.22')['validation_state'])->toBe('Invalid', $probe);
}

function spec034Clean(string $probe): void
{
    $report = spec034Verify($probe);
    expect(spec034Faults($report))->toBe([], $probe)
        ->and($report->result->state->value)->toBe('Trusted', $probe)
        ->and(spec034Oracle($probe)['validation_state'])->toBe('Trusted', $probe);
}

it('AC1: an icon that matches passes', function (): void {
    spec034Clean('generator-icon');
    expect(spec034Verify('generator-icon')->result->checksPerformed)->toContain('icons');

    // the one real file with an icon: OpenAI's claim_generator_info → c2pa.icon
    $stream = fopen(Corpus::fixtures().'/writers/openai-20260826-c2pa_2x.png', 'rb');
    assert($stream !== false);
    $openai = (new Verifier)->verify($stream);
    $icon = array_filter($openai->result->statuses, static fn (ValidationStatus $s): bool => str_contains($s->url, 'c2pa.icon') && $s->code->isFailure());
    expect($icon)->toBe([])
        ->and($openai->result->checksPerformed)->toContain('icons');
})->group('SPEC-034');

it('AC2: a claim_generator_info icon whose hash differs', function (): void {
    spec034AsOracle('generator-icon-hash-changed');
    expect(spec034Faults(spec034Verify('generator-icon-hash-changed')))->toBe(['assertion.hashedURI.mismatch self#jumbf=c2pa.assertions/c2pa.icon']);
})->group('SPEC-034');

it('AC3: an icon that resolves to nothing', function (): void {
    spec034AsOracle('generator-icon-unresolved');
    expect(spec034Faults(spec034Verify('generator-icon-unresolved')))->toBe(['assertion.missing self#jumbf=c2pa.assertions/c2pa.icoX']);
})->group('SPEC-034');

it('AC4: icons in the actions assertion', function (): void {
    spec034AsOracle('templates-icon-hash-changed');
    spec034AsOracle('action-agent-icon-hash-changed');
    spec034Clean('templates-icon');
    spec034Clean('action-agent-icon');
    // amendment 3: a softwareAgents icon left a resource reference ({format, identifier}) is hashedURI.missing on the
    // actions assertion, as c2patool 0.28.6 says (measured in step 521; 0.28.0 and 0.27.22 said Trusted)
    $agents = spec034Verify('agents-icon');
    expect(spec034Faults($agents))->toBe(['hashedURI.missing self#jumbf=/c2pa/urn:c2pa:5a56b2b6-69df-4e3f-9b36-b22ddce9d385/c2pa.assertions/c2pa.actions.v2'])
        ->and($agents->result->state->value)->toBe('Invalid')
        ->and(spec034Oracle('agents-icon')['validation_state'])->toBe('Trusted');

    // v1 claims: no actions icons are read (SPEC-034 open question 4, through the seam)
    $stream = fopen(Corpus::fixtures().'/icons/templates-icon-hash-changed.png', 'rb');
    assert($stream !== false);
    $store = (new PngManifestStoreExtractor)->extract($stream);
    assert($store !== null);
    $data = ManifestStore::fromTree((new JumbfParser)->parse($store->bytes))->active->assertions['c2pa.actions.v2']->data;
    expect(IconReferenceCheck::actionsIcons($data, 1))->toBe([])
        ->and(IconReferenceCheck::actionsIcons($data, 2))->toHaveCount(1);
})->group('SPEC-034');

it('AC5: an external icon resolves to nothing, and is not fetched', function (): void {
    spec034AsOracle('generator-icon-external');
    expect(spec034Faults(spec034Verify('generator-icon-external')))->toBe(['assertion.missing https://example.com/icon.png']);
    // no network, read in the check's own source
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Manifest/IconReferenceCheck.php');
    foreach (['curl_', 'fsockopen', 'stream_socket', 'file_get_contents', 'fopen', 'get_headers'] as $call) {
        expect(str_contains($source, $call))->toBeFalse($call);
    }
})->group('SPEC-034');

it('AC6: nothing else moves', function (): void {
    // the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 126b;
    // here, the only real file with an icon keeps its verdict
    $stream = fopen(Corpus::fixtures().'/writers/openai-20260826-c2pa_2x.png', 'rb');
    assert($stream !== false);
    expect((new Verifier)->verify($stream)->result->state->value)->toBe('Valid');
})->group('SPEC-034');

// ---- amendments 3 and 4 (step 522): an icon that is not a hashed URI ----
// Probes from bin/make-icon-form-variants.php under tests/Fixtures/icon-forms/, answers of c2patool 0.28.6 and
// 0.28.3 under tests/Fixtures/c2patool/icon-forms/.

function spec034FormVerify(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/icon-forms/{$probe}.png", 'rb');
    assert($stream !== false);

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/icon-forms/probe-root.settings.json')));
}

/** @return array<string, mixed> */
function spec034FormOracle(string $probe, string $version): array
{
    $oracle = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/icon-forms/{$probe}--{$version}.json"), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($oracle));

    /** @var array<string, mixed> $oracle */
    return $oracle;
}

it('AC7: an icon in resource-reference form is hashedURI.missing where it is held, as c2patool 0.28.6', function (string $probe, string $holder): void {
    $report = spec034FormVerify($probe);
    $theirs = spec034OracleIconFaults(spec034FormOracle($probe, '0.28.6'));
    $active = spec034FormOracle($probe, '0.28.6')['active_manifest'];
    assert(is_string($active));

    expect($theirs)->toBe(["hashedURI.missing self#jumbf=/c2pa/{$active}/{$holder}"])
        ->and(spec034Faults($report))->toBe($theirs)
        ->and($report->result->state->value)->toBe('Invalid')
        ->and(spec034FormOracle($probe, '0.28.3')['validation_state'])->toBe('Trusted');
    $status = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'hashedURI.missing'))[0];
    expect($status->explanation)->toContain('resource reference')
        ->and($status->explanation)->toContain('§10.2.3.2');
})->with([
    'claim_generator_info' => ['generator-icon-resource', 'c2pa.claim.v2'],
    'templates' => ['templates-icon-resource', 'c2pa.assertions/c2pa.actions.v2'],
    "an action's softwareAgent" => ['action-agent-icon-resource', 'c2pa.assertions/c2pa.actions.v2'],
])->group('SPEC-034');

it('AC7: an icon that is a text is hashedURI.missing on the claim; c2patool does not read the claim (amendment 4)', function (): void {
    $report = spec034FormVerify('generator-icon-text');
    $label = spec034FormOracle('generator-icon', '0.28.6')['active_manifest'];
    $control = spec034FormVerify('generator-icon');
    assert(is_string($label));

    // the text probe is the control with its icon replaced, so its manifest label is the control's
    expect(spec034Faults($report))->toBe(["hashedURI.missing self#jumbf=/c2pa/{$label}/c2pa.claim.v2"])
        ->and($report->result->state->value)->toBe('Invalid')
        ->and($control->result->state->value)->toBe('Trusted');
    foreach (['0.28.6', '0.28.3'] as $version) {
        expect((string) file_get_contents(Corpus::fixtures()."/c2patool/icon-forms/generator-icon-text--{$version}.error.txt"))
            ->toContain('claim_generator_info is missing or invalid');
    }
    $status = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'hashedURI.missing'))[0];
    expect($status->explanation)->toContain('a text');
})->group('SPEC-034');

it('AC7: a map with a url and a hash is a hashed URI whatever else it holds, and the controls pass (amendment 4)', function (string $probe): void {
    $report = spec034FormVerify($probe);

    expect(spec034Faults($report))->toBe([])
        ->and($report->result->state->value)->toBe('Trusted')
        ->and(spec034FormOracle($probe, '0.28.6')['validation_state'])->toBe('Trusted')
        ->and(spec034FormOracle($probe, '0.28.3')['validation_state'])->toBe('Trusted');
})->with(['generator-icon-both', 'generator-icon', 'templates-icon', 'action-agent-icon'])->group('SPEC-034');

it('AC7: hashedURI.missing is a failure code', function (): void {
    $code = StatusCode::from('hashedURI.missing');

    expect($code->name)->toBe('HashedUriMissing')
        ->and($code->isFailure())->toBeTrue();
})->group('SPEC-034');
