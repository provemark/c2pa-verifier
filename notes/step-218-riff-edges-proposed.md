# Step 218 — The edges of the RIFF leniency: proposed

*2026-10-05. SPEC-003 amendment 4, SPEC-055 amendment 4, SPEC-013
amendment 17, all proposed. Nothing in `src/` or `tests/` changed.*

## Why

The second review (step 217) found four places where amendment 3
stopped short. Maurice decided on the advice: walk on to see the store
when the header size is too large; stop the walk, as `c2patool` does,
where the RIFF chunk cannot hold another whole chunk; refuse a header size
below 4; document the swallowed store.

## Two more measurements

| file (built from `fixture-signed.webp`) | this verifier today | `c2patool` 0.27.22 | 0.28.1 |
|---|---|---|---|
| `VP8L` length set to run past the RIFF end, before `C2PA` | `Invalid`, `general.error`, no manifest | *No claim found* | *No claim found* |
| 3 stray bytes between `VP8L` and `C2PA` | `Invalid`, `general.error`, no manifest | *No claim found* | *No claim found* |

Both hide the store from `c2patool` too, like the reviewer's `swallow`.

## The proposal, file by file

"Today" is measured (steps 216–218); "proposed" is reasoned until the
tests exist. The reviewer's files are WebP; the WAV rows follow from the
same walk.

| file | today | proposed | `c2patool` 0.27.22 / 0.28.1 |
|---|---|---|---|
| `riff-size-plus-one` (WebP, WAV) | no manifest, `Invalid`, `general.error` | **manifest**, `Invalid`, `general.error` | manifest, `Invalid`, `assertion.dataHash.mismatch` |
| `truncated-in-c2pa` (WebP, WAV); a signed WebP cut in half | no manifest, `Invalid`, `general.error` | **manifest**, `Invalid`, `general.error` | an error |
| `truncated-between-chunks` (WebP, WAV), `c2pa-rs-sample3.invalid.wav` | no manifest, `Invalid`, `general.error` | the same | an error |
| signed WebP + 3-byte tail inside the RIFF chunk (`tail3`) | manifest, `Invalid`, `general.error` | manifest, **`Invalid`, `assertion.dataHash.mismatch`** | the same as proposed |
| signed WebP + an overrunning chunk after `C2PA` (`after-overrun`) | manifest, `Invalid`, `general.error` | manifest, **`Invalid`, `assertion.dataHash.mismatch`** | the same as proposed |
| unsigned WebP + 3-byte tail (`u-tail3`), + an overrunning chunk (`u-overrun`) | no manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |
| signed WebP, `VP8L` runs past the end; 3 stray bytes before `C2PA` | no manifest, `Invalid`, `general.error` | **no manifest, no failure** | *No claim found* |
| `swallow` (an earlier chunk runs exactly to the end) | no manifest, no failure | the same (documented) | *No claim found* |
| header size 0 (`size-zero`) | no manifest, no failure | **no manifest, `Invalid`, `general.error`** | *No claim found* |
| `wav/chunk-overruns-file`, `webp/chunk-overruns-file` (the `C2PA` chunk overruns) | manifest, `Invalid`, `general.error` | the same | an error |

Every other file keeps its report.

## To see before approving

1. **More ways to hide a store, all shared with `c2patool`**: a chunk
   before `C2PA` that runs past the end, or stray bytes that misalign the
   walk. Each changes signed bytes, but with the store hidden there is no
   claim to check them against. Deleting the chunk does the same; C2PA
   cannot prevent stripping.
2. **`size-zero` becomes stricter than `c2patool`** (a fault where it finds
   no claim), on purpose: the header contradicts itself.
3. **`riff-size-plus-one` stays a fault here** where `c2patool` reads it;
   both say `Invalid`. Reading it, as `c2patool` does, would mean walking
   past the end the header declares. That is a choice not proposed.

## Next, after approval

Tests first (SPEC-003 AC18, AC19; SPEC-055 AC20; SPEC-013 AC7), seen red;
then the walk; then the corpus and the fuzzer over the RIFF files again.
