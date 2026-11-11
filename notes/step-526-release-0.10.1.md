# Step 526 — Prepare 0.10.1

*2026-11-11.* Maurice: "bereid verifier 0.10.1 voor", and, asked whether a
release that adds a contract symbol should be 0.11.0 by this project's own
rule: "0.10.1".

Steps 521–525 compared this verifier with `c2patool` 0.28.6 (`c2pa`
0.91.11). One change moved a verdict (step 522, the icon that is not a
hashed URI); the others were checked and found nothing more lenient
(523, 524) or added a test (525). This step dates the release.

## What changes

- **`CHANGELOG.md`.** `Unreleased` becomes **0.10.1 — 2026-11-11**, with an
  introduction: the icon rule, the corpus comparison below, and the
  contract symbol. The entries written in step 522 are unchanged.
- **The version.** `Verifier::VERSION` is `0.10.1`.
- **A patch with a contract symbol.** `StatusCode::HashedUriMissing` is
  new (211 → 212 symbols). Elsewhere a contract change waits for a minor
  (`docs/milestones.md`, SPEC-081 to SPEC-089). Maurice chose a patch so
  that callers on `^0.10` — the Drupal module and `content-credentials`
  0.20 — receive the stricter check without a range change. The
  introduction says so, and says what a caller with an exhaustive `match`
  over `StatusCode` needs.
- **`README.md`.** The sentence on the current tag names 0.10.1.
- **`SECURITY.md`.** A "not counted" entry, as for `0.8.2` and `0.8.3`:
  the rule follows `c2pa` 0.91.11 and §10.2.3.2 rather than fixing one that
  broke, and the icon binds nothing the signature covers. The count stays
  at 33. The range `0.1.0` to `0.10.0`: SPEC-034 (step 126) came after
  `v0.1.0`, and before it no icon was checked at all.

## Measured

- **The corpus against `v0.10.0`**, as step 500 did against `v0.9.0`: a
  worktree at `v0.10.0` with a copied `vendor/` (each side loaded its own
  `src/`, both reporting version `0.10.0` before this step's change), the
  current `tests/Fixtures/` read by both, three trust configurations (none,
  `trust/full.settings.json`, the programme's lists through
  `fromTrustLists()`), each without and with a revocation source that
  answers null, validation time 2026-10-27T12:00:00Z, the version string
  removed before hashing `toJson()` and `toCrJson()`. 953 files, 5,718
  runs, 0 exceptions. **Verdicts:** one file moves, in all six
  configurations: `icons/agents-icon.png`, `Valid` → `Invalid`.
  **Output:** 30 runs differ, all in five files — that one and the four
  `icon-forms/` probes of step 522 (codes change, verdicts do not).
  *Corrected in step 528:* at that validation time the probes'
  certificates were not yet valid; at 2026-11-11 the four bad-icon probes
  move `Valid` → `Invalid` too, five files in all.
- `composer check`: 1,719 passed, 1 skipped. `bin/api-check.php`: the
  recorded surface matches. `bin/spec-check.php`: 89 specs.
  `bin/package-check.php`: 665 files, 6.3 MB, 2.2 MB as a zip.

## Left

Push, CI, tag `v0.10.1` and Packagist on Maurice's word. Then tracefern
0.8.1 can bundle it with the trust lists of 2026-11-04.
