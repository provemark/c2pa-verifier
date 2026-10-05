# SPEC-057: FLAC — the ID3v2 tag in front of the stream → manifest store bytes, verified

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-05                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §A.3.4 puts the store of "an ID3v2-compatible, compressed audio
file (e.g., MP3 or FLAC)" in an ID3v2 GEOB frame. Step 234
(`notes/step-234-flac-measured.md`) measured what `c2patool` 0.27.22 and
0.28.1 write: an ID3v2.4 tag with the C2PA GEOB **in front of** the FLAC
stream, which follows byte for byte unchanged, and a data hash whose one
exclusion is the store. A signed FLAC is `unknown` here today.

The tag is MP3's. SPEC-056's extractor, reviewed twice and hardened by its
amendments 2 and 3, reads it unchanged (measured in step 235: the
fixture's store, byte-exact, with `c2patool`'s exclusion as its range). So
this spec adds no reader. It adds what FLAC needs of its own: detection,
the `format` value `flac`, and an untagged FLAC as an outcome with no
manifest. The signature, hash, trust and timestamp checks apply unchanged,
and so do SPEC-056's rules about the tag and its C2PA GEOB.

## Scope

**In scope**

- Detection: `flac` when the file opens with `fLaC`, or when `fLaC` follows
  the ID3v2 tag(s) and any zero padding, skipped as SPEC-056 AC16 skips
  them for MP3.
- The store through `Id3ManifestStoreExtractor`, unchanged; a file that
  opens with `fLaC` has no tag and yields `null`, as one that opens with
  MPEG audio does (SPEC-056 AC13).
- The verifier route and the unknown-format message naming FLAC.

**Out of scope**

- A C2PA store in a FLAC metadata block (APPLICATION or otherwise): not
  what §A.3.4 says, and `c2patool` does not write it.
- Ogg FLAC, and an ID3 tag after the stream (`c2patool`: *No claim found*,
  step 234; here: `flac`, no manifest).
- Checking the FLAC stream itself (STREAMINFO, frames): the data hash
  covers it; `c2patool` does not check it either.

## Behavior

Variants are under `tests/Fixtures/flac/` (`bin/make-flac-variants.php`,
step 234).

- **AC1 — the fixture yields the store, byte-exact, with its range**
  - Given `tests/Fixtures/fixture-signed.flac`
  - When the extractor runs
  - Then 13,463 bytes, SHA-256
    `044f6afdd2ba674bdcad9e66bdf89db3bba518108c2eda0d30206a7fb130a5a5`,
    first bytes `00 00 34 97 6a 75 6d 62`, one range `[63, 13463]`

- **AC2 — a FLAC without a tag has no manifest** *(required: error /
  malformed input boundary; today a `ContainerException`)*
  - Given `tests/Fixtures/fixture-unsigned.flac` (opens with `fLaC`) and
    `flac/tag-at-end.flac` (a C2PA tag after the stream)
  - When the extractor runs, and when each is verified
  - Then the extractor returns `null`; verified, `format` `flac`,
    `hasManifest` false, no failure (*No claim found* in both versions)

- **AC3 — a tag merged with the source's own is read**
  - Given `flac/signed-with-id3.flac` (`TIT2`, then the C2PA GEOB, then
    `fLaC`; signed by `c2patool`)
  - Then `flac`, `Valid` without settings and `Trusted` with
    `trust/full.settings.json`, state and sorted codes equal to the four
    recordings under `tests/Fixtures/c2patool/flac/`

- **AC4 — detection: `flac`, `mp3`, or nothing guessed**
  - Given the fixture, `fixture-unsigned.flac`, `flac/zeros-after-tag.flac`,
    `flac/marker-damaged.flac`, `flac/tag-then-other.flac`, and
    `fixture-signed.mp3`
  - Then the first three are `flac`; the damaged marker and the tag
    followed by `XXXX` are `unknown` (`c2patool` reads both by the file
    extension, which this verifier never uses, and calls them `Invalid`:
    named); the MP3 stays `mp3`; the unknown-format message names FLAC
  - And `flac/zeros-after-tag.flac`, verified, is `Invalid` with
    `claimSignature.validated` and `assertion.dataHash.mismatch`, as both
    versions say

- **AC5 — the signed fixture verifies as `c2patool` says**
  - Given `fixture-signed.flac`, without settings and with
    `trust/full.settings.json`
  - Then `Valid` and `Trusted`, code for code with the recordings of both
    versions; one byte of the FLAC stream flipped is `Invalid` with
    `assertion.dataHash.mismatch`

- **AC6 — SPEC-056's rules hold for FLAC**
  - Given the fixture with the C2PA GEOB's grouping flag set (SPEC-056
    AC22) and the fixture with its LBox +1 (SPEC-056 AC7), both built in
    the test
  - Then each, verified, is `flac`, `hasManifest` true, `Invalid`, one
    `general.error` naming the fault

## References

- Specification: C2PA 2.4 §A.3.4 (read in step 225); the FLAC format
  (`fLaC` stream marker, metadata blocks) only to recognise it.
- Oracle: `c2patool` 0.27.22 and 0.28.1; the fixture and the variants of
  step 234, every answer in `tests/Fixtures/flac/README.md`.
- Reasoned: that SPEC-056's reader applies unchanged (shown on the
  fixture in step 235).

## API sketch

No new class. `Id3ManifestStoreExtractor::extract()` returns `null` for a
stream that opens with `fLaC`, as for one that opens with MPEG audio;
`FormatDetector` returns `'flac'`; `Verifier` routes `flac` to the same
extractor (no new constructor parameter).

## Open questions

1. **Amendments this forces** (named now): SPEC-013 (the `format` value
   `flac`, the message), SPEC-056 (its extractor's `null` for `fLaC`, and
   detection after a tag now also looking for `fLaC`). Non-blocker.

## Amendments

None yet.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
