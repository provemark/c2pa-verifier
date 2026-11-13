<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-042: a JUMBF URI written self#jumbf=c2pa/…, without the leading slash, read as c2pa-rs's
 * to_normalized_uri reads it. Variants from bin/make-spec042-variants.php under
 * tests/Fixtures/slashless/ (the signed PNG fixture's claim URIs rewritten, re-signed under a
 * throwaway root, the store's length kept; `resigned` is the control without a rewrite); both
 * c2patool versions' answers under tests/Fixtures/c2patool/slashless/. AC1 and AC5 read the two
 * corpus files that carry the form (step 529).
 */

const SPEC042_BING = 'writers/microsoft-20260609-bing-fast-heartbeat.jpg';
const SPEC042_BING_LABEL = 'urn:uuid:2b35d21e-b8eb-4e52-ba67-29bc413dfea6';
const SPEC042_ZERO = 'urn:c2pa:00000000-0000-4000-8000-000000000000';

function spec042Verify(string $variant): VerificationReport
{
    return spec020Verify("slashless/{$variant}.png", 'slashless/probe-root.settings.json');
}

/**
 * Every status of a report as "code url".
 *
 * @return list<string>
 */
function spec042Statuses(VerificationReport $report): array
{
    return array_map(static fn ($status): string => $status->code->value.' '.$status->url, $report->result->statuses);
}

/**
 * The failures of a report as "code url", signing-credential codes aside.
 *
 * @return list<string>
 */
function spec042Faults(VerificationReport $report): array
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
 * c2patool's failures for a variant, without its abort line ("Failed to load manifest", SPEC-034 amendment 2)
 * and the signing-credential codes.
 *
 * @return list<string>
 */
function spec042Oracle(string $variant, string $version): array
{
    $oracle = spec020Oracle("slashless/{$variant}--{$version}.json");
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

it('AC1: Bing\'s signature is found', function (): void {
    $stream = fopen(Corpus::fixtures().'/'.SPEC042_BING, 'rb');
    assert($stream !== false);
    // the leaf expired on 2026-10-01 (SPEC-041 open question 3): judged before that
    $report = (new Verifier)->verify($stream, null, new DateTimeImmutable('2026-07-01T00:00:00Z'));
    $statuses = spec042Statuses($report);
    $manifest = 'self#jumbf=/c2pa/'.SPEC042_BING_LABEL;

    expect($statuses)->toContain("claimSignature.validated {$manifest}/c2pa.signature")
        ->and($statuses)->toContain("claimSignature.insideValidity {$manifest}/c2pa.signature")
        ->and($statuses)->toContain("assertion.hashedURI.match {$manifest}/c2pa.assertions/c2pa.hash.boxes")
        ->and($statuses)->toContain("assertion.hashedURI.match {$manifest}/c2pa.assertions/c2pa.actions")
        ->and(array_filter($statuses, static fn (string $s): bool => str_starts_with($s, 'claimSignature.missing')))->toBe([])
        // amendment 1: since SPEC-090 the box hash is verified, and matches
        ->and($statuses)->toContain("assertion.boxesHash.match {$manifest}/c2pa.assertions/c2pa.hash.boxes")
        ->and($statuses)->not->toContain("general.error {$manifest}/c2pa.assertions/c2pa.hash.boxes")
        ->and($report->result->state->value)->toBe('Valid');
})->group('SPEC-042');

it('AC2: a slash-less file with a data hash is judged like its slashed original', function (): void {
    foreach (['0.28.6', '0.27.22'] as $version) {
        expect(spec020Oracle("slashless/all-slashless--{$version}.json")['validation_state'])->toBe('Trusted')
            ->and(spec020Oracle("slashless/resigned--{$version}.json")['validation_state'])->toBe('Trusted');
    }
    $control = spec042Verify('resigned');
    $report = spec042Verify('all-slashless');

    expect($control->result->state->value)->toBe('Trusted')
        ->and($report->result->state->value)->toBe('Trusted')
        // the status urls keep the absolute form (open question 1), so the two lists are equal
        ->and(spec042Statuses($report))->toBe(spec042Statuses($control));
})->group('SPEC-042');

it('AC3: a slash-less entry naming another manifest is outside', function (): void {
    $want = ['assertion.outsideManifest self#jumbf=c2pa/'.SPEC042_ZERO.'/c2pa.assertions/c2pa.actions.v2'];
    $report = spec042Verify('outside-manifest');

    expect(spec042Oracle('outside-manifest', '0.28.6'))->toBe($want)
        ->and(spec042Oracle('outside-manifest', '0.27.22'))->toBe($want)
        ->and(spec042Faults($report))->toBe($want)
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-042');

it('AC4: a slash-less signature URI naming another manifest is missing', function (): void {
    foreach (['0.28.6', '0.27.22'] as $version) {
        $codes = array_map(static fn (string $f): string => (string) strstr($f, ' ', true), spec042Oracle('signature-other-manifest', $version));
        expect($codes)->toBe(['claimSignature.missing']);
    }
    $report = spec042Verify('signature-other-manifest');
    $missing = array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code === StatusCode::ClaimSignatureMissing));

    // refused because it names another manifest, as resolve() refuses a slashed one — not because
    // "c2pa/…" fails to resolve inside the manifest, which is today's reason
    expect($missing)->toHaveCount(1)
        ->and($missing[0]->explanation)->toContain('refers to another manifest ('.SPEC042_ZERO.')')
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-042');

it('AC5: prerelease.jpg stays refused', function (): void {
    $report = spec020Verify('c2pa-rs/prerelease.jpg');
    $codes = array_map(static fn ($status): string => $status->code->value, array_filter($report->result->statuses, static fn ($s): bool => $s->code->isFailure()));
    $manifest = 'self#jumbf=/c2pa/adobetest:urn:uuid:7acbba1a-7473-4e1c-a8cb-1ee6f9f32a92';

    expect($codes)->not->toContain('claimSignature.missing')
        ->and(spec042Statuses($report))->toContain("general.error {$manifest}/c2pa.signature")
        ->and(array_count_values($codes)['assertion.hashedURI.mismatch'] ?? 0)->toBe(8)
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-042');

it('AC6: a relative URI keeps its meaning', function (): void {
    $store = Corpus::manifestStore('fixture-signed.png') ?? throw new RuntimeException('no store');
    $manifest = $store->active;
    $label = $manifest->label;

    // relative to the current manifest, as today
    expect($manifest->resolve('self#jumbf=c2pa.assertions/c2pa.actions.v2')->description->label)->toBe('c2pa.actions.v2')
        ->and($manifest->resolve('self#jumbf=c2pa.signature')->description->label)->toBe('c2pa.signature')
        ->and($manifest->absoluteUri('self#jumbf=c2pa.assertions/c2pa.actions.v2'))->toBe("self#jumbf=/c2pa/{$label}/c2pa.assertions/c2pa.actions.v2")
        // without the slash: from the store's root, with the label the URI writes
        ->and($manifest->absoluteUri("self#jumbf=c2pa/{$label}/c2pa.assertions/x"))->toBe("self#jumbf=/c2pa/{$label}/c2pa.assertions/x")
        ->and($manifest->absoluteUri('self#jumbf=c2pa/'.SPEC042_ZERO.'/c2pa.assertions/x'))->toBe('self#jumbf=/c2pa/'.SPEC042_ZERO.'/c2pa.assertions/x')
        ->and($manifest->resolve("self#jumbf=c2pa/{$label}/c2pa.assertions/c2pa.actions.v2")->description->label)->toBe('c2pa.actions.v2');
})->group('SPEC-042');

it('AC7: nothing else in the corpus moves', function (): void {
    // a guard: only a file that writes the form can be moved by reading it; the before/after run over
    // the corpus is the step's (as SPEC-040 AC5)
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Corpus::fixtures(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        assert($file instanceof SplFileInfo);
        if (in_array(strtolower($file->getExtension()), ['json', 'md', 'txt', 'pem', 'cfg'], true)) {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), 'self#jumbf=c2pa/')) {
            $found[] = substr($file->getPathname(), strlen(Corpus::fixtures()) + 1);
        }
    }
    sort($found);

    // amendment 1: SPEC-090's Bing variants carry the Bing store unchanged
    expect($found)->toBe([
        'box-hash/bing-after-eoi.jpg',
        'box-hash/bing-foreign-app11.jpg',
        'box-hash/bing-out-of-order.jpg',
        'box-hash/bing-pixel-changed.jpg',
        'box-hash/bing-unlisted-com.jpg',
        'c2pa-rs/prerelease.jpg',
        'slashless/all-slashless.png',
        'slashless/outside-manifest.png',
        'slashless/signature-other-manifest.png',
        'writers/microsoft-20260609-bing-fast-heartbeat.jpg',
    ]);
})->group('SPEC-042');
