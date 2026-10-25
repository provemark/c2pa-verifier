# Step 443 — The Merkle tree on a non-fragmented MP4, measured

*2026-10-25.* The first step of audit step 4 ("build what the sorting
leaves"). Group E of the sorting (step 426) is the only group whose rows
could be built without departing from `c2patool`: a Merkle tree over the
`mdat` of a single, non-fragmented BMFF file (C2PA 2.4 §15.12.2.1,
§18.6.2, §18.6.3, §A.5.6). Such a file is `Invalid` here. Before a spec,
this step measures whether anything writes one and what `c2patool` says.
No code changes.

## How

A throw-away P-256 hierarchy in a scratch directory (the keys were
overwritten with zeros and deleted at the end). `tests/Fixtures/fixture-unsigned.mp4`
(one `mdat` of 1,878 bytes) signed by `c2patool` 0.28.3 three times: with
no setting (control), and with `{"core": {"merkle_tree_chunk_size_in_kb": N}}`
for N = 1 and 4. Each file then judged by `c2patool` 0.28.3 (and 0.28.1
for the changed ones) with the root as trust anchor, and by
`bin/c2pa-verify` with the same settings. The setting is read from the
`c2pa` 0.91.1 source (`settings/mod.rs`: default `None`).

## What was measured

What `c2pa-rs` writes, from `c2patool --detailed` and the bytes:

- The `c2pa.hash.bmff.v3` assertion carries **both** `hash` and `merkle`.
  `hash` covers the file as before, with `/mdat` excluded from offset 16
  to its end (`subset: [{offset: 16, length: 0}]`), so the box header and
  the first 8 data bytes stay under `hash`.
- One merkle map per `mdat`: `count`, `fixedBlockSize` (1,024 or 4,096),
  `alg`, `uniqueId` 0, `localId` 0, and `hashes` with one entry.
- A leaf is the plain SHA-256 of one block, the blocks starting 16 bytes
  into the `mdat` box (`MDAT_EXCLUSION_SIZE` in `bmff_hash.rs`). With
  one block (N = 4) `hashes` is that leaf; with two (N = 1) it is the root,
  SHA-256(leaf 1 ‖ leaf 2), and two top-level uuid boxes of purpose
  `merkle` follow the `mdat`, one per leaf, each with its `location` and
  the sibling hash as proof. All checked by recomputation.

The verdicts:

| file | `c2patool` 0.28.3 | here |
|---|---|---|
| control (no Merkle tree) | `Trusted` | `Trusted` |
| N = 1 (two blocks, proof boxes) | `Trusted` | `Invalid`: `general.error`, "two C2PA uuid boxes": the proof boxes taken for a second store |
| N = 4 (one block) | `Trusted` | `Invalid`: `assertion.bmffHash.mismatch`, "carries both a hash and a merkle list" |
| N = 1, one byte changed in block 1, or in block 2 | `Invalid` (`assertion.bmffHash.mismatch`) | `Invalid` (`general.error`) |
| N = 1 and N = 4, bytes 8–16 of the `mdat` changed | `Invalid` in 0.28.3 and 0.28.1 | not run: refused before |
| N = 1 and N = 4, the first block byte changed | `Invalid` in 0.28.3 and 0.28.1 | not run: refused before |

## What it means (reasoned)

- These files exist only where a writer sets the option; none is in the
  corpus. Where one appears, this verifier calls a good file `Invalid`.
  The difference runs the safe way: no file is more lenient here.
- Building it is one concept with an oracle: `c2patool` writes the files
  and judges changes in them. The pieces this verifier lacks are the
  `hash` and `merkle` together, the leaf ranges from `mdat` + 16 with
  fixed or variable block sizes, and top-level `merkle` proof boxes in a
  non-fragmented file. The tree shape and the proof walk exist already
  for fragmented files (SPEC-028).
- The timed-media form (per-track sample Merkle trees, `c2pa-rs`
  `BmffSampleReader`) was not measured and stays out.

## Not done

A spec. The probes stay in the scratch directory; they become fixtures
with a spec, if Maurice wants one.
