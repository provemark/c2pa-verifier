# Step 138 — the check before the next release

*2026-09-24. Asked for after SPEC-040: check that nothing still belongs in
the release. The maintainer then chose to call it **0.2.1** rather than
0.3.0, *"if that is logically also right"*. It is, as below.*

## What was checked, and what it found

- **Specs and amendments.** All 41 specifications are `implemented`, and
  every one of the 130 amendments is confirmed. No pull request is open.
- **`composer check`**: 498 tests, the package tests of SPEC-023 among
  them, green.
- **Fuzzing, as before 0.2.0 (step 121).** Command:
  `php bin/fuzz.php 20260925 60 <out>` over `public-testfiles`, `c2pa-rs`,
  `writers`, `binding`, the four signed fixtures, `ingredient-manifest`,
  `bmff`, and the seven fixture folders of SPEC-033 to SPEC-040.
  - **9,021 runs over 157 files, 0 faults**: no exception escaped. The
    slowest run took 0.04 s, peak memory was 38 MiB, and the whole run
    took 11.7 s.
  - 41 mutated files stayed `Valid`. Each was put to both `c2patool`
    versions: **41 of 41 are `Valid` in 0.27.22 and in 0.28.0**. The
    mutations landed in bytes the formats leave unbound; eight were in the
    MP4's excluded `free`/`skip` and `uuid` padding. None is a wrong
    `Valid`.
- **Promises.** No note, spec or issue promises anything for this release
  that is not in it. Issue #4's closing comment says *"it will ship with
  0.3"*; the version is now 0.2.1.
- **Three texts were behind, and were brought up to date in this step:**
  1. `CHANGELOG.md` said under SPEC-035 that *"a redacted hard binding
     stays refused"*. SPEC-036 changed that.
  2. The Unreleased section listed all eight specifications under *Added*.
     It is rewritten the way 0.2.0's was: an introduction, then *Fixed*
     (verdicts now as `c2patool` gives them), *Changed* (same verdict,
     other codes or lines) and *Added* (14 status codes, conformance 15 →
     11 gaps).
  3. `docs/comparison.md` said *"last reviewed 2026-09-23"* and named
     three folders of 0.28.0 answers. It now names all eight added since
     0.2.0, and the four places where this verifier follows 0.28.0 over
     0.27.22.

  The numbers were counted again from the `v0.2.0` tag. There are 14 new
  status codes (46 → 60) and 112 → 126 symbols. The maintainer had been
  given 13 and 114 → 126 in conversation, and those were wrong.

## 0.2.1 rather than 0.3.0

The README promises: *"`^0.2` receives every 0.2.x fix, and a change that
breaks the API below will be `0.3.0`"*. The 0.2.0 entry drew the line in
practice:
- the one thing that made it a `0.2` was a settings file that worked
  before and was refused after;
- new status codes and changed verdicts were not counted as breaks.

By that measure nothing here breaks:
- no settings shape, class, method or member changes;
- the API grows by 14 enum cases and loses nothing;
- the verdicts that move are the ones both `c2patool` versions already
  gave differently.

The one thing a caller can notice in code is an exhaustive `match` on
`StatusCode` without a default arm. The CHANGELOG says so, as SPEC-025's
amendments have said since SPEC-030.
