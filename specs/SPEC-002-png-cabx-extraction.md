# SPEC-002: PNG `caBX` → manifest store bytes

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The second container of M1. In a PNG the manifest store is not cut into
pieces: it sits whole in one chunk of type `caBX` (C2PA 2.4 §A.3
"Embedding manifests into PNG"), and the verifier must get those bytes
out exactly, so that every later milestone works on the same bytes
`c2patool` works on. `notes/step-04-png-fixture.md` records the
measurement this spec rests on — the chunk layout of the fixture, the
store's hash, and how `c2patool 0.27.22` behaves on ten malformed
variants, with the reason for each read from the c2pa-rs source.

Two of those measurements shape this spec more than the rest: c2pa-rs
reads the CRC of every chunk and discards it, and it never compares the
LBox inside the box with the length of the chunk around it. On both this
verifier is stricter — an error where the oracle says `Valid` — because a
container that disagrees with itself is malformed, and refusing malformed
input is the fail-closed rule (`docs/milestones.md`, "Fixed across all of
them"). The divergence is written next to the criterion, as SPEC-001 did
for its AC7.

Nothing in this spec interprets the bytes. What a JUMBF box is, what is in
it, whether it is a C2PA store at all: M2.

## Scope

**In scope**

- Reading a PNG from a stream, chunk by chunk, from the eight-byte
  signature to `IEND`, without loading the whole file into memory: the
  eight-byte chunk header is read, the data of every chunk but `caBX` is
  skipped with `fseek`, and its CRC is not read.
- Recognising the one `caBX` chunk, checking its length against the limit
  before its data is read, reading its data and CRC, and checking two
  things about it: the CRC-32 over type and data equals the stored CRC
  (ISO/IEC 15948 §5.5; the algorithm is PHP's `crc32()`, measured equal on
  all four chunks of the fixture in step 04), and the LBox in the first four
  bytes of the data equals the chunk length.
- Every malformed case in AC3–AC7 and AC10–AC11 as an error with no partial
  result.
- A hard limit on the chunk length, checked before memory is spent.
- The result as the value object of SPEC-001, `ManifestStoreBytes`, and
  errors as SPEC-001's `ContainerException`. Both stay in the `Container`
  layer; nothing new is introduced for them.

**Out of scope** (each needs its own spec before it may be built)

- Parsing the JUMBF box (M2, `Jumbf` layer). In particular, whether the
  box's own contents fit its LBox is M2's question; this spec only compares
  LBox with the chunk length.
- WebP (`SPEC-003`); every other container.
- Mapping extraction errors onto `c2patool`'s verdict (as SPEC-001).
- Where the `caBX` chunk sits. C2PA says before `IDAT` and the PNG
  specification says `IHDR` first, but `c2patool` extracts the chunk
  wherever it is and lets the hash binding reject the file (measured:
  `assertion.dataHash.mismatch`, `Invalid`). This spec does the same
  (AC8, AC9): an error here would hide the more precise verdict M4 gives.
- The CRCs of chunks other than `caBX`, and anything after `IEND`. Neither
  is read.
- Chunk types that are not four ASCII letters. `c2patool` checks only that
  the four bytes are UTF-8. Not measured with a fixture; see Open questions.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-002')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — the fixture yields the store, byte-exact**
  - Given `tests/Fixtures/fixture-signed.png`
  - When the extractor runs on it
  - Then it returns 46,025 bytes whose SHA-256 is
    `1a018eb892c4b30c112976cd7411df24baa9ce788cfe2904f58a69dec6e057df`, and
    whose first eight bytes are `00 00 b3 c9 6a 75 6d 62` (LBox, `jumb`)

- **AC2 — no `caBX` is an outcome, not an error**
  - Given `tests/Fixtures/fixture-unsigned.png` (a valid PNG with no `caBX`)
  - When the extractor runs
  - Then it returns `null` and throws nothing

- **AC3 — not a PNG is an error** *(oracle: `c2patool` → `Error: Unsupported
  file type`)*
  - Given a stream whose first eight bytes are not `89 50 4E 47 0D 0A 1A 0A`
  - When the extractor runs
  - Then it throws `ContainerException` naming the expected signature,
    before reading further

- **AC4 — a file truncated inside the chunk is an error** *(required: error /
  malformed input; oracle: `c2patool` → `Error: asset could not be parsed:
  PNG out of range`)*
  - Given the fixture cut off 1,000 bytes into the `caBX` data
  - When the extractor runs
  - Then it throws `ContainerException` naming the chunk offset (33), and
    returns no bytes

- **AC5 — two `caBX` chunks are an error** *(oracle: `c2patool` → `Error:
  more than one manifest store detected`)*
  - Given the fixture with its `caBX` chunk duplicated (offsets 33 and
    46,070)
  - When the extractor runs
  - Then it throws `ContainerException` naming both offsets, and returns no
    bytes — not the first chunk, not the last

- **AC6 — a CRC that does not match is an error** *(stricter than the
  oracle: `c2patool` → `Valid`, because c2pa-rs reads the CRC and discards
  it)*
  - Given the fixture with one bit flipped in the CRC of `caBX`, data
    untouched
  - When the extractor runs
  - Then it throws `ContainerException` naming the stored and the computed
    CRC, and returns no bytes

- **AC7 — an LBox that differs from the chunk length is an error**
  *(stricter than the oracle: `c2patool` → `Valid`, because c2pa-rs never
  compares them)*
  - Given the fixture with the LBox inside the box changed from 46,025 to
    46,026, chunk length unchanged, CRC recomputed
  - When the extractor runs
  - Then it throws `ContainerException` naming both values, and returns no
    bytes

- **AC8 — a `caBX` after `IDAT` is still extracted** *(oracle: `c2patool`
  extracts and validates the signature, then reports
  `assertion.dataHash.mismatch`, which is M4's concern)*
  - Given the fixture with `caBX` moved after `IDAT`
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC9 — a `caBX` before `IHDR` is still extracted** *(same oracle
  behaviour as AC8; the PNG-spec violation is not this layer's to judge)*
  - Given the fixture with `caBX` moved before `IHDR`
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC10 — a chunk length field that is off by one is an error** *(oracle:
  `c2patool` → `PNG out of range`, because its chunk walk runs off the
  file)*
  - Given the fixture with the `caBX` length field changed from 46,025 to
    46,026, data and CRC untouched
  - When the extractor runs
  - Then it throws `ContainerException` — the LBox (46,025) no longer equals
    the chunk length (46,026), and the message names both — and returns no
    bytes

- **AC11 — a `caBX` shorter than a box header is an error** *(oracle:
  `c2patool` → `Error: unexpected end of file` for 4 bytes, `Error: No
  claim found` for 0 bytes; this verifier treats a present-but-empty store
  as malformed, not as absent)*
  - Given the fixture with its `caBX` replaced by one of 4 bytes, and
    separately by one of 0 bytes
  - When the extractor runs
  - Then, in both cases, it throws `ContainerException` naming the chunk
    length and the 8-byte minimum, and returns no bytes

- **AC12 — the limit is enforced before memory is spent**
  - Given an extractor constructed with a maximum chunk length of 1,000
    bytes
  - When it runs on the fixture (`caBX` length 46,025)
  - Then it throws `ContainerException` naming the limit and the length, and
    the chunk's data is never read (the stream stands at or before offset
    41, the end of the `caBX` chunk header)

- **AC13 — the default limit is stated and sufficient for the fixture**
  - Given an extractor constructed with no arguments
  - When it runs on the fixture
  - Then it succeeds, and its limit is readable as the value in the API
    sketch

- **AC14 — a file that ends before `IEND` is an error, not "no store"**
  *(oracle: `c2patool` → `PNG out of range`; no fixture yet, see Open
  questions)*
  - Given a PNG cut off between two chunks, with no `caBX` before the cut
  - When the extractor runs
  - Then it throws `ContainerException` naming the offset where a chunk
    header was expected, and does not return `null`

## References

- Specification: C2PA 2.4 §A.3 "Embedding manifests into PNG"; ISO/IEC
  15948 (the PNG specification, W3C Recommendation) §5.2 signature, §5.3
  chunk layout, §5.4 chunk naming conventions, §5.5 CRC, §5.6 chunk
  ordering — read, freely available, quoted where used in
  `notes/step-04-png-fixture.md`.
- Oracle: `c2patool 0.27.22`; `tests/Fixtures/fixture-signed.png`; the
  store's SHA-256 measured with a probe in step 04, with LBox equal to the
  chunk length and the box a `jumb` with a `c2pa` description; c2patool's
  behaviour on the ten variants under `tests/Fixtures/png/` (`c2patool
  <variant>.png`), each recorded in that directory's README; c2pa-rs
  `sdk/src/asset_handlers/png_io.rs` on `main`, read 2026-09-19, for why.
- Reasoned: that PHP's `crc32()` is the PNG CRC — measured on four chunks,
  reasoned for the general case (both are the IEEE CRC-32); the default
  limit (as SPEC-001's `maxLBox`); AC14's behaviour.

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;

declare(strict_types=1);

final readonly class PngManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = 64 * 1024 * 1024;   // as SPEC-001's DEFAULT_MAX_LBOX

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null  null when the PNG has no caBX chunk
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes;
}
```

Reads the signature and every chunk header with `fread`, skips the data
and CRC of every chunk that is not `caBX` with `fseek`, reads the `caBX`
data only after the limit check, then its CRC, and stops at `IEND`. Never
calls `file_get_contents`. `fread` with a length of 0 throws in PHP 8, and
`IEND` has length 0: zero-length reads are never issued.

## Open questions

- **AC14 has no fixture.** Proposal: add `truncated-between-chunks.png`
  (the fixture cut after `IHDR`'s CRC, before `caBX`) to
  `bin/make-png-variants.php` when the tests are written, measured against
  c2patool first. Blocker for `implemented`, not for approval.
- **Chunk types that are not four ASCII letters.** Fail closed says error;
  c2patool accepts anything UTF-8. No fixture, no criterion yet. Proposal:
  leave it out of this spec, note it, and revisit if a real file ever
  shows one. Non-blocker.
- **The name of the limit.** `maxChunkLength` here, `maxLBox` in SPEC-001;
  they bound the same thing (the store's size). Proposal: keep the name
  that says what is actually checked in each container. Non-blocker.

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
| AC10                 | —                           | —                    |
| AC11                 | —                           | —                    |
| AC12                 | —                           | —                    |
| AC13                 | —                           | —                    |
| AC14                 | —                           | —                    |
