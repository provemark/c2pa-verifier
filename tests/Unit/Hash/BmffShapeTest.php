<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-038: the BMFF hash's shape. Variants of fixture-signed.mp4 from bin/make-spec038-variants.php
 * under tests/Fixtures/bmff-shape/ (the bmff.v3 assertion edited, re-signed under a throwaway root,
 * no box moved; the `-rehashed` ones with a digest the script computes with its own port of
 * c2pa-rs's hashing), verified with their root; both c2patool versions' answers under
 * tests/Fixtures/c2patool/bmff-shape/.
 */

const SPEC038_HASH = 'c2pa.assertions/c2pa.hash.bmff.v3';

function spec038Verify(string $variant): VerificationReport
{
    return spec020Verify("bmff-shape/{$variant}.mp4", 'bmff-shape/probe-root.settings.json');
}

/**
 * The bmffHash statuses of a report, by value, as "code url-tail"; failures, or informational ones.
 *
 * @return list<string>
 */
function spec038Codes(VerificationReport $report, bool $informational = false): array
{
    $out = [];
    foreach ($report->result->statuses as $status) {
        $kind = $informational ? $status->code->isInformational() : $status->code->isFailure();
        if ($kind && str_starts_with($status->code->value, 'assertion.bmffHash.')) {
            $out[] = $status->code->value.' '.substr($status->url, (int) strpos($status->url, 'c2pa.assertions/'));
        }
    }

    return $out;
}

/**
 * c2patool's bmffHash failures for a variant, in the same form.
 *
 * @return list<string>
 */
function spec038Oracle(string $variant, string $version): array
{
    $oracle = spec020Oracle("bmff-shape/{$variant}--{$version}.json");
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $out = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        if (str_starts_with($entry['code'], 'assertion.bmffHash.')) {
            $out[] = $entry['code'].' '.substr($entry['url'], (int) strpos($entry['url'], 'c2pa.assertions/'));
        }
    }

    return $out;
}

/** A variant both versions fault with $code on the assertion: ours the same, and Invalid. */
function spec038AsOracle(string $variant, string $code): void
{
    $want = ["{$code} ".SPEC038_HASH];
    $report = spec038Verify($variant);
    expect(spec038Oracle($variant, '0.28.0'))->toBe($want, $variant)
        ->and(spec038Oracle($variant, '0.27.22'))->toBe($want, $variant)
        ->and(spec038Codes($report))->toBe($want, $variant)
        ->and($report->result->state->value)->toBe('Invalid', $variant);
}

it('AC1: an empty or absent exclusions', function (): void {
    spec038AsOracle('exclusions-empty', 'assertion.bmffHash.malformed');
    // c2patool gives no report for the absent key (open question 2); here the same code, fail closed
    expect(is_file(Corpus::fixtures().'/c2patool/bmff-shape/exclusions-absent--0.28.0.error.txt'))->toBeTrue();
    $report = spec038Verify('exclusions-absent');
    expect(spec038Codes($report))->toBe(['assertion.bmffHash.malformed '.SPEC038_HASH])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-038');

it('AC2: unsorted or overlapping subsets', function (): void {
    spec038AsOracle('subset-unsorted', 'assertion.bmffHash.malformed');
    spec038AsOracle('subset-overlapping', 'assertion.bmffHash.malformed');
})->group('SPEC-038');

it('AC3: a subset that covers the whole box keeps the box\'s offset', function (): void {
    foreach (['subset-sorted', 'subset-whole-one', 'subset-zero-length'] as $variant) {
        spec038AsOracle($variant, 'assertion.bmffHash.mismatch');
    }
})->group('SPEC-038');

it('AC4: the same subsets, with the digest recomputed, pass', function (): void {
    foreach (['subset-sorted', 'subset-whole-one', 'subset-zero-length', 'subset-partial', 'subset-body-only'] as $shape) {
        $variant = "{$shape}-rehashed";
        $report = spec038Verify($variant);
        expect(spec020Oracle("bmff-shape/{$variant}--0.28.0.json")['validation_state'])->toBe('Trusted', $variant)
            ->and(spec020Oracle("bmff-shape/{$variant}--0.27.22.json")['validation_state'])->toBe('Trusted', $variant)
            ->and(spec038Codes($report))->toBe([], $variant)
            ->and($report->result->state->value)->toBe('Trusted', $variant);
    }
    // the control: the route itself changes nothing
    expect(spec038Verify('unchanged-resigned')->result->state->value)->toBe('Trusted');
})->group('SPEC-038');

it('AC5: the informational code, as 0.28.0 reports it', function (): void {
    $report = spec020Verify('fixture-signed.mp4');
    expect(spec038Codes($report, true))->toBe(['assertion.bmffHash.additionalExclusionsPresent '.SPEC038_HASH])
        ->and($report->result->state->value)->toBe('Valid');

    // across the corpus: exactly the files 0.28.0 reports it on (0.27.22: none)
    /** @var array<string, bool> $theirs */
    $theirs = json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/bmff-shape/corpus-additional-exclusions--0.28.0.json'), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, bool> $older */
    $older = json_decode((string) file_get_contents(Corpus::fixtures().'/c2patool/bmff-shape/corpus-additional-exclusions--0.27.22.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(count(array_filter($theirs)))->toBe(12)
        ->and(array_filter($older))->toBe([]);
    foreach ($theirs as $relative => $reported) {
        $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
        assert($stream !== false);
        $ours = (new Verifier)->verify($stream, new TrustSettings([], []));
        expect(spec038Has($ours, 'assertion.bmffHash.additionalExclusionsPresent'))->toBe($reported, $relative);
    }
})->group('SPEC-038');

it('AC6: nothing else moves', function (): void {
    // a guard: the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 134b;
    // here, c2pa-rs's video1.mp4 (v2, nested exclusions and a subset) keeps its verdict under the DigiCert settings
    $report = spec020Verify('c2pa-rs/video1.mp4', 'trust/full-plus-digicert-g4.settings.json');
    expect($report->result->state->value)->toBe('Valid')
        ->and(spec038Codes($report))->toBe([]);
})->group('SPEC-038');

it('AC7: the vocabulary grows by one code, verbatim', function (): void {
    $case = spec038Case('assertion.bmffHash.additionalExclusionsPresent');
    expect($case?->name)->toBe('AssertionBmffHashAdditionalExclusionsPresent')
        ->and($case?->isInformational())->toBeTrue();
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(in_array('Report\StatusCode :: const AssertionBmffHashAdditionalExclusionsPresent', $surface, true))->toBeTrue();
})->group('SPEC-038');

/** The case with this value, or null: a string parameter, so the analyser cannot decide it before the case exists. */
function spec038Case(string $value): ?StatusCode
{
    return StatusCode::tryFrom($value);
}

/** Whether the report carries this code: a string parameter, for the same reason as spec038Case(). */
function spec038Has(VerificationReport $report, string $code): bool
{
    foreach ($report->result->statuses as $status) {
        if ($status->code->value === $code) {
            return true;
        }
    }

    return false;
}
