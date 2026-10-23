# Step 405 — 0.8.0 released

*2026-10-23.*

- `main` pushed (`5822546..94b659d`); CI 8/8 green on it.
- Tag `v0.8.0` (annotated) on `94b659d`; CI 8/8 green on the tag.
- Packagist lists `v0.8.0` at `94b659d`.
- A fresh `composer require provemark/c2pa-verifier:^0.8.0` in an empty
  directory installed `v0.8.0`; `Verifier::VERSION` is `0.8.0`;
  `c2pa-rs/video1.mp4` is `Invalid` with `assertion.bmffHash.mismatch`
  (SPEC-071 amendment 1), `fixture-signed.jpg` `Valid`
  (`signingCredential.untrusted` without settings), and `toCrJson()`
  writes a document.
- No GitHub release, as before. No advisory (Maurice's decision): the
  findings are in `SECURITY.md` and the CHANGELOG.

Next: the library (`provemark/content-credentials`), the Drupal module,
the WordPress plugin and the demo, each on its own word.
