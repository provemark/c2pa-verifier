# Step 507 — The BMFF box walk resumes where a container ends

*2026-10-29.* Finding P1 of the security review before 0.10.0. SPEC-029
amendment 4, approved before the test was written.

## The finding

For MP4, MOV, HEIC and AVIF the verifier walks the box tree
(`IsobmffManifestStoreExtractor::descend()`): the top-level boxes, and the
children of containers such as `moov` and `trak`. The BMFF hash rests on
that tree: which bytes are hashed, which are excluded, where the C2PA box
is (C2PA 2.4 §A.5, ISO/IEC 14496-12 §4.2).

The walk reads the stream in order. After a container's children it
stopped once fewer than 8 bytes were left, without passing over those 1 to
7 trailing bytes. The next offset was still computed from the container's
size, but the reader stayed behind by that many bytes, so every later box
header was read from the wrong place.

## What was measured, and what was reasoned

- Measured, the review's probe (a `moov` with 4 trailing bytes, then an
  `mdat`): the walk reads a box `AAAA` of 1,094,795,585 bytes out of the
  `mdat`'s payload and refuses the file.
- Reasoned, not built end to end: with a chosen layout the tree can list
  types and lengths that are not at those offsets in the file, so
  exclusions and hashed ranges apply to other bytes than the file holds
  there — one file read one way by this verifier and another by a player.
- The new test builds sixteen small files in memory: a `moov` with one
  `free` child and 0 to 7 trailing bytes, then an `mdat`, each also with
  the trailing bytes inside a QuickTime-style `meta`. It asks that every
  recorded box's type be the four bytes at its offset + 4, and that the
  `mdat` be listed at its real offset and length. On `2a0c2fb`: the two
  controls (0 bytes) pass, the 14 others fail on a type that is not at
  its offset. After: 16 pass. The old extractor against the final test
  once more: 14 failed.
- The review's probe after: every box at its real offset.
- The other walks in the extractor (`merklePayload()`, the merkle
  proofs, `topLevelBoxes()`, `walk()`) go over the top level only and skip
  `size − read` per box, so they cannot fall out of step this way.
- SPEC-026, -027, -028, -029 and -072 pass. The corpus before (`2a0c2fb`,
  worktree, `vendor/` copied) and after: 2,166 runs, none differs.
- `composer check` exit 0 (1,688 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0.

## What was done

After a container's children `descend()` seeks to the container's declared
end, so the tree follows the file by construction. The trailing bytes stay
part of the container, hashed or excluded with it. They are not refused:
ISO/IEC 14496-12 §4.2 does not forbid them, and a refusal could make a
genuine file `Invalid`.
