# Step 539 — 0.11.1 released

*2026-11-13.* Maurice: "tag en release v0.11.1 maar"; recorded at "leg de
0.11.1-release vast".

- `main` pushed up to `67eabd4` (steps 535–538); CI green on `7aeada1`
  (run 43433002874) and on `67eabd4` (run 43437182906), 8 of 8 each.
- Tag `v0.11.1` (annotated) on `67eabd4`, pushed.
- **CI on the tag** (run 43438381527): the first attempt failed one job,
  `composer check (PHP 8.5)`, on one test:
  `RevocationSourceTest` › *AC6: not asked for an untrusted signer*,
  dataset *Bing under the lists*. It verifies the file twice, without a
  validation time, and compares the two `toJson()`. The Bing signer
  expired on 2026-10-01, so the report carries
  `signingCredential.expired` with "expired at <now>", and the two runs
  fell on either side of a second (`14:01:01Z` against `14:01:00Z`). The
  release did not cause it: the same commit was green on `main`. The
  failed jobs were rerun; attempt 2: 8 of 8 `success`.
- GitHub release `v0.11.1`, its text the CHANGELOG section (as for
  0.11.0): https://github.com/provemark/c2pa-verifier/releases/tag/v0.11.1
- Packagist lists `v0.11.1`, source `67eabd4`.
- A fresh `composer require provemark/c2pa-verifier:^0.11` in an empty
  directory installed `v0.11.1` from `67eabd4`; `Verifier::VERSION` is
  `0.11.1`; 680 files, equal to the tag's `git archive` and to
  `bin/package-check.php` (step 538 counted 679 before its own note was
  committed).
  - `avi/lbox-differs.avi` in crJSON: keys `@context`, `jsonGenerator`,
    `manifests`, `validationResults`, the first failure `general.error`.
  - `fixture-unsigned.png`: the three keys only.
- No advisory: no verdict moves.

## Left

- **The AC6 test depends on the clock** since the Bing signer expired on
  2026-10-01: any run can fail when its two verifications straddle a
  second. A fix (for instance one validation time for both) is a test
  change under SPEC-086 and waits for Maurice's word.
- Downstream: nothing has to move. The Drupal module and the library
  allow `^0.11` / `<0.12` and receive 0.11.1 on their next
  `composer update`.
