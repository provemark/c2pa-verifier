# Step 362 — 0.7.0 released

*2026-10-13.*

- `9be0fdf` pushed; CI run 38393247299 green in all 8 jobs.
- The annotated tag `v0.7.0` on `9be0fdf`, pushed; its CI run 38393503089
  green in all 8 jobs.
- Packagist listed `v0.7.0`.
- A fresh `composer require provemark/c2pa-verifier:^0.7` in an empty
  directory installed `v0.7.0` at `9be0fdf`. Its `c2pa-verify` judged,
  under each fixture's throw-away root:
  - `bmff-exclusion-coverage/mdat-excluded-edited.mp4`: `Invalid`, exit 1;
  - `exclusion-coverage/all-but-store-pixel-edited.png`: `Invalid`, exit 1;
  - `pixel-shape/pixel-shape.mp4`: `Trusted`, exit 0.
- As with earlier releases of this package, there is no GitHub release:
  the tag and the CHANGELOG carry it.

Next, in Maurice's order: the Drupal module `content_credentials` first
(through `provemark/content-credentials`, which conflicts with `>=0.7`),
then the WordPress plugin and the demo.
