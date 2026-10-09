# Step 340 — 0.6.0 released

*2026-10-09.*

- `bfad0ee` pushed; CI run 37946584984 green in all 8 jobs.
- The annotated tag `v0.6.0` on `bfad0ee`, pushed; its CI run
  37946811406 green in all 8 jobs.
- Packagist listed `v0.6.0` at `bfad0ee` within the minute.
- A fresh `composer require provemark/c2pa-verifier:^0.6` in an empty
  directory installed `v0.6.0` at `bfad0ee`, and its `c2pa-verify` judged
  `manifest-probes/cloud-hash-data.png` `Invalid` (exit 1), as 0.6.0 does
  and 0.5.3 did not.
- As with earlier releases of this package, there is no GitHub release:
  the tag and the CHANGELOG carry it.
- Maurice decided that the closed `Trusted` cases of steps 317 to 327 stay
  under *Changed* in the CHANGELOG; `SECURITY.md` is unchanged.

Next: the WordPress plugin requires `^0.5.3`, so it does not receive
0.6.0 until it asks for `^0.6`; then the demo. Issues #5 and #6 can be
closed. The eight ISOBMFF candidates of the reading are the next round.
