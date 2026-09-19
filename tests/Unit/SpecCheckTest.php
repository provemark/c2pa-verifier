<?php

declare(strict_types=1);

/*
 * SPEC-000: the traceability checker, tested on fixture trees under
 * tests/Fixtures/spec-check/. Each tree is the smallest specs/ + tests/ pair
 * that triggers one criterion. The fixture "test" files are never collected
 * by Pest (phpunit.xml lists tests/Unit and tests/Integration only) and are
 * skipped by the checker itself when it runs on the repository root.
 */

require_once dirname(__DIR__, 2).'/bin/spec-check.php';

function specCheckFixture(string $case): string
{
    return dirname(__DIR__).'/Fixtures/spec-check/'.$case;
}

it('AC1: reports every spec with its status and no findings on a clean tree', function (): void {
    $result = specCheck(specCheckFixture('clean'));

    expect($result->findings)->toBe([])
        ->and($result->specs)->toBe(['SPEC-001' => 'approved'])
        ->and($result->render())->toContain('spec SPEC-001 approved');
})->group('SPEC-000');

it('AC2: a group that names no existing spec is a finding', function (): void {
    $result = specCheck(specCheckFixture('unknown-group'));

    expect($result->findings)->toBe([
        'SPEC-042: group carried by tests/Unit/FixtureTest.php but no specs/SPEC-042-*.md exists',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-000');

it('AC3: a test file without any spec group is a finding', function (): void {
    $result = specCheck(specCheckFixture('no-group'));

    expect($result->findings)->toBe([
        "tests/Unit/BareTest.php: no ->group('SPEC-###') call",
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-000');

it('AC4: a draft spec whose group is already carried by a test is a finding', function (): void {
    $result = specCheck(specCheckFixture('draft-with-tests'));

    expect($result->findings)->toBe([
        'SPEC-001: status draft but tests/Unit/FixtureTest.php carries its group — tests precede approval',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-000');

it('AC5: an implemented spec without a test carrying its group is a finding', function (): void {
    $result = specCheck(specCheckFixture('implemented-no-test'));

    expect($result->findings)->toBe([
        'SPEC-001: status implemented but no test carries its group',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-000');

it('AC6: an implemented spec with an unfilled Traceability row is a finding naming the criterion', function (): void {
    $result = specCheck(specCheckFixture('implemented-empty-traceability'));

    expect($result->findings)->toBe([
        'SPEC-001: status implemented but Traceability row AC2 names no test',
    ])->and($result->exitCode())->toBe(1);
})->group('SPEC-000');

it('AC7: a spec without a recognisable status is a finding and is not treated as any status', function (): void {
    $result = specCheck(specCheckFixture('bad-status'));

    // Exactly these two, so neither file was carried into the AC4/AC5 checks.
    expect($result->findings)->toBe([
        'specs/SPEC-001-no-status.md: Status missing',
        'specs/SPEC-002-capital.md: Status "Draft" is not one of draft, approved, implemented, superseded',
    ])
        ->and($result->specs)->toBe([])
        ->and($result->exitCode())->toBe(1);
})->group('SPEC-000');

it('AC8: a clean tree exits 0 and says OK with the counts', function (): void {
    $result = specCheck(specCheckFixture('clean'));

    expect($result->exitCode())->toBe(0)
        ->and($result->render())->toContain('OK: 1 spec(s), 1 test file(s)');
})->group('SPEC-000');

it('AC8: a tree with several problems reports one line per finding and exits 1', function (): void {
    $result = specCheck(specCheckFixture('dirty'));

    expect($result->exitCode())->toBe(1)
        ->and(count($result->findings))->toBe(3)
        ->and($result->render())->toContain('FAIL: 3 finding(s)');

    foreach ($result->findings as $finding) {
        expect($finding)->toMatch('/^(SPEC-\d{3}|[^:\s]+\.(php|md)): \S/');
    }
})->group('SPEC-000');

it('AC9: a multi-argument group call counts for every spec it names', function (): void {
    $result = specCheck(specCheckFixture('multi-group'));

    expect($result->findings)->toBe([])
        ->and($result->specs)->toBe(['SPEC-001' => 'approved', 'SPEC-004' => 'approved']);
})->group('SPEC-000');

it('AC8: this repository itself is clean, with the fixture trees skipped', function (): void {
    $result = specCheck(dirname(__DIR__, 2));

    expect($result->findings)->toBe([])
        ->and($result->specs)->toHaveKey('SPEC-000')
        ->and($result->exitCode())->toBe(0);
})->group('SPEC-000');
