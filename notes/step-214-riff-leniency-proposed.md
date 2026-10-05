# Step 214 — Strict about the `C2PA` chunk, lenient about the rest: proposed

*2026-10-05. Three amendments proposed, none approved yet: SPEC-003
amendment 3, SPEC-055 amendment 3, SPEC-013 amendment 16. Nothing in
`src/` or `tests/` changed.*

## Why

Step 213's review found, and both `c2patool` versions confirmed, that an
ordinary unsigned WAV or WebP with something harmless outside the store
(bytes after the RIFF chunk, a missing last pad byte) is reported here as
a file **with** a manifest that is `Invalid`. `c2patool` finds no claim. A
host showing this verifier's answer would mark ordinary audio as broken
Content Credentials. Maurice decided the direction the same day: strict
about the `C2PA` chunk, lenient as `c2patool` about the rest, and
`has_manifest` only when a `C2PA` chunk was reached.

## One more measurement

What `c2patool` does with a non-zero pad byte after a chunk that is not
`C2PA` had not been measured. A 3-byte chunk with pad `FF` was inserted
into the fixtures:

| file | this verifier today | `c2patool` 0.27.22 | 0.28.1 |
|---|---|---|---|
| unsigned, before `data` | `has_manifest: true`, `Invalid`, `general.error` | *No claim found* | *No claim found* |
| signed, before `C2PA` | `Invalid`, `general.error` | `Invalid`, `assertion.dataHash.mismatch` | the same |

`c2patool` does not check that pad byte. That settles "the rest": a pad
byte after another chunk is skipped unchecked.

## The proposal, file by file

Every RIFF file in the fixtures was verified today (the "today" column is
measured). The "proposed" column is what the amendments say; it is
reasoned until the tests exist. Files not listed keep their report
exactly.

| file | today | proposed | `c2patool` 0.27.22 / 0.28.1 |
|---|---|---|---|
| `webp/riff-size-excludes-c2pa.webp`, `wav/riff-size-excludes-c2pa.wav` | manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |
| `wav/trailing-bytes.wav` | manifest, `Invalid`, `general.error` | **manifest, `Invalid`, `assertion.dataHash.mismatch`** | the same as proposed |
| `wav/second-riff.wav` | manifest, `Invalid`, `general.error` | **manifest, `Invalid`, `assertion.dataHash.mismatch`** | the same as proposed |
| `webp/` and `wav/` `truncated-in-c2pa`, `truncated-between-chunks`, `riff-size-plus-one` | manifest, `Invalid`, `general.error` | **no manifest**, `Invalid`, `general.error` (same message) | an error, or `Invalid` (`riff-size-plus-one`) |
| `wav-writers/c2pa-rs-sample3.invalid.wav` | manifest, `Invalid`, `general.error` | **no manifest**, `Invalid`, `general.error` (same message) | an error |
| new: unsigned WAV + ID3v1 tag | manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |
| new: unsigned WAV, last odd chunk without pad | manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |
| new: unsigned WAV, pad `FF` after another chunk | manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |
| new: signed WAV + ID3v1 tag | manifest, `Invalid`, `general.error` | **manifest, `Invalid`, `assertion.dataHash.mismatch`** | the same as proposed |
| new: unsigned WebP + 128 bytes | manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |

Unchanged and still stricter than `c2patool`, because they concern the
`C2PA` chunk itself: two `C2PA` chunks, LBox or chunk length that
disagree, a `C2PA` chunk too short or empty, the `C2PA` chunk's own pad
byte missing or non-zero.

## Three things to see before approving

1. **A header size that leaves the `C2PA` chunk out makes the manifest
   invisible**: `riff-size-excludes-c2pa` becomes "no manifest", as in
   `c2patool`. Changing four bytes in the header hides the store. That
   is no new power: deleting the chunk hides it too, and C2PA cannot
   prevent stripping. It does mean "no manifest" never proves that a file
   had none.
2. **A header size one byte too large stays an error**, now with "no
   manifest". The size is checked before any chunk is read, so the walk
   never reaches the store. `c2patool` 0.27.22 calls the signed variant
   `Invalid` with a hash mismatch; here it is `Invalid` with
   `general.error`. Both are `Invalid`.
3. **A fault before the store stays visible**: `has_manifest` false,
   `Invalid`, one `general.error`. It is not reported like a clean
   unsigned file, because the file is malformed and `c2patool` errors on
   it too.

The JPEG, PNG and ISOBMFF extractors keep `has_manifest: true` on every
fault. Whether they need the same change is not measured; SPEC-013
amendment 16 names it as open.

## Next, after approval

Tests first (SPEC-003 AC5, AC12, AC16, AC17, AC18; SPEC-055 AC4, AC19;
SPEC-013's report), seen red; then the walk and the report; then the
corpus, which may only move the files above.
