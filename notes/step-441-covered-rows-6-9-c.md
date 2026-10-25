# Step 441 — The covered rows of §6 to §9 and Appendix C, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
the last chapter group after step 440: the 23 covered rows of §6 (labels,
references, redaction), §7 (data boxes), §8 (manifests and URIs), §9
(bindings) and Appendix C (constructs a version does not define). No
code changes: tests and the reading.

## How

As in step 440: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 23
mutations. Three rows name no code (no schema validation, nothing
fetched, Appendix C read row by row in step 330); the BMFF, fragmented
and text binding rows of §9.2 were broken in steps 439 and 440.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 18 |
| red only in other tests: citation added | 1: two manifests of one label (`ManifestProbesTest` AC17), where the row still named a scratch probe |
| green everywhere | 4 |

A soft binding counted as a hard binding (§9.3.1, marked "read") was
caught by three tests; the row now names them.

None of the four moved a corpus file. All are now tested:

| rule | what was done |
|---|---|
| §6.4: an actions assertion's `__n` instance is an actions assertion | `ActionsCheckTest` "AC7: an actions assertion is known by its base label, an instance included", with four near-misses that are not |
| §8.4.1: only `self#jumbf=` URIs | `ManifestStoreTest` "AC4: a URI resolves only as self#jumbf, …": `http://x.y/`, a prefix of the same length, is refused. Without the check it resolved |
| §8.4.2.1: an absolute URI resolves only into its own manifest | the same test: `/c2pa/<another label>/…` is refused by `resolve()` itself, not only by the entry check before it |
| §8.4.2.1: without a leading `/` a URI is relative | the same test: `c2pa/<own label>/…` finds no child `c2pa`. This pins the reading the row records (`c2pa-rs` reads it as absolute) |

Each was seen red under its mutation after the change, and green without
it. `composer check`: 1,375 passed, 1 skipped.

## Not done

With this step every covered row has been broken once (steps 431–441).
What stays open is step 4 of the audit, which builds what the sorting left.
