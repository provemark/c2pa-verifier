# Step 162 — The check before 0.2.4

*2026-09-27. The release that closes the review of step 157 (steps
158–161). Nothing is pushed or tagged in this step.*

## What was checked, and what it found

- **Specs and amendments.** `composer spec-check`: all 45 specifications
  are `implemented`. `grep -rn "Awaits confirmation" specs` finds nothing:
  the four amendments of this review (SPEC-035 5, SPEC-031 3, SPEC-045 1
  and 2) were confirmed by Maurice van Loon on 2026-09-27.
- **`composer check`**: exit 0, 536 tests.
- **Fuzzing, as in step 138, with this review's folders added.** Command:
  `php bin/fuzz.php 20260927 60 <out>`, run in `tests/Fixtures`, over
  `public-testfiles`, `c2pa-rs`, `writers`, `binding`, the four signed
  fixtures, `ingredient-manifest`, `bmff`, the fixture folders of SPEC-033
  to SPEC-040, `redaction-scope`, `tsa-signer` and `hostile-2`.
  - **10,161 runs over 176 files, 0 faults**: no exception escaped. The
    slowest run took 0.04 s, peak memory was 38 MiB, and the whole run
    took 13.3 s.
  - 45 mutated files stayed `Valid`. Each was put to both `c2patool`
    versions without settings: **45 of 45 are `Valid` in 0.27.22 and in
    0.28.0**. None is a wrong `Valid`.
- **The package.** `php bin/package-check.php`: 336 files, 3.6 MB, every
  top-level path classified. `php bin/api-check.php`: 126 contract
  symbols, the recorded surface matches. `git diff v0.2.3 --
  tests/Fixtures/api/public-surface.txt src/Report/StatusCode.php` is
  empty.
- **Four texts were behind, and were brought up to date in this step:**
  1. `docs/milestones.md` had no rows for steps 157 to 161 or for
     SPEC-045.
  2. The README said the project had found *"two such cases"* of a wrong
     `Valid` in itself. That was true until 0.2.1. It now says eleven,
     which counts the two of this review.
  3. `docs/trust-settings.md` did not say what the legacy
     `trust.trust_anchors` field does to a Time-Stamping-only signer.
  4. `SECURITY.md`'s *Findings so far* ended at 0.2.3.

## 0.2.4, not 0.3.0

By the rule of step 138, a minor version is for a change that breaks the
API. This release changes:

- no class, method, member, status code or settings shape;
- the verdicts that move are these:
  - the redaction case moves to what both `c2patool` versions say;
  - the Time-Stamping signer under the legacy field moves to a verdict
    stricter than both, which `docs/comparison.md` names;
  - the four hostile inputs move from a crash or a long run to a report.

So it is a patch.

## What this commit does, and what waits for permission

- **This commit, local:** the CHANGELOG's Unreleased section
  becomes `0.2.4 — 2026-09-27`; the README's current tag becomes
  `v0.2.4`; `SECURITY.md` records the two wrong verdicts and the four
  exhaustion findings.
- **Waiting for the maintainer's permission:** pushing `main` (six
  commits, from step 157 on), waiting for CI to be green on this
  commit, tagging `v0.2.4` on it, checking the GitHub archive against
  `bin/package-check.php`, and checking that Packagist lists the version.
  Before the push: `git log --format=%B origin/main..main | grep -i
  "claude\|anthropic"` must return nothing.
