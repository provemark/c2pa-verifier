# Step 432 — The covered rows of §15.10–§15.12, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from step 431: the 90 covered rows of §15.10 (assertions),
§15.11 (ingredients) and §15.12 (the asset's content). No code changes:
tests, four fixtures and the reading.

## How

As in step 431: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked. For the mutations no test caught, the whole
fixture corpus (972 files) was also verified with and without the
mutation, to find a file that already shows the rule. 93 mutations
covered 77 rows. The other 13 name no code of their own (a field that is
never read, data that is never retrieved, the walk that reads past a
skipped ingredient) or are reached through another row's mutation.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 80 |
| red only in other tests: citation fixed | 3: an ingredient manifest's chain, an ingredient manifest's actions, exclusions over the whole file |
| green everywhere | 10 |

The ten, and what became of them:

| rule | what was done |
|---|---|
| §15.10.1.2: `c2pa.hash.data` beside a binding of another kind | `binding/hard-bindings-mixed` (`bin/make-data-hash-variants.php`, the second box under `c2pa.hash.bmff.v2`); `DataHashCheckTest` AC11: `assertion.multipleHardBindings`, counted before either check runs. `c2patool` 0.28.1 and 0.28.3 refuse the file (the BMFF box holds a data hash's fields) |
| §15.10.1.3: a claim thumbnail in an update manifest | `UpdateManifestTest` AC4 (e), through `UpdateManifestCheck::rules()` |
| §15.10.1.3: `c2pa.hash.multi-asset` in an update manifest | the same test; an ingredient thumbnail stays allowed |
| §15.11.2.1: an `inputTo` ingredient's manifest is validated | `IngredientManifestCheckTest` AC3, on `update-manifest/ingredient-inputto.jpg`, the one corpus file that moved under the mutation |
| §15.11.3.3.1: no `claimSignature` for a redacted manifest | `redactions/claim-signature-missing.png` (`bin/make-claim-signature-missing-variant.php`: the key renamed, the hashes recomputed, not re-signed); `RedactionTest` AC2: `ingredient.claimSignature.missing`. `c2patool` 0.28.1 and 0.28.3 refuse the file under the same rule |
| §15.12.2.2: a fragment with no merkle box | `bmff-fragmented/broken/seg_3-no-merkle.m4s` (`bin/make-fragmented-variants.php`: the C2PA `uuid` box retyped `free`); `FragmentedVerifierTest` AC3: `assertion.bmffHash.mismatch` naming the fragment. `c2patool` 0.28.1 and 0.28.3: the same code |
| §15.11.3.3.1: an ingredient manifest's external references | no file holds one; step 433 |
| §15.11.3.3: a v1 redacted ingredient keeps the box hash | recorded, no test: under the mutation it fails as `ingredient.claimSignature.missing`. Stricter, never a wrong `Valid` |
| §15.12.1.1: exclusions after the store shift with an update | recorded, no test: unshifted, they no longer line up and fail as `mismatch`. Stricter, never a wrong `Valid` |
| §15.12.1.1: an exclusion past the end | equivalent at the level of the code: the cover and allowance rules refuse the same exclusion with the same `assertion.dataHash.mismatch`; only the explanation changes |

Each of the six new tests was seen red under its mutation and green
without it. `composer check`: 1,354 passed, 1 skipped.

## What was reasoned

That the two "stricter" survivors cannot hide a wrong verdict in the
direction that counts: each mutation turns a pass into a failure, never
the reverse. A test for them would need a file shape no measured writer
makes.

## Not done

External references in an ingredient manifest (step 433). The covered
rows outside §15 (about 200).
