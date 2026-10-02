# Step 200 — The check before 0.2.9

*2026-10-02. The release that carries SPEC-054. Pushing and tagging follow
in the release step, with the maintainer's permission ("release 0.2.9").*

## What was checked, and what it found

- **Specs.** `php bin/spec-check.php`: all 54 specifications are
  `implemented`, 63 test files. No amendment waits for confirmation.
- **`composer check`**: exit 0, 621 tests (9,453 assertions).
- **CI on the last commit.** Run 36749893798 on `984883a`, green in all 8
  jobs: `composer check` on PHP 8.3, 8.4 and 8.5, and the new `older-php`
  job, `requirements.php` on PHP 7.4, 8.0, 8.1 and 8.2. Step 199 left
  this unmeasured; it is measured now.
- **The package.** `php bin/package-check.php`: 14 top-level paths
  shipped, 9 `export-ignore`, every one classified; the dist holds 379
  files (0.2.8: 374). `php bin/api-check.php`: 83 public classes, 11 in
  the contract and 72 `@internal`, 126 contract symbols; the recorded
  surface matches.
- **`src/` is unchanged.** `git diff v0.2.8 -- src/
  tests/Fixtures/api/public-surface.txt` is empty.
- **Texts brought up to date:** the CHANGELOG (0.2.9, "Added") and the
  README's current tag.

## No fuzzing this time

Reasoned, not measured: the fuzzer exercises `verify()`, and every file it
loads is in `src/`, which is byte for byte the code fuzzed before 0.2.8
(11,118 runs, 0 faults, step 194). `requirements.php` reads no file; its
inputs are a version string and a list of names, and SPEC-054's AC5 tests
the malformed ones.

## 0.2.9, not 0.3.0

An addition, not a break: the README's rule is that a change that breaks
the API is `0.3.0`, and `^0.2` receives everything else. No class, method,
member, status code or settings shape changed, and no verdict can move.

## What waits

Pushing `main` (steps 196–200), CI green on the last commit, the tag
`v0.2.9` on it, the archive against `bin/package-check.php`, Packagist.
Then the WordPress plugin and the demo.
