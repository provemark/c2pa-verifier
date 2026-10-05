# SPEC-056: MP3 — an ID3v2 GEOB frame → manifest store bytes, verified

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-05, with the three proposals |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The second format after M8. A signed MP3 is `unknown` today. C2PA 2.4
§A.3.4, "Embedding manifests into ID3", puts the store in an ID3v2 tag:

> The C2PA Manifest Store shall be embedded into a ID3v2-compatible,
> compressed audio file (e.g., MP3 or FLAC) file as the Encapsulated
> object data of a General Encapsulated Object (GEOB) as defined in
> https://id3.org/id3v2.3.0. The GEOB's MIME type field shall be present
> and shall use the value for the media type for JUMBF as described in
> Section 11.4, "External Manifests".

Step 225 (`notes/step-225-mp3-measured.md`) signed
`tests/Fixtures/fixture-signed.mp3` with `c2patool` 0.27.22 and measured 25
variants against 0.27.22 and 0.28.1, which agree on every one. `c2patool`
writes an ID3v2.4 tag at offset 0, a GEOB with MIME `application/c2pa`,
and binds the file with `c2pa.hash.data` whose one exclusion is the store
alone (the GEOB's object). As for WAV, this spec adds a container reader;
the signature, hash, trust and timestamp checks apply unchanged.

The rule is the one Maurice decided for RIFF (SPEC-003 amendments 3 and
4): **strict about the C2PA GEOB frame, as lenient as `c2patool` about the
rest of the tag and the file.** Leniency outside the store cannot make a
changed signed file `Valid`, because the data hash covers every byte
outside the store, the tag's own header and frames included.

## Scope

**In scope**

- An ID3v2 tag at offset 0, version 2.3 or 2.4: the 10-byte header, an
  extended header when its flag is set (skipped), the frames up to the
  end of the tag (v2.3: plain 32-bit sizes; v2.4: syncsafe sizes),
  padding, a v2.4 footer (ignored).
- A GEOB frame's body: the text encoding byte, the MIME type
  (ISO-8859-1, `NUL`-terminated), the file name and the description (in
  the frame's encoding: one-byte terminator for 0 and 3, two-byte for 1
  and 2), then the object.
- The C2PA GEOB: a GEOB whose MIME type is exactly `application/c2pa`. At
  most one; its object is the store; LBox equals the object's length.
- The result as `ManifestStoreBytes`, its range the object (offset 86,
  13,462 bytes in the fixture: equal to the exclusion `c2patool` wrote).
- `ContainerException::$storeReached`: true once a C2PA GEOB's header and
  MIME type have been read (SPEC-013 amendments 16–18).
- Detection and the `format` value `mp3` (open question 3); the
  verifier route.

**Out of scope** (each needs its own spec)

- FLAC, which C2PA puts in the same ID3 tag: its own spec after this one,
  reusing this reader. A file whose tag is followed by `fLaC` stays
  `unknown` here.
- AAC (ADTS) and other audio behind an ID3 tag: `unknown`.
- ID3v2.2 (three-character frame ids) and any version other than 3 and 4:
  refused.
- A tag that is not at offset 0, or one appended at the end of the file:
  not looked for (as `c2patool`).
- Undoing unsynchronisation (open question 1).
- Compressed or encrypted frames (refused when the frame is the C2PA
  GEOB, skipped otherwise).

## Behavior

Variants are under `tests/Fixtures/mp3/`, built by
`bin/make-mp3-variants.php` (step 225). Each criterion names `c2patool`'s
answer; where this verifier differs, it says why.

- **AC1 — the fixture yields the store, byte-exact, with its range**
  - Given `tests/Fixtures/fixture-signed.mp3`
  - When the MP3 extractor runs
  - Then it returns 13,462 bytes, SHA-256
    `aa19d53907b32e1dd479e081f39622c700f46c1492b3e1b6d2ddbda45126319f`,
    first eight bytes `00 00 34 96 6a 75 6d 62`, one range `[86, 13462]`

- **AC2 — no C2PA GEOB is an outcome, not an error**
  - Given `tests/Fixtures/fixture-unsigned.mp3` (a tag with `TSSE` and
    padding), `store-in-appended-tag.mp3`, `mime-octet-stream.mp3`,
    `mime-upper-case.mp3`
  - When the extractor runs
  - Then each returns `null` (*No claim found* in both versions; the MIME
    type is matched exactly, open question 2)

- **AC3 — the tag is read in all its shapes** *(oracle: read by both
  versions)*
  - Given `encoding-latin1`, `encoding-utf16`, `encoding-utf16be`,
    `version-2-3`, `flag-extended-header`, `flag-footer`, `padding-after`,
    `other-geob-first`
  - When the extractor runs
  - Then each returns the store of AC1

- **AC4 — a tag header that contradicts itself is a fault** *(required:
  error / malformed input; stricter than the oracle where marked)*
  - Given `tag-size-not-syncsafe.mp3` (a size byte with its top bit set;
    *No claim found* in both versions: stricter by name); a tag of
    version 2, and of version 5 (built in the test)
  - When the extractor runs
  - Then each throws `ContainerException` naming the field, with
    `storeReached` false, before any frame is read

- **AC5 — a tag that promises more than the file holds is a fault, and
  says whether the store was there**
  - Given `truncated-in-store.mp3` (*invalid CBOR box* in both versions)
  - When the extractor runs
  - Then it throws `ContainerException` naming the tag size and the file
    length; frame headers are scanned up to the end of the file, never a
    frame's data, and `storeReached` is true because the C2PA GEOB's
    header lies inside the file (as SPEC-003 amendment 4)

- **AC6 — two C2PA GEOBs are a fault** *(stricter than the oracle: both
  versions take the first)*
  - Given `two-geob.mp3`
  - Then `ContainerException` naming both offsets, `storeReached` true

- **AC7 — LBox must equal the object's length** *(stricter than the
  oracle: both versions → `Valid`)*
  - Given `lbox-differs.mp3`
  - Then `ContainerException` naming both values

- **AC8 — the C2PA GEOB must fit the tag** *(stricter than the oracle:
  both versions read the store and fail the hash)*
  - Given `frame-overruns-tag.mp3`
  - Then `ContainerException` naming the frame offset, its size and the
    tag's end, `storeReached` true

- **AC9 — an object shorter than a box header is a fault** *(oracle:
  *unexpected end of file* for 4 bytes, *No claim found* for 0)*
  - Given `object-too-short.mp3` and `object-empty.mp3`
  - Then `ContainerException` naming the length and the 8-byte minimum

- **AC10 — a C2PA GEOB that cannot be read as it stands is a fault**
  *(open question 1; stricter than the oracle)*
  - Given `frame-flags-compressed.mp3` (*No claim found* in both
    versions), and `flag-unsynchronisation.mp3` (read by both)
  - Then `ContainerException` naming the flag, `storeReached` true
  - And given the unsigned fixture with the unsynchronisation flag set
    (built in the test): `null` — the flag matters only for a C2PA GEOB

- **AC11 — what lies outside the tag is not the container's concern**
  - Given `id3v1-appended.mp3`, `unsigned-id3v1.mp3`,
    `tag-size-plus-one.mp3`
  - Then the first and third yield the store of AC1 and, verified, are
    `Invalid` with `assertion.dataHash.mismatch` (`c2patool`: the same
    for the first; *No claim found* for the third, where this verifier
    reads the tag as its header declares and the hash judges the changed
    byte); the second yields `null`

- **AC12 — the bounds apply** *(SPEC-024)*
  - Given an extractor with a maximum object length of 1,000 bytes, and
    one with no arguments
  - Then the first throws naming the limit and 13,462 before the object
    is read; the second's limit is 16 MiB; a tag of more than 4,096 frames
    is refused

- **AC13 — detection: `mp3` when MPEG audio follows the tag, or opens
  the file** *(open question 3)*
  - Given the fixture, `unsigned-no-tag.mp3`, `junk-before-tag.mp3`, the
    sister library's `fixture.flac` signed (an ID3 tag followed by
    `fLaC`, built in the test), and `fixture-signed.webp`
  - Then the first two are `mp3` (the second: no manifest, no failure);
    `junk-before-tag` and the FLAC are `unknown`; the WebP stays `webp`;
    the unknown-format message names MP3

- **AC14 — the signed fixture verifies as `c2patool` says**
  - Given `fixture-signed.mp3`, without settings and with
    `trust/full.settings.json`
  - Then `Valid` and `Trusted`, state and sorted success and failure codes
    equal to `c2patool` 0.27.22's and 0.28.1's, recorded under
    `tests/Fixtures/c2patool/mp3/`; one byte of the audio flipped is
    `Invalid` with `assertion.dataHash.mismatch`

## References

- Specification: C2PA 2.4 §A.3.4 (read 2026-10-05); §11.4 for the JUMBF
  media type; ID3v2.3.0 and ID3v2.4.0 (structure, frames, GEOB, syncsafe
  integers, unsynchronisation); ISO/IEC 11172-3 for the MPEG audio frame
  header (only to recognise it).
- Oracle: `c2patool` 0.27.22 and 0.28.1; the fixture and the 25 variants,
  every answer in `tests/Fixtures/mp3/README.md`.
- Reasoned: the rule carried over from RIFF; the three proposals below.

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;

/** @internal SPEC-056: an ID3v2 tag at offset 0 → the C2PA GEOB's object; FLAC's spec will reuse it. */
final readonly class Id3ManifestStoreExtractor
{
    public const DEFAULT_MAX_OBJECT_LENGTH = 16 * 1024 * 1024;   // SPEC-024
    public const MAX_FRAMES = 4096;

    public function __construct(
        public int $maxObjectLength = self::DEFAULT_MAX_OBJECT_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /** @param resource $stream  @throws ContainerException */
    public function extract($stream): ?ManifestStoreBytes;
}
```

`FormatDetector` returns `'mp3'`; `Verifier` gains the route as its last
constructor parameter, as WAV's.

## Open questions (Maurice's, each with a proposal)

1. **Unsynchronisation.** Step 225 could not tell whether `c2patool`
   undoes it: the fixture's store holds no byte it would change.
   *Proposal:* a C2PA GEOB under the tag's unsynchronisation flag, or
   with the v2.4 frame flag, is a fault (AC10); a tag with the flag but no
   C2PA GEOB is read as usual. Undoing it is a later step if a real file
   ever needs it. Blocker.
2. **The MIME match.** `c2patool` matches `application/c2pa` exactly; the
   specification names the JUMBF media type. *Proposal:* exactly, as
   `c2patool` (AC2). Blocker.
3. **Detection and `format`.** A file opening with `ID3` may be MP3, FLAC
   or AAC. *Proposal:* `mp3` when the bytes after the tag (and its footer)
   open an MPEG audio frame — 11 sync bits, a layer and a bitrate and
   sample rate that are not reserved — and when the file itself opens with
   such a frame (a tagless MP3: no manifest, as `c2patool`); `unknown`
   otherwise, FLAC included, until its own spec. This reads beyond
   `FormatDetector`'s twelve bytes: one seek to the end of the tag and four
   bytes there. Blocker.
4. **Amendments this forces** (named now): SPEC-013 (the `format` value
   and the message), SPEC-024 (the bounds list), SPEC-001/SPEC-013's
   detection probe length. Non-blocker.

## Amendments

1. **2026-10-05, step 228, found by the build; approved by Maurice van
   Loon the same day (option 1 of three)** — AC11 and AC13 contradicted
   each other on `tag-size-plus-one.mp3`. AC11 said the verifier reads it
   and the data hash judges the changed byte; AC13's detection, approved
   with open question 3, calls a file `mp3` only when MPEG audio follows
   the tag, and here the tag ends one byte into the audio, so the file is
   `unknown` and never reaches the extractor. AC13 stands. AC11 now says:
   the **extractor** yields the store of AC1 for `tag-size-plus-one.mp3`;
   **verified**, the file is `unknown`, `Invalid`, one `general.error`
   (`c2patool`: *No claim found*; neither says `Valid`).
   `id3v1-appended.mp3` is unchanged: read and `Invalid` with
   `assertion.dataHash.mismatch`. Rejected: calling every ID3 tag not
   followed by `fLaC` an MP3 (an AAC file would be labelled `mp3`), and
   searching a few bytes past the tag for the sync word.

2. **2026-10-05, step 230, approved by Maurice van Loon** — after a code
   review and a search for other writers (`notes/step-230-mp3-review.md`).
   No input gave a wrong `Valid`; five made a real manifest invisible, one
   crashed, one was mis-detected. Measured with both `c2patool` versions
   unless marked.

   - **AC15 — the legacy JUMBF media type.** The C2PA GEOB is one whose
     MIME type is exactly `application/c2pa` **or**
     `application/x-c2pa-manifest-store`, the two `c2patool` accepts and
     no others (`application/jumbf`, upper case and parameters are not).
     Given `mime-legacy.mp3`: the store of AC1. Given
     `mp3-writers/c2pa-ts-signed.mp3` (signed with c2pa-ts 0.14.0, an
     implementation independent of `c2pa-rs`, whose exclusion covers the
     whole tag): a store is extracted and, verified, the file is `Invalid`
     with a data-hash failure, as in 0.28.1 (0.27.22: `Valid`).
   - **AC16 — MPEG audio after padding and further tags.** Detection skips
     zero bytes (up to 64 KiB) and further ID3v2 tags (up to 8) after the
     first tag before it requires MPEG audio. Given
     `signed-zeros-after-tag.mp3` and `signed-second-empty-tag.mp3`
     (signed by `c2patool`, `Valid` in both versions): `mp3`, `Valid`,
     code for code with the recordings. `tag-size-plus-one.mp3` stays
     `unknown` (amendment 1).
   - **AC17 — MPEG audio without a tag needs two frame headers.** A file
     that opens with a frame header is `mp3` only if a second header
     follows at the first frame's length. Given `unsigned-no-tag.mp3`:
     `mp3`; given a UTF-16LE text file (`FF FE` and text): `unknown`.
   - **AC18 — iTunes frame sizes, and frame ids.** A v2.4 frame size with a
     byte above `7F` is read as a plain 32-bit integer. Given
     `tsse-plain-size.mp3`: the store of AC1. A frame id that is not four
     capitals or digits is a `ContainerException`
     (`frame-id-invalid.mp3`; `c2patool` reads past it: stricter by name,
     so that a walk thrown off never hides a store silently).
   - **AC19 — unsynchronisation that changes bytes.** A tag with the
     unsynchronisation flag that contains `FF 00` is a `ContainerException`
     before its frames are walked (`unsync-ff00.mp3`; `c2patool`: *No claim
     found*: stricter by name). Without `FF 00` the flag changes nothing,
     and AC10 applies as before.
   - **AC20 — text fields, grouping, footers.** The C2PA GEOB's file name
     and description may be of any length within the frame
     (`long-description.mp3`: the store of AC1); a grouped GEOB is not read
     as C2PA in either version (`grouped-geob-v24.mp3`,
     `grouped-geob-v23.mp3`: `null`, as `c2patool`); a footer exists only in
     v2.4 (`footer-bit-v23.mp3`: detected as `mp3`, the store read).
   - **AC21 — every malformed input is a `ContainerException`.** A GEOB
     frame header that ends exactly at the end of the file, with the tag
     ending there or promising more, raises `ContainerException` or yields
     `null`, never another `Throwable` (it raised a `ValueError`).

   The tag header is parsed by one function shared by the detector and
   the extractor, and the scan of AC5 uses the walk's own frame reading.

   Approved by Maurice van Loon, 2026-10-05 (step 230).

3. **2026-10-05, step 232, approved by Maurice van Loon** — after a
   second review of steps 230–231. Measured with both `c2patool`
   versions on files built from the fixture (`bin/make-mp3-variants.php`).

   - **AC22 — the grouping flag on the C2PA GEOB is a fault.** A GEOB whose
     body still reads as C2PA while its grouping flag (v2.3 `0x20`, v2.4
     `0x40`) is set has no group byte where the flag says one is; `c2patool`
     reads the first body byte as the group id and finds no claim. Given
     `group-flag-only.mp3` and `group-flag-only-v23.mp3`:
     `ContainerException` naming the flags, `storeReached` true (stricter
     by name). Step 231 had dropped this refusal on the reasoning that a
     grouped GEOB never matches the MIME type; that holds only when the
     group byte is really there (`grouped-geob-v2x.mp3`, AC20, unchanged).
     Without this, a signer who set the flag before signing got `Valid`
     here and no claim in `c2patool`.
   - **AC23 — a tag that runs past the end of the file is `mp3`.** Given
     `tag-past-eof.mp3` and `tag-past-eof-extended.mp3` (cut right after
     the GEOB; `c2patool`: the manifest read, `Invalid`): detected as
     `mp3`; verified, `hasManifest` true, `Invalid`, one `general.error`
     (AC5's message); the scan of AC5 honours an extended header, so both
     report `storeReached` true.
   - **AC24 — an ID3v2.2 tag before MPEG audio is `mp3`**, so AC4's
     refusal reaches the report. Given `v22-tag.mp3` (`c2patool`: *No claim
     found*): `mp3`, `Invalid`, `general.error` naming `ID3v2.2`,
     `hasManifest` false.
   - **AC25 — free-format MPEG without a tag stays `unknown`** (a known
     limit, named): its header gives no frame length, so the second header
     of AC17 cannot be found; widening it would let text files through
     again.
   - AC20 gains a bound on time: text fields are searched once, from where
     the last read ended, so an 8 MB unterminated UTF-16 description is
     refused in well under the 13 s it took (measured in step 232).

   Approved by Maurice van Loon, 2026-10-05 (step 232).

4. **2026-10-05, step 237, with SPEC-057's implementation** — AC13 said a
   file whose tag is followed by `fLaC` stays `unknown` "until its own
   spec". SPEC-057 is that spec: such a file is now `flac`, and the
   extractor returns `null` for a stream that opens with `fLaC`, as for one
   that opens with MPEG audio. AC13's test changes in that one expectation.
   Named in SPEC-057's open questions before either was written.
   Confirmed by Maurice van Loon, 2026-10-05 (step 238).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC1: extracts the store from the fixture, byte-exact, with its range / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(), objectStart() |
| AC2 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC2: no C2PA GEOB is an outcome, not an error; the MIME type is matched exactly (four datasets) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(), mime() |
| AC3 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC3: the tag is read in all its shapes (eight datasets) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(), extendedHeaderLength(), objectStart(), wideNul() |
| AC4 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC4: a tag header that contradicts itself is a fault before any frame is read (three datasets) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (version and syncsafe checks) |
| AC5 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC5: a tag that promises more than the file holds is a fault, and says the store was there / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(), scanForStore() |
| AC6 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC6: two C2PA GEOBs are a fault naming both offsets / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (`$storeOffset !== null`) |
| AC7 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC7: LBox must equal the object length / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (LBox check) |
| AC8 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC8: the C2PA GEOB must fit the tag / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (`$bodyEnd > $tagEnd` for the C2PA GEOB) |
| AC9 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC9: an object shorter than a box header is a fault (two datasets) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (`BOX_HEADER_LENGTH`) |
| AC10 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC10: a C2PA GEOB that cannot be read as it stands is a fault; the flag alone is not / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (format flags, unsynchronisation) |
| AC11 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC11: what lies outside the tag is not the container's concern; AC11: a frame past the tag after the C2PA GEOB ends the walk but keeps the store, as c2patool reads it (step 244);  tests/Unit/Verifier/Mp3Test.php :: AC11: bytes after the tag are judged by the data hash; AC11: a tag size one too large leaves no MPEG audio after the tag, so the file is unknown (amendment 1) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(); src/Container/FormatDetector.php :: detect() |
| AC12 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC12: the bounds apply / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: DEFAULT_MAX_OBJECT_LENGTH, MAX_FRAMES, walk() |
| AC13 | tests/Unit/Verifier/Mp3Test.php :: AC13: mp3 when MPEG audio follows the tag or opens the file, and nothing else is guessed; AC13: a tagless MP3 has no manifest and no failure; an unknown file names MP3 among the formats / SPEC-056 | src/Container/FormatDetector.php :: detect(), isMpegFrame(); src/Container/Id3ManifestStoreExtractor.php :: walk() (a tagless file); src/Verifier/Verifier.php :: verify() |
| AC14 | tests/Unit/Verifier/Mp3Test.php :: AC14: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say, without and with trust settings (four datasets); AC14: one byte of the audio flipped is assertion.dataHash.mismatch / SPEC-056 | src/Verifier/Verifier.php :: __construct() (`$mp3`), verify() (the `mp3` arm) |
| AC15 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC15: the legacy JUMBF media type is a C2PA GEOB too (amendment 2); tests/Unit/Verifier/Mp3Test.php :: AC15: the MP3 c2pa-ts signed is read, and fails its data hash as c2patool 0.28.1 says (amendment 2) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: MIME_TYPES, walk() |
| AC16 | tests/Unit/Verifier/Mp3Test.php :: AC16: MPEG audio after zero padding or a further tag is mp3, and verifies as c2patool says (amendment 2) (four datasets); AC16: after a tag one MPEG frame header is enough; two are asked only of a file without a tag (amendment 2) / SPEC-056 | src/Container/FormatDetector.php :: detect(); src/Container/Id3ManifestStoreExtractor.php :: header() |
| AC17 | tests/Unit/Verifier/Mp3Test.php :: AC17: MPEG audio without a tag needs two frame headers (amendment 2) / SPEC-056 | src/Container/FormatDetector.php :: detect(), isMpegFrame(), mpegFrameLength() |
| AC18 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC18: an iTunes frame size is read as a plain integer; an invalid frame id is a fault (amendment 2) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: frameHeader() |
| AC19 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC19: unsynchronisation with FF 00 inside the tag is a fault before the frames are walked (amendment 2) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(), unsynchronisedBytesAt() |
| AC20 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC20: text fields of any length, grouped frames, v2.3 header bit 0x10 (amendment 2); AC20: unterminated text fields are refused in linear time (amendment 3); tests/Unit/Verifier/Mp3Test.php :: AC20: a v2.3 tag with header bit 0x10 is followed by its audio, not by a footer (amendment 2) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: objectStart(), walk(), header(); src/Container/FormatDetector.php :: detect() |
| AC21 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC21: a GEOB frame header at the very end of the file is a ContainerException or null, never another error (amendment 2) (two datasets) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: mime(), walk() |
| AC22 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC22: the grouping flag on a GEOB that still reads as C2PA is a fault (amendment 3) (two datasets) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk() (format flags) |
| AC23 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC23: a tag that runs past the end of the file says the store was there, extended header or not (amendment 3); tests/Unit/Verifier/Mp3Test.php :: AC23: a tag that runs past the end of the file is mp3, a manifest that failed (amendment 3) / SPEC-056 | src/Container/FormatDetector.php :: mpegAudioAfterTags(); src/Container/Id3ManifestStoreExtractor.php :: scanForStore() |
| AC24 | tests/Unit/Verifier/Mp3Test.php :: AC24: an ID3v2.2 tag before MPEG audio is mp3, refused by name (amendment 3) / SPEC-056 | src/Container/FormatDetector.php :: mpegAudioAfterTags(); src/Container/Id3ManifestStoreExtractor.php :: header() |
| AC25 | tests/Unit/Verifier/Mp3Test.php :: AC25: free-format MPEG audio without a tag stays unknown, a named limit (amendment 3) / SPEC-056 | src/Container/FormatDetector.php :: mpegFrameLength() |
