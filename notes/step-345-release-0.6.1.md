# Step 345 — 0.6.1 released

*2026-10-10.*

- `ea2d9f7` pushed; CI run 38041043054 green in all 8 jobs.
- The annotated tag `v0.6.1` on `ea2d9f7`, pushed; its CI run
  38041151610 green in all 8 jobs.
- Packagist listed `v0.6.1` at `ea2d9f7`.
- A fresh `composer require provemark/c2pa-verifier:^0.6` in an empty
  directory installed `v0.6.1` at `ea2d9f7`, and its `c2pa-verify`, under
  the C2PA test roots, judged the C2PA Sign fixture with a site logo
  (`tests/Fixtures/databox/c2pasign-logo.png`) `Trusted` (exit 0) with no
  data-box status, where 0.6.0 said `Invalid`.
- As with earlier releases of this package, there is no GitHub release:
  the tag and the CHANGELOG carry it.

Next: the Drupal module `content_credentials` drops its known issue about
the verifier route and C2PA Sign logos, and reruns its suite on 0.6.1.
