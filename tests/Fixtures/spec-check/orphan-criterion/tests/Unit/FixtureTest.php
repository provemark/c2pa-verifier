<?php

declare(strict_types=1);

// A test naming a criterion its spec traces: fine.
it('AC1: one', function (): void {
    $name = 'x';
    expect("{$name}")->toBe('x');   // a brace inside a string must not throw the statement boundaries off
})->group('SPEC-001');

// A row whose label carries more than the number still counts: fine.
it('AC2: two', function (): void {
    expect(true)->toBeTrue();
})->group('SPEC-001');

// A test with no criterion in its name is outside this rule: fine.
it('a helper-level test', function (): void {
    expect(true)->toBeTrue();
})->group('SPEC-001');

// Two orphans: AC3 and, through the spec named in the test's own title, AC9.
it('AC3: three, never written into the spec', function (): void {
    expect(true)->toBeTrue();
})->group('SPEC-001');

test('SPEC-001 AC9: nine', function (): void {
    expect(true)->toBeTrue();
})->group('SPEC-001');
