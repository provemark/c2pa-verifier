<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/*
 * SPEC-039: claimSignature.insideValidity, beside every verified signature, as c2patool reports it.
 * The recorded c2patool reports are the oracle: SPEC013_CORPUS (tests/Pest.php, no settings), the
 * multi-manifest files of SPEC-021 (spec020Multi(), SPEC021_SETTINGS), and both versions on
 * profile/expired.png under tests/Fixtures/c2patool/inside-validity/.
 */

const SPEC039_INSIDE = 'claimSignature.insideValidity';
const SPEC039_VALIDATED = 'claimSignature.validated';

/**
 * A success list as "code url", in order.
 *
 * @return list<string>
 */
function spec039Successes(VerificationReport $report): array
{
    $out = [];
    foreach ($report->result->statuses as $status) {
        if ($status->ingredientUri === null && $status->code->isSuccess()) {
            $out[] = $status->code->value.' '.$status->url;
        }
    }

    return $out;
}

/**
 * The codes of one list of a recorded report's active manifest, in order.
 *
 * @param  array<string, mixed>  $oracle
 * @return list<string>
 */
function spec039OracleCodes(array $oracle, string $kind): array
{
    $results = $oracle['validation_results'] ?? [];
    assert(is_array($results));
    $active = $results['activeManifest'] ?? [];
    assert(is_array($active));

    return array_values(array_map(static fn (mixed $e): string => is_array($e) && is_string($e['code'] ?? null) ? $e['code'] : '', (array) ($active[$kind] ?? [])));
}

/** Ours: insideValidity directly before validated, on the same url; or neither. */
function spec039Paired(VerificationReport $report, string $name): bool
{
    $list = spec039Successes($report);
    $validated = array_values(array_filter($list, static fn (string $s): bool => str_starts_with($s, SPEC039_VALIDATED.' ')));
    $inside = array_values(array_filter($list, static fn (string $s): bool => str_starts_with($s, SPEC039_INSIDE.' ')));
    expect(count($inside))->toBe(count($validated), $name);
    foreach ($validated as $v) {
        $at = array_search($v, $list, true);
        expect($at !== false && $at > 0 && $list[$at - 1] === SPEC039_INSIDE.substr($v, strlen(SPEC039_VALIDATED)))->toBeTrue("{$name}: {$v}");
    }

    return $validated !== [];
}

it('AC1: beside every verified signature, as c2patool', function (): void {
    $withIt = 0;
    foreach (SPEC013_CORPUS as $name => $carrier) {
        $oracle = spec020Oracle($name.'.json');
        $theirs = spec039OracleCodes($oracle, 'success');
        $report = spec020Verify($carrier);
        if ($name === 'variants/json-broken') {
            // amendment 2: a parse fault stops this verifier before the signature, where c2patool goes on
            // (SPEC013_SUBSET_ONLY) — no validated here, so no insideValidity either
            expect(spec039Paired($report, $name))->toBeFalse();

            continue;
        }
        $ours = spec039Paired($report, $name);
        expect($ours)->toBe(in_array(SPEC039_INSIDE, $theirs, true), $name);
        $withIt += $ours ? 1 : 0;
    }
    expect($withIt)->toBeGreaterThan(0);
})->group('SPEC-039');

it('AC2: an expired signer does not get it, as c2patool 0.29.0', function (): void {
    // amendment 3: c2pa withholds it beside signingCredential.expired since 0.91.11 (C2PA 2.4 §15.8)
    $oracle = spec020Oracle('inside-validity/profile-expired-no-settings--0.29.0.json');
    expect(spec039OracleCodes($oracle, 'success'))->not->toContain(SPEC039_INSIDE)
        ->and(spec039OracleCodes($oracle, 'failure'))->toContain('signingCredential.expired');
    $report = spec020Verify('profile/expired.png');
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
    expect(in_array('signingCredential.expired', $codes, true))->toBeTrue()
        ->and(in_array(SPEC039_INSIDE, $codes, true))->toBeFalse()
        ->and($report->result->state->value)->toBe('Invalid');
})->group('SPEC-039');

it('AC3: no verified signature, no code', function (): void {
    foreach (['variants/claim-title-changed' => 'cose/claim-title-changed.png', 'variants/signature-changed' => 'cose/signature-changed.png'] as $name => $carrier) {
        expect(spec039OracleCodes(spec020Oracle($name.'.json'), 'success'))->not->toContain(SPEC039_INSIDE);
        $report = spec020Verify($carrier);
        $codes = array_map(static fn (string $s): string => (string) strstr($s, ' ', true), spec039Successes($report));
        expect(in_array(SPEC039_INSIDE, $codes, true))->toBeFalse($name)
            ->and(in_array(SPEC039_VALIDATED, $codes, true))->toBeFalse($name);
    }
    // and the positive twin, so the absence above is not an absence everywhere
    expect(spec039Paired(spec020Verify('fixture-signed.png'), 'png'))->toBeTrue();
})->group('SPEC-039');

it('AC4: ingredient manifests alike', function (): void {
    // amendments 3 and 4: never beside signingCredential.expired, and beside every claimSignature.validated, as 0.29.0's
    $ours = 0;
    $theirs = 0;
    $withheld = 0;
    foreach (spec020Multi() as $name => $relative) {
        $report = spec020Verify($relative, SPEC021_SETTINGS);
        $oracle = spec020Oracle(DRIFT_ORACLE.'/'.(string) preg_replace('/\.[a-z]+$/', '.json', $relative));
        foreach ([[spec020Deltas($report->toArray()), &$ours], [spec020Deltas($oracle), &$theirs]] as [$deltas, &$count]) {
            foreach ($deltas as $delta) {
                $codes = array_column($delta['validationDeltas']['success'], 'code');
                $expired = in_array('signingCredential.expired', array_column($delta['validationDeltas']['failure'], 'code'), true);
                $validated = in_array(SPEC039_VALIDATED, $codes, true);
                $inside = in_array(SPEC039_INSIDE, $codes, true);
                // amendment 4: never beside expired, and validated never without it (SPEC-010 amendment 7 withholds
                // validated on a refused credential, so the code may now stand alone, as in 0.29.0)
                expect($inside && $expired)->toBeFalse("{$name}: {$delta['ingredientAssertionURI']}")
                    ->and(! $validated || $inside)->toBeTrue("{$name}: {$delta['ingredientAssertionURI']}");
                $count += in_array(SPEC039_INSIDE, $codes, true) ? 1 : 0;
                $withheld += $expired ? 1 : 0;
            }
        }
        unset($count);
    }
    expect($theirs)->toBeGreaterThan(0)
        ->and($ours)->toBeGreaterThan(0)
        ->and($withheld)->toBeGreaterThan(0);   // an expired ingredient signer is among them, so the rule is exercised
})->group('SPEC-039');

it('AC5: nothing else moves', function (): void {
    // a guard: the corpus is the drift alarms' (SPEC-013 AC10–AC13) and the before/after run of step 135b;
    // here, one plain file keeps its verdict and its failures
    $report = spec020Verify('fixture-signed.jpg');
    $failures = array_values(array_map(static fn (ValidationStatus $s): string => $s->code->value, array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code->isFailure())));
    expect($report->result->state->value)->toBe('Valid')
        ->and($failures)->toBe(['signingCredential.untrusted']);
})->group('SPEC-039');

it('AC6: the vocabulary grows by one code, verbatim', function (): void {
    $case = StatusCode::tryFrom(spec039Value());
    expect($case?->name)->toBe('ClaimSignatureInsideValidity')
        ->and($case?->isSuccess())->toBeTrue();
    $surface = (array) file(dirname(__DIR__, 2).'/Fixtures/api/public-surface.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(in_array('Report\StatusCode :: const ClaimSignatureInsideValidity', $surface, true))->toBeTrue();
})->group('SPEC-039');

function spec039Value(): string
{
    return SPEC039_INSIDE;
}

it('AC7: a signer kept in time keeps it', function (): void {
    // amendment 3, the control: the signer expired, a trusted time-stamp from before keeps it in time
    $oracle = spec020Oracle('tsa-matrix/expired-signer-trusted-tsa--0.29.0.json');
    expect(spec039OracleCodes($oracle, 'success'))->toContain(SPEC039_INSIDE)
        ->and($oracle['validation_state'] ?? null)->toBe('Trusted');
    $report = spec020Verify('timestamp/tsa-matrix/expired-signer-trusted-tsa.png', 'timestamp/tsa-matrix/expired-signer-trusted-tsa.settings.json');
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
    expect(spec039Paired($report, 'expired-signer-trusted-tsa'))->toBeTrue()
        ->and(in_array('signingCredential.expired', $codes, true))->toBeFalse()
        ->and($report->result->state->value)->toBe('Trusted');
})->group('SPEC-039');
