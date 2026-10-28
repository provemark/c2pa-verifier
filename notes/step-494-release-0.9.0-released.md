# Step 494 — 0.9.0 released

*2026-10-28.* Maurice: "akkoord, tag v0.9.0 op cf09fcc".

- `main` pushed up to `cf09fcc` (steps 490–493); CI green on it.
- Tag `v0.9.0` (annotated) on `cf09fcc`; CI green on the tag, 8 of 8.
- Packagist lists `v0.9.0`, source `cf09fcc`.
- The archive Composer fetches (GitHub's zipball of `cf09fcc`) holds 629
  files, the count `bin/package-check.php` gives for the dist;
  `Verifier::VERSION` in it is `0.9.0`.
- A fresh `composer require provemark/c2pa-verifier:^0.9` in an empty
  directory installed `v0.9.0`; `Verifier::VERSION` is `0.9.0`.
  `fixture-signed.jpg` is `Valid` without settings (`ocsp.skipped`).
  Under their settings, `revocation-stored-issuer/leaf-alone-revoked.png`
  is `Invalid` (`ocsp.revoked`, SPEC-030 amendment 5) and
  `leaf-root-good.png` on the allowed list `Trusted` with `ocsp.skipped`
  (amendment 6). `claim/assertion-cbor-depth.png` is `Invalid` with
  `general.error`, `claim/assertion-cbor-break.png` with
  `assertion.cbor.invalid` (SPEC-088 amendment 1).
- No GitHub release, as before. No advisory: the `Trusted` that steps
  483 and 490 corrected needed a caller's revocation source, which 0.9.0
  introduces, so no released version carried it.

Before the tag, the fixes of the review of 0.9's code (steps 483–488)
were reviewed again (two findings, steps 490–491), and those fixes once
more (two findings, steps 492–493); the CHANGELOG lists them.

Not done here: the library, the Drupal module (this is the minor it
follows, `^0.9`), the WordPress plugin and the demo, each on Maurice's
word; then findings 5, 8 and 9 of the review of 0.9's code.
