# Step 116 — a test that names a criterion must find it in its spec

*2026-09-24. SPEC-000 amendment 1 (AC11), and the five orphans it found,
repaired.*

## Why

SPEC-017 amendment 5 (step 115) had to number a new criterion and found
a test already called *"SPEC-017 AC12"*: the Pixel 10 file, added in step
46 on 2026-09-22. The spec had no AC12 and no Traceability row for it.
`bin/spec-check.php` checks that every spec is carried by a group and
every row names a test. It never checked the other direction: that a
test claiming a criterion claims one that exists.

## Measured before the rule was written

A throwaway script over the suite, using the same tokenizer approach as
step 106b:

- **394 Pest declarations**, of which 376 open their name with a
  criterion (`ACn: …` or `SPEC-### ACn: …`). The other 18 (the CLI
  table-driven cases, the M7 absence tests, the `toContain` rule, one
  manifest-store helper test) name none and are outside the rule.
- **Five orphans**, with a row in the spec's Traceability table as the
  test:
  - `SPEC-013` AC16, AC17, AC18 (`MatrixTest`, step 59). Described in
    SPEC-013 amendment 12's text, never given rows.
  - `SPEC-015` AC11 (`CertificateProfileCheckTest`, the Ed25519 bug of
    step 59). Described in SPEC-015 amendment 5's text, never given a row.
  - `SPEC-017` AC12 (`TimestampCheckTest`, Pixel 10, step 46). **Never
    written down at all.**
- The same five came out whether "exists" meant *a bold `**ACn` in
  Behavior or a row* or *a row only*. The rule uses rows only: that is
  the table a reader follows from spec to test.
- One false start, caught before it counted: the first script reported
  dozens of tests as having no group, because a `"{$var}"` inside a
  string opens a brace token (`T_CURLY_OPEN`) that a plain `{` counter
  does not see. The rule counts both, and the fixture includes such a
  string.

## Red, then green

- A fixture tree `tests/Fixtures/spec-check/orphan-criterion/` holds a
  spec with rows for AC1 and `AC2 (amendment 1)`, and a test file with
  those two, a declaration with no criterion, an `AC3` with no row, and a
  `test('SPEC-001 AC9: …')` with no row.
- `SpecCheckTest` AC11 expects exactly two findings with file, line and
  criterion. It was **red**, with no findings at all, before
  `specCheckNamedCriteria()` and `specCheckTracedCriteria()` existed.
- With them, the fixture test went green, and the repository self-check
  (AC8) went red on **six** findings: the five orphans and AC11 of SPEC-000
  itself, whose row did not exist yet. The check was its own first alarm.
- Repaired: rows for SPEC-013 AC16–AC18 and SPEC-015 AC11 (the
  Traceability section may change without approval); SPEC-017 AC12
  written as a criterion from the test as it stands (SPEC-017 amendment
  6); SPEC-000 AC11 with its row.
- `composer check`: 432 passed, clean. **Falsified**: removing SPEC-017's
  new AC12 row makes `bin/spec-check.php` say `FAIL: 1 finding(s)` and
  name the file, line 627 and AC12. Restoring the row makes it pass
  again.

## What it does not cover

A test whose name claims no criterion is not checked. That is
deliberate, because the rule is about claims. `bin/spec-check.php` still
cannot tell whether a criterion's text and its test say the same thing.
That remains reading.
