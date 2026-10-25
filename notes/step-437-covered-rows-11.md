# Step 437 — The covered rows of §11, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from step 436: the 21 covered rows of §11 (the JUMBF layout of
a manifest store, labels, manifest types, update manifests). No code
changes: tests and the reading.

## How

As in step 436: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 28
mutations covered 20 rows. The 21st (store and manifest may hold boxes
of other UUIDs) names no code: such boxes are ignored, which
`JumbfParserTest` AC7 shows.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 22 (one in a row that said "read": an update manifest with a thumbnail, held by `UpdateManifestTest` AC4 since step 432) |
| red only in other tests: citation fixed | 2: the version 2 manifest label (`ManifestProbesTest` AC18, AC20) and `c2md` read as a manifest (`ManifestProbesTest` AC16), both rows that cited a probe or nothing |
| green everywhere | 4 |

None of the four moved a corpus file. All four are now tested:

| rule | what was done |
|---|---|
| §11.1.4.1: no C1 controls in a label | `JumbfParserTest` AC12 tried a slash and a C0 control only. Now also U+0085, each in place of the control byte of the existing `label-control` variant, the label's length kept |
| §11.1.4.1: no U+FEFF or U+FFFF | the same test |
| §11.1.4.1: UTF-8, no surrogates | the same test: U+D800 encoded, and a byte 0xFF |
| §11.1.4.2: one assertion store, one claim signature | `ManifestStoreTest` "AC12: a manifest holds one assertion store and one claim signature, never two": the box copied after itself in `fixture-signed.png`'s store, the manifest's and the store's LBox grown by its length |

Each was seen red under its mutation after the change, and green without
it. `composer check`: 1,365 passed, 1 skipped.

Found on the way, not changed: the refusal reads "2 assertion storees":
`Manifest::theOne()` makes a plural by appending "es". The test matches
the count, not the plural.

## Not done

The covered rows of §18, Appendix A, and §6–§9 and C (about 100).
