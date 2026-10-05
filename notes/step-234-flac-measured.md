# Step 234 — The signed FLAC, measured

*2026-10-05. `c2patool` 0.27.22 and 0.28.1. Maurice decided that 0.3.0
carries FLAC and AVI as well as WAV and MP3. No verifier code, no spec yet.*

## The fixture

`tests/Fixtures/fixture-unsigned.flac` (12,200 bytes, SHA-256
`00fd03a663ff043b47664af1d22aab6175cd5b53f5c7ab6ac5b853cda42a82c8`; the
sister library's `fixture.flac`): `fLaC`, STREAMINFO (34), VORBIS_COMMENT
(44), PADDING (8,192, last), then frames from 8,286 (`FF F8 …`).
`tests/Fixtures/fixture-signed.flac` (25,726 bytes, SHA-256
`32d3a8ddcd29fbd2fe3f1842bfe130cd9d5b63fdf3ee58fda0dec337a9c0034c`), signed
with `c2patool` 0.27.22 and the WebP manifest definition with the title
changed: an ID3v2.4 tag (13,526 bytes, one GEOB, MIME `application/c2pa`)
followed by the unsigned file **byte for byte**. The exclusion is the store
alone, `[63, 13463]`. `Valid` in both versions, `Trusted` with
`trust/full.settings.json`. This verifier: `unknown`.

## What it means for the reader

C2PA 2.4 §A.3.4 names FLAC beside MP3, and this is the same ID3 tag:
SPEC-056's extractor reads it unchanged. What FLAC needs is detection and a
format value. FLAC's frame sync (`FF F8`) is not an MPEG header (layer `00`
is reserved), so an untagged FLAC is never mistaken for MP3.

## Other writers

The public repositories hold one FLAC, `c2pa-rs`'s `sample1.flac`,
unsigned (*No claim found* in both versions). `c2pa-ts` does not write FLAC.

## The variants

`bin/make-flac-variants.php`, every answer in `tests/Fixtures/flac/README.md`:

- An existing ID3 tag in the source is merged by `c2patool` into one tag
  (`TIT2`, then `GEOB`), `fLaC` after it: `Valid` in both versions.
- `c2patool` reads the tag whatever follows it: zeros, a damaged marker,
  or no FLAC at all are read and fail the data hash (the bytes changed).
- A C2PA tag after the stream instead of before it: *No claim found*.
- `c2patool` cannot sign a FLAC with zero bytes between an ID3 tag and
  `fLaC` (*Error: embedding manifest*, both versions).

## For the spec (proposals for Maurice)

1. `flac` when `fLaC` opens the file, or follows the ID3 tag(s) and zero
   padding as MP3's detection skips them (SPEC-056 AC16); the store read by
   `Id3ManifestStoreExtractor`; an untagged FLAC has no manifest.
2. A tag followed by neither MPEG audio nor `fLaC` stays `unknown`
   (`marker-damaged`, `tag-then-other`): `c2patool` reads them by the file
   extension, which this verifier never uses; both say `Invalid`.
