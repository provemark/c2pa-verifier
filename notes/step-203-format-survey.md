# Step 203 — Seven more formats, surveyed before any spec

*2026-10-05. `c2patool` 0.27.22 and 0.28.1; the unsigned fixtures of the
sister library (`provemark/content-credentials`, `tests/Fixtures/`); the
public `es256` test certificate and trust settings that `c2patool`'s own
`sample/` ships. Nothing in `src/` changed.*

## Why

The milestone table lists GIF, TIFF, SVG, WAV, MP3, FLAC and AVI as
"later, one spec per format". Before the first of those specs, Maurice
asked for a plan that adds formats slowly. One question decides that plan
more than any other: which hard binding does `c2patool` write for each
format? If it is `c2pa.hash.data`, the existing check (SPEC-012) is reused
and each format is only a new container reader, as M1 was. If it is
`c2pa.hash.boxes`, which this verifier refuses today, a new hash check has
to come first. That question had not been measured. This step measures it,
and four more things per format, before anything is built.

## How it was measured

Each of the seven unsigned fixtures was signed twice in a scratch
directory outside the repository, once with each `c2patool` version, using
one `c2pa.actions.v2` assertion (`c2pa.created`, `digitalCapture`). Each
signed file was then read with both versions, with `--settings` pointing at
the shared trust file and with `--detailed`. That gives 7 formats × 2
signers × 2 readers = 28 readings. Then one byte of each 0.28.1-signed file
was flipped, once in the content and once at the end, and the result was
read with both versions. No signed file was kept; the formats' own steps
will make their fixtures with a script, as step 59 did.

## Measured

### 1. Every format signs, reads and verifies, in both versions

All 28 readings are `Trusted`. Both versions read each other's files.

### 2. Every format uses `c2pa.hash.data`

All seven formats, in all 28 readings, carry exactly one hard binding, and
it is `c2pa.hash.data`. **No format needs `c2pa.hash.boxes`.** The
existing data-hash check carries all seven; each needs only a reader that
finds the store and reports its byte range.

### 3. Where the store sits, and what the exclusion covers

Offsets are for the file signed by 0.28.1.

| format | where the store is | exclusion(s) in `c2pa.hash.data` |
|---|---|---|
| WAV | a top-level RIFF chunk `C2PA` (form `WAVE`), the last chunk. Its size here is odd (3509), so a pad byte follows | one range: the chunk header and data (16078, 8 + 3509). **The pad byte is not excluded: it is hashed** |
| AVI | a top-level RIFF chunk `C2PA` (form `AVI `), the last chunk, the same layout as WAV | one range: chunk header and data |
| MP3 | an ID3v2.4 tag at the start, frame `GEOB`, MIME type `application/c2pa`, file name `c2pa`, description `c2pa manifest store` | one range: **only the store bytes**. The ID3 header and the `GEOB` frame header are hashed |
| FLAC | **the same ID3v2.4 `GEOB` frame, prepended in front of `fLaC`**. The unsigned fixture starts with `fLaC`; the signed one starts with `ID3`. No FLAC metadata block is used | one range: only the store bytes, as for MP3 |
| GIF | an Application Extension (`21 FF 0B`), identifier `C2PA_GIF`, authentication code `01 00 00`, the store split over data sub-blocks of at most 255 bytes | one range: the whole extension, from the introducer to the end |
| TIFF | IFD tag `0xCD41` (52545), type 7 (`UNDEFINED`), the value offset pointing to the store at the end of the file | **two ranges**: the entry's 4-byte value-offset field, and the store itself |
| SVG | the store in Base64 as the text of `<c2pa:manifest>` inside `<metadata>`; `c2patool` also adds `xmlns:c2pa="http://c2pa.org/manifest"` to the root element | one range: only the Base64 text |

### 4. One flipped byte

| format | content byte | last byte |
|---|---|---|
| WAV | `Invalid`, `assertion.dataHash.mismatch` | the same (this is the pad byte) |
| AVI | the same | the same |
| MP3 | the same | the same |
| FLAC | byte 5 (in the ID3 header): `Error: No claim found` | `Invalid`, `assertion.dataHash.mismatch` |
| GIF | `Invalid`, `assertion.dataHash.mismatch` | the same |
| TIFF | the same; the value-offset field flipped: `Error: asset could not be parsed: TIFF/DNG out of range` | — |
| SVG | the same | the same |

Both versions gave the same answer in every case.

### 5. The two versions differ only in padding

Every file signed by 0.27.22 is about 10 KB larger. In the WAV file, the
difference is one run of zero bytes: 10,932 bytes against 996 in 0.28.1.
The difference is padding, and it does not change any verdict.

### 6. This verifier today

A signed WAV or GIF is `Invalid` with `general.error`, *"unsupported file
type"*, `format: unknown`. That is fail closed: no false verdict, but no
useful one either.

## What it means (reasoned)

- **No new hash check is needed.** Each format is a container reader that
  returns `ManifestStoreBytes` with its `$ranges`, as SPEC-001/002/003
  do. SPEC-012's check, which requires the store's own exclusion to equal
  the store's range, is the part that keeps a wrong `Valid` out. For TIFF
  it has to accept a second, prescribed range (the offset field). For
  SVG, the range is Base64 text, not the decoded bytes. Both are new, and
  each is its own amendment.
- **The formats fall into four families:**
  1. **RIFF** (WAV, AVI): WebP's reader already walks RIFF chunks (SPEC-003).
  2. **ID3v2 `GEOB`** (MP3, FLAC): one new parser serves both.
  3. **GIF**: sub-blocks, a cousin of JPEG's segment reassembly.
  4. **TIFF** and **SVG**: each different. SVG needs XML, which the
     dependency rule (`openssl`, `mbstring`, `sodium` only) does not
     provide, so it needs an ADR first.
- **The WAV pad byte is hashed.** A reader that treats the pad as part of
  the excluded chunk would let one byte change without detection. This is
  small, but it is exactly the kind of difference that yields a wrong
  `Valid`, so it belongs in WAV's acceptance criteria.
- **FLAC is not a FLAC container here.** `c2pa-rs` puts an ID3 tag in
  front of the stream. Whether C2PA 2.4 Annex A prescribes that, or whether
  it is `c2pa-rs`'s own choice, has not been read yet; FLAC's own step reads it.

## Not measured yet (each format's own step)

The Annex A section for each format, and the cases a reader must refuse or
accept: a second store, a store chunk that is not last, a wrong RIFF size,
RF64 and BigTIFF, ID3 unsynchronisation and extended headers, a GIF
sub-block that ends early. These are measured against `c2patool` in the
first step of each format, before its spec.

## Decided

By Maurice van Loon, 2026-10-05: WAV comes first. The order after that is
proposed, not decided, and follows the families above: AVI → MP3 and FLAC
→ GIF → TIFF → SVG (after an ADR on XML). Text still waits for an oracle
(step 183). Each format follows the same steps: measure, draft the spec,
approval, tests seen red, build, corpus and fuzzing. A refactor that shares
a reader (RIFF for WebP, WAV and AVI) is its own step, with no change in
behaviour.
