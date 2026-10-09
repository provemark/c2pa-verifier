# Step 325 — The reading document caught up with steps 316 to 324

*2026-10-09. Documentation only.*

The fixes of steps 316 to 321 marked their candidates fixed in the lists
of `docs/reading-c2pa-2.4.md` (L1, L3, L7, L9 to L13). Seven rule rows in
the per-section tables were not updated with them and still read
*candidate*. They are now *covered*, each with the step and amendment that
closed it:

| row | rule | closed by |
|---|---|---|
| §8.1 | a manifest is identified by its label | SPEC-007 amendment 7 (step 318) |
| §11.1.4.2 | a version 2 label is a C2PA URN | SPEC-007 amendment 7 (step 318) |
| §11.2.2 | a `c2md` manifest is a manifest | SPEC-007 amendment 7 (step 318) |
| §15.5.1 | the last manifest box is the active one | SPEC-007 amendment 7 (step 318) |
| §15.8.1.1 | more than one timestamp token is malformed | SPEC-017 amendment 9 (step 316) |
| §18.5.1 | no hard binding in cloud data | SPEC-063 (step 321) |
| §18.6.2 | a BMFF hash without `alg` takes the claim's | SPEC-027 amendment 8 (step 317) |

The tallies are now 293 covered, 65 partial, 59 by design, 101 n/a and 43
candidates.

While preparing an overview of the open candidates, an issue that looked
misfiled was checked as well. Issue #7, the fallback to a multi-asset
hash, is recorded as *by design: stricter* in the §15.12.1.1 and §15.12.2
rows, which is right. The row read first, §15.10.1.3, is a different rule
(no multi-asset hash in an update manifest) and is correct as covered.

- No behaviour changes. `composer check`: 972 passed.
