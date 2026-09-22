# SPEC-003: WebP RIFF `C2PA` → manifest store bytes

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

The third and last container of M1. A WebP is a RIFF file, and C2PA 2.4
§A.3 "Embedding manifests into WebP" puts the manifest store whole in one
chunk of type `C2PA`. `notes/step-06-webp-fixture.md` records the
measurement this spec rests on: the fixture's layout, the store's hash,
the pad byte that RIFF adds after an odd-length chunk (the fixture's
store is odd, so the fixture itself exercises it), and how `c2patool
0.27.22` behaves on sixteen malformed variants, with the reason for each
read from c2pa-rs `riff_io.rs`.

RIFF gives a reader less to check than PNG (no CRC) and one thing more:
a size field in the file header that promises the file's length. c2pa-rs
honours that size — it walks only within it — but never compares it to
the file, never checks the form type after `RIFF`, takes the first `C2PA`
chunk and ignores any other, and never compares LBox with the chunk
length. On all of these this verifier is stricter, an error where the
oracle extracts, because each is the container disagreeing with itself
and refusing malformed input is the fail-closed rule. Every divergence is
written next to its criterion. The maintainer decided the two open ones
(the RIFF size, the pad byte) on 2026-09-20: both errors.

Nothing in this spec interprets the bytes. What a JUMBF box is: M2.

## Scope

**In scope**

- Reading a WebP from a stream: the twelve-byte header (`RIFF`, size,
  `WEBP`), then chunk by chunk to the end of the RIFF body, without
  loading the whole file into memory: eight bytes of chunk header, the
  data of every chunk but `C2PA` skipped with `fseek`, the pad byte after
  an odd-length chunk read and checked to be `00`.
- Checking the header's size against the file length before the walk
  (the length is looked up with a seek to the end), so that a truncated
  or padded file is one error naming both numbers, not a walk that runs
  off the file.
- Recognising the one `C2PA` chunk, checking its length against the limit
  and the 8-byte minimum before its data is read, then LBox against the
  chunk length, then reading the data — without the pad byte, which is
  not part of the store.
- Every malformed case in AC3–AC7, AC9–AC12 and AC16 as an error with no
  partial result.
- The result as SPEC-001's `ManifestStoreBytes`, errors as SPEC-001's
  `ContainerException`.

**Out of scope** (each needs its own spec before it may be built)

- Parsing the JUMBF box (M2).
- WAV and AVI, the other RIFF forms. A file whose form type is not `WEBP`
  is an error here (AC4), not a different code path.
- Mapping extraction errors onto `c2patool`'s verdict (as SPEC-001).
- Where the `C2PA` chunk sits. c2patool extracts it before or after the
  image data and lets M4 judge (measured: `assertion.dataHash.mismatch`);
  this spec does the same (AC8).
- Chunk types that are not four ASCII characters (as SPEC-002).
- A shared stream reader for the three extractors. This is the third copy
  of `readExactly` / `skip` / `tell`; merging them is its own step, after
  this spec is implemented, with the JPEG probe's boundary imprecision
  (step 05) harmonised in the same step.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-003')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

- **AC1 — the fixture yields the store, byte-exact, without the pad byte**
  - Given `tests/Fixtures/fixture-signed.webp`
  - When the extractor runs on it
  - Then it returns 100,635 bytes whose SHA-256 is
    `5062cb0a602aa10a8d826370d27107284fcc9957dc93c9f1b025f7a380c13999`, and
    whose first eight bytes are `00 01 89 1b 6a 75 6d 62` (LBox, `jumb`)

- **AC2 — no `C2PA` is an outcome, not an error**
  - Given `tests/Fixtures/fixture-unsigned.webp` (a valid WebP with no
    `C2PA`)
  - When the extractor runs
  - Then it returns `null` and throws nothing

- **AC3 — not a RIFF file is an error** *(oracle: `c2patool` → `Error:
  Unsupported file type`)*
  - Given a stream whose first four bytes are not `RIFF`
  - When the extractor runs
  - Then it throws `ContainerException` naming `RIFF`, before reading
    further

- **AC4 — a RIFF file that is not a WebP is an error** *(stricter than the
  oracle: `c2patool` reads a `WAVE` form as if it were WebP and extracts)*
  - Given the fixture with the form type changed from `WEBP` to `WAVE`
  - When the extractor runs
  - Then it throws `ContainerException` naming `WEBP` and `WAVE`, before
    reading any chunk

- **AC5 — a header size that disagrees with the file is an error**
  *(required: error / malformed input; decided 2026-09-20. Oracle: size +1
  → `c2patool` extracts and M4 rejects; size as if `C2PA` were absent →
  `No claim found`; file truncated inside the chunk → `RIFF chunk declared
  size exceeds file size`; truncated between chunks → `Invalid RIFF
  format`)*
  - Given the fixture with the header size +1 (100,949 for a file of
    100,956 bytes); or with the size set to 304 as if `C2PA` were absent;
    or cut off 1,000 bytes into the `C2PA` data; or cut off where the
    `C2PA` chunk header should start
  - When the extractor runs
  - Then, in all four cases, it throws `ContainerException` naming the
    header's size and the file's length, and returns no bytes

- **AC6 — a chunk that overruns the file is an error** *(oracle:
  `c2patool` → `RIFF chunk declared size exceeds file size`)*
  - Given the fixture with the `C2PA` length field +1,000 and the header
    size correct for the file
  - When the extractor runs
  - Then it throws `ContainerException` naming the chunk offset (312), its
    declared length and where the file ends, before reading the chunk's
    data

- **AC7 — two `C2PA` chunks are an error** *(stricter than the oracle:
  `c2patool` takes the first and never sees the second)*
  - Given the fixture with its `C2PA` chunk duplicated (offsets 312 and
    100,956)
  - When the extractor runs
  - Then it throws `ContainerException` naming both offsets, and returns no
    bytes — not the first, not the last

- **AC8 — a `C2PA` before the image data is still extracted** *(oracle:
  `c2patool` extracts, then `assertion.dataHash.mismatch`, M4's concern)*
  - Given the fixture with `C2PA` moved before `VP8L`
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC9 — an LBox that differs from the chunk length is an error**
  *(stricter than the oracle: `c2patool` → `Valid`)*
  - Given the fixture with the LBox inside the box changed from 100,635 to
    100,636, chunk length unchanged
  - When the extractor runs
  - Then it throws `ContainerException` naming both values, and returns no
    bytes

- **AC10 — a chunk length that is off by one is an error** *(stricter than
  the oracle: `c2patool` → `Valid`; the pad byte becomes data and the
  JUMBF parser tolerates it)*
  - Given the fixture with the `C2PA` length field changed from 100,635 to
    100,636, data untouched
  - When the extractor runs
  - Then it throws `ContainerException` — the LBox (100,635) no longer
    equals the chunk length (100,636), and the message names both — and
    returns no bytes

- **AC11 — a `C2PA` shorter than a box header is an error** *(oracle:
  `c2patool` → `unexpected end of file` for 4 bytes, `No claim found` for
  0; as SPEC-002 AC11)*
  - Given the fixture with its `C2PA` replaced by one of 4 bytes, and
    separately by one of 0 bytes
  - When the extractor runs
  - Then, in both cases, it throws `ContainerException` naming the chunk
    length and the 8-byte minimum, and returns no bytes

- **AC12 — a pad byte that is missing or not zero is an error** *(decided
  2026-09-20; stricter than the oracle: `c2patool` extracts both and M4
  rejects, because the pad byte lies inside the hashed range)*
  - Given the fixture without the pad byte after its odd-length `C2PA`
    chunk (header size adjusted, so AC5 does not fire); and separately with
    the pad byte `FF` instead of `00`
  - When the extractor runs
  - Then it throws `ContainerException` naming the offset where the pad
    byte was expected (100,955) and, in the second case, the byte found,
    and returns no bytes

- **AC13 — an odd-length chunk before `C2PA` is skipped correctly**
  *(oracle: `c2patool` extracts; a reader that forgets the pad reads the
  `C2PA` header one byte off)*
  - Given the fixture with an unknown 3-byte chunk `XXXX` (plus its pad)
    inserted before `C2PA`
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC14 — the limit is enforced before memory is spent**
  - Given an extractor constructed with a maximum chunk length of 1,000
    bytes
  - When it runs on the fixture (`C2PA` length 100,635)
  - Then it throws `ContainerException` naming the limit and the length, and
    the chunk's data is never read (the stream stands at or before offset
    320, the end of the `C2PA` chunk header)

- **AC15 — the default limit is stated and sufficient for the fixture**
  - Given an extractor constructed with no arguments
  - When it runs on the fixture
  - Then it succeeds, and its limit is readable as the value in the API
    sketch

- **AC16 — the header size is checked against the file before the walk**
  - Given the fixture with the header size set to 304 as if `C2PA` were
    absent (one of AC5's cases)
  - When the extractor runs
  - Then it throws before reading any chunk header: the stream stands at
    or before offset 12 after the exception

## References

- Specification: C2PA 2.4 §A.3 "Embedding manifests into WebP"; the RIFF
  container (Microsoft/IBM, 1991: chunk frame, little-endian lengths, pad
  byte to an even boundary, pad value zero) and Google's WebP Container
  Specification (`RIFF` header, form type `WEBP`, unknown chunks to be
  skipped) — read, freely available.
- Oracle: `c2patool 0.27.22`; `tests/Fixtures/fixture-signed.webp`; the
  store's SHA-256 measured with a probe in step 06, with LBox equal to the
  chunk length and the box a `jumb` with a `c2pa` description; c2patool's
  behaviour on the sixteen variants under `tests/Fixtures/webp/`, each
  recorded in that directory's README; c2pa-rs
  `sdk/src/asset_handlers/riff_io.rs` on `main`, read 2026-09-20, for why.
- Reasoned: that the pad byte must be zero (the RIFF specification;
  c2patool does not check it); the default limit (as SPEC-001 and
  SPEC-002).

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;

declare(strict_types=1);

final readonly class WebpManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = 16 * 1024 * 1024;   // as SPEC-002

    public function __construct(
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
    ) {}

    /**
     * @param  resource  $stream  a readable, seekable stream positioned at 0
     * @return ManifestStoreBytes|null  null when the WebP has no C2PA chunk
     *
     * @throws ContainerException on every malformed case
     */
    public function extract($stream): ?ManifestStoreBytes;
}
```

Reads the twelve-byte header, looks up the file length with one seek to
the end and compares it with the header's size (AC5, AC16), then walks
the chunks to that end: eight bytes of header each, `fseek` over the data
of every chunk but `C2PA`, one byte read and checked after every
odd-length chunk (AC12). For `C2PA`: the limit (AC14), the minimum (AC11),
the overrun (AC6), the LBox from the first four bytes (AC9, AC10), then the
rest of the data, then the pad byte. Keeps walking to see a second `C2PA`
(AC7). Never calls `file_get_contents`; never issues a zero-length read.

## Open questions

- **The shared stream reader** (`readExactly`, `skip`, `tell`, now to be
  copied a third time). Proposal: implement this spec with the copy, then
  one step that merges the three and harmonises the JPEG probe. Non-blocker
  for this spec; a blocker for nothing.
- Resolved before approval: `pad-missing.webp` as generated has the
  header size recomputed for the shorter file (measured: 100,947 = file −
  8), so AC5 does not fire on it and AC12 is the criterion it exercises.

## Amendments

1. **2026-09-21, defined in SPEC-012 and approved with it** — `ManifestStoreBytes` gains `public array $ranges`, the byte ranges of the file the store and its container framing occupy, one per piece, contiguous pieces merged: for WebP the `C2PA` chunk from its FourCC through its data (`8 + strlen(store)`), the pad byte excluded — it is hashed, measured in step 23 — `[312, 100643]` on the fixture. No criterion of this spec changed; the bytes are as they were.

2. **2026-09-22, step 67b, defined in SPEC-024 and approved with it** —
   the default bound on the manifest store falls from **64 MiB to 16 MiB**,
   and a store that fits the bound but not the host's remaining memory is
   refused before it is read. Step 66 measured why: a 63 MiB store — inside
   this criterion's own limit — needs 132 MB and ends a 128 MB host with a
   PHP fatal error instead of returning `Invalid`, which cannot be caught
   and leaves the caller no report at all. Measured beside it: across 212
   corpus stores the median is 45 kB, the 90th percentile 241 kB and the
   largest ever met 3.36 MB, so the old figure was nineteen times anything
   real. A store at the new bound peaks at 38 MB, which a 64 MB host
   survives. AC15's literal changes with it.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC1: extracts the store from the fixture, byte-exact, without the pad byte / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract(), readPad() |
| AC2 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC2: a WebP without C2PA yields null, not an error / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (`$store === null`) |
| AC3 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC3: a stream that does not start with RIFF is an error / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (RIFF check), hex() |
| AC4 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC4: a RIFF file whose form type is not WEBP is an error naming both / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (form type check), printable() |
| AC5 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC5: a header size +1 is an error naming the header size and the file length; AC5: a header size that excludes the C2PA chunk is an error, not "no store"; AC5: a file truncated inside the C2PA chunk is an error naming the header size and the file length; AC5: a file truncated between chunks is an error naming the header size and the file length / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (size check), fileEnd() |
| AC6 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC6: a chunk that overruns the file is an error naming the chunk offset and its declared length / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (overrun check) |
| AC7 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC7: two C2PA chunks are an error naming both offsets / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (`$storeOffset !== null`) |
| AC8 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC8: a C2PA before the image data still yields the same store / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract(), skip() |
| AC9 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC9: an LBox that differs from the chunk length is an error naming both values / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (LBox check) |
| AC10 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC10: a chunk length that is off by one is an error naming LBox and the chunk length / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (LBox check) |
| AC11 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC11: a C2PA of 4 bytes is an error naming the length and the 8-byte minimum; AC11: an empty C2PA is an error, not "no store" / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (`BOX_HEADER_LENGTH` check) |
| AC12 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC12: a missing pad byte is an error naming the offset where it was expected; AC12: a pad byte that is not zero is an error naming the offset and the byte / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: readPad() |
| AC13 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC13: an odd-length chunk before C2PA is skipped correctly, pad included / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: skip(), readPad() |
| AC14 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC14: a chunk length above the limit is an error before the data is read / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (`$maxChunkLength` check before the LBox read) |
| AC15 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC15: the default limit is 16 MiB / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: DEFAULT_MAX_CHUNK_LENGTH, __construct() |
| AC16 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC16: the header size is checked against the file before any chunk header is read / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: extract() (size check before the loop, stream repositioned first) |
