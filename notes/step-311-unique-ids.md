# Step 311 — A signer certificate carries no unique IDs (SPEC-015 amendment 9)

*2026-10-09.*

Step 310 measured that a leaf certificate with an `issuerUniqueID` or a
`subjectUniqueID` was `Trusted` here and `Invalid` in both `c2patool`
versions. C2PA 2.4 §14.5.1.1 forbids the fields; `c2pa-rs` 0.91.1 refuses
them on the end-entity certificate.

## What changed

- **`CertificateExtensions`** records which of the two fields the
  tbsCertificate carries (`$uniqueIds`).
- **`CertificateProfileCheck::checkLeaf()`** reports each one as
  `signingCredential.invalid`, naming the field. For a version 2 claim's
  TSA leaf this happens too, through SPEC-017 amendment 8. Certificates
  above the leaf are left as `c2patool` leaves them (candidate C4).
- **Specs:** SPEC-015 amendment 9 and AC14; SPEC-061 amendment 4.
- **The trust matrix:** `bin/make-trust-matrix.php` inserts the field into
  an issued certificate's tbsCertificate and signs it again
  (`tmDerLength()`, `tmDerChildren()`). Three probes are built as fixtures
  in `chain-matrix` with both `c2patool` versions' answers; the matrix holds
  57 probes.
- The folder's README, the CHANGELOG under a new *Unreleased*, and
  `docs/reading-c2pa-2.4.md` (C5 closed for the leaf).

## Measured

- **Tests first.** `tests/Unit/Trust/UniqueIdTest.php` (AC14) and the
  trust-matrix test with 57 probes: 3 failed (the drift alarm fired on the
  two leaves), 3 passed. After the change: 6 passed.
- **The corpus.** 844 files under no settings and 156 settings files,
  before (a worktree of `f05e6b8` with its own `vendor/` and the new
  fixtures) and after. 314 runs moved, all of them the two leaf probes:
  from `Valid` to `Invalid` under every settings file, and from `Trusted`
  to `Invalid` under their own. No real file moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, the same
  238 suspects. With `--trust` (236 pairs, 161 `Trusted` unmutated):
  - 20261005 × 60: 0 faults, 0 raised, 531 suspects judged by `c2patool`
    0.28.1 under the same settings, none more lenient here;
  - 20261009 × 200: 0 faults, 0 raised, 1,755 judged, none more lenient
    here.
- `composer check`: 955 passed. PHPStan on macOS and in Docker
  `php:8.3-cli`: no errors. (macOS PHPStan asked for the DER length bytes
  to be written with `pack('C', …)`; the bytes are the same.)
