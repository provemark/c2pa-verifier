# SPEC-004: One stream reader for the Container layer

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-20                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The three extractors of M1 (SPEC-001, -002, -003) each carry a private
copy of the same three stream helpers — read exactly *n* bytes or fail,
skip *n* bytes or fail, tell the position — and two of them a copy of the
hex formatter for untrusted bytes. Three copies of code that decides how
a truncated file is reported is three places for the same mistake, and
two of the copies already disagree: the JPEG `skip()` probes one byte
after the segment, the PNG and WebP versions look up the file's end. The
probe is imprecise for a file that ends exactly on a segment boundary —
it reports "inside the segment" for a segment that is complete
(`notes/step-05-png-extractor.md`, `notes/step-07-webp-extractor.md`,
measured in step 08 on `truncated-between-segments.jpg`: `inside the
segment at offset 2`, while APP0 at offset 2 is whole and the file ends
at 20).

This spec introduces one `StreamReader` in the `Container` layer, moves
the three extractors onto it, and fixes the JPEG imprecision through
SPEC-001 amendment 2 (AC16). Nothing else changes: every existing
criterion of SPEC-001–003 stays green, byte for byte and message for
message, except the one JPEG message that becomes more precise.

## Scope

**In scope**

- `StreamReader`: wraps a readable, seekable stream and a noun for
  messages ("segment", "chunk"); offers `readExactly`, `skip`, `tell`,
  `end`, and the hex formatter. `skip` decides truncation by the file's
  end, as PNG and WebP do now: a file that ends *inside* the skipped
  bytes is an error naming the segment or chunk; one that ends *exactly*
  at their end is not the reader's error — the caller's next read reports
  what is missing.
- The three extractors use it; their private copies are removed. Their
  public API (`extract($stream)`, the constructor limits) is unchanged.
- SPEC-001 amendment 2: AC16, a JPEG that ends exactly on a segment
  boundary before SOS is an error naming the offset where a marker was
  expected; and AC14's message gains the two numbers the shared `skip`
  reports (where the segment ends, where the file ends).

**Out of scope** (each needs its own spec before it may be built)

- Any change to what is extracted or to any limit.
- A reader for non-seekable streams (`php://stdin`, network). Every
  extractor requires a seekable stream and says so.
- M2's box parser reading *from* a `StreamReader`: M2 works on the bytes
  `ManifestStoreBytes` holds, not on the file.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-004')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — the three extractors are unchanged in behaviour**
  - Given every fixture and variant of SPEC-001, SPEC-002 and SPEC-003
  - When the three extractors run on them after the move
  - Then every SPEC-001/002/003 test is green — measured as the whole
    suite passing with the same count plus this spec's and amendment 2's
    tests, and no extractor left with a private `readExactly`, `skip` or
    `tell` (a test greps the three source files)

- **AC2 — `readExactly` reads exactly or fails naming the offset**
  *(required: error / malformed input)*
  - Given a stream of 10 bytes and a reader with noun "chunk"
  - When `readExactly(4, offset 0, 'the header')` is called at position 0,
    then `readExactly(8, offset 4, 'the data')` at position 4
  - Then the first returns the four bytes, and the second throws
    `ContainerException` whose message names "the data", "the chunk at
    offset 4", 8 wanted and 6 got; and `readExactly(0, …)` returns `''`
    without touching the stream

- **AC3 — `skip` distinguishes "ends inside" from "ends exactly after"**
  - Given a stream of 20 bytes positioned at 8, and a reader with noun
    "segment"
  - When `skip(12, offset 4)` is called
  - Then it returns and the position is 20, and a following
    `readExactly(1, offset 20, 'a marker')` throws naming offset 20
  - And when instead `skip(13, offset 4)` is called
  - Then it throws `ContainerException` naming "the segment at offset 4",
    that it ends at 21 and the file at 20, and the position afterwards is
    not past 20

- **AC4 — `end` is the file length and leaves the position alone**
  - Given a stream of 20 bytes positioned at 8
  - When `end()` is called
  - Then it returns 20 and the position is still 8

- **AC5 — bytes are never shown raw**
  - Given the bytes `1B 5B 33 31 6D` (a terminal escape sequence) and `''`
  - When `StreamReader::hex()` formats them
  - Then the results are `1B 5B 33 31 6D` and `(nothing)`: upper-case pairs
    separated by single spaces, nothing else

## References

- Specification: none — this is an internal seam. The truncation
  semantics are those SPEC-002 AC14 and SPEC-003 AC5/AC6 already fix.
- Oracle: the 63 existing tests, unchanged; `c2patool 0.27.22` on
  `truncated-between-segments.jpg` → `Error: asset could not be parsed:
  Could not parse input JPEG` (measured 2026-09-20, step 08).
- Reasoned: that "ends exactly after" belongs to the caller's next read —
  the reader cannot know whether the caller expects more.

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;

declare(strict_types=1);

final readonly class StreamReader
{
    /** @param resource $stream a readable, seekable stream */
    public function __construct(private mixed $stream, private string $noun) {}

    /** Exactly $length bytes, or ContainerException naming $what and the {noun} at $offset. '' for 0. */
    public function readExactly(int $length, int $offset, string $what): string;

    /** Forward $length bytes without reading; ContainerException if the file ends inside them. */
    public function skip(int $length, int $offset): void;

    public function tell(): int;

    /** The file's length; the position is unchanged afterwards. */
    public function end(): int;

    /** Upper-case hex pairs, '(nothing)' for ''. Never raw bytes. */
    public static function hex(string $bytes): string;
}
```

The extractors construct one reader per `extract()` call
(`new StreamReader($stream, 'segment')`) and keep their own marker/chunk
logic; only the four helpers move. The JPEG `readMarker`, `readUint16`,
`hasLengthField`, the WebP `readPad` and `printable` stay where they are.

## Open questions

- Whether `hex()` belongs on the reader or in a `Bytes` helper of its own.
  Kept on the reader; it formats what the reader read. Non-blocker.
- Added while implementing: `readUpTo(int $length)`, a read that may return
  fewer bytes, for the three places where a caller wants to see a short
  read and name the fault itself (the WebP header, the PNG chunk header,
  the WebP pad byte). Without it those three kept a direct `fread`, and the
  reader would not have been the one seam it is meant to be. Covered by
  the existing criteria those callers serve (SPEC-002 AC14, SPEC-003 AC3
  and AC12).

## Amendments

1. **2026-09-21, approved by Maurice van Loon with SPEC-005 step 11b** —
   `hex()` moved from `StreamReader` to `Support\Bytes::hex()`, with the
   WebP extractor's `printable()` next to it, in a new `Support` layer
   (`deptrac.yaml`) that the parsers may use. Cause: SPEC-005's parser
   needs the same formatter and `Jumbf` is a leaf layer that may not see
   `Container`. AC5 is unchanged in substance; its test now names
   `Bytes::hex()`.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/StreamReaderTest.php :: AC1: no extractor carries a private readExactly, skip or tell any more; and the whole suite (SPEC-001/002/003 groups unchanged) / SPEC-004 | src/Container/{Jpeg,Png,Webp}ManifestStoreExtractor.php :: extract() (`new StreamReader(`) |
| AC2 | tests/Unit/Container/StreamReaderTest.php :: AC2: readExactly returns exactly the bytes asked for, or fails naming what, where and how much; AC2: readExactly of zero bytes returns an empty string and does not touch the stream / SPEC-004 | src/Container/StreamReader.php :: readExactly() |
| AC3 | tests/Unit/Container/StreamReaderTest.php :: AC3: skip to exactly the end of the file is not an error; the next read is; AC3: skip past the end of the file is an error naming the segment, its end and the file end / SPEC-004 | src/Container/StreamReader.php :: skip() |
| AC4 | tests/Unit/Container/StreamReaderTest.php :: AC4: end returns the file length and leaves the position alone / SPEC-004 | src/Container/StreamReader.php :: end(), tell() |
| AC5 | tests/Unit/Container/StreamReaderTest.php :: AC5: hex never shows bytes raw / SPEC-004 | src/Support/Bytes.php :: hex() (amendment 1) |
