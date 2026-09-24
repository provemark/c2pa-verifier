<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-035: redactions. Probes from bin/make-spec035-variants.php under
 * tests/Fixtures/redactions/ (PNG, a child made by c2patool 0.28.0 with -p and
 * "redactions"; the claim-signature variant patched at the same length and
 * re-signed), verified with their throwaway root. The two existing files with a
 * redaction (SPEC-021's ingredient-manifest/redacted.png, SPEC-010's
 * binding/claim-redacted.png) carry the claim-level rules (amendments 1 and 2).
 * Both c2patool versions' answers under tests/Fixtures/c2patool/redactions/.
 */

const SPEC035_SETTINGS = 'redactions/probe-root.settings.json';

/** @return array<string, mixed> */
function spec035Oracle(string $name): array
{
    return spec020Oracle("redactions/{$name}.json");
}

/** @return list<string> "code url", the active manifest's failures, signing-credential codes aside */
function spec035Faults(VerificationReport $report): array
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

/**
 * The oracle's active-manifest failures, signing-credential codes aside, optionally only those under a prefix set.
 *
 * @param  array<string, mixed>  $oracle
 * @param  list<string>  $codes  keep only these codes; [] keeps all
 * @return list<string> "code url"
 */
function spec035OracleFaults(array $oracle, array $codes = []): array
{
    $results = $oracle['validation_results'];
    assert(is_array($results) && is_array($results['activeManifest']));
    $faults = [];
    foreach ((array) ($results['activeManifest']['failure'] ?? []) as $entry) {
        assert(is_array($entry) && is_string($entry['code']) && is_string($entry['url']));
        if (! str_starts_with($entry['code'], 'signingCredential.') && ($codes === [] || in_array($entry['code'], $codes, true))) {
            $faults[] = $entry['code'].' '.$entry['url'];
        }
    }
    sort($faults);

    return $faults;
}

/**
 * One kind of the ingredient deltas' codes, in order.
 *
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function spec035DeltaCodes(array $array, string $kind): array
{
    $codes = [];
    foreach (spec020Deltas($array) as $delta) {
        foreach ($delta['validationDeltas'][$kind] as $entry) {
            $codes[] = $entry['code'];
        }
    }

    return $codes;
}

/**
 * The report's statuses with one code, by value: a string parameter, so that a code this build
 * does not have yet reads as absent rather than as a comparison the analyser can decide.
 *
 * @return list<ValidationStatus>
 */
function spec035WithCode(VerificationReport $report, string $code): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === $code));
}

/** The case with this value, or null: the same reason as spec035WithCode(). */
function spec035Case(string $value): ?StatusCode
{
    return StatusCode::tryFrom($value);
}

const SPEC035_REDACTION_CODES = ['assertion.action.redacted', 'assertion.selfRedacted', 'assertion.notRedacted', 'general.error'];

it('AC1: a redacted ingredient assertion is accepted', function (): void {
    foreach (['redacted-with-action', 'redacted-without-action'] as $probe) {
        $report = spec020Verify("redactions/{$probe}.png", SPEC035_SETTINGS);
        $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
        expect(spec035Oracle("{$probe}--0.28.0")['validation_state'])->toBe('Trusted', $probe)
            ->and(spec035Oracle("{$probe}--0.27.22")['validation_state'])->toBe('Trusted', $probe)
            ->and($report->result->state->value)->toBe('Trusted', $probe)
            ->and(spec035Faults($report))->toBe([], $probe)
            // present, and informational, as 0.28.0 records it
            ->and(spec035DeltaCodes($report->toArray(), 'informational'))->toBe(['ingredient.claimSignature.validated'], $probe)
            ->and(spec035DeltaCodes(spec035Oracle("{$probe}--0.28.0"), 'informational'))->toBe(['ingredient.claimSignature.validated'], $probe)
            ->and(spec035DeltaCodes($report->toArray(), 'failure'))->toBe([], $probe);
        foreach (['assertion.missing', 'ingredient.manifest.mismatch', 'general.error'] as $absent) {
            expect(in_array($absent, $codes, true))->toBeFalse("{$probe}: {$absent}");
        }
    }
})->group('SPEC-035');

it('AC2: an ingredient\'s claim signature that does not match', function (): void {
    $report = spec020Verify('redactions/claim-signature-changed.png', SPEC035_SETTINGS);
    $oracle = spec035Oracle('claim-signature-changed--0.28.0');
    $scoped = spec035WithCode($report, 'ingredient.claimSignature.mismatch');

    expect(spec035DeltaCodes($report->toArray(), 'failure'))->toBe(['ingredient.claimSignature.mismatch'])
        ->and(spec035DeltaCodes($oracle, 'failure'))->toBe(['ingredient.claimSignature.mismatch'])
        ->and(spec035DeltaCodes(spec035Oracle('claim-signature-changed--0.27.22'), 'failure'))->toBe(['ingredient.claimSignature.mismatch'])
        ->and($scoped)->toHaveCount(1)
        ->and(($scoped[0] ?? null)?->ingredientUri)->toContain('c2pa.ingredient')
        ->and(spec035Faults($report))->toBe(spec035OracleFaults($oracle))
        ->and($report->result->state->value)->toBe($oracle['validation_state']);
})->group('SPEC-035');

it('AC3–AC5 together: redacted.png holds the three claim-level codes, as c2patool 0.28.0', function (): void {
    $report = spec020Verify('ingredient-manifest/redacted.png', 'ingredient-manifest/throw-away-root.settings.json');
    $theirs = spec035OracleFaults(spec035Oracle('ingredient-manifest-redacted--0.28.0'), SPEC035_REDACTION_CODES);

    expect($theirs)->toHaveCount(3)
        ->and(array_values(array_filter(spec035Faults($report), static fn (string $f): bool => in_array(strstr($f, ' ', true), SPEC035_REDACTION_CODES, true))))->toBe($theirs)
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-035');

it('AC3: a redaction of an actions assertion', function (): void {
    // on its own: SPEC-010's claim-redacted.png, a relative entry, verbatim as the url (amendment 2)
    $report = spec020Verify('binding/claim-redacted.png');
    $mine = array_values(array_filter(spec035Faults($report), static fn (string $f): bool => in_array(strstr($f, ' ', true), SPEC035_REDACTION_CODES, true)));
    expect($mine)->toBe(['assertion.action.redacted self#jumbf=c2pa.assertions/c2pa.actions.v2'])
        ->and($mine)->toBe(spec035OracleFaults(spec035Oracle('binding-claim-redacted--0.28.0'), SPEC035_REDACTION_CODES))
        ->and($report->result->state->value)->toBe('Invalid');
    // and in redacted.png, on the absolute url
    $label = spec020Verify('ingredient-manifest/redacted.png', 'ingredient-manifest/throw-away-root.settings.json');
    expect(spec035Faults($label))->toContain('assertion.action.redacted self#jumbf=/c2pa/'.($label->store?->active->label ?? 'x').'/c2pa.assertions/c2pa.actions.v2');
})->group('SPEC-035');

it('AC4: self-redaction', function (): void {
    $report = spec020Verify('ingredient-manifest/redacted.png', 'ingredient-manifest/throw-away-root.settings.json');
    expect(spec035Faults($report))->toContain('assertion.selfRedacted self#jumbf=/c2pa/'.($report->store?->active->label ?? 'x').'/c2pa.assertions/c2pa.actions.v2');
})->group('SPEC-035');

it('AC5: declared redacted but still there', function (): void {
    $report = spec020Verify('ingredient-manifest/redacted.png', 'ingredient-manifest/throw-away-root.settings.json');
    expect(spec035Faults($report))->toContain('assertion.notRedacted self#jumbf=/c2pa/'.($report->store?->active->label ?? 'x').'/c2pa.assertions/c2pa.actions.v2');
})->group('SPEC-035');

it('AC6: a mismatch without a redaction stays a mismatch', function (): void {
    // a guard, green before and after: SPEC-021's seam, a broken box hash in a store without redactions
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, spec021Mismatch());
    expect($codes)->toBe(['ingredient.manifest.mismatch']);
})->group('SPEC-035');

it('AC7: nothing else moves', function (): void {
    // a guard: the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 129b;
    // here, the ingredient files without a redaction keep c2patool's ingredient codes
    foreach (['ingredient-manifest/ingredient-signature-broken.jpg' => 'ingredient-manifest/ingredient-signature-broken.json'] as $file => $oracle) {
        $report = spec020Verify($file, 'ingredient-manifest/throw-away-root.settings.json');
        expect(spec035DeltaCodes($report->toArray(), 'success'))->toBe(spec035DeltaCodes(spec020Oracle($oracle), 'success'), $file)
            ->and(spec035DeltaCodes($report->toArray(), 'informational'))->toBe(spec035DeltaCodes(spec020Oracle($oracle), 'informational'), $file);
    }
    $stream = fopen(Corpus::fixtures().'/c2pa-rs/CACA.jpg', 'rb');
    assert($stream !== false);
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, (new Verifier)->verify($stream)->result->statuses);
    expect(array_filter($codes, static fn (string $c): bool => str_starts_with($c, 'ingredient.claimSignature.')))->toBe([]);
})->group('SPEC-035');

it('AC8: the vocabulary grows by six codes, verbatim', function (): void {
    $want = [
        'assertion.action.redacted' => ['AssertionActionRedacted', true],
        'assertion.notRedacted' => ['AssertionNotRedacted', true],
        'assertion.selfRedacted' => ['AssertionSelfRedacted', true],
        'ingredient.claimSignature.mismatch' => ['IngredientClaimSignatureMismatch', true],
        'ingredient.claimSignature.missing' => ['IngredientClaimSignatureMissing', true],
        'ingredient.claimSignature.validated' => ['IngredientClaimSignatureValidated', false],
    ];
    $found = [];
    foreach ($want as $value => $expected) {
        $case = spec035Case($value);
        if ($case !== null) {
            $found[$case->value] = [$case->name, $case->isFailure()];
        }
    }
    ksort($found);
    expect($found)->toBe($want)
        ->and(spec035Case('ingredient.claimSignature.validated')?->isInformational())->toBeTrue();
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($want as [$name]) {
        expect(in_array("Report\\StatusCode :: const {$name}", $surface, true))->toBeTrue($name);
    }
})->group('SPEC-035');
