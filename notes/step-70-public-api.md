# Step 70 — The public API, tests first

*2026-09-22.* SPEC-025 was approved the same day, with both of its
decisions taken: `VerificationReport::$store` is `@internal`, and so are
the seven exception types a caller can never meet, leaving
`TrustException` as the ninth class of the contract. This step is the red
phase — seven failing tests and the recorded surface they compare against,
no implementation.

```
Tests: 7 failed, 374 passed (7294 assertions)
```

## The recorded surface is a file

`tests/Fixtures/api/public-surface.txt`, ninety-one lines, one per public
symbol of the nine contract classes:

```
Cli\Command :: const USAGE
Cli\Command :: method __construct
Cli\Command :: method run
…
```

It is a file rather than an array inside the test for the same reason
SPEC-023 put the shipped-paths decision in `.gitattributes`: a promise that
changes should change **visibly**, as a diff somebody reads, not as an
assertion somebody quietly edits. Ninety-one symbols against the six
hundred the library exposes — the sixteen per cent step 69 measured, now
written down.

It was generated from today's code, which means it *records* the contract
rather than dictating it. That is the right direction for a first
snapshot: the argument about whether a member belongs in the contract is a
separate one, and pretending to settle it here would hide it.

## What each criterion catches, and why it is red

| AC | red because, today |
|---|---|
| AC1 (the snapshot) | `apiSurface()` does not exist |
| AC1 (not internal) | `apiPublicClasses()` does not exist |
| AC2 (the real run) | `apiCheck()` does not exist; once it does, about sixty classes are in neither set |
| AC2 (the error path) | the same, on synthetic input: a class in neither set, and a contract class marked `@internal`, must each be a finding naming it |
| AC3 | `$store` carries no `@internal` and its docblock says nothing about changing |
| AC4 | `apiCompare()` does not exist |
| AC5 | the README has no `## Public API` section and never says what may change |

AC3 and AC5 are worth pointing at: they fail on **what is missing from the
code and the README**, not on a missing function. They would be red even
if the checker existed, which is what makes them tests of the decision
rather than of the tooling.

## The red phase says something, instead of printing a wall

AC2's real run will report roughly sixty findings once the checker exists —
correct, and unreadable. The test therefore asserts on the **count** and
puts the first three names in the failure message:

```php
expect(count($result->findings))->toBe(0, sprintf(
    '%d classes are in neither set; first three: %s', …
));
```

A red phase that prints sixty lines teaches nothing; one that says "sixty,
starting with these three" tells you the shape of the work.

## Red on purpose after this step

- Pest: 7 failed, 374 passed.
- PHPStan: 27 errors, every one of them following from the four undefined
  functions in the new test file.
- Pint and `bin/spec-check.php` (26 specs, 31 test files) are clean.

70b writes `bin/api-check.php`, marks about sixty classes `@internal`,
marks `$store`, and gives the README its `## Public API` section. It will
be a large diff and a small change: sixty docblocks, no behaviour.
