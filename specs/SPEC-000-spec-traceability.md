# SPEC-000: Spec traceability tooling

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-19                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This project is spec-driven: every feature starts as a `specs/SPEC-###-*.md`
file, no code is written while that file is `draft`, tests come before the
implementation and carry the spec's ID as a Pest group, and a spec becomes
`implemented` only when its Traceability table names the tests that prove
each acceptance criterion. Those are rules on paper. Nothing in `composer
check` enforces them, so a test without a spec, a spec marked `implemented`
without a test, or tests written against a `draft` would all pass CI unseen.

`bin/spec-check.php` closes that gap: one script that reads `specs/` and
`tests/` and fails when they disagree. It is tooling, not product code: it
lives in `bin/`, outside the Deptrac layers, but it is analysed by PHPStan at
level max and formatted by Pint like everything else, because a checker that
is itself unchecked is the shape of bug the sister project documented three
times in its own version of this script.

SPEC-000 is numbered zero on purpose. It is not the first feature of the
verifier — that is SPEC-001, JPEG APP11 extraction — but the tool that makes
every later spec's promises checkable.

## Scope

**In scope**

- Reading every `specs/SPEC-*.md` and its Status field.
- Reading every `*Test.php` under `tests/` for `->group('SPEC-###')` calls,
  including multi-argument calls such as `->group('SPEC-001', 'SPEC-004')`.
- The cross-checks AC1–AC8 below, reported one finding per line, exit code 0
  only when there are no findings.
- Running as the first step of `composer check`.
- Being testable on a directory other than the repository root, so the tests
  can feed it small fixture trees under `tests/Fixtures/spec-check/`.

**Out of scope** (each needs its own spec before it may be built)

- Checking that a test actually exercises the criterion it is listed under.
  Traceability rows are declarations; whether a test tests anything is the
  "seen red" rule, which is a human check.
- Parsing acceptance criteria out of the Behavior section, or counting them.
- Any check on `src/` (Deptrac and PHPStan own that).
- Warnings. Every finding is an error; there is no advisory category, because
  an advisory is an error the reader learns to skip.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-000')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — every spec file has a valid status**
  - Given a `specs/` directory whose every `SPEC-*.md` file has a Status row
    whose value is exactly one of `draft`, `approved`, `implemented`,
    `superseded`
  - When the checker runs
  - Then it reports no finding for status and lists each spec with its
    status in its output

- **AC2 — a test group must name an existing spec**
  - Given a test file carrying `->group('SPEC-042')` and no file
    `specs/SPEC-042-*.md`
  - When the checker runs
  - Then it reports one finding naming `SPEC-042` and the test file, and
    exits 1

- **AC3 — every test file carries a spec group**
  - Given a `*Test.php` file under `tests/` that contains no
    `->group('SPEC-###')` call at all
  - When the checker runs
  - Then it reports one finding naming that file, and exits 1

- **AC4 — a draft spec has no tests yet**
  - Given a spec with Status `draft` and a test file carrying its group
  - When the checker runs
  - Then it reports one finding naming the spec and the file ("tests precede
    approval"), and exits 1

- **AC5 — an implemented spec has at least one test**
  - Given a spec with Status `implemented` and no test file carrying its
    group
  - When the checker runs
  - Then it reports one finding naming the spec, and exits 1

- **AC6 — an implemented spec has a filled Traceability table**
  - Given a spec with Status `implemented` whose Traceability table has at
    least one row whose Test cell is `—` (or empty)
  - When the checker runs
  - Then it reports one finding naming the spec and the criterion of that row,
    and exits 1

- **AC7 — a spec without a recognisable status is an error** *(required:
  error / malformed input)*
  - Given a `specs/SPEC-*.md` file with no Status row, or a Status row whose
    value is not one of the four (for example `Draft`, `approved?`, `done`)
  - When the checker runs
  - Then it reports one finding naming the file and the offending value (or
    "missing"), exits 1, and does not treat the spec as any status for the
    other checks

- **AC8 — clean repository, exit 0; one line per finding otherwise**
  - Given a tree that triggers none of AC2–AC7
  - When the checker runs
  - Then it exits 0 and its output contains the word `OK` followed by the
    number of specs and test files scanned
  - And given a tree that triggers several of them
  - Then every finding is one line of the form `SPEC-###: <reason>` (or
    `<file>: <reason>` when no spec applies), and the exit code is 1

- **AC9 — multi-argument group calls are read completely**
  - Given a test file carrying `->group('SPEC-001', 'SPEC-004')` and both
    specs exist and are `approved`
  - When the checker runs
  - Then both specs count as having a test, and no finding is reported

- **AC10 — the check is a step of `composer check`**
  - Given the repository root
  - When `composer check` runs
  - Then `bin/spec-check.php` runs before Pint, and a non-zero exit stops the
    chain

- **AC11 — a test that names a criterion finds it in its spec's Traceability** *(amendment 1)*
  - Given a test file whose declarations are named `AC1: …`, `AC2: …` (both
    with a Traceability row, one labelled `AC2 (amendment 1)`), a
    declaration with no criterion in its name, `AC3: …` with no row, and
    `test('SPEC-001 AC9: …')` with no row. All of them carry
    `->group('SPEC-001')`, and one holds a `"{$var}"` string
  - When the checker runs
  - Then it reports exactly two findings, one per orphan, each naming the
    spec, the file and line of the declaration, and the criterion. The
    spec is the one in the test's own name if it names one, else the
    groups of that declaration's own statement. The file is read with
    PHP's tokenizer, so a comment or a string cannot fake a declaration.

## References

- Specification: none — this spec governs the project's own process, which is
  described in `docs/` (the way of working) and in the spec template.
- Oracle: none external. The acceptance criteria are measured against fixture
  trees under `tests/Fixtures/spec-check/`, each a minimal `specs/` +
  `tests/` pair built for one criterion.
- Reasoned: the choice to make AC4 an error rather than a warning follows the
  project rule that unknown or out-of-order states fail closed. The sister
  project's script warns there; this one does not.

## API sketch

```php
// bin/spec-check.php — a script, not a namespaced class. It defines the
// functions below and, when invoked from the command line, runs them on the
// repository root and exits with the result's code.

declare(strict_types=1);

/** @return list<string>  one finding per element, empty when clean */
function specCheck(string $root): array;

// The CLI entry point prints each finding on its own line, then either
// "OK: N specs, M test files" (exit 0) or "FAIL: K finding(s)" (exit 1).
// Tests require_once the file and call specCheck() on fixture roots; the
// CLI part is guarded so requiring the file has no side effects.
```

## Open questions

- Non-blocker: whether `superseded` specs should still be allowed to have
  tests carrying their group (the tests would be stale). Proposed: allowed,
  no finding; revisit when the first spec is superseded.

## Amendments

1. **2026-09-24, step 116, decided by Maurice van Loon** — AC11 is added.
   SPEC-017 amendment 5 found a test named *"SPEC-017 AC12"* (step 46)
   with no AC12 in its spec and no Traceability row, and this checker had
   passed it for two days. Measured before the rule was written: 394 Pest
   declarations in the suite, 376 of which name a criterion, and five
   orphans. SPEC-013 AC16–AC18 and SPEC-015 AC11 were defined in an
   amendment's text but never given a row. SPEC-017 AC12 was never
   written at all. A test that claims a criterion now has to find a row
   for it. The five are repaired in the same step, and the check itself
   was the alarm: it named them all, plus AC11 of this very spec before
   its row existed.

   **Weight B: a rule about the record, no verdict involved.**

   Confirmed by Maurice van Loon, 2026-09-24 (step 120).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/SpecCheckTest.php :: AC1: reports every spec with its status and no findings on a clean tree / SPEC-000 | bin/spec-check.php :: specCheck(), specCheckStatus(), SpecCheckResult::render() |
| AC2 | tests/Unit/SpecCheckTest.php :: AC2: a group that names no existing spec is a finding / SPEC-000 | bin/spec-check.php :: specCheck() (group without spec) |
| AC3 | tests/Unit/SpecCheckTest.php :: AC3: a test file without any spec group is a finding / SPEC-000 | bin/spec-check.php :: specCheckGroups(), specCheck() |
| AC4 | tests/Unit/SpecCheckTest.php :: AC4: a draft spec whose group is already carried by a test is a finding / SPEC-000 | bin/spec-check.php :: specCheck() (draft) |
| AC5 | tests/Unit/SpecCheckTest.php :: AC5: an implemented spec without a test carrying its group is a finding / SPEC-000 | bin/spec-check.php :: specCheck() (implemented) |
| AC6 | tests/Unit/SpecCheckTest.php :: AC6: an implemented spec with an unfilled Traceability row is a finding naming the criterion / SPEC-000 | bin/spec-check.php :: specCheckUnfilledTraceability() |
| AC7 | tests/Unit/SpecCheckTest.php :: AC7: a spec without a recognisable status is a finding and is not treated as any status / SPEC-000 | bin/spec-check.php :: specCheckStatus() |
| AC8 | tests/Unit/SpecCheckTest.php :: AC8: a clean tree exits 0 and says OK with the counts; AC8: a tree with several problems reports one line per finding and exits 1; AC8: this repository itself is clean, with the fixture trees skipped / SPEC-000 | bin/spec-check.php :: SpecCheckResult::exitCode(), render(); specCheckTestFiles() skips tests/Fixtures/ |
| AC9 | tests/Unit/SpecCheckTest.php :: AC9: a multi-argument group call counts for every spec it names / SPEC-000 | bin/spec-check.php :: specCheckGroups() |
| AC11 (amendment 1) | tests/Unit/SpecCheckTest.php :: AC11: a test naming a criterion its spec does not trace is a finding naming file, line and criterion / SPEC-000 | bin/spec-check.php :: specCheckNamedCriteria(), specCheckTracedCriteria(), specCheck() |
| AC10 | measured by hand, not a Pest test (a test calling `composer check` from inside `composer check` is a loop): on a copy with SPEC-000 set to `draft`, `composer check` stopped at spec-check with exit 1 and Pint never ran — AI-LOG.md 2026-09-19, M0.3c | composer.json :: scripts.check (spec-check first) |
