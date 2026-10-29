# SPEC-080: A Merkle tree over the mdat of a non-fragmented BMFF file

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-25                      |
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

- **AC10 — a block larger than any file is a report, not a crash** *(amendment 3; required: malformed input)*
  - Given `block-size-huge.mp4`, signed by `bin/make-mdat-merkle-variants.php`
    as `six-blocks.mp4` but with `fixedBlockSize` 2^63 − 1, the largest
    integer the CBOR decoder gives
  - When verified under the directory's settings
  - Then a report, not a thrown error: `assertion.bmffHash.mismatch` saying
    the map's count 6 against the 1 block the `mdat` holds, not `Trusted`;
    and `c2patool` 0.28.3's answer is recorded beside it

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
   *Status 2026-10-25 (step 445):* decided by Maurice: settled by
   measurement, not by reading. The first test step signs the AC1 fixture
   and checks its stored row against the shape of step 82 (the largest
   power of two on the left); where they differ, the `c2patool` file
   decides and the step's note says so. Blocking for AC1 until measured.
2. **The status code of a shape fault** (AC6). `c2pa-rs` raises most as
   `HashMismatch`, one as a validation error.
   *Status 2026-10-25 (step 445):* decided by Maurice: the proposal.
   `assertion.bmffHash.mismatch`, as `c2patool` reports it;
   `assertion.bmffHash.malformed` only where SPEC-038 already uses it. Each
   case pinned against the oracle's output.
3. **`variableBlockSizes` and the single-leaf form** (AC8).
   *Status 2026-10-25 (step 445):* decided by Maurice: the proposal.
   Refused by name until a writer is found. The rule for a later spec: the
   variable sizes add up to the `mdat` size minus 16; a map with neither
   field is one leaf over the whole `mdat` from offset 16.
4. **The fixtures.**
   *Status 2026-10-25 (step 445):* decided by Maurice: the proposal.
   `bin/make-mdat-merkle-variants.php`, built like
   `bin/make-bmff-probe-variants.php`: a throw-away hierarchy in a
   temporary directory, overwritten and deleted at the end; an unsigned MP4
   made with `ffmpeg`, large enough for AC1. The signed files, the public
   root and the `c2patool` outputs are committed under
   `tests/Fixtures/mdat-merkle/`; no key ever is.

## Amendments

1. **2026-10-25, step 447, approved by Maurice van Loon ("akkoord")** — what step 446 measured against the fixtures
   (`tests/Fixtures/mdat-merkle/`, `notes/step-446-mdat-merkle-fixtures.md`).
   - **Open question 1 settled.** The six-block file's stored root is the
     root of step 82's shape, and that shape is the same tree as the one
     `check_merkle_tree()` walks for every count from 1 to 1,999. AC1 no
     longer waits on a measurement.
   - **AC1** becomes: `six-blocks.mp4` (six blocks of 1,024 bytes, the last
     482, the root stored, six proof boxes) is `Trusted` with
     `assertion.bmffHash.match` and, beside it,
     `assertion.bmffHash.additionalExclusionsPresent`, as `c2patool` 0.28.3
     and 0.28.1 report; the explanation names six blocks.
   - **AC2** becomes: `one-block.mp4` (8 KB blocks, count 1, the leaf stored,
     no proof boxes) is `Trusted`, as in both `c2patool` versions.
   - **AC3** becomes: `block-0-changed`, `block-5-changed`, `gap-changed`
     (byte 12 of the `mdat`) and `moov-changed` are each `Invalid` with
     `assertion.bmffHash.mismatch`, as in both versions; the explanation
     names a block by its index for the first two and the file `hash` for
     the other two.
   - **AC4** becomes: `proof-sibling-changed`, `proof-location-moved`,
     `proof-freed` (a proof box turned into a `free` box of the same size:
     nothing moves, five proofs for six blocks), `proof-removed` and
     `proof-duplicated` are each `Invalid` with
     `assertion.bmffHash.mismatch`, as in both versions. The last two are
     caught by the file `hash` before any proof is read, since the boxes
     after them move; `proof-freed` is the file that tests the count of
     proofs.
   - **AC6** becomes: `fixed-and-variable`, `block-size-one`, `count-five`,
     `init-hash` and `proof-location-past-count` are `Invalid` with
     `assertion.bmffHash.mismatch`, as in both versions;
     `block-size-text`, which `c2patool` cannot decode, is
     `assertion.bmffHash.malformed`. `hashes-four` (the stored row four
     copies of the root, a row the six-leaf tree does not have) stays
     `Invalid` with `assertion.bmffHash.mismatch`, where both `c2patool`
     versions say `Trusted`. By design under ADR-0005: §15.12.2.1 defines
     `hashes` as a row of the tree, and a structure that is not what the
     specification defines is refused rather than read some other way. A
     changed byte in that file is `Invalid` in `c2patool` too, so nothing
     else is lost.
   - **AC7** becomes a rule on what an `/mdat` exclusion may leave out,
     instead of a single allowed shape. An `/mdat` exclusion is allowed
     only with a `subset`, and only when a merkle map binds that `mdat`;
     each subset must start at offset 16 or later, where the leaves begin.
     Then every byte the `hash` leaves out is under a leaf.
     `mdat-offset-24` and `mdat-length-1` are `Trusted`, as in both
     `c2patool` versions: neither leaves a byte unbound (a changed byte in
     block 0, block 5, byte 12 or byte 16 is `Invalid` in both).
     `mdat-whole` stays `Invalid`, where both `c2patool` versions say
     `Trusted`: the bytes 8 to 16 of an `mdat` with an eight-byte header
     are under neither the `hash` nor a leaf, and `mdat-whole` with byte 12
     changed is still `Trusted` there. That copy is made in the test, from
     `mdat-whole.mp4`, and must be `Invalid` here. By design under
     ADR-0005: it prevents trust in bytes nobody checked, the same gap
     `docs/comparison.md` records for an `/mdat` exclusion since SPEC-071.
     The Scope's second bullet ("exactly `subset: [{offset: 16, length:
     0}]`") reads accordingly.
   - **AC8** becomes: `variable-sizes`, `no-block-field` and `two-mdat` are
     each `Invalid` with `assertion.bmffHash.mismatch` and an explanation
     saying this verifier does not read that form. `c2patool` says
     `Trusted` for `variable-sizes` (`c2pa-rs` reads `variableBlockSizes`,
     though no setting of it writes one), `Invalid` for `no-block-field`,
     and `Trusted` or `Invalid` from one run to the next for `two-mdat`,
     the same file (10 of 30 runs `Trusted` in 0.28.3). That answer is no
     oracle; the test pins this verifier's refusal alone. Out of scope, as
     the Scope says: each needs its own spec.
   - The differences of AC6, AC7 and AC8 get rows in `docs/comparison.md`
     when this spec is implemented.

2. **2026-10-26, step 448, approved by Maurice van Loon ("akkoord")** — what building the spec showed. Four mutants of the first
   implementation survived its tests; the files made to catch them
   (`local-id-one`, `row-of-six`, `row-of-three`, `two-mdat-one-map`,
   signed by the same script) and four changes made in memory were asked
   of both `c2patool` versions first.
   - **The stored row is the root or the leaves.** The Scope's fourth
     bullet ("the row of the computed tree that has as many nodes") is
     narrowed: a row between the two is refused by name. Without proof
     boxes `c2pa-rs` calls such a row a mismatch (`row-of-three`: `Invalid`
     in both versions, "chunk hash did not match"); with proof boxes no file
     was measured. The verdict means what `c2patool`'s means. `row-of-six`
     (the leaves stored, no proof boxes) is `Trusted`, as in both versions
     (AC2).
   - **What a proof box says beyond its proof is not read.** Bytes after the
     proof (`c2pa-rs` pads every proof box to one size with zeros), sibling
     hashes past the stored row, and the proof's `uniqueId` and `localId`
     are each `Trusted` in both `c2patool` versions, measured on
     `six-blocks.mp4` changed in memory. No signature covers a proof box,
     and the node a proof reaches is compared with the signed row whatever
     else the box holds, so refusing them would protect nothing (ADR-0005).
     A proof too short for the climb is still a mismatch.
   - **A map's `uniqueId` and `localId` select nothing when there is one map
     and one `mdat`.** `local-id-one` is `Trusted`, as in both versions.
     More than one map, or more than one `mdat`, stays out of scope
     (`two-mdat-one-map`: `Invalid` here and in both versions).
   - **Only without `moof`.** Proof boxes in a file with `moof` are refused
     by the extractor as before (a purpose it does not read), and a hash
     beside a merkle list in such a file is a mismatch. Without the `hash`,
     a merkle list is SPEC-028's fragmented form, and an `/mdat` exclusion
     stays refused there by SPEC-071's allow-list.
   - **A bound that follows.** SPEC-043's limit of 4,096 top-level boxes
     holds; with one proof box per block it refuses a file of more than
     about 4,000 blocks.
   - `CborDecoder::decodePrefix()` reads one data item and says where it
     ends, for the padded proof boxes. `decode()` is unchanged.

3. **2026-10-29, step 508, found by the security review before 0.10.0; measured** *(approved by Maurice van Loon, 2026-10-29)* —
   The number of blocks was computed as `intdiv(size + fixedBlockSize − 1,
   fixedBlockSize)`. With a `fixedBlockSize` near 2^63 the sum passes
   `PHP_INT_MAX`, becomes a float, and `intdiv()` throws a `TypeError` that
   no check catches: measured on the review's probe (`six-blocks.mp4` with
   `fixedBlockSize` 2^63 − 1 and the assertion's hash recomputed),
   `verify()` throws instead of returning a report. No wrong `Valid`, but
   whoever can upload a file can make a caller fail.

   Now the same number is computed as `intdiv(size − 1, fixedBlockSize) +
   1`, equal for every size of at least 1 (checked just before) and free
   of overflow. A block larger than the `mdat` makes one block, and the
   `count` and hash checks that follow decide as for any other file. New
   criterion AC10.

   **Weight A.** A file that threw now gets a report. Nothing else moves:
   for every value that did not overflow the two formulas agree.

## Traceability

All in `tests/Unit/Hash/MdatMerkleTest.php`, group `SPEC-080`, unless named
otherwise.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | AC1: six blocks with six proof boxes are Trusted, as c2patool says | src/Hash/BmffHashCheck.php :: check(), checkMdatMerkle() |
| AC2 (amendment 2)    | AC2: one block, the leaf stored and no proof boxes, is Trusted, as c2patool says; AC2: the leaves stored as the row, with no proof boxes, are Trusted, as c2patool says | src/Hash/BmffHashCheck.php :: checkMdatMerkle() |
| AC3                  | AC3: a changed byte in a block, before the first block or in moov is found, and named (4 datasets); AC3: with no proof boxes, a changed block is found by the stored row | src/Hash/BmffHashCheck.php :: check() (the file hash), checkMdatMerkle() (the fold) |
| AC4 (amendment 2)    | AC4: a proof box is checked, not trusted (5 datasets); AC4: what follows a proof in its box is not read, as c2patool does not read it; AC4: a proof box's ids and any hash past the climb are not read, as c2patool does not read them | src/Hash/BmffHashCheck.php :: checkMdatMerkle(), climb(); src/Cbor/CborDecoder.php :: decodePrefix() |
| AC5 (amendment 2)    | AC5: the proof boxes are not stores; a second manifest box, or a purpose this verifier does not read, still is refused; AC5: a hash beside a merkle list is read only in a file without moof | src/Container/IsobmffManifestStoreExtractor.php :: walk(), readPurpose(), merkleProofs() |
| AC6 (amendment 2)    | AC6: a merkle map or a proof that is not the shape SPEC-080 reads is refused, with the reason named (8 datasets); AC6: a map's localId selects nothing when there is one map and one mdat, as c2patool reads it | src/Hash/BmffHashCheck.php :: checkMdatMerkle() |
| AC7 (amendment 1)    | AC7: an /mdat subset from offset 16 on leaves no byte unbound and is read, as c2patool reads it; AC7: an /mdat exclusion is read only beside a merkle tree, and only from offset 16 on; AC7: the whole /mdat excluded leaves eight bytes under neither the hash nor a leaf, and is refused where c2patool trusts it; AC7: without the file hash, a merkle list is a fragmented stream's, and /mdat stays refused there | src/Hash/BmffHashCheck.php :: refusedExclusion(), mdatRefusal() |
| AC8                  | AC8: the forms SPEC-080 leaves out are refused by name (4 datasets); AC8: what c2patool says of those forms, kept beside the refusal | src/Hash/BmffHashCheck.php :: checkMdatMerkle() |
| AC9                  | tests/Unit/Verifier/FragmentedVerifierTest.php and tests/Unit/Hash/BmffHashCheckTest.php (SPEC-027, SPEC-028), unchanged and green; the corpus before and after (step 448): only `tests/Fixtures/mdat-merkle/` moves | — |
| AC10 (amendment 3) | tests/Unit/Hash/MdatMerkleTest.php :: AC10: a block larger than any file is a report, not a crash (amendment 3) / SPEC-080 | src/Hash/BmffHashCheck.php :: checkMdatMerkle() (the block count); bin/make-mdat-merkle-variants.php (`block-size-huge`) |
