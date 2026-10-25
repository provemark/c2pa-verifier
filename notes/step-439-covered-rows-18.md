# Step 439 — The other covered rows of §18, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
the second of two steps for §18 (step 438 did §18.15 and §18.16): the
40 other covered rows, from the hard-binding table (§18.4) through the
data hash, the BMFF hash, soft binding, cloud data, alternative content,
time-stamp assertions and external references to §18.26. No code
changes: tests and the reading.

## How

As in step 438: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 50
mutations. Eight rows name no code that could be broken (a deprecated
`url` or `extent` nobody reads, `bindingMetadata` unread, nothing
fetched, the cloud-data assertion's own label list unread); step 438
already broke §18.26's `relatedAssertions`.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 30 |
| red in tests the row did not name: citations added | 10: the merkle map's own `alg` (`FragmentedVerifierTest` AC2), the claim's `alg` for a BMFF hash (`ClaimAlgTest` AC10, `BmffProbesTest` AC9), every exclusion applied to every box (`BmffExclusionLimitsTest` AC3), and six on rows marked "read": cloud data standing for a hard binding (`CloudDataCheckTest` AC1, AC7), the three original-preservation-image rules (`AlternativeContentCheckTest` AC3), two time-stamp assertions and an empty token (`TimestampAssertionTest` AC4) |
| red by a hang | 1: an unbalanced Merkle split loops forever, and `FragmentedVerifierTest` dies on it |
| green everywhere | 9 |

None of the nine moved a corpus file. All are now tested:

| rule | what was done |
|---|---|
| §18.4: `c2pa.hash.boxes` and `c2pa.hash.collection.data` are hard bindings | `DataHashCheckTest` "AC6, AC7, AC11: …": every label of the table, an instance of each, counts; four near-misses do not. Without them, a data hash beside a box hash was no longer two hard bindings |
| §18.5.1: `alg` is text | the same test: alg-sha384.png with its `alg` turned into a byte string (the caBX CRC recomputed) is malformed |
| §18.5.1: the hash has the algorithm's length | the same file with `sha384` renamed `sha256`: refused by the length rule, with its own message, before a byte is hashed |
| §18.24.1 (and §18.5.1, §18.6.1, §18.7.1): no external reference names one of thirteen labels | `AssertionRulesTest` "AC4: each of the thirteen labels §18.24.1 lists is refused, one at a time": the test had one label, so dropping any other from the list went unseen |
| §18.6.2: a merkle map `alg` outside the three is refused | `MerkleHashAlgorithmTest` AC1, AC2 now ask for the map's own message: the digest's second guard behind it gave the same code |
| §18.6.2: an `xpath` starts with `/` | `BmffHashCheckTest` "AC5: an xpath without its leading slash is refused, not matched loosely" |
| §18.18.3: a key of the time-stamp map is not empty | `TimestampAssertionTest` "AC4: an empty key is malformed, as an empty token is" |

Each was seen red under its mutation after the change, and green without
it. `composer check`: 1,371 passed, 1 skipped.

## What was reasoned

That a time-stamp map key which is not empty but names no manifest
(§18.18.3 asks for a manifest label) needs no rule of its own: its token
is never offered to any manifest, so it cannot lend one a time. It is
not refused, and this step does not change that.

## Not done

Appendix A; §6–§9 and C (about 47 rows).
