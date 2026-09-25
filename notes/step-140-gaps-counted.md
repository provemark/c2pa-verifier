# Step 140 — The open gaps, counted in files that exist

*2026-09-25. Measurement only: no specification, test or code changed.*

## Why

After 0.2.1, seven issues stay open (#1, #5, #6, #7, #8, #9, #12). Each is a
rule of the specification this verifier does not check yet. Before one of
them becomes the next spec, the question was which of them a real file
actually reaches. A gap that no file carries is a gap on paper. A gap that
a mass-market writer's files carry is the one to close first.

## What was scanned

| corpus | where from | media files |
|---|---|---|
| `c2pa-rs` fixtures | `contentauth/c2pa-rs`, `sdk/tests/fixtures`, commit `ada3e4a` (2026-09-24), fresh clone | 117 |
| `tests/Fixtures/c2pa-rs/` | this repository's copy (step 39, step 85) | 34 |
| `tests/Fixtures/public-testfiles/` | this repository's copy of `c2pa-org/public-testfiles` `legacy/1.4` | 26 |
| `tests/Fixtures/writers/` | real writers: Adobe, Amazon, Google Pixel, OpenAI, TrustNXT (step 46) | 7 |

`encypherai/c2pa-conformance-suite` (commit `e2feae1`) holds no media
files, only generators and JSON. Its sources name every one of these gaps
(`multi-asset` in 14 files, `reviewRatings` in 25), but that shows what it
*tests*, not what files *carry*. So it was left out of the count.

## Method

Two passes over every file with a media extension.

1. **Bytes.** Assertion labels and CBOR map keys are plain ASCII in a JUMBF
   store, so the raw file was searched for a marker per gap:

   | issue | marker |
   |---|---|
   | #6 | `c2pa.time-stamp` |
   | #7 | `multi-asset` |
   | #8 | `session-keys`, `session_keys` |
   | #5 | `alternative-content`, `alternative_content` |
   | #1 | `reviewRatings`, then `humanEntry` in the same file |

   #9 is about trust settings rather than files, and #12 (a custom status
   code recorded in an ingredient) has no fixed string to search for.
   Neither can be counted this way.

2. **Verdicts.** `bin/c2pa-verify <file>` for every file, and `c2patool`
   0.27.22 (`c2patool <file>`, no settings) for every file in a format
   this verifier reads, comparing `validation_state`.

**Limit of the byte pass:** a manifest compressed into a `brob` box
(C2PA 2.2) hides its labels. `brob` occurs in one file, `sample1.jxl`, a
format this verifier does not read. None of the JPEG, PNG, WebP or ISOBMFF
files carries one.

## Measured

**The gaps.**

| issue | files carrying the marker |
|---|---|
| #6 `c2pa.time-stamp` | **1**, `update_manifest.jpg`: `Valid` here and at `c2patool` |
| #7 `c2pa.hash.multi-asset` | **1**, the Google Pixel 10 file in `writers/`. Its `c2pa.hash.data` matches, so the fallback #7 describes is never needed. `c2patool` does not read the assertion either (`docs/comparison.md`) |
| #1 `reviewRatings` beside `humanEntry` | **0**. `reviewRatings` occurs in 8 distinct files, `humanEntry` in none of them |
| #5, #8 | **0** |

**The verdicts.** Of the 117 `c2pa-rs` files, 69 are in formats this
verifier does not read. Of the other 48:

- 15 get the same `validation_state` from both tools;
- 28 are an error at `c2patool` and `Invalid` here:
  - 26 carry no manifest (*"No claim found"*, and `has_manifest: false`
    here);
  - `no_alg.jpg` and `prerelease.jpg` are refused by both;
- 5 are `Invalid` here and `Valid` at `c2patool`, and all five are
  documented divergences in `docs/comparison.md`:
  - `ocsp.jpg`, `ocsp_with_assertion.jpg`, and `video1.mp4` through its
    ingredient: a timestamp authority is trusted only through configured
    anchors;
  - `cloud.jpg`: a remote manifest is never fetched;
  - `C_with_CAWG_data.jpg`: a CAWG identity assertion is refused until it
    is validated.

Of the 7 writer files, 4 agree. The other 3 are the same documented
divergences: Amazon Titan (the timestamp authority), the Photoshop file
(a remote manifest) and `cawg_ica` (CAWG).

**No difference with `c2patool` was found that `docs/comparison.md` does
not already explain.**

**What this verifier does refuse, on these files, is formats.** Of the 69
files in unsupported formats:

| format | files |
|---|---|
| DASH media segments (`.m4s`, `ftyp` brand `msdh`, without their init segment) | 38 |
| PDF | 9 |
| TIFF and DNG | 6 |
| SVG | 5 |
| WAV | 3 |
| MP3 | 2 |
| bare `.c2pa` stores | 2 |
| FLAC | 1 |
| AVI | 1 |
| GIF | 1 |
| JPEG XL | 1 |

JPEG, PNG, WebP, AVIF and HEIC, the formats a CMS media library handles,
are all read.

## Reasoned

- None of the open issues changes a verdict on any file measured here.
  Closing one now would make the verifier stricter on paper and change
  nothing a user sees.
- #7 is the only gap that a real device's files reach. It stays dormant as
  long as the data hash matches, which is the normal case.
- These are mostly test files written by implementers. That #5 and #8 do
  not occur here says little about files in the wild. The stronger
  measurement is a fresh set from current writers (Firefly, ChatGPT,
  Pixel, Samsung), counted the same way. Firefly in particular is still
  not in the corpus.
