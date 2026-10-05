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
   Confirmed by Maurice van Loon, 2026-09-22 (step 68).

3. **2026-10-05, step 214, proposed (review finding 1–3 of step 213;
   direction decided by Maurice van Loon the same day)** — an unsigned
   WebP with bytes after its RIFF chunk is `Invalid` with
   `has_manifest: true` here, where both `c2patool` versions find no
   claim (measured). The walk is stricter than it needs to be outside the
   `C2PA` chunk.

   The rule, in one line: **strict about the `C2PA` chunk, as lenient as
   `c2patool` about everything else.** Leniency outside the store cannot
   make a changed signed file `Valid`: the data hash covers every byte
   outside the store's exclusion, the bytes after the RIFF chunk included
   (measured in steps 204 and 213: each such change is
   `assertion.dataHash.mismatch` in both `c2patool` versions).

   - **AC5 is split.** A header size that promises **more** than the file
     holds stays an error before any chunk header is read, with the same
     message (`riff-size-plus-one`, `truncated-in-c2pa`,
     `truncated-between-chunks`); AC16 keeps holding for these. A header
     size that promises **less** than the file holds is no longer an error:
     the walk ends where the RIFF chunk ends, and the bytes after it are
     not read here. `riff-size-excludes-c2pa.webp` therefore yields
     `null`, as `c2patool` says (*No claim found*).
   - **AC12 is narrowed to the `C2PA` chunk's own pad byte**, which must
     still be present and zero. After any other odd-length chunk the pad
     byte is skipped and its value not checked, and when such a chunk ends
     exactly where the RIFF chunk ends, a missing pad byte is accepted.
   - **AC17 (new) — bytes after the RIFF chunk are not the container's
     concern.** Given the signed fixture with 128 bytes appended, the
     extractor returns the same store as AC1; given the unsigned fixture
     with the same bytes appended, it returns `null`.
   - **AC18 (new) — the walk says whether it reached a `C2PA` chunk.** A
     `ContainerException` thrown before a `C2PA` chunk header was read says
     so (SPEC-013 amendment 16 uses it).

   Approved by Maurice van Loon, 2026-10-05 (step 215).

4. **2026-10-05, step 218, proposed (second review, findings 1–4 of step
   217; direction decided by Maurice van Loon the same day)** — amendment
   3 stopped short in four places. Measured on the reviewer's files with
   both `c2patool` versions; none yields a wrong `Valid` today.

   - **A header size larger than the file stays a fault**, with the same
     message, but before it is thrown the chunk headers are scanned up to
     the end of the file, without reading any chunk's data, to see whether
     a `C2PA` chunk header is there. `storeReached` says what the scan
     found. A signed file cut short (a partial upload) is then reported as
     a manifest that failed, not as a file without one.
   - **Where the RIFF chunk cannot hold another whole chunk, the walk
     stops**: fewer than 8 bytes left before its end, or a chunk other than
     `C2PA` whose length runs past its end. What remains is not read here
     and is left to the data hash, as `c2patool` leaves it. A `C2PA` chunk
     whose length runs past the end stays a fault (AC6 unchanged).
   - **A header size below 4**, too small for the form type it must hold,
     is a fault before any chunk is read (`storeReached` false). `c2patool`
     finds no claim; this is stricter on purpose: the header contradicts
     itself.
   - **Documented, not changed:** an earlier chunk whose length is changed
     to run to the end of the RIFF chunk, or stray bytes that misalign the
     walk before the `C2PA` chunk, hide the store; the file is reported
     without a manifest, as `c2patool` reports it. That is no new power:
     deleting the chunk hides it as well. It is one more reason why "no
     manifest" never proves that a file had none (step 214).

   - **AC19 (new) — the edges of the leniency.** Built in memory from the
     fixtures: a signed WebP with a 3-byte tail inside the RIFF chunk, and
     with an overrunning chunk after the `C2PA` chunk, yield the store of
     AC1; an unsigned WebP with the same tail, or the same overrunning
     chunk, yields `null`; a signed WebP whose `VP8L` length runs past the
     RIFF end, or with 3 stray bytes before the `C2PA` chunk, yields
     `null`; a header size of 0 is a `ContainerException` with
     `storeReached` false; `riff-size-plus-one` and `truncated-in-c2pa`
     are a `ContainerException` with `storeReached` **true**,
     `truncated-between-chunks` with `storeReached` false.
   - AC18's dataset changes with it: `riff-size-plus-one` now reaches the
     store.

   Approved by Maurice van Loon, 2026-10-05 (step 219).

   **Addendum, 2026-10-05, step 220, approved by Maurice van Loon.** The
   scan above conflicts with AC16's *"before any chunk header is read"*
   (SPEC-055 AC4 said the same for WAV), which the build showed: four tests
   failed on their stream position. AC16 now reads: a header size larger
   than the file is refused **without any chunk's data being read**; only
   chunk headers are scanned, and the stream stands at most at the end of
   the `C2PA` chunk header (offset 320 in the WebP fixture, 16,086 in the
   WAV fixture). The goal is unchanged: no costly read of a broken file.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec. Since step 206 the walk is in
`RiffManifestStoreExtractor.php` (SPEC-004 amendment 2, SPEC-055); the WebP class delegates to it.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC1: extracts the store from the fixture, byte-exact, without the pad byte / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract(), readPad() |
| AC2 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC2: a WebP without C2PA yields null, not an error / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (`$store === null`) |
| AC3 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC3: a stream that does not start with RIFF is an error / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (RIFF check); src/Support/Bytes.php :: hex() |
| AC4 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC4: a RIFF file whose form type is not WEBP is an error naming both / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (form type check); src/Support/Bytes.php :: printable() |
| AC5 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC5: a header size +1 is an error naming the header size and the file length; AC5: a header size that ends before the C2PA chunk yields null, as c2patool finds no claim (amendment 3); AC5: a file truncated inside the C2PA chunk is an error naming the header size and the file length; AC5: a file truncated between chunks is an error naming the header size and the file length / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (size check); src/Container/StreamReader.php :: end() |
| AC6 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC6: a chunk that overruns the file is an error naming the chunk offset and its declared length / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (overrun check) |
| AC7 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC7: two C2PA chunks are an error naming both offsets / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (`$storeOffset !== null`) |
| AC8 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC8: a C2PA before the image data still yields the same store / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract(); src/Container/StreamReader.php :: skip() |
| AC9 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC9: an LBox that differs from the chunk length is an error naming both values / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (LBox check) |
| AC10 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC10: a chunk length that is off by one is an error naming LBox and the chunk length / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (LBox check) |
| AC11 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC11: a C2PA of 4 bytes is an error naming the length and the 8-byte minimum; AC11: an empty C2PA is an error, not "no store" / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (`BOX_HEADER_LENGTH` check) |
| AC12 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC12: a missing pad byte is an error naming the offset where it was expected; AC12: a pad byte that is not zero is an error naming the offset and the byte / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: readPad(); AC12: a pad byte after another chunk is not checked, and may be missing where the RIFF chunk ends (amendment 3) |
| AC13 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC13: an odd-length chunk before C2PA is skipped correctly, pad included / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: readPad(); src/Container/StreamReader.php :: skip() |
| AC14 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC14: a chunk length above the limit is an error before the data is read / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (`$maxChunkLength` check before the LBox read) |
| AC15 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC15: the default limit is 16 MiB / SPEC-003 | src/Container/WebpManifestStoreExtractor.php :: DEFAULT_MAX_CHUNK_LENGTH, __construct() (delegating to RiffManifestStoreExtractor since step 206) |
| AC16 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC16: a header size larger than the file is refused without reading any chunk's data / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (size check before the loop, stream repositioned first) |
| AC17 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC17: bytes after the RIFF chunk are not the container's concern (amendment 3) / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract() (the walk ends where the RIFF chunk ends) |
| AC18 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC18: a fault says whether the walk had reached a C2PA chunk (amendment 3) / SPEC-003 | src/Container/ContainerException.php :: $storeReached; src/Container/RiffManifestStoreExtractor.php :: extract(); AC18: a stream that cannot be measured is a fault before any C2PA chunk (step 217) |
| AC19 | tests/Unit/Container/WebpManifestStoreExtractorTest.php :: AC19: where the RIFF chunk cannot hold another whole chunk, the walk stops (amendment 4); AC19: a header size below 4 is refused before any chunk is read (amendment 4) / SPEC-003 | src/Container/RiffManifestStoreExtractor.php :: extract(), walk(), reachesStore() |
