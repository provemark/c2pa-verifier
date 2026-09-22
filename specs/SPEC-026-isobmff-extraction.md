# SPEC-026: ISOBMFF `uuid` box → manifest store bytes

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | — while draft                                     |
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

- **AC7 — `size == 0` is the last box, or it is an error** *(malformed input)*
  - Given a box declaring `size == 0`, which ISO/IEC 14496-12 defines as
    "to the end of the file"
  - When it is not the last top-level box, or when it is the C2PA box
  - Then the first is a `ContainerException`; the second is read, with its
    length taken from the end of the stream.

- **AC8 — the bounds of SPEC-024 apply here too**
  - Given a C2PA `uuid` box whose declared store is larger than the
    default bound, or larger than this host can hold
  - When it is extracted
  - Then it is refused before the store is read, with the same wording
    SPEC-024 requires of the other three containers, and the peak memory
    stays near the baseline.

- **AC9 — AVIF is measured, not assumed**
  - Given the sister repository's `fixture.avif`, signed with the same test
    certificates
  - When it is extracted
  - Then either it yields a store the existing stack reads — in which case
    AVIF is supported by this spec and the fixture joins the repository —
    or it does not, and what differs is written into this spec as an
    amendment before any claim of AVIF support is made anywhere.

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
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
