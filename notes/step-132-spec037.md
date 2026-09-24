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

## 132b — built

`ActionsCheck` applies rule 2.d in its content rules (v2 claims only), to
every `c2pa.redacted` action that has `parameters`:
- `redactionFault()` returns `assertion.action.redactionMismatch` when
  `redacted` is not a string, not an absolute `self#jumbf=/c2pa/<label>`
  URI, or names a manifest not in the store;
- it returns `assertion.notRedacted` when the named claim lists no
  assertion whose url contains the label after `c2pa.assertions/`, or
  when the URI names no assertion (a data box included);
- the fault sits on the actions assertion's url.

`ActionsCheck::claimLabels()` gives every manifest's listed labels. The
Verifier and `IngredientManifestCheck` pass them in as a new optional
argument. `ActionsCheck` is `@internal`, so the surface gains only the
code, 121 → 122.

One change to a test while it was still red: the seam's actions assertion
now opens with the `c2pa.opened` that the builder adds for the parent.
Without it, the opening rule stops before the content rules (SPEC-033
amendment 1), and the seam could not have gone green for the right reason.

`vendor/bin/pest --group=SPEC-037`: **8 passed.** The six red tests of
132a are green. PHPStan is clean again. Two counts moved:
`CertificateProfileCheckTest` (55 → 56 codes) and `ApiSurfaceTest`
(121 → 122).

**Before and after, the whole corpus** under the three standard settings,
with ingredient deltas (1041 runs). Only step 131's four holes moved, from
`Valid` (`Trusted` with their root) to `Invalid`:
- `parameters-without-redacted`, `relative` and `foreign-manifest` now
  give `assertion.action.redactionMismatch`;
- `unknown-label` now gives `assertion.notRedacted`.

Each code is what both `c2patool` versions give.

`composer check`: exit 0, 479 tests.

One amendment, SPEC-025 #9 (the code), confirmed by the maintainer the
same day.
