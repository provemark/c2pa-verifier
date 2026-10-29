# Step 506 — The handler of an excluded track is read behind a 64-bit header too

*2026-10-29.* Finding H2 of the security review before 0.10.0, a wrong
`Trusted`. SPEC-072 amendment 1, approved before the test was written.

## The finding

Pixel Camera videos exclude their timed-metadata track from the BMFF hash
(`/moov[1]/trak[N]`). SPEC-072 (step 360) allows that only when the track's
`mdia/hdlr` names the handler `meta`: a video or audio track's sample table
says which `mdat` bytes are frames, so excluding one could unbind the
picture. The check runs on the file as it is, and the excluded track's
bytes are free to change after signing.

`trackRefusal()` read the handler type at the `hdlr` box's offset + 16.
That is right behind the ordinary 8-byte header only. ISO/IEC 14496-12
§4.2 also allows a 16-byte header: `size` 1, then a 64-bit `largesize`.
Behind it offset 16 holds the full box's version and flags, and the handler
type sits at offset 24, where ffmpeg and other readers take it. A `hdlr`
whose version and flags read `meta` and whose handler is `vide` passed.

## What was measured

- The reviewer's probe before the change: accepted, while the handler at
  header + 8 is `vide`.
- `pixel-shape.mp4` (step 360, `Trusted` in this verifier and in
  `c2patool` 0.28.2) with the excluded third track's `hdlr` (45 bytes,
  at offset 15582) rewritten in memory with a 64-bit header of the same
  length: with version and flags `meta` and handler `vide`, `Trusted`
  with `assertion.bmffHash.match`; with version and flags 0 and handler
  `meta` (the genuine form), `Invalid`, the handler read as `0x00000000`.
  Both cases of the new test failed on `447eb17`.
- After the change: the first `Invalid` with `assertion.bmffHash.mismatch`
  naming `/moov[1]/trak[3]` and "this one's is vide", the second
  `Trusted`. SPEC-072's tests pass (the local Pixel file skipped, as in
  CI). The reviewer's probe is refused. The old `BmffHashCheck` against
  the final test once more: 2 failed.
- The corpus before (`447eb17`, worktree, `vendor/` copied) and after:
  2,166 runs, none differs.
- `composer check` exit 0 (1,672 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0.

## What was done

`trackRefusal()` reads the header length from the box's own size field
(16 when it is 1, else 8) and the handler type at header + 8, after
version and flags and `pre_defined`; the box must hold header + 12 bytes.
The test builds its files in memory from the existing fixture, so no new
fixture is committed.
