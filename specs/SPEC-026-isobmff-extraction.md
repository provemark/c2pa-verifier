# SPEC-026: ISOBMFF `uuid` box → manifest store bytes

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

M8 is ISOBMFF — MP4, MOV, AVIF, HEIC — and the brief put it last because it
is the least documented part of C2PA and the place where c2pa-rs is still
fixing bugs. Step 73 measured it before anything was planned, and the
measurement makes this first slice small.

A signed MP4 keeps its manifest store in **one top-level `uuid` box**, with
twenty-one bytes between the UUID and the JUMBF superbox:

| offset | bytes | what |
|---|---|---|
| 32 | `00 00 35 0a` `uuid` | the box header: 32-bit size, then the type |
| 40 | `d8fec3d6-1b0e-483c-9297-5828877ec481` | the C2PA UUID (16 bytes) |
| 56 | `00 00 00 00` | version (1) and flags (3) |
| 60 | `manifest` `00` | the purpose, a null-terminated string |
| 69 | eight zero bytes | `merkle_offset`, a uint64 |
| 77 | `00 00 34 dd` `jumb` | the JUMBF superbox begins |

Peel those twenty-one bytes off and hand the rest to `JumbfParser` and
`ManifestStore` and **they read it unchanged** — one manifest, claim v2,
`c2pa.actions.v2` and `c2pa.hash.bmff.v3` (measured, step 73). Every layer
this project has built since SPEC-005 already works on ISOBMFF. What is
missing is the extractor, and nothing else.

Today an MP4 is `Invalid` with `general.error: unsupported file type`,
which is the right shape — it fails closed and names the magic bytes it
saw, rather than reporting "no manifest" for a file that has one. This spec
replaces a correct refusal with a correct reading; it does not close a
hole.

There is no byte-exact extraction to be had from `c2patool`: it prints no
store offset for BMFF. The oracle is therefore the store itself — the
bytes this extractor returns must be a manifest store that the existing
stack reads into the same manifest `c2patool --detailed` reports, and whose
SHA-256 this spec records once, measured.

## Scope

**In scope**

- Detecting ISOBMFF: a `ftyp` box at the head of the stream.
- Walking top-level boxes and finding the `uuid` box whose first sixteen
  content bytes are the C2PA UUID.
- The twenty-one-byte preamble, including a purpose that is not
  `manifest`.
- 64-bit box sizes (`size == 1`, a `largesize` follows) and the
  to-end-of-file form (`size == 0`).
- Bounds and every malformed case, as SPEC-001, SPEC-002 and SPEC-003 have
  them, and the store bound and host budget of SPEC-024.
- AVIF and HEIC, which are ISOBMFF and come along for free — measured, not
  assumed (see AC9).

**Out of scope** (each needs its own spec before it may be built)

- **`c2pa.hash.bmff.v3`.** The box-tree walk and exclusion matching are a
  different algorithm from SPEC-012's byte ranges, and are the next spec.
  Until it exists, an ISOBMFF file has no hard-binding check and this
  verifier must not pretend otherwise.
- Fragmented BMFF, the `merkle` purpose box, and `merkle_offset` when it is
  not zero — the fixture for it does not exist here.
- Boxes nested below the top level. C2PA 2.4 puts the store at the top
  level; a `uuid` box found deeper is not this spec's business.
- Every other format the brief lists for "later": GIF, TIFF, SVG, audio.

## Behavior

- **AC1 — a signed MP4 gives the store** *(happy path)*
  - Given `tests/Fixtures/fixture-signed.mp4`
  - When it is extracted
  - Then the returned bytes are 13 533 long, begin `00 00 34 dd 6a 75 6d
    62`, have the SHA-256 this spec's Traceability records, and
    `ManifestStore::fromTree(JumbfParser::parse(...))` reads from them one
    manifest with claim v2 whose label equals `active_manifest` in
    `tests/Fixtures/c2patool/mp4.json`.

- **AC2 — an ISOBMFF file without a C2PA box yields null, not an error**
  - Given `tests/Fixtures/fixture-unsigned.mp4`
  - When it is extracted
  - Then the result is `null`: a file with no Content Credentials is not a
    malformed file, as in SPEC-002 AC2.

- **AC3 — the format is detected, and detection does not guess**
  - Given a stream whose bytes 4 to 8 are `ftyp`
  - When the format is detected
  - Then it is reported as `isobmff`; and a file whose head is not one of
    the four known signatures is still `null`, so an unknown format stays
    an error at the verifier (SPEC-013 AC6).

- **AC4 — two C2PA boxes are an error** *(malformed input)*
  - Given a file carrying two top-level `uuid` boxes with the C2PA UUID
  - When it is extracted
  - Then it is a `ContainerException` naming both offsets — a store that
    exists twice is not a store to choose between, as SPEC-002 AC5 decided
    for `caBX`.

- **AC5 — the preamble is read, not assumed** *(malformed input)*
  - Given a C2PA `uuid` box whose purpose is not `manifest` — `merkle`, or
    a string this verifier does not know, or one with no null terminator
    before the end of the box
  - When it is extracted
  - Then it is a `ContainerException` naming the purpose it found. A
    `merkle` box is refused by name, because this spec does not read
    fragmented files and must not silently treat merkle data as a store.

- **AC6 — a box header that does not fit is an error** *(malformed input)*
  - Given a file whose box size is smaller than its own header, or whose
    size runs past the end of the file, or which declares `size == 1`
    without the eight bytes of `largesize` that must follow
  - When it is extracted
  - Then each is a `ContainerException` naming the offset and what was
    expected, and no read is attempted past the end of the stream.

- **AC7 — `size == 0` runs to the end of the stream** *(amended 2026-09-22, see Amendments 1)*
  - Given a box declaring `size == 0`, which ISO/IEC 14496-12 defines as
    "to the end of the file"
  - When it is read
  - Then its length is taken from the end of the stream, and if it is the
    C2PA box its store is read to there. There is no "not the last box"
    case to refuse: the declaration is what makes a box the last one, so a
    reader cannot tell a deliberate one from a truncation of what followed.
    What that costs is named in the Amendment, and belongs to the hash.

- **AC8 — the bounds of SPEC-024 apply here too**
  - Given a C2PA `uuid` box whose declared store is larger than the
    default bound, or larger than this host can hold
  - When it is extracted
  - Then it is refused before the store is read, with the same wording
    SPEC-024 requires of the other three containers, and the peak memory
    stays near the baseline.

- **AC9 — every ISOBMFF flavour this verifier claims is measured, not assumed**
  *(amended 2026-09-22, see Amendments 2)*
  - Given `fixture-signed.avif`, `fixture-signed.mov` and
    `fixture-signed.heic`, each signed with the same test certificates
  - When it is extracted
  - Then each yields a store the existing stack reads, with its
    `c2patool` verdict recorded beside it. **No ISOBMFF flavour may be
    named in the README, in `docs/comparison.md` or in a milestone row
    unless a fixture here holds it**, which is the rule step 80 had to be
    written because it was not followed.

## References

- Specification: C2PA 2.4 §11.3 (ISOBMFF), and ISO/IEC 14496-12 for the box
  structure — sizes, `largesize`, `size == 0`, and the `uuid` box.
- Oracle: `c2patool` 0.27.22, `tests/Fixtures/c2patool/mp4.json`, recorded
  in step 73. It reports `Valid` with `assertion.bmffHash.match`; what this
  spec can compare against is the manifest label and the claim it holds,
  not a store offset, which c2patool does not print.
- Measured, step 73 (`notes/step-73-isobmff-fixture.md`): the box layout
  above; the store at 13 533 bytes; the existing stack reading it
  unchanged; `c2pa.hash.bmff.v3` with box-path exclusions.
- Reasoned: that a `merkle` purpose must be refused rather than ignored,
  because ignoring it would leave a fragmented file looking like one with
  no Content Credentials.

## API sketch

The same shape as the other three extractors, so that `Verifier` gains a
fourth arm of its `match` and nothing else.

```php
// namespace Provemark\C2paVerifier\Container;

final readonly class IsobmffManifestStoreExtractor
{
    public const C2PA_UUID = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";

    public const PURPOSE_MANIFEST = 'manifest';

    public function __construct(
        public int $maxBoxLength = PngManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH,
        public int $maxBoxes = 4096,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null null when the file carries no C2PA box
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes;
}
```

## Open questions

1. **What `format` is reported.** The report's `format` field says `jpeg`,
   `png`, `webp` today. ISOBMFF covers several media types under one box
   structure, so the honest value is `isobmff` rather than `mp4` — but a
   caller who sees `isobmff` learns less than one who sees `mp4`. The
   proposal is `isobmff`, because this extractor genuinely does not know
   which brand it is looking at and should not guess. Non-blocker.
2. **Which `ftyp` brands are accepted.** The proposal is *any*: the brand
   list is long and grows, and a file that declares `ftyp` and holds a
   C2PA `uuid` box is one this verifier can read whatever its brand says.
   The risk is claiming support for a container whose hash rules differ.
   Non-blocker while the hash is out of scope; it becomes one with the
   next spec.
3. **Two amendments this spec forces when it is implemented.** SPEC-024
   AC1 says the bound is "in all three containers" and SPEC-013 AC6's
   unknown-format list is written against three; both need their literals
   widened to four. Naming them here so they are not discovered as
   surprises. Non-blocker.
4. **The new class must be marked `@internal`** and recorded, or SPEC-025
   AC2 fails the moment the file lands. That is the drift alarm working as
   designed, and it is mentioned so that the red phase is not mistaken for
   a fault. Non-blocker.

## Amendments

1. **2026-09-22, step 74b, found by the implementation** — AC7 asked for a
   `size == 0` box that is *not* the last one to be refused. That case
   cannot occur. `size == 0` means "to the end of the file", so the
   declaration itself makes the box the last one: a reader that honours it
   consumes everything after it, and a reader that does not has stopped
   honouring the format. The variant built for it
   (`isobmff/size-zero-not-last.mp4`) is read as one box running to the
   end, exactly as `c2patool` reads it.

   The criterion now says what is true, and the cost is named rather than
   wished away: a box declaring `size == 0` **can** swallow the boxes that
   followed it, and nothing in the container betrays that. What catches it
   is the hard binding — the bytes it swallowed are the bytes the hash
   covers — which is why `c2patool` answers `Invalid` on that variant
   rather than refusing to parse it, and why this verifier will do the same
   once `c2pa.hash.bmff.v3` exists. Until then an ISOBMFF file has no hard
   binding here and is `Invalid` for that reason, which happens to cover
   this case too, for the wrong reason. Written down so that the next spec
   knows it inherits this.
   Confirmed by Maurice van Loon, 2026-09-22 (step 75).

2. **2026-09-22, step 80b, after a claim outran its measurement** — AC9
   asked only for AVIF, because AVIF was the flavour in hand. MOV and HEIC
   were then named in the README, `docs/comparison.md` and a milestone row
   on the strength of ISOBMFF covering them in principle, with no fixture,
   no oracle and no test. Both do verify — measured afterwards, `Trusted`
   here and `Valid` at `c2patool` — which is luck rather than method.

   The criterion now covers all three and says the rule that was missing:
   no ISOBMFF flavour may be named anywhere unless a fixture here holds it.
   `fixture-signed.mov` (the sister repository's file) and
   `fixture-signed.heic` (made from this repository's own
   `fixture-unsigned.png` with macOS `sips`, so nothing third-party enters)
   join AVIF, each with its recorded `c2patool` verdict.

   Confirmed by Maurice van Loon, 2026-09-22 (step 81).



## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

All tests are in `tests/Unit/Container/IsobmffManifestStoreExtractorTest.php`,
group `SPEC-026`; the source is `src/Container/IsobmffManifestStoreExtractor.php`
unless another file is named. The store of `fixture-signed.mp4` is 13 533 bytes,
SHA-256 `58b8f2cce9f90ccb41239a8428c723a427862eb58670e8ca803dbd1a6f4a3a82`.

| Acceptance criterion | Test (name) | Source (symbol) |
|---|---|---|
| AC1 | `AC1: a signed MP4 gives the store, and the existing stack reads it` | `extract()`, `readStore()` |
| AC2 | `AC2: an ISOBMFF file with no C2PA box yields null, not an error` | `extract()` (the null return) |
| AC3 | `AC3: ftyp is detected as isobmff, and nothing else is guessed` | `src/Container/FormatDetector.php :: detect()`; `src/Verifier/Verifier.php` (the fourth match arm) |
| AC4 | `AC4: two C2PA boxes are an error naming both offsets` | `extract()` (the walk continues past the first) |
| AC5 | `AC5: a purpose this verifier does not read is an error naming it` | `readStore()`, `PURPOSE_MANIFEST` |
| AC6 | `AC6: a box header that does not fit is an error, and nothing is read past the end` | `boxHeader()` |
| AC7 | `AC7: size zero runs to the end of the stream, and cannot be caught here` | `boxHeader()` (amendment 1) |
| AC8 | `AC8: the bounds of SPEC-024 apply here too` | `DEFAULT_MAX_BOX_LENGTH`, `MemoryBudget` |
| AC9 | `AC9: AVIF is the same container, measured rather than assumed` | `extract()`; `tests/Fixtures/fixture-signed.avif` |
