<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Verifier\CrJsonDocument;

/*
 * SPEC-091: a delta failure that says the holder's own ingredient assertion
 * failed — ingredient.manifest.missing or assertion.ingredient.malformed —
 * is also written in the validationResults of the manifest that holds the
 * delta, because the programme's integrity rubric reads only
 * manifests[0].validationResults. The report itself is unchanged.
 */

/** The two codes SPEC-091 writes on the holder too. */
const SPEC091_CODES = ['ingredient.manifest.missing', 'assertion.ingredient.malformed'];

/**
 * A manifest's failure entries as (code, url, explanation) triples.
 *
 * @param  array<string, mixed>  $manifest
 * @return list<array{mixed, mixed, mixed}>
 */
function spec091Failures(array $manifest): array
{
    $failure = spec075A(spec075A($manifest['validationResults'] ?? null, 'validationResults')['failure'] ?? null, 'failure');

    return array_values(array_map(static function (mixed $entry): array {
        $entry = spec075A($entry, 'entry');

        return [$entry['code'] ?? null, $entry['url'] ?? null, $entry['explanation'] ?? null];
    }, $failure));
}

/**
 * The entries of a manifest's held deltas with one of SPEC-091's codes.
 *
 * @param  array<string, mixed>  $manifest
 * @return list<array{mixed, mixed, mixed}>
 */
function spec091Held(array $manifest): array
{
    $held = [];
    foreach (spec075A($manifest['ingredientDeltas'] ?? []) as $delta) {
        $failure = spec075A(spec075A(spec075A($delta)['validationDeltas'] ?? null)['failure'] ?? []);
        foreach ($failure as $entry) {
            $entry = spec075A($entry);
            if (in_array($entry['code'] ?? null, SPEC091_CODES, true)) {
                $held[] = [$entry['code'], $entry['url'] ?? null, $entry['explanation'] ?? null];
            }
        }
    }

    return $held;
}

/**
 * Unique failure codes of the first manifest.
 *
 * @param  array<string, mixed>  $doc
 * @return list<string>
 */
function spec091Codes(array $doc): array
{
    $manifests = spec075Manifests($doc);
    if ($manifests === []) {
        return [];
    }
    $codes = array_values(array_unique(array_map(static fn (array $f): string => spec075S($f[0]), spec091Failures($manifests[0]))));
    sort($codes);

    return $codes;
}

it('AC1: the missing manifest is also on the active manifest, as c2patool writes it', function (string $relative): void {
    $manifests = spec075Manifests(spec075Doc(spec075Report($relative)));
    $missing = array_values(array_filter(spec091Failures($manifests[0]), static fn (array $f): bool => $f[0] === 'ingredient.manifest.missing'));

    expect($missing)->toHaveCount(1)
        ->and($missing[0][1])->toBe('contentbeef:urn:uuid:8bb8ad50-ef2f-4f75-b709-a0e302d58019')
        ->and(spec091Held($manifests[0]))->toBe($missing)
        ->and(spec091Codes(spec075Doc(spec075Report($relative))))->toBe(spec091Codes(spec075Oracle()[$relative]));
})->with([
    'public-testfiles/adobe-20220124-E-clm-CAICAI.jpg',
    'c2pa-rs/adobe-20220124-E-clm-CAICAI.jpg',
])->group('SPEC-091');

it('AC1: the delta itself is left as the report has it', function (): void {
    $report = spec075Report('public-testfiles/adobe-20220124-E-clm-CAICAI.jpg');
    $manifests = spec075Manifests(spec075Doc($report));
    $results = spec075A($report->result->toArray()['validation_results'] ?? null);

    expect($manifests[0]['ingredientDeltas'] ?? null)->toBe($results['ingredientDeltas'] ?? null);
})->group('SPEC-091');

it('AC2: a malformed ingredient assertion is also on the active manifest', function (string $relative): void {
    $manifests = spec075Manifests(spec075Doc(spec075Report($relative)));
    $held = spec091Held($manifests[0]);
    $malformed = array_values(array_filter(spec091Failures($manifests[0]), static fn (array $f): bool => $f[0] === 'assertion.ingredient.malformed'));

    expect($held)->not->toBe([])
        ->and($held[0][0])->toBe('assertion.ingredient.malformed')
        ->and($malformed)->toBe($held);
})->with([
    'ingredient/manifest-and-dst.png',
    'ingredient/hash-text.png',
    'ingredient/manifest-no-results.png',
])->group('SPEC-091');

it('AC3: on the manifest that holds the delta, not on the active one', function (): void {
    $relative = 'graph-fuzz-cycle-acknowledged/cycle-below-the-active-acknowledged.png';
    $report = spec075Report($relative);
    $manifests = spec075Manifests(spec075Doc($report));
    $holders = array_values(array_filter(array_slice($manifests, 1), static fn (array $m): bool => spec091Held($m) !== []));
    $active = spec075A($report->result->toArray()['validation_results'] ?? null)['activeManifest'] ?? null;

    expect($holders)->not->toBe([]);
    foreach ($holders as $holder) {
        foreach (spec091Held($holder) as $entry) {
            expect(spec091Failures($holder))->toContain($entry);
        }
    }
    expect(spec075Groups($manifests[0]['validationResults'] ?? null))->toBe($active);
})->group('SPEC-091');

it('AC4: other delta codes stay where they are', function (string $relative): void {
    $report = spec075Report($relative);
    $manifests = spec075Manifests(spec075Doc($report));
    $active = spec075A($report->result->toArray()['validation_results'] ?? null)['activeManifest'] ?? null;

    expect(spec091Held($manifests[0]))->toBe([])
        ->and(spec075Groups($manifests[0]['validationResults'] ?? null))->toBe($active);
})->with([
    'public-testfiles/adobe-20220124-E-uri-CIE-sig-CA.jpg',
    'spec052/crc32b-reference.jpg',
])->group('SPEC-091');

it('AC5: each held entry once, and nothing but held entries is added', function (string $relative): void {
    $report = spec075Report($relative);
    $results = spec075A($report->result->toArray()['validation_results'] ?? null);
    $manifests = spec075Manifests(spec075Doc($report));
    $failures = spec091Failures($manifests[0]);
    $own = array_map(static function (mixed $e): array {
        $e = spec075A($e);

        return [$e['code'] ?? null, $e['url'] ?? null, $e['explanation'] ?? null];
    }, spec075A(spec075A($results['activeManifest'] ?? null)['failure'] ?? null));
    $added = array_values(array_udiff($failures, $own, static fn (array $a, array $b): int => serialize($a) <=> serialize($b)));

    expect(count($failures))->toBe(count(array_unique(array_map('serialize', $failures))))
        ->and($added)->toBe(array_values(array_udiff(array_values(array_unique(spec091Held($manifests[0]), SORT_REGULAR)), $own, static fn (array $a, array $b): int => serialize($a) <=> serialize($b))));
})->with([
    'public-testfiles/adobe-20220124-E-clm-CAICAI.jpg',
    'ingredient/manifest-and-dst.png',
    'graph-fuzz-cycle-acknowledged/cycle-below-the-active-acknowledged.png',
    'public-testfiles/adobe-20220124-CACA.jpg',
])->group('SPEC-091');

it('AC5: against c2patool, the first manifest\'s failure codes are equal in 48 of 59 files', function (): void {
    $equal = 0;
    foreach (spec075Oracle() as $relative => $theirs) {
        if (spec091Codes(spec075Doc(spec075Report($relative))) === spec091Codes($theirs)) {
            $equal++;
        }
    }

    expect($equal)->toBe(48);
})->group('SPEC-091');

it('AC5: two deltas naming the same missing manifest add it once; one already present is not added again', function (): void {
    // No fixture holds the same code and url twice, so the rule is reached directly (as MdatMerkleTest does).
    $lift = new ReflectionMethod(CrJsonDocument::class, 'withHeldReferenceFailures');
    $missing = ['code' => 'ingredient.manifest.missing', 'url' => 'urn:uuid:gone', 'explanation' => 'ingredient not found'];
    $other = ['code' => 'ingredient.manifest.missing', 'url' => 'urn:uuid:also-gone', 'explanation' => 'ingredient not found'];
    $delta = static fn (array ...$failure): array => ['ingredientAssertionURI' => 'self#jumbf=/c2pa/m/c2pa.assertions/c2pa.ingredient', 'validationDeltas' => ['success' => [], 'informational' => [], 'failure' => $failure]];
    $empty = ['success' => [], 'informational' => [], 'failure' => []];

    expect(spec075A($lift->invoke(null, $empty, [$delta($missing), $delta($missing, $other)]))['failure'] ?? null)->toBe([$missing, $other])
        ->and(spec075A($lift->invoke(null, ['failure' => [$missing]] + $empty, [$delta($missing)]))['failure'] ?? null)->toBe([$missing]);
})->group('SPEC-091');
