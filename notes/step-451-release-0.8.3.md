# Step 451 — The release commit for 0.8.3

*2026-10-26.* Maurice: "akkoord, bereid 0.8.3 voor".

0.8.3 carries steps 425–450: the reading of C2PA 2.4 brought up to date
and sorted (425, 426, 450), the four questions of group A (427–430, two
of which changed a verdict to `c2patool`'s and one made an ingredient's
staple count), every covered rule broken once (431–441), a plural (442),
and SPEC-080 (443–448). Step 449 changed a test only.

**A patch, not a minor.** The question was put to Maurice in this
session. Until now a minor came when the public contract changed (0.7.0
a property, 0.8.0 parameters); reading a new form or judging more
strictly was a patch (0.6.1 read the data-box icons, 0.8.2 refused two
signatures). `bin/api-check.php` reports the recorded surface unchanged,
142 symbols. A caller on `^0.8`, the Drupal module among them, receives
0.8.3 with `composer update` and no change of constraint. Maurice's
reason: the Drupal module should not have to follow every change of the
verifier. What does change the contract (new status codes for group F,
the additions of 2.5) is to be gathered into one later minor.

What the release commit does:

- The CHANGELOG gains **0.8.3 — 2026-10-26** (Added, Changed,
  Documentation). The README names the new tag. `Verifier::VERSION` is
  `0.8.3`.
- `SECURITY.md` names the two files that were `Trusted` here and
  `Invalid` in `c2patool`, and why they are not counted: no byte went
  unhashed and no signature unchecked, and the change follows
  `c2patool`'s rule, as for 0.8.2. Maurice may decide otherwise.

Measured:

- The corpus against `v0.8.2` (a worktree with a copied `vendor/`, the
  current `tests/Fixtures/` copied once and read by both): 33 of 874 files
  moved, each a fixture new since 0.8.2. The 28 of `mdat-merkle/` (six now
  `Trusted`), `claim-alg/entries-alg-bmff-no-alg.mp4`,
  `ingredient-staple/child-staple-revoked.png` and three of
  `opened-references/`, each `Trusted` on 0.8.2 and `Invalid` now. No
  older fixture moved.
- `bin/package-check.php`: 588 files, 5.6 MB in the dist, 2.0 MB as a
  zip; every top-level path classified.
- `composer check`: 1,410 passed, 1 skipped; `bin/spec-check.php`: 80
  specs.

Not done here: the push, the tag, Packagist, and the library, Drupal,
plugin and demo updates wait for Maurice's word.
