<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-064: the time-stamp assertion. The probes come from bin/make-timestamp-assertion-variants.php under
 * tests/Fixtures/timestamp/assertion/, judged after the parent's short-lived signer expired; both c2patool
 * versions' answers are under tests/Fixtures/c2patool/timestamp-assertion/.
 */

function spec064Verify(string $probe): VerificationReport
{
    $stream = fopen(Corpus::fixtures()."/timestamp/assertion/{$probe}.png", 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$probe}");
    }

    return (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures()."/timestamp/assertion/{$probe}.settings.json")));
}

function spec064Oracle(string $probe, string $version): ?string
{
    $json = json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/timestamp-assertion/{$probe}--{$version}.json"), true);

    return is_array($json) && is_string($json['validation_state'] ?? null) ? $json['validation_state'] : null;
}

/** @return list<string> the codes of the statuses scoped to an ingredient (the parent), or to the active manifest */
function spec064Codes(VerificationReport $report, bool $ingredient): array
{
    return array_values(array_unique(array_map(
        static fn (ValidationStatus $s): string => $s->code->value,
        array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => ($s->ingredientUri !== null) === $ingredient),
    )));
}

/** @return list<ValidationStatus> the timeStamp statuses whose url names a time-stamp assertion: a token taken from one */
function spec064FromAssertion(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => str_starts_with($s->code->value, 'timeStamp.') && str_contains($s->url, '/c2pa.assertions/c2pa.time-stamp')));
}

it('AC1: a later token keeps an earlier manifest alive, in a standard and an update manifest, in either form', function (string $probe): void {
    $report = spec064Verify($probe);
    $from = array_map(static fn (ValidationStatus $s): string => $s->code->value, spec064FromAssertion($report));

    expect($report->result->state->value)->toBe('Trusted', $probe)
        ->and([...spec064Codes($report, true), ...spec064Codes($report, false)])->not->toContain('signingCredential.expired')
        ->and($from)->toContain('timeStamp.validated')
        ->and($from)->toContain('timeStamp.trusted');
})->with(['raw', 'structure', 'update-raw'])->group('SPEC-064');

it('AC2: without a token for its label, the same parent is expired', function (string $probe): void {
    $report = spec064Verify($probe);

    expect($report->result->state->value)->toBe('Invalid', $probe)
        ->and([...spec064Codes($report, true), ...spec064Codes($report, false)])->toContain('signingCredential.expired')
        ->and(array_filter(spec064FromAssertion($report), static fn (ValidationStatus $s): bool => $s->code === StatusCode::TimeStampTrusted))->toBe([]);
})->with(['other-label', 'update-other-label'])->group('SPEC-064');

it('AC3: an untrusted or mismatched token does not help', function (string $probe, string $code): void {
    $report = spec064Verify($probe);
    $from = array_map(static fn (ValidationStatus $s): string => $s->code->value, spec064FromAssertion($report));

    expect($report->result->state->value)->toBe('Invalid', $probe)
        ->and([...spec064Codes($report, true), ...spec064Codes($report, false)])->toContain('signingCredential.expired')
        ->and($from)->toContain($code)
        ->and($from)->not->toContain('timeStamp.trusted');
})->with([
    'a token over other bytes' => ['wrong-data', 'timeStamp.mismatch'],
    'a TSA not in the settings' => ['untrusted', 'timeStamp.untrusted'],
])->group('SPEC-064');

it('AC4: a malformed time-stamp assertion, or a second one, is assertion.timestamp.malformed, and its tokens are not used', function (string $probe, string $says): void {
    $report = spec064Verify($probe);
    $malformed = array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->value === 'assertion.timestamp.malformed'));

    expect($report->result->state->value)->toBe('Invalid', $probe)
        ->and($malformed)->not->toBe([])
        ->and(implode(' | ', array_map(static fn (ValidationStatus $s): string => $s->explanation, $malformed)))->toContain($says)
        ->and([...spec064Codes($report, true), ...spec064Codes($report, false)])->toContain('signingCredential.expired');
})->with([
    'an array' => ['array', 'not a map'],
    'two assertions' => ['two-assertions', 'at most one'],
])->group('SPEC-064');

it('AC5: a header token that passed is not replaced by an assertion\'s', function (): void {
    $report = spec064Verify('header-wins');

    expect($report->result->state->value)->toBe('Trusted')
        ->and([...spec064Codes($report, true), ...spec064Codes($report, false)])->not->toContain('signingCredential.expired')
        ->and(spec064FromAssertion($report))->toBe([]);
})->group('SPEC-064');

it('AC6: never more lenient than c2patool 0.28.1, which does not judge the parent again (amendment 1)', function (): void {
    $rank = ['Invalid' => 0, 'Valid' => 1, 'Trusted' => 2];
    foreach (['raw', 'structure', 'other-label', 'wrong-data', 'untrusted', 'array', 'two-assertions', 'update-raw', 'update-other-label', 'header-wins'] as $probe) {
        $mine = spec064Verify($probe)->result->state->value;
        $theirs = spec064Oracle($probe, '0.28.1');
        expect($rank[$mine])->toBeLessThanOrEqual($theirs === null ? 0 : $rank[$theirs], $probe);
    }
    // 0.28.1 is Trusted with any token or none; 0.27.22 shows the expired parent: the control is AC2
    expect(spec064Oracle('other-label', '0.28.1'))->toBe('Trusted')
        ->and(spec064Oracle('other-label', '0.27.22'))->toBe('Invalid')
        ->and(spec064Oracle('array', '0.28.1'))->toBeNull();
})->group('SPEC-064');

it('AC7: one code, verbatim, a failure', function (): void {
    expect(StatusCode::from('assertion.timestamp.malformed')->isFailure())->toBeTrue();
})->group('SPEC-064');

it('AC1 (amendment 1): a real update manifest\'s token, over the whole COSE_Sign1 as earlier c2pa-rs took it, validates', function (): void {
    $stream = fopen(Corpus::fixtures().'/c2pa-rs/update_manifest.jpg', 'rb');
    if ($stream === false) {
        throw new RuntimeException('cannot open update_manifest.jpg');
    }
    $report = (new Verifier)->verify($stream, TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/trust/full.settings.json')));
    $from = array_map(static fn (ValidationStatus $s): string => $s->code->value, spec064FromAssertion($report));

    // the imprint matches; its TSA (DigiCert) is no tsa anchor in these settings, so the time is not used
    expect($from)->toContain('timeStamp.validated')
        ->and($from)->not->toContain('timeStamp.mismatch')
        ->and($from)->toContain('timeStamp.untrusted')
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-064');
