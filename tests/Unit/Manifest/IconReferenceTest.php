<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\PngManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\IconReferenceCheck;
use Provemark\C2paVerifier\Manifest\ManifestStore;
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
    // amendment 2: the builder leaves a softwareAgents icon a resource reference; not a reference, not checked
    spec034Clean('agents-icon');

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
