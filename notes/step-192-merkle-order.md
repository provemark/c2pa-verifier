# Step 192 — Merkle location order: measured, nothing to change

*2026-09-30. `c2patool` 0.27.22; this verifier at `c7cd798`.*

## Why

`contentauth/c2pa-rs` PR #2702 was merged to main on 2026-09-28 and is in
no release yet. It says that two equal-sized media chunks, swapped
together with their own Merkle proof boxes, stayed `Trusted`. The fix
requires `location` to be 0, 1, 2, … in physical order.

This verifier checks that a fragment's `location` is inside the tree and
not used twice (SPEC-028 amendment 1). It does not compare `location`
with anything else. So the question was whether the same swap gives a
wrong `Trusted` here.

## What #2702 covers (read in its diff)

`verify_sequential_merkle_locations` is called at three sites:

- the non-fragmented `mdat` path;
- the per-track timed-media path;
- the path that builds leaves from `uuid` boxes.

All three pair Merkle maps with Merkle boxes found **inside one stream**
and sort by byte offset. The route that verifies an init segment with
separate fragment files is not touched.

## Measured

1. **The upstream shape: one file.** `init.mp4` and `seg_1…5.m4s`
   concatenated into one file. This verifier says `Invalid`:
   `general.error: two C2PA uuid boxes at offsets 28 and 14556; a file
   carries at most one manifest store`. The in-file Merkle case is refused
   outright, so the upstream hole does not exist here.

2. **This verifier's route: separate fragments, `seg_2` and `seg_3`
   exchanged.** The two files' contents were swapped, each with its own
   proof. This verifier used `FragmentedVerifier` with fragments in name
   order; `c2patool` used
   `init.mp4 --settings full.settings.json fragment --fragments_glob "seg_*.m4s"`.

   | set | this verifier | `c2patool` 0.27.22 |
   |---|---|---|
   | as signed | `Trusted` | `Trusted` |
   | `seg_2` ↔ `seg_3` | `Trusted` | `Trusted` |

   The verdicts are the same. `c2patool` 0.28.1, the newest, is still on
   `c2pa-rs` 0.91.0, without #2702, so no newer oracle exists.

## Reasoned, not measured

- **Little is gained by the swap.** Each fragment carries its own decode
  time (`tfdt` in its `moof`), and those bytes are inside the fragment's
  hashed leaf. A swapped file still says when it plays.
- **A stricter rule would hurt genuine sets.** It would require `location`
  to equal the fragment's position in the order the caller passes. A
  caller that sorts file names as text passes `seg_10` before `seg_2`, and
  a genuine set would become `Invalid`. `c2patool` does not do this.

## Decided

By Maurice van Loon, 2026-09-30: record it and change nothing. There is a
paragraph in `docs/comparison.md` under the equal verdicts. Look again
when `c2pa-rs` adds an order check to the separate-fragment route, or when
a `c2patool` with #2702 is released.
