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
| AC11 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC11: what lies outside the tag is not the container's concern; tests/Unit/Verifier/Mp3Test.php :: AC11: bytes after the tag are judged by the data hash; AC11: a tag size one too large leaves no MPEG audio after the tag, so the file is unknown (amendment 1) / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: walk(); src/Container/FormatDetector.php :: detect() |
| AC12 | tests/Unit/Container/Id3ManifestStoreExtractorTest.php :: AC12: the bounds apply / SPEC-056 | src/Container/Id3ManifestStoreExtractor.php :: DEFAULT_MAX_OBJECT_LENGTH, MAX_FRAMES, walk() |
| AC13 | tests/Unit/Verifier/Mp3Test.php :: AC13: mp3 when MPEG audio follows the tag or opens the file, and nothing else is guessed; AC13: a tagless MP3 has no manifest and no failure; an unknown file names MP3 among the formats / SPEC-056 | src/Container/FormatDetector.php :: detect(), isMpegFrame(); src/Container/Id3ManifestStoreExtractor.php :: walk() (a tagless file); src/Verifier/Verifier.php :: verify() |
| AC14 | tests/Unit/Verifier/Mp3Test.php :: AC14: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say, without and with trust settings (four datasets); AC14: one byte of the audio flipped is assertion.dataHash.mismatch / SPEC-056 | src/Verifier/Verifier.php :: __construct() (`$mp3`), verify() (the `mp3` arm) |
