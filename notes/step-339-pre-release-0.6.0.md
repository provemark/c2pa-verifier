# Step 339 — The check before 0.6.0, and the release commit

*2026-10-09. 0.6.0 carries steps 316 to 338: the fixes and the new
checks that came out of reading the whole of C2PA 2.4.*

## Measured

- **Specs:** `php bin/spec-check.php`: 66 specs, all `implemented`, no
  amendment waiting, every open question with a status.
- **Tests:** `composer check` exit 0, 1,008 tests. PHPStan in Docker
  `php:8.3-cli`: no errors (run in every step since 316). CI was green on
  `fdf0dda` (run 37943984677).
- **Contract:** `php bin/api-check.php`: the recorded surface matches (135
  symbols, 126 in v0.5.3). The difference is nine `StatusCode` cases:
  three for cloud data, two for the text wrapper, one for the time-stamp
  assertion and three for the alternative content representation. A
  minor version.
- **Package:** `php bin/package-check.php`: 454 files, 4.6 MB; as a zip
  1.6 MB. `composer.json` and `composer.lock` are unchanged since `v0.5.3`.
- **0.5.3 against 0.6.0:** today's `bin/fuzz.php` and today's fixtures in
  a worktree at `v0.5.3` (with this tree's `vendor/`), and in this tree:
  - **The corpus under every settings file:** 160,160 runs. The verdict
    changes in 4,445 runs over 38 files, every one a probe made for the
    new rules (16 manifest probes, 8 trust matrix, 5 timestamp, 4
    revocation, 3 ISOBMFF, 1 profile, 1 chain constraint). No real file
    changes its verdict. Codes change, verdict unchanged, for three real
    files: `c2pa-rs/update_manifest.jpg` (its time-stamp assertion is
    read), `c2pa-rs/ocsp_with_assertion.jpg` (its certificate-status
    assertion is read, its responses stale) and `hostile-3/keyusage-not-utf8.jpg`
    (the Digital Signature rule adds a reason).
  - **Fuzzing, seed 20261005, 60 rounds, without settings:** 15,036 runs
    under 0.5.3, 15,015 under 0.6.0 (a text file that now stops at a
    corrupted wrapper offers no store to mutate), 0 faults in both, the
    same 238 suspects by name.
  - **With `--trust`:** 0 faults and 0 raised in both (297 pairs under
    0.5.3, 276 under 0.6.0: fewer probes are `Trusted` unmutated, so fewer
    are paired). Every 0.6.0 suspect was judged by `c2patool` 0.28.1 in
    the steps, none more lenient.

## The release commit

- CHANGELOG: *Unreleased* → `0.6.0 — 2026-10-09` with an introduction.
- README: `v0.6.0`; what it adds; coming from 0.5.x; the fuzzing figures.
- `docs/comparison.md`: eight rows on today's differences with `c2patool`
  (in both directions, each by a rule of the specification), the plain
  text row's codes, the fuzzing before 0.6.0, the trust matrix (61
  chains, eleven stricter).
- `SECURITY.md` is unchanged. Several of today's fixes closed a `Trusted`
  where `c2patool` refuses: a version 2 label that is not a URN, a
  duplicated label as the last box, an empty `claim_generator_info`, a
  merkle map without a count, an intermediate without an AKI, an anchor
  without a SKI. Whether they are recorded there as findings is Maurice's
  decision.

The tag, the GitHub release and Packagist wait for Maurice's word.
