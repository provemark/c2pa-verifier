# SPEC-055: WAV RIFF `C2PA` → manifest store bytes, verified

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-05                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The first format after M8. Today a signed WAV is `Invalid` with
`general.error` (*unsupported file type*). That fails closed, but the
verifier has nothing to say about a file that `c2patool` calls `Valid` or
`Trusted`.

C2PA 2.4 §A.3.7, "Embedding manifests into RIFF-based assets", treats WAV,
BWF, AVI and WebP as one family. The store is the data of a chunk with
identifier `C2PA`, and *"this C2PA chunk shall appear as the last sub-chunk
of the first RIFF header chunk"*. Step 203 measured that `c2patool` binds a
WAV with `c2pa.hash.data`, and the store's exclusion is the chunk from its
identifier through its data, with the pad byte hashed. That is exactly
WebP's layout (SPEC-003, and its amendment 1). Step 204
(`notes/step-204-wav-measured.md`) signed `tests/Fixtures/fixture-signed.wav`
and measured 21 malformed variants against `c2patool` 0.27.22 and 0.28.1.
In all eighteen cases it shares with WebP, WAV behaves like WebP.

So this spec adds no new kind of check. It adds a container, `WAVE`,
read under SPEC-003's rules criterion by criterion, and routes it into the
verifier, whose signature, hash-binding, trust and timestamp checks already
apply. Every place where this verifier is stricter than `c2patool` is
SPEC-003's, written next to its criterion here as well.

**Decided by Maurice van Loon, 2026-10-05 (step 204):** a `C2PA` chunk
that is not the last is extracted, and the data hash judges it, as SPEC-003
AC8 does for WebP. This is not stricter than the oracle on purpose: neither
`c2patool` version checks the position, every move that can be built is
caught by the hash, and refusing a signer's own placement would protect
against nothing (ADR-0005).

## Scope

**In scope**

- Reading a WAV from a stream with SPEC-003's walk, form type `WAVE`
  instead of `WEBP`: the header size against the file length, chunk by
  chunk at the top level, every chunk's data except `C2PA` skipped, the pad
  byte after every odd-length chunk read and checked to be `00`, one `C2PA`
  at most, LBox equal to the chunk length, the bounds of SPEC-024.
- The result as `ManifestStoreBytes`, its range the chunk from its
  identifier through its data (the pad byte excluded from the range, so it
  is hashed), as SPEC-003 amendment 1.
- Detection: a stream that starts with `RIFF` and has form type `WAVE` is
  `wav`, and the report's `format` says `wav`.
- The verifier's existing checks on the result, unchanged.
- One file that changes verdict because of this (AC17).

**Out of scope** (each needs its own spec before it may be built)

- AVI (form `AVI `) and every other RIFF form: still `unknown`. AVI is the
  next format, with its own measurement (a second `RIFF` chunk, `AVIX`,
  is normal there).
- **RF64**, the 64-bit WAV for files over 4 GB: both `c2patool` versions
  refuse it (*expected "RIFF", got "RF64"*). It stays `unknown` here (AC14).
- A `C2PA` chunk nested inside a `LIST` chunk: not looked for, as in
  `c2patool` (AC15).
- Whether the WAV is a sound file: `fmt ` and `data` are not required or
  read. `c2patool` does not check them either, and they do not affect the
  hash binding.
- BWF's `bext` chunk and other WAV metadata: they are chunks to skip.
- Correcting what `docs/comparison.md` and `tests/Fixtures/webp/README.md`
  say about `length-differs.webp` now that 0.28.1 rejects it too (step
  204): a separate step.
- A release. Whether this becomes 0.3.0 is decided when it is done.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-055')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed. Variants are under
`tests/Fixtures/wav/`, made by `bin/make-wav-variants.php` (step 204).

- **AC1 — the fixture yields the store, byte-exact, without the pad byte**
  - Given `tests/Fixtures/fixture-signed.wav`
  - When the WAV extractor runs on it
  - Then it returns 13,463 bytes whose SHA-256 is
    `622fdd9b14027f9fdf915890c4f004b2ca0fe7d5eeb15db05fe67df85939b318` and
    whose first eight bytes are `00 00 34 97 6a 75 6d 62` (LBox, `jumb`),
    with one range `[16078, 13471]`

- **AC2 — no `C2PA` is an outcome, not an error**
  - Given `tests/Fixtures/fixture-unsigned.wav`
  - When the extractor runs
  - Then it returns `null` and throws nothing

- **AC3 — a RIFF file that is not a WAV is an error in the extractor**
  *(as SPEC-003 AC4)*
  - Given `riff-form-xxxx.wav`
  - When the WAV extractor runs on it directly
  - Then it throws `ContainerException` naming `WAVE` and `XXXX`, before
    reading any chunk

- **AC4 — a header size that disagrees with the file is an error**
  *(required: error / malformed input; as SPEC-003 AC5 and AC16. Oracle:
  `riff-size-plus-one`, `trailing-bytes` and `second-riff` → both versions
  extract and the data hash fails; `riff-size-excludes-c2pa` → `No claim
  found`; the two truncations → a parse error)*
  - Given `riff-size-plus-one.wav`, `riff-size-excludes-c2pa.wav`,
    `truncated-in-c2pa.wav`, `truncated-between-chunks.wav`,
    `trailing-bytes.wav` and `second-riff.wav`
  - When the extractor runs
  - Then each throws `ContainerException` naming the header's size and the
    file's length, before reading any chunk header

- **AC5 — a chunk that overruns the file is an error** *(as SPEC-003 AC6;
  oracle: both versions, `RIFF chunk declared size exceeds file size`)*
  - Given `chunk-overruns-file.wav`
  - When the extractor runs
  - Then it throws `ContainerException` naming the chunk offset (16,078),
    its declared length and where the file ends, before reading its data

- **AC6 — two `C2PA` chunks are an error** *(as SPEC-003 AC7; stricter
  than the oracle: both versions take the first, and the hash fails)*
  - Given `two-c2pa.wav`
  - When the extractor runs
  - Then it throws `ContainerException` naming both offsets (16,078 and
    29,550), and returns no bytes

- **AC7 — a `C2PA` that is not the last chunk is still extracted**
  *(as SPEC-003 AC8; decided 2026-10-05, see Problem; oracle: both
  versions extract, then `assertion.dataHash.mismatch`)*
  - Given `c2pa-before-data.wav`, `c2pa-first.wav` and
    `chunk-after-c2pa.wav`
  - When the extractor runs
  - Then each returns bytes with the same SHA-256 as AC1; and when each is
    verified, the report is `Invalid` with `claimSignature.validated` and
    `assertion.dataHash.mismatch`

- **AC8 — an LBox that differs from the chunk length is an error** *(as
  SPEC-003 AC9; stricter than the oracle: both versions → `Valid`)*
  - Given `lbox-differs.wav`
  - When the extractor runs
  - Then it throws `ContainerException` naming both values (13,464 and
    13,463)

- **AC9 — a chunk length off by one is an error** *(as SPEC-003 AC10;
  0.27.22 → `Valid`, 0.28.1 → `Invalid`, its new location check)*
  - Given `length-differs.wav`
  - When the extractor runs
  - Then it throws `ContainerException` naming LBox (13,463) and the chunk
    length (13,464)

- **AC10 — a `C2PA` shorter than a box header is an error** *(as SPEC-003
  AC11; oracle: `unexpected end of file` for 4 bytes, `No claim found`
  for 0)*
  - Given `c2pa-too-short.wav` and `c2pa-empty.wav`
  - When the extractor runs
  - Then each throws `ContainerException` naming the length and the 8-byte
    minimum

- **AC11 — a pad byte that is missing or not zero is an error** *(as
  SPEC-003 AC12; stricter than the oracle: both versions extract and the
  hash fails, because the pad byte is hashed)*
  - Given `pad-missing.wav` and `pad-nonzero.wav`
  - When the extractor runs
  - Then each throws `ContainerException` naming the offset where the pad
    byte was expected (29,549) and, for the second, the byte found (`FF`)

- **AC12 — an odd-length chunk before `C2PA` is skipped correctly** *(as
  SPEC-003 AC13)*
  - Given `odd-chunk-before.wav`
  - When the extractor runs
  - Then it returns bytes with the same SHA-256 as AC1

- **AC13 — the bounds apply before memory is spent** *(as SPEC-003 AC14
  and AC15, SPEC-024)*
  - Given an extractor constructed with a maximum chunk length of 1,000
    bytes, and separately one constructed with no arguments
  - When each runs on the fixture
  - Then the first throws `ContainerException` naming the limit and 13,463,
    with the stream at or before offset 16,086 (the end of the `C2PA`
    chunk header); the second succeeds, and its limit is 16 MiB

- **AC14 — detection: `WAVE` is `wav`, and nothing else is guessed**
  - Given the fixture; `riff-form-xxxx.wav`; `rf64.wav`; and
    `tests/Fixtures/fixture-signed.webp`
  - When the format is detected and the file verified
  - Then the fixture is `wav`; the WebP is still `webp`; `riff-form-xxxx`
    and `rf64` are `unknown`, `Invalid` with one `general.error` whose
    explanation shows the bytes found and names WAV among the formats
    read, and nothing is read past the magic bytes (as SPEC-013 AC6)

- **AC15 — a `C2PA` inside a `LIST` chunk is not found** *(oracle: both
  versions, `No claim found`)*
  - Given `c2pa-in-list.wav`
  - When the extractor runs
  - Then it returns `null`; verified, the report has `format` `wav` and
    `hasManifest` false, with no failure status

- **AC16 — the signed fixture verifies as `c2patool` says**
  - Given `tests/Fixtures/fixture-signed.wav`
  - When verified without trust settings, and again with the shared trust
    settings
  - Then the first is `Valid` with `claimSignature.validated`,
    `assertion.dataHash.match` and `signingCredential.untrusted`, the
    second `Trusted`; both reports' status codes equal `c2patool` 0.27.22's,
    recorded under `tests/Fixtures/c2patool/wav/` and held by the drift
    alarm; and the fixture with one byte of its `data` chunk flipped is
    `Invalid` with `assertion.dataHash.mismatch`

- **AC17 — the one existing file that changes verdict**
  - Given `tests/Fixtures/webp/riff-not-webp.webp` (a signed WebP with its
    form type changed to `WAVE`; `unknown` today)
  - When verified
  - Then it is `format` `wav`, `Invalid`, with `claimSignature.validated`
    and `assertion.dataHash.mismatch`: equal to `c2patool` 0.27.22's answer
    recorded in `tests/Fixtures/webp/README.md`. The WebP extractor's own
    test of it (SPEC-003 AC4) is unchanged

## References

- Specification: C2PA 2.4 §A.3.7 "Embedding manifests into RIFF-based
  assets" (read 2026-10-05 from the published HTML); §18.7.3.5 for the
  RIFF tree (not used: no box hash); the RIFF container (pad byte, little
  endian) as in SPEC-003; EBU Tech 3306 for RF64 (only to recognise it).
- Oracle: `c2patool` 0.27.22 and 0.28.1; `tests/Fixtures/fixture-signed.wav`
  and the 21 variants under `tests/Fixtures/wav/`, every answer recorded in
  that directory's README (step 204); the store's SHA-256 and the range
  computed with a probe in step 205 (LBox equals the chunk length; the
  range equals the exclusion `c2patool` wrote, step 203).
- Reasoned: that WAV can follow SPEC-003 because all eighteen shared cases
  measured the same; that the position rule protects against nothing
  (Maurice's decision above).

## API sketch

The RIFF walk is written once and given the form type. A refactor step
before the tests moves `WebpManifestStoreExtractor`'s walk into
`RiffManifestStoreExtractor` without changing any behaviour (SPEC-003's
tests unchanged and green, corpus identical); this spec then adds the
`WAVE` instance.

```php
// namespace Provemark\C2paVerifier\Container;

declare(strict_types=1);

/** @internal SPEC-003, SPEC-055: the walk shared by every RIFF form this verifier reads. */
final readonly class RiffManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = 16 * 1024 * 1024;   // SPEC-024

    /** @param 'WEBP'|'WAVE' $form  @param string $name  for messages: 'WebP', 'WAV' */
    public function __construct(
        private string $form,
        private string $name,
        public int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH,
        private MemoryBudget $budget = new MemoryBudget,
    ) {}

    /** @param resource $stream  @throws ContainerException */
    public function extract($stream): ?ManifestStoreBytes;
}

/** @internal SPEC-055 */
final readonly class WavManifestStoreExtractor
{
    public const DEFAULT_MAX_CHUNK_LENGTH = RiffManifestStoreExtractor::DEFAULT_MAX_CHUNK_LENGTH;

    public function __construct(int $maxChunkLength = self::DEFAULT_MAX_CHUNK_LENGTH, MemoryBudget $budget = new MemoryBudget);

    /** @param resource $stream  @throws ContainerException */
    public function extract($stream): ?ManifestStoreBytes;   // new RiffManifestStoreExtractor('WAVE', 'WAV', …)
}
```

`WebpManifestStoreExtractor` keeps its name and signature and delegates the
same way, so SPEC-003's tests prove the refactor. `FormatDetector` returns
`'wav'` for `RIFF` + `WAVE`; `Verifier` gains a fifth match arm.

## Open questions

1. **Amendments this spec forces when it is implemented** (named now so
   they are not surprises): SPEC-013 (the `format` values and the
   unknown-format message, AC6's list of formats read), SPEC-024 AC1 ("a
   JPEG, PNG, WebP or ISOBMFF file" gains WAV), SPEC-025 (two new
   `@internal` classes recorded, or its AC2 fails), and SPEC-003's
   Traceability (the walk's new file). Non-blocker.
2. **Public text** that names the formats: README (two places),
   `docs/comparison.md` (the formats row), CHANGELOG `Unreleased`. Updated
   in the build step. Non-blocker.
3. **The refactor as its own step.** Proposal: step 206 is the refactor
   alone, then 207 the red tests, then 208 the build. Non-blocker.

## Amendments

1. **2026-10-05, step 208, found by the implementation** — open question
   1 expected a SPEC-025 amendment for the two new classes. None is
   needed: `RiffManifestStoreExtractor` and `WavManifestStoreExtractor`
   carry `@internal`, which is what SPEC-025 AC2 asks, and the recorded
   contract surface is unchanged (`bin/api-check.php`: 85 public classes,
   11 in the contract, 74 internal). The amendments that were needed are
   SPEC-013 amendment 15 and SPEC-024 amendment 2. SPEC-003's
   Traceability moved in step 206.
   Awaiting confirmation.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC1: extracts the store from the fixture, byte-exact, without the pad byte, with its range / SPEC-055 | src/Container/WavManifestStoreExtractor.php :: extract(); src/Container/RiffManifestStoreExtractor.php :: extract(), readPad() |
| AC2 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC2: a WAV without C2PA yields null, not an error / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (`$store === null`) |
| AC3 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC3: a RIFF file whose form type is not WAVE is an error naming both, before any chunk / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (form type check); src/Container/WavManifestStoreExtractor.php :: __construct() (`WAVE`, `WAV`) |
| AC4 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC4: a header size that disagrees with the file is an error naming both, before any chunk header (six datasets) / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (size check before the loop) |
| AC5 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC5: a chunk that overruns the file is an error naming the chunk offset and its declared length / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (overrun check) |
| AC6 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC6: two C2PA chunks are an error naming both offsets / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (`$storeOffset !== null`) |
| AC7 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC7: a C2PA that is not the last chunk still yields the same store; tests/Unit/Verifier/WavTest.php :: AC7: a C2PA that is not the last chunk is judged by the data hash / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (no position check); src/Hash/DataHashCheck.php (unchanged) |
| AC8 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC8: an LBox that differs from the chunk length is an error naming both values / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (LBox check) |
| AC9 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC9: a chunk length that is off by one is an error naming LBox and the chunk length / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (LBox check) |
| AC10 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC10: a C2PA shorter than a box header is an error naming the length and the 8-byte minimum / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (`BOX_HEADER_LENGTH` check) |
| AC11 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC11: a missing pad byte is an error naming the offset where it was expected; AC11: a pad byte that is not zero is an error naming the offset and the byte / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: readPad() |
| AC12 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC12: an odd-length chunk before C2PA is skipped correctly, pad included / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract(), readPad() |
| AC13 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC13: a chunk length above the limit is an error before the data is read; AC13: the default limit is 16 MiB, and the fixture fits it / SPEC-055 | src/Container/WavManifestStoreExtractor.php :: DEFAULT_MAX_CHUNK_LENGTH, __construct(); src/Container/RiffManifestStoreExtractor.php :: extract() (limit before the LBox read) |
| AC14 | tests/Unit/Verifier/WavTest.php :: AC14: RIFF with form WAVE is detected as wav, and nothing else is guessed; AC14: another RIFF form and RF64 stay unknown, an error naming the bytes, nothing read past them / SPEC-055 | src/Container/FormatDetector.php :: detect() (`WAVE`); src/Verifier/Verifier.php :: verify() (unknown-format message) |
| AC15 | tests/Unit/Container/WavManifestStoreExtractorTest.php :: AC15: a C2PA inside the LIST chunk is not looked for; tests/Unit/Verifier/WavTest.php :: AC15: a C2PA inside the LIST chunk is no manifest, and no failure / SPEC-055 | src/Container/RiffManifestStoreExtractor.php :: extract() (top-level walk only) |
| AC16 | tests/Unit/Verifier/WavTest.php :: AC16: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say, without and with trust settings (four datasets); AC16: one byte of the audio data flipped is assertion.dataHash.mismatch / SPEC-055 | src/Verifier/Verifier.php :: __construct() (`$wav`), verify() (the `wav` arm) |
| AC17 | tests/Unit/Verifier/WavTest.php :: AC17: the WebP whose form type says WAVE is read as a WAV and fails its data hash, as c2patool says / SPEC-055 | src/Container/FormatDetector.php :: detect(); src/Verifier/Verifier.php :: verify() |
