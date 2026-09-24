# Step 132 — SPEC-037: the `c2pa.redacted` action

*2026-09-24. SPEC-037 approved the same day. On open question 1 the
maintainer chose to follow `c2patool`: a `c2pa.redacted` without
`parameters` passes. Questions 2, 3 and 4 adopted their proposals.*

## 132a — the fixtures, and the tests seen red

`bin/make-spec037-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`
builds step 131's eight shapes with `c2patool` 0.28.0's builder: a parent
carrying `com.example.secret`, and children made with `-p` and
`redactions`, each with one `c2pa.redacted` action. The ninth shape, a
non-string `redacted`, is refused by the builder and is covered through
the seam. The keys are shredded.

Both versions answered as in step 131:
- `valid`, `no-parameters`, `present-not-redacted` and `no-redaction-list`
  are `Trusted`;
- `parameters-without-redacted`, `relative` and `foreign-manifest` are
  `Invalid` with `assertion.action.redactionMismatch`;
- `unknown-label` is `Invalid` with `assertion.notRedacted`.

Every fault is on the child's actions assertion.

`tests/Unit/Manifest/RedactedActionTest.php`, run as
`vendor/bin/pest --group=SPEC-037`: **6 failed, 2 passed.**
- AC2–AC4 fail because there is no fault where both oracles give one.
  This verifier calls those four children `Trusted`.
- AC5 and AC6 fail because the seam gives no fault.
- AC8 fails because the case does not exist.
- AC1 (the four shapes both oracles accept) and AC7 (SPEC-035's
  redacting child) are guards, green before and after.

**PHPStan reports one error in this state.** The seam passes the store's
claims as the seventh argument of `ActionsCheck::checkAssertions()`, which
step 132b introduces. That is the tests-first state, as with SPEC-001's
missing classes. Spec-check, Pint and Deptrac are clean, and the other
473 tests pass.

Committed locally, not pushed: CI sees this only together with 132b.
