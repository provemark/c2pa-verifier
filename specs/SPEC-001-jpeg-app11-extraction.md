# SPEC-001: JPEG APP11 → manifest store bytes

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
- The result as an immutable value object holding the bytes.

**Out of scope** (each needs its own spec before it may be built)

- Parsing the JUMBF box (M2, `Jumbf` layer).
- PNG (`SPEC-002`) and WebP (`SPEC-003`); every other container.
- Mapping extraction errors onto `c2patool`'s verdict. `c2patool` reports
  these as a top-level error, not as a C2PA 2.4 §15 status code; how the
  `Verifier` layer presents them is that layer's spec.
- APP11 segments after SOS. The scan stops at SOS; see Open questions.
- Segments without the `JP` identifier (other users of APP11): skipped as
  any unknown APPn segment is skipped, never read.

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

- **AC8 — an APP11 segment without `JP` is skipped**
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

- **AC11 — two different box instance numbers are an error**
  - Given the fixture with piece 2's En changed from 529 to 530
  - When the extractor runs
  - Then it throws `ContainerException` naming both instance numbers

- **AC12 — the default limits are stated and sufficient for the fixture**
  - Given an extractor constructed with no arguments
  - When it runs on the fixture
  - Then it succeeds, and its limits are readable as the values in the API
    sketch

## References

- Specification: C2PA 2.4 §A.3.1 "Embedding manifests into JPEG" (quoted in
  full in `notes/step-02-jpeg-fixture.md`); ISO/IEC 18477-3 and ISO
  19566-5:2023 D.2 are referenced there for the segment layout but were not
  read (paywalled).
- Oracle: `c2patool 0.27.22`; `tests/Fixtures/fixture-signed.jpg`; the
  reassembled store's SHA-256 measured with a probe in step 02; c2patool's
  behaviour on the swapped and non-contiguous variants, measured in step 02
  (`c2patool <variant>.jpg`).
- Reasoned: the 16-byte header layout (from the measurement, consistent
  across both pieces and with LBox); stopping the scan at SOS; skipping
  APP11 segments without `JP`; the default limits (no measurement says what
  the largest real store is — the sister library saw 2.5 MB from an
  auto-thumbnail); that two En values are an error (c2patool's behaviour
  unmeasured — see Open questions).

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
    public const DEFAULT_MAX_LBOX   = 64 * 1024 * 1024;

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

- Non-blocker, to be measured before the tests are written: what
  `c2patool` does with two APP11 groups with different En values in one
  file, and with an APP11 piece placed after SOS. This spec says "error" and
  "not scanned" respectively; if c2patool is more lenient, the spec is
  amended before approval, not after.
- Non-blocker: whether `ContainerException` should carry a machine-readable
  reason (an enum) next to the message. Deferred to the `Verifier` spec that
  first needs to map it.

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
