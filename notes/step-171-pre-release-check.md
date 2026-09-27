# Step 171 — The check before 0.2.5

*2026-09-27. The release that closes the chain findings of steps 164–170.
Nothing is pushed or tagged in this step.*

## What was checked, and what it found

- **Specs and amendments.** `composer spec-check`: all 48 specifications
  are `implemented`. `grep -rn "Awaits confirmation" specs` finds nothing:
  SPEC-047 amendment 1 and SPEC-048 amendment 1 were confirmed by Maurice
  van Loon on 2026-09-27.
- **`composer check`**: exit 0, 552 tests.
- **Fuzzing,** as in step 162, with `chain-constraints` added:
  `php bin/fuzz.php 20260927 60 <out>` in `tests/Fixtures`.
  - **11,181 runs over 193 files, 0 faults.** The slowest run took
    0.04 s, peak memory was 38 MiB, and the whole run took 17.2 s.
  - 46 mutated files stayed `Valid`. **All 46 are `Valid` in both
    `c2patool` versions** (without settings).
- **The package.** `php bin/package-check.php`: 348 files, 3.7 MB, every
  top-level path classified. `php bin/api-check.php`: the recorded surface
  matches. `git diff v0.2.4 -- tests/Fixtures/api/public-surface.txt
  src/Report/StatusCode.php` is empty. The new classes
  (`CertificateExtensions`, `NameConstraints`) are `@internal`.
- **Texts brought up to date in this step:**
  - the CHANGELOG, with 0.2.5's *Security* and *Changed* sections;
  - SECURITY.md's *Findings so far*, fifteen cases now;
  - the README's current tag and its count of wrong verdicts;
  - the milestones row of SPEC-048.

## 0.2.5, not 0.3.0

By step 138's rule this is a patch. No class, method, member, status code
or settings shape changes. The verdicts that move are these:

- four wrong `Trusted` go to what `c2patool` says;
- three refusals go beyond `c2patool`, each named in
  `docs/comparison.md`:
  - an unprotected chain in a claim v2 or later;
  - MD5 or SHA-1 in the path;
  - a name constraint of a form not evaluated.

## What waits for permission

- Pushing `main`: steps 164–171, eight commits.
- Waiting for CI to be green on this commit, then tagging `v0.2.5` on it.
- Checking the GitHub archive against `bin/package-check.php`, and that
  Packagist lists the version.
- Then, in the plugin: `composer update provemark/c2pa-verifier`, the WPCS
  baseline reviewed again, every suite.

Before the push: `git log --format=%B origin/main..main | grep -i
"claude\|anthropic"` must return nothing.
