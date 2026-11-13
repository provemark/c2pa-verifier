<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-090: the general box hash (c2pa.hash.boxes) for JPEG, C2PA 2.4 §15.12.3 and §18.7. Variants from
 * bin/make-spec090-variants.php under tests/Fixtures/box-hash/ (step 533):
 *  - bing-*: the Bing Image Creator file with its asset bytes changed, claim and signature untouched,
 *    judged at 2026-07-01 because its leaf expired on 2026-10-01 (SPEC-041 open question 3);
 *  - written-*: this project's own box-hash JPEG, re-signed under a throw-away root.
 * Both c2patool versions' answers are under tests/Fixtures/c2patool/box-hash/.
 */

const SPEC090_BING = 'writers/microsoft-20260609-bing-fast-heartbeat.jpg';
const SPEC090_BING_URL = 'self#jumbf=/c2pa/urn:uuid:2b35d21e-b8eb-4e52-ba67-29bc413dfea6/c2pa.assertions/c2pa.hash.boxes';
const SPEC090_WRITTEN_URL = 'self#jumbf=/c2pa/urn:c2pa:4e936c4e-e4e0-42f5-ad23-22121bd90948/c2pa.assertions/c2pa.hash.boxes';

function spec090Bing(string $relative): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    assert($stream !== false);

    return (new Verifier)->verify($stream, null, new DateTimeImmutable('2026-07-01T00:00:00Z'));
}

function spec090Written(string $variant): VerificationReport
{
    return spec020Verify("box-hash/{$variant}.jpg", 'box-hash/probe-root.settings.json');
}

/**
 * Every status of a report as "code url".
 *
 * @return list<string>
 */
function spec090Statuses(VerificationReport $report): array
{
    return array_map(static fn ($status): string => $status->code->value.' '.$status->url, $report->result->statuses);
}

/**
 * The failure codes of a report, signing-credential codes aside.
 *
 * @return list<string>
 */
function spec090Faults(VerificationReport $report): array
{
    $out = [];
    foreach ($report->result->statuses as $status) {
        if ($status->code->isFailure() && ! str_starts_with($status->code->value, 'signingCredential.')) {
            $out[] = $status->code->value;
        }
    }

    return $out;
}

/**
 * c2patool's verdict and failure codes for a variant (signing-credential codes aside), or "abort" with
 * its first error line.
 *
 * @return array{0: string, 1: list<string>}
 */
function spec090Oracle(string $variant, string $version): array
{
    $base = Corpus::fixtures()."/c2patool/box-hash/{$variant}--{$version}";
    if (is_file("{$base}.error.txt")) {
        return ['abort', [trim(strtok((string) file_get_contents("{$base}.error.txt"), "\n") ?: '')]];
    }
    $oracle = spec020Oracle("box-hash/{$variant}--{$version}.json");
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $codes = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']));
        if (! str_starts_with($entry['code'], 'signingCredential.')) {
            $codes[] = $entry['code'];
        }
    }
    assert(is_string($oracle['validation_state']));

    return [$oracle['validation_state'], $codes];
}

it('AC1: the Bing file\'s box hash matches', function (): void {
    $bing = spec020Oracle('writers/microsoft-20260609-bing-fast-heartbeat.json');
    expect($bing['validation_state'])->toBe('Valid');

    $report = spec090Bing(SPEC090_BING);
    $statuses = spec090Statuses($report);

    expect($statuses)->toContain('assertion.boxesHash.match '.SPEC090_BING_URL)
        ->and($statuses)->toContain('signingCredential.untrusted '.str_replace('c2pa.assertions/c2pa.hash.boxes', 'c2pa.signature', SPEC090_BING_URL))
        ->and(array_filter($statuses, static fn (string $s): bool => str_starts_with($s, 'general.error')))->toBe([])
        ->and(spec090Faults($report))->toBe([])
        ->and($report->result->state->value)->toBe('Valid');
})->group('SPEC-090');

it('AC2: a changed pixel is a mismatch', function (): void {
    foreach (['0.28.6', '0.27.22'] as $version) {
        expect(spec090Oracle('bing-pixel-changed', $version))->toBe(['Invalid', ['assertion.boxesHash.mismatch']]);
    }
    $report = spec090Bing('box-hash/bing-pixel-changed.jpg');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.mismatch '.SPEC090_BING_URL)
        ->and(spec090Faults($report))->toBe(['assertion.boxesHash.mismatch'])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-090');

it('AC3: an unlisted segment is an unknown box', function (): void {
    expect(spec090Oracle('bing-unlisted-com', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.unknownBox']]);
    $report = spec090Bing('box-hash/bing-unlisted-com.jpg');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.unknownBox '.SPEC090_BING_URL)
        ->and(spec090Faults($report))->toBe(['assertion.boxesHash.unknownBox'])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-090');

it('AC4: data after EOI is an unknown box, listed or not', function (): void {
    // c2pa 0.91.11 (#2949) refuses the unlisted trailer; 0.27.22 predates it and says Valid
    expect(spec090Oracle('bing-after-eoi', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.unknownBox']])
        ->and(spec090Oracle('bing-after-eoi', '0.27.22'))->toBe(['Valid', []]);
    $report = spec090Bing('box-hash/bing-after-eoi.jpg');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.unknownBox '.SPEC090_BING_URL)
        ->and(spec090Faults($report))->toBe(['assertion.boxesHash.unknownBox'])
        ->and($report->result->state->value)->toBe('Invalid');

    // open question 4: a c2pa.after the list names and hashes correctly is refused too (0.28.6 accepts it)
    expect(spec090Oracle('written-listed-after', '0.28.6'))->toBe(['Trusted', []]);
    $listed = spec090Written('written-listed-after');
    expect(spec090Faults($listed))->toBe(['assertion.boxesHash.unknownBox'])
        ->and($listed->result->state->value)->toBe('Invalid');
})->group('SPEC-090');

it('AC5: boxes out of order are a mismatch, by the letter', function (): void {
    // c2pa-rs matches names position by position: 0.28.6 says unknownBox, 0.27.22 mismatch (open question 2)
    expect(spec090Oracle('bing-out-of-order', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.unknownBox']])
        ->and(spec090Oracle('bing-out-of-order', '0.27.22'))->toBe(['Invalid', ['assertion.boxesHash.mismatch']]);
    $report = spec090Bing('box-hash/bing-out-of-order.jpg');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.mismatch '.SPEC090_BING_URL)
        ->and(spec090Faults($report))->toBe(['assertion.boxesHash.mismatch'])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-090');

it('AC6: a well-formed box hash from this project\'s writer', function (): void {
    // a range of names (DQT, DHT, SOF0), sha384 on the first map under a sha256 box-map, pad and pad2 zero-filled
    foreach (['0.28.6', '0.27.22'] as $version) {
        expect(spec090Oracle('written', $version))->toBe(['Trusted', []]);
    }
    $report = spec090Written('written');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.match '.SPEC090_WRITTEN_URL)
        ->and(spec090Statuses($report))->not->toContain('assertion.boxesHash.additionalExclusionsPresent '.SPEC090_WRITTEN_URL)
        ->and(spec090Faults($report))->toBe([])
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-090');

it('AC7: malformed box-maps', function (string $variant): void {
    $report = spec090Written($variant);

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.malformed '.SPEC090_WRITTEN_URL)
        ->and(spec090Faults($report))->toBe(['assertion.boxesHash.malformed'])
        ->and($report->result->state->value)->toBe('Invalid');
})->with([
    'without boxes' => 'written-no-boxes',
    'a map without names' => 'written-no-names',
    'an empty exclusions array' => 'written-empty-exclusions',
    'a boxIndex beyond its names' => 'written-box-index-beyond',
    'overlapping exclusions' => 'written-overlapping-exclusions',
    'C2PA sharing a map' => 'written-c2pa-shared',
])->group('SPEC-090');

it('AC7: what c2patool says about the malformed variants', function (): void {
    // recorded, not followed: c2pa-rs aborts on a missing field, and reports some of these as mismatch
    expect(spec090Oracle('written-no-boxes', '0.28.6')[0])->toBe('abort')
        ->and(spec090Oracle('written-no-names', '0.28.6')[0])->toBe('abort')
        ->and(spec090Oracle('written-empty-exclusions', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.malformed']])
        ->and(spec090Oracle('written-box-index-beyond', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.malformed']])
        ->and(spec090Oracle('written-overlapping-exclusions', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.malformed']])
        ->and(spec090Oracle('written-c2pa-shared', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.mismatch']]);
})->group('SPEC-090');

it('AC8: no algorithm anywhere', function (): void {
    expect(spec090Oracle('written-no-alg', '0.28.6'))->toBe(['abort', ['Error: unknown algorithm']]);
    $report = spec090Written('written-no-alg');

    // the claim without alg already fails its hashed URIs; what binds is the box hash's own refusal
    $own = array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code === StatusCode::AlgorithmUnsupported && $s->url === SPEC090_WRITTEN_URL && str_contains($s->explanation, 'box-map')));
    expect($own)->toHaveCount(1)
        ->and(spec090Statuses($report))->not->toContain('general.error '.SPEC090_WRITTEN_URL)
        ->and(spec090Statuses($report))->not->toContain('assertion.boxesHash.match '.SPEC090_WRITTEN_URL)
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-090');

it('AC9: an exclusion inside an Exif payload is allowed, elsewhere it is a mismatch', function (): void {
    expect(spec090Oracle('written-exif-exclusion', '0.28.6'))->toBe(['Trusted', []])
        ->and(spec090Oracle('written-dqt-exclusion', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.mismatch']])
        ->and(spec090Oracle('written-past-end-exclusion', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.mismatch']]);

    $exif = spec090Written('written-exif-exclusion');
    expect(spec090Statuses($exif))->toContain('assertion.boxesHash.match '.SPEC090_WRITTEN_URL)
        ->and(spec090Statuses($exif))->toContain('assertion.boxesHash.additionalExclusionsPresent '.SPEC090_WRITTEN_URL)
        ->and($exif->result->state->value)->toBe('Trusted');

    foreach (['written-dqt-exclusion', 'written-past-end-exclusion'] as $variant) {
        $report = spec090Written($variant);
        expect(spec090Faults($report))->toBe(['assertion.boxesHash.mismatch'])
            ->and($report->result->state->value)->toBe('Invalid');
    }
})->group('SPEC-090');

it('AC10: excluded: true skips the box', function (): void {
    expect(spec090Oracle('written-excluded', '0.28.6'))->toBe(['Trusted', []]);
    $report = spec090Written('written-excluded');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.match '.SPEC090_WRITTEN_URL)
        ->and(spec090Statuses($report))->toContain('assertion.boxesHash.additionalExclusionsPresent '.SPEC090_WRITTEN_URL)
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-090');

it('AC11: a box hash in another container stays refused', function (string $png): void {
    $report = spec020Verify($png);
    $errors = array_values(array_filter($report->result->statuses, static fn ($s): bool => $s->code === StatusCode::GeneralError && str_ends_with($s->url, '/c2pa.hash.boxes')));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->explanation)->toContain('not supported')
        ->and($errors[0]->explanation)->toContain('JPEG')
        ->and($report->result->state->value)->toBe('Invalid');
})->with(['binding/hard-binding-boxes.png'])->group('SPEC-090');

it('AC12: a foreign APP11 must be listed', function (): void {
    expect(spec090Oracle('bing-foreign-app11', '0.28.6'))->toBe(['Invalid', ['assertion.boxesHash.unknownBox']]);
    $report = spec090Bing('box-hash/bing-foreign-app11.jpg');

    expect(spec090Statuses($report))->toContain('assertion.boxesHash.unknownBox '.SPEC090_BING_URL)
        ->and(spec090Faults($report))->toBe(['assertion.boxesHash.unknownBox'])
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-090');

it('AC13: the vocabulary grows by five codes, verbatim', function (): void {
    $kinds = [
        'assertion.boxesHash.match' => 'success',
        'assertion.boxesHash.mismatch' => 'failure',
        'assertion.boxesHash.malformed' => 'failure',
        'assertion.boxesHash.unknownBox' => 'failure',
        'assertion.boxesHash.additionalExclusionsPresent' => 'informational',
    ];
    $surface = (string) file_get_contents(Corpus::fixtures().'/api/public-surface.txt');
    foreach ($kinds as $value => $kind) {
        $code = StatusCode::from($value);   // throws for a value the enum lacks
        expect(match (true) {
            $code->isSuccess() => 'success',
            $code->isFailure() => 'failure',
            $code->isInformational() => 'informational',
            default => 'none',
        })->toBe($kind)
            ->and($surface)->toContain('Report\StatusCode :: const '.$code->name);
    }
})->group('SPEC-090');

it('AC14: nothing else in the corpus carries a box hash', function (): void {
    // a guard: only a file that carries c2pa.hash.boxes can be moved by verifying it; the before/after run
    // over the corpus is the step's (as SPEC-042 AC7)
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Corpus::fixtures(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        assert($file instanceof SplFileInfo);
        if (in_array(strtolower($file->getExtension()), ['json', 'md', 'txt', 'pem', 'cfg'], true)) {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), 'c2pa.hash.boxes')) {
            $found[] = substr($file->getPathname(), strlen(Corpus::fixtures()) + 1);
        }
    }
    sort($found);
    $variants = array_map(static fn (string $f): string => 'box-hash/'.basename($f), glob(Corpus::fixtures().'/box-hash/*.jpg') ?: []);
    $expected = [...$variants, 'binding/hard-binding-boxes.bin', 'binding/hard-binding-boxes.png', 'hard-binding-redacted/hash-boxes-relative.png', SPEC090_BING];
    sort($expected);

    expect($found)->toBe($expected)
        ->and($variants)->toHaveCount(19);
})->group('SPEC-090');
