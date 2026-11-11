# Step 527 — 0.10.1 released

*2026-11-11.* Maurice: "push maar", then "tag en release v0.10.1 maar".

- `main` pushed up to `51c661d` (step 526); CI green, 8 of 8.
- Tag `v0.10.1` (annotated) on `51c661d`; CI green on the tag, 8 of 8.
- Packagist lists `v0.10.1`, source `51c661d`.
- A fresh `composer require provemark/c2pa-verifier:^0.10` in an empty
  directory installed `v0.10.1` from `51c661d`: 666 files, the count
  `bin/package-check.php` gives for the dist; `Verifier::VERSION` is
  `0.10.1`.
  - `icons/agents-icon.png` is `Invalid` with `hashedURI.missing`
    (SPEC-034 amendment 3).
  - `fixture-signed.jpg` is still `Valid` without settings
    (`signingCredential.untrusted`).
- No GitHub release, as before.

Because it is a patch, the Drupal module and `content-credentials` 0.20
(both `^0.10`) receive it on their next `composer update` without a
change. Next, on Maurice's word: the WordPress plugin (tracefern 0.8.1,
with the trust lists of 2026-11-04).
