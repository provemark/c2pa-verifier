# Step 508 — A huge `fixedBlockSize` gives a report, not a crash

*2026-10-29.* Finding H1 of the security review before 0.10.0, the first
of the crashes: a `Throwable` that escapes `verify()`. SPEC-080 amendment
3, approved before the test was written.

## The finding

In a non-fragmented MP4 with a Merkle tree over its `mdat` (C2PA 2.4
§A.5.3, SPEC-080) the map gives the size of a block, `fixedBlockSize`.
The verifier computes how many blocks the `mdat` holds and compares that
with the map's `count`. It computed `intdiv(size + fixedBlockSize − 1,
fixedBlockSize)`. With `fixedBlockSize` near 2^63 the sum passes
`PHP_INT_MAX`, PHP makes it a float, and `intdiv()` throws a `TypeError`
that no check catches. No wrong `Valid`; but a caller such as a WordPress
site gets a fatal error instead of an `Invalid` report, from one uploaded
file.

## What was measured

- The reviewer's probe before the change (`six-blocks.mp4` with
  `fixedBlockSize` 2^63 − 1, the file `hash` recomputed): `verify()`
  throws `TypeError: intdiv(): Argument #1 ($num1) must be of type int,
  float given` at `src/Hash/BmffHashCheck.php:284`.
- `bin/make-mdat-merkle-variants.php` makes one more variant,
  `block-size-huge`, the CBOR integer `1b 7f ff ff ff ff ff ff ff` in
  place of `fixedBlockSize` and the claim signed again. Run with
  `c2patool` 0.28.3 and 0.28.1, it signed every file of the directory
  again under new throw-away keys, as each run does; every recorded
  `c2patool` state is what it was. On the new file both versions say
  `Invalid`, with `assertion.bmffHash.mismatch` ("number of hash ranges
  does not match BmffMerkleMap count").
- AC10 before the change: fails with that `TypeError`. After: passes, and
  SPEC-080's 36 tests. The old `BmffHashCheck` against the final test
  once more: fails.
- The reviewer's probe after: `Invalid`.
- The corpus before (`4ff78c9`, worktree, `vendor/` copied, the current
  fixtures) and after: 2,168 runs; only `block-size-huge.mp4` differs, in
  both modes (an error before, `Invalid` after).
- `composer check` exit 0 (1,689 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0, `bin/package-check.php` classifies every path.

## What was done

The block count is `intdiv(size − 1, fixedBlockSize) + 1`, equal to the
old formula for every size of at least 1 (checked just before) and free
of overflow. A block larger than the `mdat` makes one block, and the
`count` and hash checks decide as for any other file: here the count of 6
against 1 block is `assertion.bmffHash.mismatch`, as in `c2patool`.
