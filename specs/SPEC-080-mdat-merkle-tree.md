# SPEC-080: A Merkle tree over the mdat of a non-fragmented BMFF file

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §15.12.2.1 and §18.6.2 let a BMFF hash bind the media data of a
single, non-fragmented file with a Merkle tree: the `mdat` box is cut into
blocks, each block is a leaf, and the assertion carries a row of the tree
(`merkle`) beside the ordinary `hash` over the rest of the file. A player
can then check a block it has read without hashing the whole file.

This verifier reads a Merkle tree only for fragmented files (SPEC-028). A
non-fragmented file that carries one is refused, for two reasons: an
assertion with both `hash` and `merkle` is refused outright
(`BmffHashCheck::assertionOf()`), and the `merkle` uuid boxes that carry
the proofs are taken for a second manifest store
(`IsobmffManifestStoreExtractor`). The audit of C2PA 2.4 sorted these rows
as group E: unsupported, failing closed, could be built
(`docs/reading-c2pa-2.4.md`, *The open rows sorted*).

Step 443 measured that such files exist wherever a writer asks for them:
`c2patool` 0.28.3 writes one when `core.merkle_tree_chunk_size_in_kb` is set
(off by default), says `Trusted`, and reports `assertion.bmffHash.mismatch`
for a changed byte in any block or in the 16 bytes before the first
(`notes/step-443-mdat-merkle-measured.md`). Here the same good file is
`Invalid`. The difference runs the safe way, but a file `c2pa-rs` writes by
a documented setting should not be refused by a verifier whose verdict
means the same as `c2patool`'s (ADR-0005).

## Scope

**In scope**

- One `mdat` box at the top level of a file without `moof`, bound by one
  merkle map with `fixedBlockSize` and no `initHash`, as `c2pa-rs` 0.91
  writes it.
- The assertion's `hash` verified as today, with one more allowed
  exclusion: `/mdat` with exactly `subset: [{offset: 16, length: 0}]`, and
  only when the merkle map binds that `mdat`.
- The leaves: from the `mdat` box's start + 16 to its end, in blocks of
  `fixedBlockSize`, the last one shorter; each leaf the digest of its block
  alone, by the map's `alg`, else the assertion's (SPEC-051).
- The stored row (`hashes`): compared with the row of the computed tree
  that has as many nodes, the root when it has one.
- Top-level C2PA uuid boxes whose purpose is `merkle`, in a non-fragmented
  file: read as proof boxes (one per leaf, its `location` and its sibling
  hashes), each proof walked from its computed leaf to the stored row.
  Never a second manifest store.

**Out of scope** (each needs its own spec before it may be built)

- `variableBlockSizes`, and a merkle map with neither block field (one leaf
  for the whole `mdat`). The specification defines both; no writer measured
  produces them. Refused by name.
- More than one `mdat`, or more than one merkle map (`uniqueId`, `localId`).
  Refused as today (SPEC-028: "this verifier reads one").
- The timed-media form, a tree per track over samples (`c2pa-rs`
  `BmffSampleReader`). Not measured.
- A fragmented file: SPEC-028, unchanged.

## Behavior

- **AC1 — several blocks, with proof boxes**
  - Given a file signed by `c2patool` 0.28.3 with
    `core.merkle_tree_chunk_size_in_kb` 1 over an `mdat` large enough for at
    least five blocks, a count that is not a power of two
  - When it is verified with the signer's root as trust anchor
  - Then the verdict is `Trusted` with `assertion.bmffHash.match`, as
    `c2patool` 0.28.3 says, and the explanation names the number of blocks

- **AC2 — one block, no proof boxes**
  - Given the same fixture signed with a block size larger than the `mdat`
    (count 1, the leaf stored, no `merkle` boxes)
  - When it is verified
  - Then `Trusted`, as `c2patool` says

- **AC3 — a changed byte is found wherever it is** *(error path)*
  - Given copies of the AC1 file with one byte changed: in the first block,
    in the last (short) block, in the 16 bytes before the first block, and
    in `moov`
  - When each is verified
  - Then each is `Invalid` with `assertion.bmffHash.mismatch`, as
    `c2patool` says, and the explanation names which part failed (the file
    `hash`, or a block by its index)

- **AC4 — a proof box is checked, not trusted** *(error path)*
  - Given copies of the AC1 file with a byte changed in a proof box's
    sibling hash, a proof box's `location` changed to another leaf's, a
    proof box removed, and a proof box duplicated
  - When each is verified
  - Then each is `Invalid` with `assertion.bmffHash.mismatch`; the
    `c2patool` verdict for each is recorded beside it, and none is more
    lenient here

- **AC5 — proof boxes are not stores**
  - Given the AC1 file
  - When its manifest store is extracted
  - Then the store is the `manifest` box's, byte-exact, and the `merkle`
    boxes are listed as proof boxes. A second C2PA box of purpose
    `manifest`, or of any other purpose than `merkle`, is still refused as
    today (SPEC-003, §A.5.3)

- **AC6 — the shape, fail closed** *(error path)*
  - Given AC1 variants whose assertion is changed and signed again: both
    `fixedBlockSize` and `variableBlockSizes`; a `fixedBlockSize` that is
    not an integer, or below 2; a `count` that is not the number of blocks;
    an `initHash` in a file without `moof`; a `hashes` row whose length is
    no row of the tree; proof boxes whose locations repeat or reach past
    `count`
  - When each is verified
  - Then each is `Invalid` with `assertion.bmffHash.mismatch` or
    `assertion.bmffHash.malformed`, never `Valid`, with the reason named

- **AC7 — every byte of the mdat is bound** *(error path)*
  - Given AC1 variants whose `/mdat` exclusion is changed and signed again:
    no `subset` (the whole box), `offset` 24, a `length` other than 0
  - When each is verified
  - Then each is refused by the SPEC-071 allow-list as an exclusion this
    verifier does not accept: with the whole box excluded, bytes 8 to 16 of
    an `mdat` with an 8-byte header would be under neither the `hash` nor a
    leaf

- **AC8 — out of scope is refused by name** *(error path)*
  - Given a merkle map with `variableBlockSizes`, one with neither block
    field, and a file with two `mdat` boxes and two maps
  - When each is verified
  - Then each is `Invalid` with `assertion.bmffHash.mismatch` and an
    explanation saying this verifier does not read that form

- **AC9 — nothing else moves**
  - Given the fixture corpus with each directory's settings
  - When it is verified before and after
  - Then only the files of this spec move; SPEC-028's fragmented files and
    every other BMFF file give the same statuses

## References

- Specification: C2PA 2.4 §15.12.2, §15.12.2.1, §18.6.2, §18.6.3, §A.5.4,
  §A.5.6.
- Oracle: `c2patool` 0.28.3 (and 0.28.1 for the changed files), with the
  throw-away root as `trust_anchors`; files signed by `c2patool` 0.28.3
  with `{"core": {"merkle_tree_chunk_size_in_kb": N}}`, as in step 443.
- Measured (step 443): the shape `c2pa-rs` writes (both `hash` and
  `merkle`; `/mdat` excluded from offset 16; leaves from `mdat` + 16; the
  root stored for two leaves, the leaf for one; one proof box per leaf
  holding the sibling); `Trusted` there, every changed block caught.
- Reasoned (`c2pa` 0.91.1, `assertions/bmff_hash.rs`):
  `validate_merkle_maps_mdat_boxes()` and `check_merkle_tree()`. Whether its
  tree puts the largest power of two on the left for a non-fragmented file,
  as step 82 measured for fragmented ones, is read, not measured: AC1's
  count is chosen so that the answer shows.

## API sketch

Illustrative only.

```php
// namespace Provemark\C2paVerifier\Hash;

final class BmffHashCheck
{
    // check(): when the assertion has `merkle` and the file has no moof, the
    // file `hash` first (as today), then checkMdatMerkle(); with moof,
    // checkMerkle() as in SPEC-028.
    private function checkMdatMerkle(string $url, $stream, array $boxes, array $assertion): array;

    /** The blocks of one mdat: offset and length, from start + 16. */
    public static function mdatBlocks(int $mdatOffset, int $mdatLength, int $blockSize): array;
}

// namespace Provemark\C2paVerifier\Container;

final class IsobmffManifestStoreExtractor
{
    // walk(): a C2PA uuid box of purpose `merkle` in a file without moof is a
    // proof box, kept by offset; it is not a second store.
}
```

## Open questions

1. **The tree shape for five or more leaves** (blocking for AC1). Step 82
   measured the shape for fragmented files; `check_merkle_tree()` walks the
   index bit by bit. If the two differ for a count that is not a power of
   two, the measured `c2patool` file decides.
2. **The status code of a shape fault** (AC6). `c2pa-rs` raises most as
   `HashMismatch`, one as a validation error. Proposal: `mismatch` as
   `c2patool` reports it, `malformed` only where SPEC-038 already uses it;
   each pinned against the oracle's output.
3. **`variableBlockSizes` and the single-leaf form** (AC8). Proposal:
   refuse by name until a writer is found; the rule (the sizes add up to
   the `mdat` size minus 16) is written down in this spec so that a later
   one can build on it.
4. **The fixtures.** Proposal: `bin/make-mdat-merkle-variants.php`, as
   `bin/make-bmff-probe-variants.php`: a throw-away hierarchy in a
   temporary directory, deleted at the end; an unsigned MP4 made with
   `ffmpeg` large enough for AC1; the files, the public root and the
   `c2patool` outputs committed under `tests/Fixtures/mdat-merkle/`.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
