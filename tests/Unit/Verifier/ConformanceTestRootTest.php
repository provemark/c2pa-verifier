<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-078: files signed with the conformance programme's public test
 * certificate (contentauth/c2pa-conformance-tool 6d10ee0), made by
 * bin/make-conformance-test-variants.php. The oracle is c2patool 0.28.2
 * under c2patool/conformance-test/, with the test root alone and with the
 * root and store.cfg; the first differs from this verifier by name.
 */

const SPEC078_DIR = 'conformance-test';
const SPEC078_TYPES = ['jpg', 'png', 'webp'];

function spec078Verify(string $name, ?TrustSettings $settings, ?DateTimeInterface $at = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.SPEC078_DIR.'/'.$name, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$name}");
    }
    $report = (new Verifier)->verify($stream, $settings, $at);
    fclose($stream);

    return $report;
}

function spec078RootList(): TrustSettings
{
    return TrustSettings::fromTrustLists((string) file_get_contents(Corpus::fixtures().'/'.SPEC078_DIR.'/test-root.pem'));
}

/** @return list<string> */
function spec078Failures(VerificationReport $report): array
{
    return array_values(array_map(static fn ($s): string => $s->code->value, array_filter($report->result->statuses, static fn ($s): bool => $s->code->isFailure() && $s->ingredientUri === null)));
}

/** @return array{state: string, failures: list<string>} c2patool's answer for a file and a settings name */
function spec078Oracle(string $name, string $settings): array
{
    $answer = spec075A(json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/conformance-test/{$name}--{$settings}.json"), true, 512, JSON_THROW_ON_ERROR));
    $failures = [];
    foreach (spec075A(spec075A(spec075A($answer['validation_results'] ?? null)['activeManifest'] ?? null)['failure'] ?? null) as $status) {
        $failures[] = spec075S(spec075A($status)['code'] ?? null);
    }

    return ['state' => spec075S($answer['validation_state'] ?? null), 'failures' => $failures];
}

// AC1
it('AC1: trusted under the test root, through the harness, schema-valid', function (string $type): void {
    $report = spec078Verify("signed.{$type}", spec078RootList(), new DateTimeImmutable('2026-10-23T12:00:00Z'));
    $codes = array_map(static fn ($s): string => $s->code->value, $report->result->statuses);

    expect($report->result->state->value)->toBe('Trusted')
        ->and($codes)->toContain('signingCredential.trusted')
        ->and(spec078Failures($report))->toBe([])
        ->and(spec075SchemaErrors($report->toCrJson()))->toBe([]);
})->with(SPEC078_TYPES)->group('SPEC-078');

// AC2
it('AC2: untrusted without a list, and under the official lists', function (string $type): void {
    $official = TrustSettings::fromTrustLists(
        (string) file_get_contents(Corpus::fixtures().'/trust/conformance-public/C2PA-TRUST-LIST.pem'),
        (string) file_get_contents(Corpus::fixtures().'/trust/conformance-public/C2PA-TSA-TRUST-LIST.pem'),
    );
    foreach ([null, $official] as $settings) {
        $report = spec078Verify("signed.{$type}", $settings);
        expect($report->result->state->value)->toBe('Valid')
            ->and(spec078Failures($report))->toBe(['signingCredential.untrusted']);
    }
})->with(SPEC078_TYPES)->group('SPEC-078');

// AC3
it('AC3: one changed byte of the image data is a data-hash mismatch, here and in c2patool', function (string $type): void {
    $report = spec078Verify("edited.{$type}", spec078RootList());

    expect($report->result->state->value)->toBe('Invalid')
        ->and(spec078Failures($report))->toBe(['assertion.dataHash.mismatch'])
        ->and(spec078Oracle("edited.{$type}", 'root-store-cfg'))->toBe(['state' => 'Invalid', 'failures' => ['assertion.dataHash.mismatch']]);
})->with(SPEC078_TYPES)->group('SPEC-078');

// AC4
it('AC4: c2patool with the root and store.cfg agrees; with the root alone it says invalid, the named difference', function (string $type): void {
    $settings = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.SPEC078_DIR.'/root-store-cfg.settings.json'));
    foreach (["signed.{$type}", "edited.{$type}"] as $name) {
        $report = spec078Verify($name, $settings);
        expect(spec078Oracle($name, 'root-store-cfg'))->toBe(['state' => $report->result->state->value, 'failures' => spec078Failures($report)]);
    }
    expect(spec078Oracle("signed.{$type}", 'root'))->toBe(['state' => 'Invalid', 'failures' => ['signingCredential.invalid']])
        ->and(spec078Verify("signed.{$type}", TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/'.SPEC078_DIR.'/root.settings.json')))->result->state->value)->toBe('Trusted');
})->with(SPEC078_TYPES)->group('SPEC-078');

// AC5
it('AC5: no private key in the fixtures, and the generator refuses a scratch directory inside the repository', function (): void {
    $files = glob(Corpus::fixtures().'/'.SPEC078_DIR.'/*') ?: [];
    expect(count($files))->toBeGreaterThan(8);
    foreach ($files as $file) {
        expect(str_contains((string) file_get_contents($file), 'PRIVATE KEY'))->toBeFalse($file);
    }

    $root = dirname(__DIR__, 3);
    exec(sprintf('%s %s %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root.'/bin/make-conformance-test-variants.php'), escapeshellarg($root.'/tests'), escapeshellarg('c2patool'), escapeshellarg('bundle.pem')), $output, $status);
    expect($status)->toBe(1)
        ->and(implode("\n", $output))->toContain('outside the repository');
})->group('SPEC-078');
