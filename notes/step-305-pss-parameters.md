# Step 305 — RSASSA-PSS parameters in the leaf's profile (SPEC-015 amendment 8)

*2026-10-09.*

Step 304 measured that a leaf signed with RSASSA-PSS over SHA-224, or with
an MGF1 hash other than the PSS hash, was `Trusted` here and `Invalid` in
both `c2patool` versions. A leaf whose outer `signatureAlgorithm` was
changed after signing was `Valid` here and `Invalid` there. Maurice van
Loon chose to refuse in both open cases: the default MGF1 (SHA-1) under a
SHA-256 PSS hash, and two copies of the algorithm that differ.

## What changed

- **`CertificateExtensions`** also reads the MGF1 hash (SHA-1 when absent,
  RFC 4055 §3.1; the mask function's own OID when it is not MGF1). It
  records whether the outer `signatureAlgorithm` is byte-equal to
  tbsCertificate's `signature`. `algorithmFaults()` names what breaks the
  profile:
  - two copies of the algorithm that differ (RFC 5280 §4.1.1.2), for any
    algorithm;
  - for RSASSA-PSS, a hash other than SHA-256, SHA-384 or SHA-512, and an
    MGF1 hash other than the PSS hash.
  A weak hash stays `weakHash()`'s.
- **`CertificateProfileCheck::checkLeaf()`** reports each fault as
  `signingCredential.invalid`.
- **Specs:** SPEC-015 amendment 8 and AC13; SPEC-061 amendment 3.
- **The trust matrix:** the nine PSS probes of step 304 are in
  `bin/make-trust-matrix.php` (54 probes) and built as fixtures in
  `chain-matrix`, with both `c2patool` versions' answers. `leaf-pss-sha1`
  and `leaf-pss-mgf1-sha1` are named stricter; the other seven agree with
  0.28.1.
- `docs/comparison.md` (two stricter cases), the folder's README, and the
  CHANGELOG under *Unreleased*.

## Measured

- **Tests first.** `tests/Unit/Trust/PssParametersTest.php` (AC13) and the
  trust-matrix test with the nine probes: 5 failed (the drift alarm fired
  on the two `Trusted` probes `c2patool` calls `Invalid`), 3 passed. After
  the change: 8 passed.
- **The corpus.** 841 files under no settings and 153 settings files,
  before (a worktree of `ad629d5` with its own `vendor/` and the new
  fixtures) and after. Of the runs, 770 moved, all of them five of the
  new probes: from `Valid` to `Invalid` under every settings file, and
  from `Trusted` to `Invalid` under their own. No real file moved, also
  none of Adobe's PSS-signed test files, which use SHA-256 for both.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, the same
  238 suspects. With `--trust` (233 pairs, 160 `Trusted` unmutated):
  - 20261005 × 60: 0 faults, 0 raised, 531 suspects judged by `c2patool`
    0.28.1 under the same settings, none more lenient here;
  - 20261009 × 200: 0 faults, 0 raised, 1,755 judged, none more lenient
    here.
  Step 303's two cases are gone. The CII case is still a suspect, since its
  token is still read, but it is `Invalid` here as in 0.28.1. The CAI case
  is no longer a suspect.
- `composer check`: 953 passed. PHPStan on macOS and in Docker
  `php:8.3-cli`: no errors. (macOS PHPStan asked for `int<0, 255>` on the
  generator's edit byte; Docker did not.)
