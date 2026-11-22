# Step 551 — Prepare 0.11.4

*2026-11-22.* Maurice: "akkoord amendement 5, push maar en bereid 0.11.4
voor". A patch, as he decided with SPEC-017 amendment 12 (step 550). Step
550 went out as `0d236dc`, with SPEC-062 amendment 5 now marked approved.
A timestamp authority off the certificate profile now gives an untrusted
time-stamp instead of an `Invalid` file. No report field or contract
symbol changes. A caller on `^0.11` receives it without a change of
constraint.

## What changes

- **`CHANGELOG.md`.** *Unreleased* becomes **0.11.4 — 2026-11-22**, with
  an introduction.
- **The version.** `Verifier::VERSION` is `0.11.4`.
- **`README.md`.** The sentence on the current tag names 0.11.4 and what
  it changes.
- **`SECURITY.md`.** Unchanged. The verdicts that move become more
  lenient, but only where the time-stamp is ignored and the signer is
  judged at the validation time. AC15 pins that this keeps no expired
  signer valid.

## Measured

- **CI on `0d236dc`:** run 45007446533, 8 of 8 jobs `success`.
- **Against `v0.11.3`:**
  - Setup: a worktree at `v0.11.3` with a copied `vendor/` and its own
    autoloader, checked to load its own `src/` (`VERSION` 0.11.3).
  - Files: the 1,229 under `tests/Fixtures/` except `.md`, `.json`,
    `.pem` and `.php`.
  - Configurations, all at 2026-11-22T12:00:00Z:
    - none;
    - the programme's official lists as `--trust-list` /
      `--tsa-trust-list`, a local copy (SHA-256 `709555a0…` and
      `6f409f08…`), not the lists step 547 used;
    - the conformance test root
      (`tests/Fixtures/conformance-test/root.settings.json`);
    - the file's own `<name>.settings.json`, where one exists (127 files).
  - Per file and side: the ordinary report with exit status, and
    `--crjson`. 3,814 runs a side.

  Results:
  - **No exit 2** on either side.
  - **Report:** the same 13 files differ in every configuration, and
    nothing else does:
    - the eight `tsa-matrix/tsa-leaf-*` probes and the three
      `tsa-profile/tsa-leaf-*` probes go from `Invalid` to `Valid`, and to
      `Trusted` under their own settings;
    - `tsa-profile/expired-signer-tsa-leaf-ca-true` and
      `…-eku-email-only` stay `Invalid` and lose
      `signingCredential.invalid`.
  - **crJSON:** the same 13 differ beyond the version. Every other
    document either differs only in `jsonGenerator.version`
    (`0.11.3` → `0.11.4`) or not at all.

  This is step 550's corpus result again, now with crJSON and two more
  configurations. `src/` did not change between `v0.11.3` and `44ce16e`.
- **`composer check`:** exit 0.
  - pest: 1,775 passed, 1 skipped;
  - api-check: 217 symbols;
  - spec-check: 92 specs.
- **`composer test:parallel`**, as CI runs it: 1,775 passed, 1 skipped.
- **`bin/package-check.php`:** 691 files, 6.5 MB, 2.3 MB as a zip, counted
  on the uncommitted tree. The note adds one file once committed.

## Left

Each on Maurice's word:
1. commit and push, then CI;
2. tag `v0.11.4`, the GitHub release, then Packagist;
3. downstream, nothing has to change. The library, the Drupal module and
   the WordPress plugin take or bundle it in their own sessions.
