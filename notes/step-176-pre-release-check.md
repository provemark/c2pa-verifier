# Step 176 — The check before 0.2.6

*2026-09-28. The release that carries SPEC-049. Nothing is pushed or
tagged in this step.*

## What was checked, and what it found

- **Specs and amendments.** `composer spec-check`: all 49 specifications
  are `implemented`. `grep -rn "Awaiting confirmation" specs` finds
  nothing: SPEC-049's amendments 1 to 3 were confirmed by Maurice van Loon
  on 2026-09-28.
- **`composer check`**: exit 0, 569 tests.
- **Fuzzing,** as in steps 162 and 171: `php bin/fuzz.php 20260928 60
  <out>` in `tests/Fixtures`, over `public-testfiles`, `c2pa-rs`,
  `writers`, `binding`, the signed JPEG, PNG, WebP and MP4 fixtures,
  `ingredient-manifest`, `bmff`, `actions-rules`, `assertion-rules`,
  `redacted-action`, `redactions`, `outside-manifest`,
  `hard-binding-redacted`, `first-piece-z`, `redaction-scope`,
  `tsa-signer`, `hostile-2` and `chain-constraints`.
  - **10,818 runs over 188 files, 0 faults.** The slowest run took
    0.04 s, peak memory was 38 MiB, and the whole run took 17.2 s.
  - 50 mutated files stayed `Valid`. **All 50 are `Valid` in both
    `c2patool` versions** (0.27.22 and 0.28.0, without settings).
- **The package.** `php bin/package-check.php`: every top-level path
  classified; the count, with this note, is in the release step.
  `php bin/api-check.php`: the recorded surface matches, and every public
  class is in the contract or `@internal`. `git diff v0.2.5 --
  tests/Fixtures/api/public-surface.txt src/Report/StatusCode.php` is
  empty. The new class `RsaExponent` is `@internal`.
- **Texts brought up to date in this step:**
  - the CHANGELOG, with 0.2.6's *Security* and *Changed* sections;
  - SECURITY.md's *Findings so far*, sixteen cases now;
  - the README's current tag and its count of wrong verdicts;
  - the fuzzing line in the README and `docs/comparison.md`. Both said
    70 870 files, a total from step 50 that the fuzz runs of steps 138,
    162 and 171 were never added to. They now give this release's run
    instead of a cumulative figure;
  - `docs/conformance.md`, last updated at SPEC-040: `PRED-INGR-002` and
    `PRED-CRYP-009` described behaviour that SPEC-035 amendment 5 and
    SPEC-047 had changed, and `PRED-CRYP-003` and `-007` gained SPEC-046
    to SPEC-049. No verdict in the count changed.

## 0.2.6, not 0.3.0

By step 138's rule this is a patch. No class, method, member, status code
or settings shape changes. The verdicts that move:

- one wrong `Trusted` (an RSA leaf with exponent 1) goes to what c2pa-rs
  0.91.1 says; the released `c2patool` versions still give the wrong
  verdict;
- one refusal goes beyond every oracle, named in `docs/comparison.md`:
  an RSA key with such an exponent between the anchor and the leaf.

No file in the corpus moved (step 175: 23,352 runs).

## What waits for permission

- Pushing `main`: steps 174–176, four commits.
- Waiting for CI to be green on this commit, then tagging `v0.2.6` on it.
- Checking the GitHub archive against `bin/package-check.php`, and that
  Packagist lists the version.
- Then the plugin (`composer update provemark/c2pa-verifier`, every
  suite) and the demo (`npm run fetch-lib -- 0.2.6`, rebuild).

Before the push: `git log --format=%B origin/main..main | grep -i
"claude\|anthropic"` must return nothing.
