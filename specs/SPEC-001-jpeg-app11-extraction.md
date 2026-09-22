# SPEC-001: JPEG APP11 → manifest store bytes

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-19                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Everything the verifier will ever check — signature, hash binding, chain,
timestamp — lives in the C2PA manifest store, and in a JPEG that store is
cut into pieces and hidden in APP11 marker segments (C2PA 2.4 §A.3.1). The
first thing the verifier must do is get those bytes out, exactly, so that
every later milestone works on the same bytes `c2patool` works on.

This is the first feature on purpose: it is measurable with a hash and no
cryptography. `notes/step-02-jpeg-fixture.md` records the measurement this
spec rests on — every segment of the fixture, the 16-byte header of each
APP11 piece, the byte-exact reassembly, and how `c2patool 0.27.22` behaves
when the pieces have a gap between them or are out of order.

Nothing in this spec interprets the bytes. What a JUMBF box is, what is in
it, whether it is a C2PA store at all: M2.

## Scope

**In scope**

- Reading a JPEG from a stream, marker by marker, from SOI to SOS, without
  loading the whole file into memory.
- Recognising APP11 segments (`FFEB`) whose payload starts with the common
  identifier `JP`, reading their 16-byte header (CI, En, Z, LBox, TBox), and
  reassembling the box: LBox and TBox once, then the data of every piece in
  file order.
- Every malformed and out-of-order case in AC3–AC11 as an error with no
  partial result.
- Hard limits on the number of pieces and on LBox, checked before memory is
  spent.
- Markers that carry no length field (TEM `FF01`, RST0–7 `FFD0`–`FFD7`, a
  second SOI, EOI) and reserved marker codes (`FF02`–`FFBF`) before SOS are
  errors naming the marker and its offset (AC15, amendment 1). Reading a
  length where there is none would skip an arbitrary number of bytes and
  could land the scan past the store.
- The result as an immutable value object holding the bytes.

**Out of scope** (each needs its own spec before it may be built)

- Parsing the JUMBF box (M2, `Jumbf` layer).
- PNG (`SPEC-002`) and WebP (`SPEC-003`); every other container.
- Mapping extraction errors onto `c2patool`'s verdict. `c2patool` reports
  these as a top-level error, not as a C2PA 2.4 §15 status code; how the
  `Verifier` layer presents them is that layer's spec.
- APP11 segments after SOS. The scan stops at SOS, as `c2patool` does
  (AC13).
- Segments without the `JP` identifier (other users of APP11): skipped as
  any unknown APPn segment is skipped, never read (AC8).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-001')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — the fixture yields the store, byte-exact**
  - Given `tests/Fixtures/fixture-signed.jpg`
  - When the extractor runs on it
  - Then it returns 94,740 bytes whose SHA-256 is
    `f47af93e8afe0f71912e4c7545184ace2fb2cd5628b4a3ac832e591ac33546a3`, and
    whose first eight bytes are `00 01 72 14 6a 75 6d 62` (LBox, `jumb`)

- **AC2 — no APP11 is an outcome, not an error**
  - Given `tests/Fixtures/fixture-unsigned.jpg` (a valid JPEG with no APP11)
  - When the extractor runs
  - Then it returns `null` and throws nothing

- **AC3 — pieces out of order are an error** *(oracle: `c2patool` →
  `Error: invalid embedded file box`)*
  - Given the fixture with its two APP11 segments swapped (Z = 2 before Z = 1)
  - When the extractor runs
  - Then it throws `ContainerException` whose message names the expected and
    the found sequence number, and returns no bytes

- **AC4 — a gap between pieces does not stop extraction** *(oracle:
  `c2patool` extracts and validates the signature, then reports
  `assertion.dataHash.mismatch`, which is M4's concern)*
  - Given the fixture with the COM segment moved between the two APP11
    pieces
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC5 — a file truncated inside a piece is an error** *(required: error /
  malformed input)*
  - Given the fixture cut off 1,000 bytes into the second APP11 segment
  - When the extractor runs
  - Then it throws `ContainerException` naming the segment offset, and
    returns no bytes

- **AC6 — a missing piece is an error**
  - Given the fixture with the second APP11 segment removed (Z = 1 only,
    LBox still 94,740)
  - When the extractor runs
  - Then it throws `ContainerException` naming LBox and the bytes actually
    collected

- **AC7 — LBox or TBox that differ between pieces are an error**
  - Given the fixture with piece 2's LBox field changed by one
  - When the extractor runs
  - Then it throws `ContainerException` naming both values

- **AC8 — an APP11 segment without `JP` is skipped** *(oracle: `c2patool`
  extracts and validates the signature, then `assertion.dataHash.mismatch`
  for the moved bytes — M4)*
  - Given the fixture with an extra APP11 segment inserted before the first
    piece, whose payload starts with `XX` instead of `JP`
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC9 — limits are enforced before memory is spent**
  - Given an extractor constructed with a maximum LBox of 1,000 bytes
  - When it runs on the fixture (LBox 94,740)
  - Then it throws `ContainerException` naming the limit and the LBox, and
    the first piece's data is never read
  - And given an extractor with a maximum of 1 piece
  - Then it throws `ContainerException` at the second piece

- **AC10 — not a JPEG is an error**
  - Given a stream whose first two bytes are not `FF D8`
  - When the extractor runs
  - Then it throws `ContainerException` before reading further

- **AC11 — two different box instance numbers are an error** *(oracle:
  `c2patool` → `Error: invalid embedded file box`)*
  - Given the fixture with piece 2's En changed from 529 to 530
  - When the extractor runs
  - Then it throws `ContainerException` naming both instance numbers

- **AC12 — the default limits are stated and sufficient for the fixture**
  - Given an extractor constructed with no arguments
  - When it runs on the fixture
  - Then it succeeds, and its limits are readable as the values in the API
    sketch

- **AC13 — pieces after SOS are not scanned** *(oracle: `c2patool` →
  `Error: No claim found`)*
  - Given the fixture with both APP11 pieces moved to after the entropy-coded
    data, before EOI
  - When the extractor runs
  - Then it returns `null` and throws nothing

- **AC14 — a file truncated before the first piece is an error, not "no
  store"** *(amendment 1; oracle: `c2patool` → `Error: asset could not be
  parsed: Could not parse input JPEG`)*
  - Given the fixture cut off 12 bytes in, inside the APP0 segment, before
    any APP11
  - When the extractor runs
  - Then it throws `ContainerException` naming the segment offset (2), and
    does not return `null`

- **AC15 — a marker without a length field before SOS is an error naming
  the marker** *(amendment 1; oracle: `c2patool` → `Error: No claim found`
  — it reads the two bytes after `FF D0` as a length, skips the first piece
  and finds no store; this verifier is stricter and names the cause)*
  - Given the fixture with a bare `FF D0` (RST0) inserted between APP0 and
    the first piece
  - When the extractor runs
  - Then it throws `ContainerException` naming `FF D0` and offset 20, and
    returns no bytes

- **AC16 — a file that ends exactly on a segment boundary is an error
  naming the offset where a marker was expected** *(amendment 2; oracle:
  `c2patool` → `Error: asset could not be parsed: Could not parse input
  JPEG`)*
  - Given the fixture cut off at offset 20, exactly after APP0 and before
    the first piece
  - When the extractor runs
  - Then it throws `ContainerException` naming a marker expected at offset
    20 — not "inside the segment at offset 2", which is whole

## References

- Specification: C2PA 2.4 §A.3.1 "Embedding manifests into JPEG" (quoted in
  full in `notes/step-02-jpeg-fixture.md`); ISO/IEC 18477-3 and ISO
  19566-5:2023 D.2 are referenced there for the segment layout but were not
  read (paywalled).
- Oracle: `c2patool 0.27.22`; `tests/Fixtures/fixture-signed.jpg`; the
  reassembled store's SHA-256 measured with a probe in step 02; c2patool's
  behaviour on five variants, measured in step 02 (`c2patool <variant>.jpg`):
  swapped, non-contiguous, two En values, pieces after SOS, an APP11
  without `JP`.
- Amendment 1: ITU-T T.81 Table B.1 (marker code assignments) for which
  markers carry a length field; `c2patool 0.27.22` on the two new variants,
  measured 2026-09-19 (`notes/step-03-jpeg-extractor.md`).
- Reasoned: the 16-byte header layout (from the measurement, consistent
  across both pieces and with LBox, not from the ISO text); the default
  limits (no measurement says what the largest real store is — the sister
  library saw 2.5 MB from an auto-thumbnail).

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;

declare(strict_types=1);

final readonly class ManifestStoreBytes
{
    public function __construct(public string $bytes) {}
}

final class ContainerException extends \RuntimeException {}

final readonly class JpegManifestStoreExtractor
{
    public const DEFAULT_MAX_PIECES = 2048;         // 2048 × 64 KiB ≈ 128 MiB, above MAX_LBOX
    public const DEFAULT_MAX_LBOX   = 16 * 1024 * 1024;

    public function __construct(
        public int $maxPieces = self::DEFAULT_MAX_PIECES,
        public int $maxLBox = self::DEFAULT_MAX_LBOX,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null  null when the JPEG has no APP11 JUMBF pieces
     *
     * @throws ContainerException on every malformed or out-of-order case
     */
    public function extract($stream): ?ManifestStoreBytes;
}
```

The extractor reads segment headers with `fread`, skips segment bodies it
does not need with `fseek`, and reads piece data only after the header
checks and limits pass. It never calls `file_get_contents`.

## Open questions

- Resolved before approval: `c2patool`'s behaviour with two En values
  (error) and with pieces after SOS (no claim found) was measured on
  2026-09-19; AC11 and AC13 carry the result.
- Non-blocker: whether `ContainerException` should carry a machine-readable
  reason (an enum) next to the message. Deferred to the `Verifier` spec that
  first needs to map it.

## Amendments

1. **2026-09-19, approved by Maurice van Loon** — AC14 and AC15 added, the
   marker rule added to Scope. Cause: a walk through the implementation
   showed two behaviours guarded by reasoning only. The end-of-file probe in
   the skip of an unneeded segment (without it a file truncated before the
   first piece would yield `null`, "no store") had no fixture; and the
   check for markers without a length field used a numeric boundary
   (`< 0xC0`) that missed RST0–7, so a bare `FF D0` before SOS produced a
   misleading error at a far offset instead of naming the marker.

2. **2026-09-20, approved by Maurice van Loon with SPEC-004** — AC16 added; AC14's message
   gains the two numbers the shared `skip` reports (the segment's end and
   the file's end). Cause: the end-of-file probe in `skip()` (amendment 1)
   reported "inside the segment" for a segment that is complete when the
   file ends exactly after it. SPEC-002 and SPEC-003 already decide this
   by the file's end; SPEC-004 gives all three the same reader.
3. **2026-09-21, defined in SPEC-012 and approved with it** — `ManifestStoreBytes` gains `public array $ranges`, the byte ranges of the file the store and its container framing occupy, one per piece, contiguous pieces merged: for JPEG each APP11 piece from its marker through its data (`2 + length`), so the two-piece fixture gives one range `[20, 94772]` and the gap variant of AC4 two. No criterion of this spec changed; the bytes are as they were.

4. **2026-09-22, step 67b, defined in SPEC-024 and approved with it** —
   the default bound on the manifest store falls from **64 MiB to 16 MiB**,
   and a store that fits the bound but not the host's remaining memory is
   refused before it is read. Step 66 measured why: a 63 MiB store — inside
   this criterion's own limit — needs 132 MB and ends a 128 MB host with a
   PHP fatal error instead of returning `Invalid`, which cannot be caught
   and leaves the caller no report at all. Measured beside it: across 212
   corpus stores the median is 45 kB, the 90th percentile 241 kB and the
   largest ever met 3.36 MB, so the old figure was nineteen times anything
   real. A store at the new bound peaks at 38 MB, which a 64 MB host
   survives. AC12's literal changes with it; `DEFAULT_MAX_PIECES` is untouched, and the note beside it ("2048 x 64 KiB, above MAX_LBOX") still holds.
   Confirmed by Maurice van Loon, 2026-09-22 (step 68).


## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC1: extracts the store from the fixture, byte-exact / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract(); src/Container/ManifestStoreBytes.php |
| AC2 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC2: a JPEG without APP11 yields null, not an error / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$pieces === 0`) |
| AC3 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC3: pieces out of order are an error naming the expected and found sequence numbers / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$fields['z'] !== $pieceNumber`); src/Container/ContainerException.php |
| AC4 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC4: a gap between the pieces still yields the same store / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract(), skip() |
| AC5 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC5: a file truncated inside a piece is an error naming the segment offset / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: readExactly(), skip() |
| AC6 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC6: a missing piece is an error naming LBox and the bytes collected / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`strlen($collected) !== $lBox`) |
| AC7 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC7: an LBox that differs between pieces is an error naming both values / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$fields['lbox'] !== $lBox`) |
| AC8 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC8: an APP11 segment without JP is skipped / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`str_starts_with($header, 'JP')`) |
| AC9 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC9: an LBox above the limit is an error before any piece data is read; AC9: more pieces than the limit is an error at the piece that exceeds it / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$maxLBox`, `$maxPieces` checks before the data read) |
| AC10 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC10: a stream that does not start with FF D8 is an error / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (SOI check) |
| AC11 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC11: two different box instance numbers are an error naming both / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$fields['en'] !== $instanceNumber`) |
| AC12 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC12: the default limits are 2048 pieces and 16 MiB / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: DEFAULT_MAX_PIECES, DEFAULT_MAX_LBOX, __construct() |
| AC13 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC13: pieces after SOS are not scanned; the result is null / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: extract() (loop ends at SOS) |
| AC14 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC14: a file truncated before the first piece is an error naming the segment offset, not null / SPEC-001 | src/Container/StreamReader.php :: skip() (end-of-file look-up, amendment 2) |
| AC15 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC15: a marker without a length field before SOS is an error naming the marker and its offset / SPEC-001 | src/Container/JpegManifestStoreExtractor.php :: hasLengthField(), extract() |
| AC16 | tests/Unit/Container/JpegManifestStoreExtractorTest.php :: AC16: a file that ends exactly on a segment boundary is an error naming the offset where a marker was expected / SPEC-001 | src/Container/StreamReader.php :: skip() (ends exactly after: no error), src/Container/JpegManifestStoreExtractor.php :: readMarker() (the next read names offset 20) |
