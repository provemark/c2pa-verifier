# Step 513 — A merkle block smaller than 1 KiB is refused before it costs

*2026-10-29.* Finding H3 of the security review before 0.10.0, a resource
finding. SPEC-080 amendment 4, approved before the test was written.

## The finding

A merkle map over the `mdat` of a non-fragmented file (C2PA 2.4
§15.12.2.1, §18.6.3) gives a `fixedBlockSize`, and with it the number of
leaves. Each leaf is a read and a hash of its own. Amendment 3 (step 508)
made a huge block size safe; nothing bounded the size from below but the
floor of 2 bytes. The verdict was right. The time was not.

## What was measured

- `checkMdatMerkle()` alone, on one `mdat` of 4 MB with a stored root
  that does not match (every block is hashed before the compare):
  `fixedBlockSize` 2 took 4.6 s (2,000,000 blocks), 16 took 0.58 s, 256
  took 0.04 s, 1,024 took 0.02 s. SHA-256 over the same 4 MB took
  0.01 s. A file of a few megabytes that anyone can upload buys seconds
  of a host's time, and more per megabyte.
- `bin/make-mdat-merkle-variants.php` now also makes
  `block-size-512.mp4`: `six-blocks.mp4` re-signed with `fixedBlockSize`
  512, `count` 11 and the root of the eleven 512-byte blocks, folded as
  c2pa-rs folds, the proof boxes turned into `free` boxes. `c2patool`
  0.28.3 and 0.28.1: `Trusted`, which is also the check on the root. This
  verifier before the change: `Trusted`. After: `Invalid`. Every other
  file the generator re-signed keeps its recorded state in both versions.
- AC11 on `aec67b4`: both tests fail (the file read, and 4.5 s for
  blocks of 2 against 0.02 s for 1,024). After: both pass. The old
  source against the final tests once more: 2 failed.
- The corpus before (`aec67b4`, worktree, `vendor/` copied, the current
  fixtures) and after: 2,188 runs; only `block-size-512.mp4` differs, in
  both modes.
- `composer check` exit 0 (1,702 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0, `bin/package-check.php` classifies every path.

## What was done

`BmffHashCheck::MIN_BLOCK_SIZE` is 1,024. A `fixedBlockSize` under it is
`assertion.bmffHash.mismatch`, named, before any block is read, as
SPEC-080 refuses the other forms it does not read. At 1,024 bytes a block
costs about twice its hash, so the time follows the file's size as for
every other hash.

This is a deliberate difference from `c2patool`, recorded in
`docs/comparison.md`: a file with blocks under 1 KiB and a right root is
`Trusted` there and `Invalid` here. `c2pa-rs` writes blocks in whole KiB
(`merkle_tree_chunk_size_in_kb`); no file with smaller blocks is in the
corpus or known to exist.
