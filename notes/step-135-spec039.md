# Step 135 — SPEC-039: `claimSignature.insideValidity`

*2026-09-24. SPEC-039 approved the same day. On open question 1 the
maintainer chose to copy `c2patool`: the code sits beside every verified
signature, an expired signer's included. Question 2 adopted its proposal.*

## 135a — the tests seen red

No new fixture was needed: the corpus and its recorded `c2patool`
reports are the oracle.
- `SPEC013_CORPUS`: 22 files, no settings;
- SPEC-021's 17 multi-manifest files, for the ingredient deltas;
- `profile/expired.png`, where both versions were run and recorded under
  `tests/Fixtures/c2patool/inside-validity/`. Both report the code beside
  `signingCredential.expired`. That is SPEC-039 amendment 1, which also
  corrects the draft's `.jpg` to `.png`.

`tests/Unit/Cose/InsideValidityTest.php`, run as
`vendor/bin/pest --group=SPEC-039`: **5 failed, 1 passed.**
- AC1–AC4 fail because no `claimSignature.insideValidity` appears where
  the oracle has it. AC3 fails on its positive twin, which exists so that
  the absence it asserts cannot be an absence everywhere.
- AC6 fails because the case does not exist.
- AC5 is the guard.

`composer check` is otherwise clean: 487 passed.

Committed locally, not pushed.

## 135b — built

`ClaimSignatureCheck` returns `claimSignature.insideValidity` (*"claim
signature valid"*) directly before `claimSignature.validated`, on the same
url, whenever the signature verifies. It adds no validity condition, as
the maintainer chose. Through the same check, ingredient manifests get it
too. `StatusCode` gains the case as a success.

`vendor/bin/pest --group=SPEC-039`: **6 passed.** Tests of other specs
that pinned the old success list were updated:
- SPEC-010 AC1, AC9 and AC10: AC1 said *"exactly one status"*, and now
  compares both successes with `c2patool`'s, code, url and order;
- SPEC-011 AC9, SPEC-012 AC10 and SPEC-013 AC1;
- SPEC-017 AC9;
- the two counts (58 → 59 codes, 124 → 125 symbols).

Amendments were written where a criterion's words changed: SPEC-010 #6,
SPEC-011 #4, SPEC-012 #8, and SPEC-025 for the code. SPEC-039 amendment 2
names `variants/json-broken`: this verifier stops at its parse fault before
the signature is read, so it gets neither code there.

**Before and after, the whole corpus** under the three standard settings,
comparing verdicts, failures, informational codes and ingredient deltas
(1086 runs): **no difference**. The success lists are the only change,
and the tests hold them.

`composer check`: exit 0, 492 tests.

Six amendments await confirmation:
- SPEC-039 #1 and #2;
- SPEC-010 #6, SPEC-011 #4, SPEC-012 #8;
- SPEC-025, for the code.
