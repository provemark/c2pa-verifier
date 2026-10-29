# Step 520 — 0.10.0 released

*2026-10-29.* Maurice: "akkoord, tag v0.10.0".

- `main` pushed up to `9831ee0` (steps 502–519); CI green on `8f0a133`
  and on `9831ee0`, 8 of 8 each.
- Tag `v0.10.0` (annotated) on `9831ee0`; CI green on the tag, 8 of 8.
- Packagist lists `v0.10.0`, source `9831ee0`.
- The archive Composer fetches (GitHub's zipball of `v0.10.0`) holds 659
  files, the count `bin/package-check.php` gives for the dist.
- A fresh `composer require provemark/c2pa-verifier:^0.10` in an empty
  directory installed `v0.10.0` from `9831ee0`; `Verifier::VERSION` is
  `0.10.0`.
  - `fixture-signed.jpg` is `Valid` without settings
    (`signingCredential.untrusted`, `ocsp.skipped`).
  - Under its throw-away root, `cawg/cawg-instance-control.png` is
    `Trusted` and `cawg/cawg-instance.png` `Invalid` with `general.error`
    (SPEC-013 amendment 24).
  - `jumbf/manifest-label-slash.png` is `Invalid` with `general.error`
    (SPEC-005 amendment 3).
  - `evidenceToJson()` writes format `c2pa-verifier/evidence` version 1,
    verifier `0.10.0` (SPEC-089).
- No GitHub release, as before.
- No advisory, by Maurice's decision ("akkoord, alles oplossen voor de
  tag, geen advisory"). The six wrong verdicts the review found are
  recorded in `SECURITY.md` with the versions they were present in
  (step 519).

0.10.0 carries steps 495–499 (prepared in step 500), the security review
of the whole code and its fixes (steps 502–518), and the release text
brought up to date (step 519); the CHANGELOG lists them.

Not done here, each on Maurice's word: the library (SPEC-046,
`readVerified()` with an optional `Verifier`, on `^0.10`), the Drupal
module, the WordPress plugin and the demo.
