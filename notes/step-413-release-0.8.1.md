# Step 413 — 0.8.1 released

*2026-10-24.* Maurice: "akkoord, push en tag v0.8.1, geen advisory".

- `main` pushed (`94b659d..cfeeab5`); CI 8/8 green on it.
- Tag `v0.8.1` (annotated) on `cfeeab5`; CI 8/8 green on the tag.
- Packagist lists `v0.8.1` at `cfeeab5`.
- A fresh `composer require provemark/c2pa-verifier:^0.8.1` in an empty
  directory installed `v0.8.1`; `Verifier::VERSION` is `0.8.1`. With
  their settings, `graph-fuzz/ingredient-second-reference-wrong-hash.png`,
  `graph-fuzz-cycle/redaction-of-the-active-through-a-cycle.png` and
  `graph-fuzz-cycle-acknowledged/cycle-back-to-the-active-acknowledged.png`
  are `Invalid`, and `graph-fuzz/one-manifest.png` is `Trusted`.
  `fixture-signed.jpg` is `Valid` without settings.
- No GitHub release, as before. No advisory (Maurice's decision): the
  findings are in `SECURITY.md` and the CHANGELOG.

Next: the library (`provemark/content-credentials`), the Drupal module,
the WordPress plugin and the demo, each on its own word.
