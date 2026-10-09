# Step 306 — The check before 0.5.3, and the release commit

*2026-10-09. 0.5.3 carries SPEC-017 amendment 8, SPEC-005 amendment 2 and
SPEC-015 amendments 7 and 8 (steps 290, 298, 302, 305).*

## Measured

- **Specs:** `php bin/spec-check.php`: 62 specs, all `implemented`, no
  amendment waiting, every open question with a status.
- **Tests:** `composer check` exit 0, 953 tests. PHPStan in Docker
  `php:8.3-cli`: no errors. CI was green on `7715b10` (run 37897052376).
- **Contract:** `php bin/api-check.php`: the recorded surface matches (126
  symbols). `git diff v0.5.2` on `tests/Fixtures/api/public-surface.txt`
  and `src/Report/StatusCode.php` is empty. Seven files of `src/` changed.
  A patch version.
- **Package:** `php bin/package-check.php`: 419 files, 4.2 MB; as a zip
  1.5 MB.
- **Fuzzing, 0.5.2 against 0.5.3:** today's `bin/fuzz.php` and today's
  fixtures in a worktree at `v0.5.2` (with this tree's `vendor/`;
  `composer.json` and `composer.lock` unchanged since the tag), and in this
  tree. Seed 20261005, 60 rounds:
  - **Without settings:** 15,036 runs over 287 files in both, the same 238
    suspects by name, all `Valid` in both `c2patool` versions (steps 297
    and 299). 0.5.2 has 1 fault (`mdat-byte-changed.mp4`, the negative
    serial's PHP notice); 0.5.3 has none.
  - **With `--trust`:** 0.5.3 has 0 faults and 0 raised over 233 pairs;
    its suspects were judged by `c2patool` 0.28.1 in step 305, none more
    lenient. Under 0.5.2 the run stops while pairing: the negative-serial
    probe raises the notice already on the unmutated file, which the
    pairing step does not catch. So there is no 0.5.2 side to compare.
- **The corpus and the matrices** (steps 298, 302, 305): only the new
  probes move. The trust matrix differs from 0.28.1 only on its seven
  stricter probes. The timestamp matrix agrees with SPEC-062's named lists.

## The release commit

- CHANGELOG: *Unreleased* → `0.5.3 — 2026-10-09` with an introduction.
- README: `v0.5.3`; one sentence on what 0.5.3 refuses; the fuzzing figure.
- `docs/comparison.md`: the fuzzing before 0.5.3 and the trust matrix's
  count.
- `SECURITY.md` is unchanged in this commit. Whether the RSASSA-PSS cases
  are recorded there as findings is Maurice's decision.

Who receives it, measured on 2026-10-09:
- **Packagist** lists one dependent, `provemark/content-credentials`
  (`require-dev` and `suggest`, `^0.5`).
- **The WordPress plugin** (`provemark/tracefern-image-check`) requires
  `^0.5.2` and bundles `vendor/` locked at `v0.5.2`.
- **The private Drupal module** names it as `require-dev` and `suggest`,
  `^0.5`.
- **The private measurement repository** requires `^0.4`.
- A GitHub code search for the package name and the namespace found no
  other user. The one PHP hit, `Like4Share/HoloLearn`, calls `c2patool`.

The push, the tag and Packagist wait for Maurice's word.
