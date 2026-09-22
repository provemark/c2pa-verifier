<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Manifest\ActionsCheck;
use Provemark\C2paVerifier\Report\StatusCode;
use Provemark\C2paVerifier\Report\ValidationStatus;
use Provemark\C2paVerifier\Tests\Support\Corpus;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\VerificationReport;
use Provemark\C2paVerifier\Verifier\Verifier;

/*
 * SPEC-018: the actions assertion — a 2.x manifest opens with c2pa.created
 * or c2pa.opened, or it is not valid. Oracles: c2patool 0.27.22 on the
 * absence variants (tests/Fixtures/c2patool/absence/) and on the corpora;
 * c2pa-rs claim.rs verify_actions for the v1 rule.
 */

function spec018Verify(string $relative, ?TrustSettings $settings = null): VerificationReport
{
    $stream = fopen(Corpus::fixtures().'/'.$relative, 'rb');
    if ($stream === false) {
        throw new RuntimeException("cannot open {$relative}");
    }

    return (new Verifier)->verify($stream, $settings);
}

/** @return list<ValidationStatus> */
function spec018Malformed(VerificationReport $report): array
{
    return array_values(array_filter($report->result->statuses, static fn (ValidationStatus $s): bool => $s->code === StatusCode::AssertionActionMalformed));
}

/** @return array<string, mixed> */
function spec018Oracle(string $name): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(Corpus::fixtures()."/c2patool/absence/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<array{code: string, url: string}> */
function spec018OracleFailures(array $oracle): array
{
    $out = [];
    foreach ((array) ($oracle['validation_status'] ?? []) as $status) {
        assert(is_array($status) && is_string($status['code']) && is_string($status['url']));
        $out[] = ['code' => $status['code'], 'url' => $status['url']];
    }

    return $out;
}

test('SPEC-018 AC1: the audit\'s file — assertion.action.malformed on the manifest, Invalid, even when trusted', function (): void {
    $root = TrustSettings::fromJson((string) file_get_contents(Corpus::fixtures().'/absence/throw-away-root.settings.json'));
    foreach ([null => 'no-actions', 'root' => 'no-actions-trusted'] as $mode => $oracleName) {
        $report = spec018Verify('absence/no-actions.png', $mode === 'root' ? $root : null);
        $label = $report->store?->active->label ?? '';
        expect($report->result->state->value)->toBe('Invalid', $oracleName);   // Valid until step 49b: the verdict is the red line
        $malformed = spec018Malformed($report);
        expect($malformed)->toHaveCount(1, $oracleName)
            ->and($malformed[0]->url)->toBe("self#jumbf=/c2pa/{$label}")
            ->and($malformed[0]->explanation)->toContain('no actions assertion')
            ->and($report->result->checksPerformed)->toBe(['signature', 'certificate', 'trust', 'hashedUris', 'actions', 'dataHash']);
        $theirs = array_values(array_filter(spec018OracleFailures(spec018Oracle($oracleName)), static fn (array $f): bool => $f['code'] === 'assertion.action.malformed'));
        expect($theirs)->toHaveCount(1)
            ->and($theirs[0]['url'])->toBe($malformed[0]->url)
            ->and(spec018Oracle($oracleName)['validation_state'])->toBe('Invalid');
        if ($mode === 'root') {
            expect(in_array('signingCredential.trusted', array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses), true))->toBeTrue();
        }
    }
})->group('SPEC-018');

test('SPEC-018 AC2: every corpus verdict is unchanged, and actions is in checks_performed of every readable manifest', function (): void {
    $v2 = ['fixture-signed.jpg', 'fixture-signed.png', 'fixture-signed.webp', 'c2pa-rs/C_with_CAWG_data.jpg', 'c2pa-rs/no_alg.jpg', 'c2pa-rs/CACA.jpg', 'writers/openai-20260826-c2pa_2x.png', 'writers/google-20250919-pixel10-npld-picnic-table.jpg'];
    $v1Odd = ['public-testfiles/truepic-20230212-camera.jpg', 'public-testfiles/truepic-20230212-landscape.jpg', 'public-testfiles/truepic-20230212-library.jpg', 'public-testfiles/nikon-20221019-building.jpeg', 'writers/c2pa-rs-cawg_ica.jpg', 'writers/trustnxt-20260113-icon-signed-timestamp.jpg', 'c2pa-rs/exp-test1.png'];
    foreach ([...$v2, ...$v1Odd] as $relative) {
        $report = spec018Verify($relative);
        $version = $report->store?->active->claim->version;
        expect($version)->toBe(in_array($relative, $v2, true) ? 2 : 1, $relative)
            ->and(spec018Malformed($report))->toBe([], $relative)
            ->and($report->result->checksPerformed)->toContain('actions', $relative);
    }
    // the drift alarms themselves (SPEC-013 AC10–AC13) run in their own group; here the two verdict-bearing facts they rest on
    expect(spec018Verify('fixture-signed.png')->result->state->value)->toBe('Valid')
        ->and(spec018Verify('writers/trustnxt-20260113-icon-signed-timestamp.jpg')->result->state->value)->toBe('Valid');
})->group('SPEC-018');

test('SPEC-018 AC3: a first action that is not an opening, and an empty actions list', function (): void {
    $edited = spec018Verify('absence/actions-first-edited.png');
    $label = $edited->store?->active->label ?? '';
    $malformed = spec018Malformed($edited);
    expect($edited->result->state->value)->toBe('Invalid')
        ->and($malformed)->toHaveCount(1)
        ->and($malformed[0]->url)->toBe("self#jumbf=/c2pa/{$label}")
        ->and($malformed[0]->explanation)->toContain('c2pa.edited');
    $theirs = array_values(array_filter(spec018OracleFailures(spec018Oracle('actions-first-edited')), static fn (array $f): bool => $f['code'] === 'assertion.action.malformed'));
    expect($theirs)->toHaveCount(1)->and($theirs[0]['url'])->toBe($malformed[0]->url);

    // c2patool exits 1 on the empty list ("No Action array in Actions", no report): a refusal here too, on the assertion's url
    $empty = spec018Verify('absence/actions-empty.png');
    $malformed = spec018Malformed($empty);
    expect($empty->result->state->value)->toBe('Invalid')
        ->and($malformed)->toHaveCount(1)
        ->and($malformed[0]->url)->toBe("self#jumbf=/c2pa/{$label}/c2pa.assertions/c2pa.actions.v2")
        ->and($malformed[0]->explanation)->toContain('empty')
        ->and(trim((string) file_get_contents(Corpus::fixtures().'/c2patool/absence/actions-empty.stderr.txt')))->toContain('No Action array in Actions');
})->group('SPEC-018');

test('SPEC-018 AC4: a gathered actions assertion counts; two in a v1 claim do not', function (): void {
    // the fixture's own c2pa.actions.v2 sits in gathered_assertions and opens with c2pa.created
    $fixture = spec018Verify('fixture-signed.png');
    $gathered = array_map(static fn ($u): string => $u->url, $fixture->store?->active->claim->gatheredAssertions ?? []);
    expect(implode(',', $gathered))->toContain('c2pa.actions.v2')
        ->and(spec018Malformed($fixture))->toBe([]);

    // the v1 rule through the seam: no signed v1 fixture exists, so the lists are given as the check would read them
    $check = new ActionsCheck;
    $url = 'self#jumbf=/c2pa/urn:uuid:v1';
    $one = ['action' => 'c2pa.edited'];
    $statuses = $check->checkAssertions($url, 1, [
        ['url' => $url.'/c2pa.assertions/c2pa.actions', 'data' => ['actions' => [$one]]],
        ['url' => $url.'/c2pa.assertions/c2pa.actions__1', 'data' => ['actions' => [$one]]],
    ]);
    expect($statuses)->toHaveCount(1)
        ->and($statuses[0]->code)->toBe(StatusCode::AssertionActionMalformed)
        ->and($statuses[0]->url)->toBe($url)
        ->and($statuses[0]->explanation)->toContain('v1');
    // one v1 actions assertion opening with c2pa.edited: nothing (c2pa-rs's default, no strict_v1_validation)
    expect($check->checkAssertions($url, 1, [['url' => $url.'/c2pa.assertions/c2pa.actions', 'data' => ['actions' => [$one]]]]))->toBe([]);
    // and none at all in a v1 claim: nothing
    expect($check->checkAssertions($url, 1, []))->toBe([]);
    // the same two as v2: the first does not open → the manifest's url
    $v2 = $check->checkAssertions($url, 2, [['url' => $url.'/c2pa.assertions/c2pa.actions.v2', 'data' => ['actions' => [$one]]]]);
    expect($v2)->toHaveCount(1)->and($v2[0]->url)->toBe($url);
})->group('SPEC-018');

test('SPEC-018 AC5: an actions assertion the claim did not vouch for is not read', function (): void {
    $report = spec018Verify('binding/hashed-uris-two-changed.png');
    $codes = array_map(static fn (ValidationStatus $s): string => $s->code->value, $report->result->statuses);
    expect(in_array('assertion.hashedURI.mismatch', $codes, true))->toBeTrue()
        ->and(spec018Malformed($report))->toBe([])
        ->and($report->result->checksPerformed)->toContain('actions');
})->group('SPEC-018');

test('SPEC-018 AC6: malformed content is refused naming the field; as claim v1 the same six pass', function (): void {
    $check = new ActionsCheck;
    $cases = [
        'actions absent' => [['digitalSourceType' => 'x'], 'actions'],
        'actions a map' => [['actions' => ['action' => 'c2pa.created']], 'actions'],
        'an entry not a map' => [['actions' => ['c2pa.created']], 'actions[0]'],
        'action not text' => [['actions' => [['action' => 7]]], 'action'],
        'action empty' => [['actions' => [['action' => '']]], 'action'],
        'too many' => [['actions' => array_fill(0, 10001, ['action' => 'c2pa.created'])], 'actions'],
    ];
    foreach ($cases as $name => [$data, $field]) {
        $faults = $check->checkData($data, 2);
        expect($faults)->not->toBe([], $name)
            ->and(implode(' ', $faults))->toContain($field, $name)
            ->and($check->checkData($data, 1))->toBe([], $name);
    }
    expect($check->checkData(['actions' => [['action' => 'c2pa.opened']]], 2))->toBe([])
        ->and($check->checkData(['actions' => array_fill(0, 10000, ['action' => 'c2pa.created'])], 2))->toBe([]);
})->group('SPEC-018');
