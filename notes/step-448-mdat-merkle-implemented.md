# Step 448 — SPEC-080 built: a Merkle tree over the mdat of a non-fragmented MP4

*2026-10-26.* SPEC-080 with amendment 1 was approved in step 447. This
step writes its tests, sees them red, builds it, and proposes amendment 2
for what the building showed. Maurice approved amendment 2, and SPEC-080
is `implemented`.

## How

`tests/Unit/Hash/MdatMerkleTest.php` was written first: 25 tests, 24 red
on the code of step 447 (every file refused as "two C2PA uuid boxes", or
"both a hash and a merkle list"), the one green test asserting only the
stored `c2patool` answers. Then three changes in `src/`:

- `IsobmffManifestStoreExtractor`: a C2PA box of purpose `merkle` beside
  the manifest box of a file without `moof` is a proof box, skipped by the
  store walk and read by `merkleProofs()`; anywhere else it is refused as
  before. The purpose reading is one function now, `readPurpose()`.
- `BmffHashCheck`: a `hash` beside a `merkle` list is read when the file
  has no `moof`. The file hash first, as before; then `checkMdatMerkle()`:
  the shape of the map, one `mdat`, the blocks from its start + 16 streamed
  one at a time, each against its proof box when there is one, and folded
  into the stored row on a stack of one hash per level. `/mdat` may be
  excluded beside such a tree only with subsets from offset 16 on
  (`mdatRefusal()`).
- `CborDecoder::decodePrefix()`: one data item and where it ends.
  `c2pa-rs` pads every proof box to one size with zero bytes (measured:
  blocks 4 and 5 of six need two siblings, the others three, and every box
  is 175 bytes), so a proof is not the whole of its box.

## What was measured

**Mutations.** 24 mutations of the new code, then 11 more after the first
fixes, each run against the test file (the step-431 harness, every file
restored byte for byte). In the first round 11 survived. Two are
equivalent: a stored hash, or a sibling, of the wrong width can never
equal or produce a 32-byte node. For the others, four files were added to
`bin/make-mdat-merkle-variants.php` and the whole set signed again
(`local-id-one`, `row-of-six`, `row-of-three`, `two-mdat-one-map`), and
four cases were made in memory. Each was asked of `c2patool` 0.28.3 and
0.28.1 before a test pinned it:

| case | `c2patool` | here, before | here, now |
|---|---|---|---|
| a byte of the padding after a proof made `0x41` | `Trusted` | `Invalid` (a strict decode) | `Trusted` |
| a third sibling written into that padding | `Trusted` | `Invalid` (exact length) | `Trusted` |
| a proof box's `uniqueId` 1 | `Trusted` | `Invalid` | `Trusted` |
| `local-id-one`: the map's `localId` 1 | `Trusted` | `Invalid` | `Trusted` |
| `row-of-six`: the leaves stored, proofs made `free` | `Trusted` | `Trusted` | `Trusted` |
| `row-of-three`: a middle row stored, proofs made `free` | `Invalid` ("chunk hash did not match") | `Trusted` | `Invalid` |
| `two-mdat-one-map` | `Invalid` | `Invalid` | `Invalid` |
| `one-block` with a byte of its block changed | `Invalid` | `Invalid` | `Invalid` |
| `six-blocks` with a `moof` box appended | `Invalid` (file hash) | `Invalid` | `Invalid` |

The first four were stricter than `c2patool` and protected nothing: no
signature covers a proof box, and the node a proof reaches is compared
with the signed row whatever else the box holds (ADR-0005). They now
follow `c2pa-rs`. `row-of-three` was the other way round, more lenient
than `c2patool`; a row between the root and the leaves is now refused by
name. Two guards were reached in memory with the check called directly
(no signature is checked there): a `moof` box with the file hash computed
again over it, and the assertion's `hash` key renamed so that the merkle
list reads as SPEC-028's fragmented form, where `/mdat` stays refused.
After the second round every non-equivalent mutant is red.

**The tests.** 35 in `MdatMerkleTest.php`, all green.

**The corpus.** Every fixture, each under its own directory's settings,
with the code of step 447 (a worktree with a copied `vendor/`) and with
this step's: 874 files, and only the 28 of `tests/Fixtures/mdat-merkle/`
move. Six are now `Trusted` (`six-blocks`, `one-block`, `row-of-six`,
`local-id-one`, `mdat-offset-24`, `mdat-length-1`), as in both `c2patool`
versions; the other 22 are `Invalid` for the reason their criterion names.

**`composer check`.** Green except one test, `HostileInputSecondRoundTest`
AC2, which also fails on the code of step 447 in a full serial run: the
process holds about 60 MB from earlier tests (after `gc_collect_cycles()`)
under the local `memory_limit` of 128 MB, and the memory budget then
refuses that test's 8.5 MB chunk ("does not fit this host"), so nothing is
hashed. On its own it passes; `composer test:parallel` and a serial run
with `memory_limit=-1` pass all 1,410 tests (1 skipped). Step 440 saw the
same failure once. It is not this step's to fix.

## What was reasoned

- A stored middle row with proof boxes was never measured; refusing every
  middle row keeps the verdict where `c2patool`'s is known.
- The bound of 4,096 top-level boxes (SPEC-043) now also bounds a file with
  proof boxes at about 4,000 blocks.

## Not done

The memory held across the serial suite (the next step).
