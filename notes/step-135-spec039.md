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
