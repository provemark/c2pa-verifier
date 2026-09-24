<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Container\JpegManifestStoreExtractor;
use Provemark\C2paVerifier\Jumbf\JumbfParser;
use Provemark\C2paVerifier\Manifest\ManifestException;
use Provemark\C2paVerifier\Manifest\ManifestStore;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-040: assertion.outsideManifest. Variants from bin/make-spec040-variants.php under
 * tests/Fixtures/outside-manifest/ (the signed PNG fixture's actions entry rewritten, re-signed under
 * a throwaway root, the store's length kept); both c2patool versions' answers under
 * tests/Fixtures/c2patool/outside-manifest/. AC4 rewrites c2pa-rs/CACA.jpg's active claim in memory.
 */

const SPEC040_OTHER = 'self#jumbf=/c2pa/urn:c2pa:00000000-0000-4000-8000-000000000000/c2pa.assertions/c2pa.actions.v2';

function spec040Verify(string $variant): VerificationReport
{
    return spec020Verify("outside-manifest/{$variant}.png", 'outside-manifest/probe-root.settings.json');
}

/**
 * The failures of a report as "code url", signing-credential codes aside.
 *
 * @return list<string>
 */
function spec040Faults(VerificationReport $report): array
{
    $out = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure() && ! str_starts_with($status->code->value, 'signingCredential.')) {
            $out[] = $status->code->value.' '.$status->url;
        }
    }

    return $out;
}

/**
 * c2patool's failures for a variant, without its abort line ("Failed to load manifest", SPEC-034 amendment 2).
 *
 * @return list<string>
 */
function spec040Oracle(string $variant, string $version): array
{
    $oracle = spec020Oracle("outside-manifest/{$variant}--{$version}.json");
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $out = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        $abort = ($entry['explanation'] ?? null) === 'Failed to load manifest';
        if (! $abort && ! str_starts_with($entry['code'], 'signingCredential.')) {
            $out[] = $entry['code'].' '.$entry['url'];
        }
    }

    return $out;
}

it('AC1: an entry naming another manifest', function (): void {
    $want = ['assertion.outsideManifest '.SPEC040_OTHER];
    $report = spec040Verify('other-manifest');
    expect(spec040Oracle('other-manifest', '0.28.0'))->toBe($want)
        ->and(spec040Oracle('other-manifest', '0.27.22'))->toBe($want)
        ->and(spec040Faults($report))->toBe($want)
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-040');

it('AC2: an absolute entry naming the claim\'s own manifest passes', function (): void {
    $report = spec040Verify('own-absolute');
    expect(spec020Oracle('outside-manifest/own-absolute--0.28.0.json')['validation_state'])->toBe('Trusted')
        ->and(spec020Oracle('outside-manifest/own-absolute--0.27.22.json')['validation_state'])->toBe('Trusted')
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-040');

it('AC3: an entry with no manifest label keeps its code', function (): void {
    // c2patool prints no report for it
    expect(is_file(Corpus::fixtures().'/c2patool/outside-manifest/no-manifest-label--0.28.0.error.txt'))->toBeTrue();
    $report = spec040Verify('no-manifest-label');
    $codes = array_map(static fn (string $f): string => (string) strstr($f, ' ', true), spec040Faults($report));
    expect($codes)->toBe(['assertion.missing'])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-040');

it('AC4: a label that exists in the store is still outside', function (): void {
    $stream = fopen(Corpus::fixtures().'/c2pa-rs/CACA.jpg', 'rb');
    assert($stream !== false);
    $bytes = (new JpegManifestStoreExtractor)->extract($stream)->bytes ?? throw new RuntimeException('no store');
    $tree = (new JumbfParser)->parse($bytes);
    $manifests = $tree->superboxes();
    [$ingredient, $active] = [$manifests[0], $manifests[count($manifests) - 1]];
    $claim = $active->child('c2pa.claim.v2') ?? throw new RuntimeException('no claim');
    $cbor = $claim->contentBoxes()[0];

    // the actions entry, rewritten to name the ingredient manifest; every enclosing LBox grows with it
    $was = 'self#jumbf=c2pa.assertions/c2pa.actions.v2';
    $url = 'self#jumbf=/c2pa/'.$ingredient->description->label.'/c2pa.assertions/c2pa.actions.v2';
    $text = static fn (string $s): string => (strlen($s) < 24 ? pack('C', 0x60 | strlen($s)) : "\x78".pack('C', strlen($s))).$s;   // CBOR text, under 256 bytes
    $at = strpos($bytes, $text($was), $cbor->offset);
    assert($at !== false && $at < $cbor->offset + $cbor->length);
    $delta = strlen($text($url)) - strlen($text($was));
    foreach ([0, $active->offset, $claim->offset, $cbor->offset] as $lbox) {
        $size = unpack('N', $bytes, $lbox);
        assert(is_array($size) && is_int($size[1]));
        $bytes = substr_replace($bytes, pack('N', $size[1] + $delta), $lbox, 4);
    }
    $bytes = substr_replace($bytes, $text($url), $at, strlen($text($was)));

    try {
        ManifestStore::fromTree((new JumbfParser)->parse($bytes));
        $caught = null;
    } catch (ManifestException $e) {
        $caught = $e;
    }
    expect($caught?->status->value)->toBe('assertion.outsideManifest')
        ->and($caught?->url)->toBe($url);
})->group('SPEC-040');

it('AC5: nothing else moves', function (): void {
    // a guard: the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 137b;
    // here, the unchanged two-manifest file keeps reading
    expect(Corpus::manifestStore('c2pa-rs/CACA.jpg')?->manifests)->toHaveCount(2);
})->group('SPEC-040');

it('AC6: the vocabulary grows by one code, verbatim', function (): void {
    $case = StatusCode::tryFrom(spec040Value());
    expect($case?->name)->toBe('AssertionOutsideManifest')
        ->and($case?->isFailure())->toBeTrue();
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(in_array('Report\StatusCode :: const AssertionOutsideManifest', $surface, true))->toBeTrue();
})->group('SPEC-040');

/** A string the analyser cannot decide against today's cases. */
function spec040Value(): string
{
    return 'assertion.outsideManifest';
}
