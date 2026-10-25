# Step 446 — The fixtures of SPEC-080, and what `c2patool` says of them

*2026-10-25.* SPEC-080 (a Merkle tree over the `mdat` of a non-fragmented
MP4) was approved in step 445 with the fixtures as its open question 4.
This step builds them: `bin/make-mdat-merkle-variants.php`, 24 files under
`tests/Fixtures/mdat-merkle/`, and the answers of `c2patool` 0.28.3 and
0.28.1 under `tests/Fixtures/c2patool/mdat-merkle/`. No code in `src/`, no
tests yet.

## How

`c2patool` 0.28.3 signs three times `fixture-unsigned.mp4` (ffmpeg 9.0.2,
`-stream_loop 2 -c copy`), with `core.merkle_tree_chunk_size_in_kb` 1 and
8, and the same source with its `mdat` cut in two boxes. The keys are a
throw-away P-256 hierarchy in a scratch directory, overwritten and deleted
when the script ends; only the public root is committed. From the six-block
file the script makes three kinds of variant: bytes changed after signing,
proof boxes changed (no signature covers them), and the BMFF hash assertion
edited and the claim signed again. The README of the fixture directory
lists every file with both verdicts.

A first run re-signed only the claim, and most of those variants failed in
`c2patool` with "BMFF file level hash mismatch": from BMFF hash v2 on, the
file `hash` binds the offset of every top-level box it hashes, and an
assertion of another length moves them all. Those files tested the wrong
thing. The script now computes the file `hash` again
(`BmffHashCheck::plan()` and the offset markers), and `c2patool` no longer
finding a file level mismatch on them is the check on that recomputation.

## What was measured

**Open question 1, the tree shape.** The six-block file's stored root is
the root of the tree that puts the largest power of two on the left (step
82's shape), and also of the tree that pairs neighbours and lifts an odd
last node, which is how `check_merkle_tree()` walks. The two shapes were
then compared as trees for every count from 1 to 1,999: they are the same
tree every time. The question no longer blocks AC1.

**The verdicts.** Every file signed or edited as the spec expects behaves
as SPEC-080 says, in both `c2patool` versions: the good files are
`Trusted`; a changed byte in a block, in the eight bytes before the first
block or in `moov` is `Invalid`; a changed sibling hash, a moved location,
a location past the count, a proof turned into `free` (nothing moves) are
`Invalid`; `fixedBlockSize` with `variableBlockSizes`, a block size of 1, a
count of 5, an `initHash` are `Invalid`; a text block size cannot be
decoded. A removed or doubled proof box is caught by the file `hash`,
because the boxes after it move.

Five files differ from what the spec assumed:

| file | `c2patool` | what was measured next |
|---|---|---|
| `two-mdat` | `Trusted` or `Invalid` from one run to the next, the same file: 10 of 30 runs `Trusted` in 0.28.3, 9 of 20 in 0.28.1 | the same file with one byte changed in either `mdat`: `Invalid` in 60 of 60 runs. A false `Invalid` at random, never a false `Trusted` |
| `hashes-four` | `Trusted`: a stored row of four copies of the root, a row the six-leaf tree does not have | a changed byte in block 0, block 5, before the first block, at byte 16: each `Invalid`. Every byte stays bound |
| `mdat-offset-24`, `mdat-length-1` | `Trusted` | the same four changed bytes: each `Invalid`. Every byte stays bound |
| `variable-sizes` | `Trusted`: `c2pa-rs` reads `variableBlockSizes`, though no setting writes it | the same four: each `Invalid` |
| `mdat-whole` | `Trusted`: the whole `mdat` excluded, the leaves from offset 16 | byte 12 of the `mdat` changed: **`Trusted`**. The bytes 8 to 16 of an `mdat` with an eight-byte header are under neither the file `hash` nor a leaf. A changed byte elsewhere is `Invalid` |

This verifier says `Invalid` for all 24 files today: "two C2PA uuid
boxes" for the 23 with proof boxes, "both a hash and a merkle list" for
`one-block`.

## What was reasoned

- `mdat-whole` is what SPEC-080 AC7 was written for: refusing a `/mdat`
  exclusion other than `subset [{offset: 16, length: 0}]` keeps those eight
  bytes bound. `c2pa-rs` never writes that exclusion itself; a signer has to
  choose it.
- `hashes-four`, `mdat-offset-24` and `mdat-length-1` are refused by
  SPEC-080 as written (AC6, AC7) and accepted by `c2patool`, with every
  byte still bound. ADR-0005 allows the stricter verdict only where it
  protects one; for the two exclusions, SPEC-071 already refuses every
  shape `c2pa-rs` does not write. Whether to keep these three refusals is
  a question for an amendment, not for this step.
- `two-mdat` stays refused by name (AC8, out of scope); `c2patool`'s answer
  for it is not stable enough to be an oracle.

## Not done

The tests and the implementation of SPEC-080. An amendment for the
measured answers above (open question 1 settled; AC6 and AC7 against
`c2patool`; AC8's oracle) is Maurice's call.
