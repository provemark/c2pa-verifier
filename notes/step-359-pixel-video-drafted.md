# Step 359 — SPEC-072 drafted: Pixel Camera video

*2026-10-13.*

SPEC-071 named a Pixel video as its trigger, and step 358 found and
measured one (the attachment to `c2pa-rs` issue #2373, kept outside the
repository). It is `Invalid` here and its binding verifies in `c2patool`
0.28.x, for three reasons. SPEC-072 (draft) addresses all three:

- **A.** The QuickTime-style `meta` (no version and flags) is read
  correctly: when bytes 12–15 are `hdlr`, the children start at 8.
- **B.** Indexed xpath steps (`trak[3]`, 1-based, as `c2pa-rs` since PR
  #2434). Malformed brackets are `assertion.bmffHash.malformed`.
- **C.** Pixel's exclusions join the allow-list: `/moov/free`,
  `/moov/udta`, `/moov/meta`, and `/moov[1]/trak[n]` only when that track's
  handler is `meta`. A video or audio track excluded by index stays
  refused.

Measured in the file for the design: the three tracks' handlers are
`vide`, `soun` and `meta` (Pixel excludes the third), and the moov-level
`meta` starts with `hdlr` (handler `mdta`).

The blocking open question is the file's licence. The proposal is to test
it locally behind an environment variable, and to cover CI with a synthetic
fixture of the same shape, its digest confirmed by `c2patool` 0.28.2.

Spec only; no code changes.
