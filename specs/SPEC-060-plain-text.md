# SPEC-060: plain text — the `C2PATextManifestWrapper` → manifest store bytes, verified (opt-in)

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-07                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §A.8 carries the store of an unstructured text (`text/plain`) as a
`C2PATextManifestWrapper` in the text itself: `U+FEFF`, then one variation
selector per byte (0–15 as `U+FE00`–`U+FE0F`, 16–255 as
`U+E0100`–`U+E01EF`) of the magic `C2PATXT\0`, a version byte (1), a
big-endian 32-bit length, the store and optional padding. A signed text is
`unknown` here today: `Invalid`, `general.error` — no wrong verdict, but no
useful one either.

Step 265 (`notes/step-265-text-measured.md`) measured the only oracle there
is: `c2patool` 0.28.1 built with c2pa-rs's experimental Cargo feature
`unstable_plain_text` (c2pa-rs 0.91.1, `plain_text_io.rs`). Stock
`c2patool` does not read text. The oracle appends the wrapper after the
text, padded to a fixed length, and the data hash has one exclusion: the
wrapper, from the marker to the end of its selector run. It normalises the
text to NFC when it signs, and hashes the raw bytes when it verifies.
Twenty variants (`tests/Fixtures/text/`) were answered.

The binding is `c2pa.hash.data`; SPEC-012's check applies unchanged once a
reader returns the store with the wrapper as its range.

Text is experimental upstream, and Unicode's L2/26-042 objects to the
scheme. This verifier therefore reads text only when the caller asks for
it (open question 1).

## Scope

**In scope**

- A reader, used only when the caller turns text on, for a stream that no
  other format claims: the whole stream must be valid UTF-8, read in
  pieces; every `U+FEFF` followed by a run of selectors is a candidate.
- A candidate whose run decodes to `C2PATXT\0` and version 1 is a wrapper.
  Its length field must fit the run, and the store must be a JUMBF box
  whose LBox equals that length. What follows the store, up to the end of
  the run, is padding and is not judged.
- The result as `ManifestStoreBytes`, its range from the marker to the end
  of the run, as the exclusion `c2patool` writes.
- No normalisation. The data hash is computed over the bytes as they are,
  as the oracle does.
- The verifier route, `format` `text`, `has_manifest` once a wrapper with
  the magic and version 1 was found; a CLI flag that turns text on.

**Out of scope** (each needs its own spec before it may be built)

- NFC normalisation before hashing (`ext-intl`, and the oracle does not do
  it; see open question 5).
- §A.7 (HTML) and §A.9 (structured text: Markdown, source code).
- Text in another encoding than UTF-8 (UTF-16, Latin-1): not text here, as
  in the oracle.
- Recognising text by a file name or a MIME type: the verifier is given a
  stream.
- A release.

## Behavior

Variants are under `tests/Fixtures/text/` (`bin/make-text-variants.php`,
step 265); every answer of the oracle is in that directory's README. "Text
on" means a `Verifier` constructed with the text reader; "text off" is the
default.

- **AC1 — the fixture yields the store, byte-exact, with the wrapper as its range**
  - Given `tests/Fixtures/fixture-signed.txt`, text on
  - Then 3,526 bytes, first bytes a JUMBF box header (`jumb`), SHA-256
    equal to the store decoded in step 265 (the full value in the test),
    one range `[60, 14225]`

- **AC2 — text off is today's answer**
  - Given every file under `tests/Fixtures/text/` and both `.txt` fixtures,
    text off
  - Then each is `unknown`, `hasManifest` false, `Invalid`, one
    `general.error`, as today: turning text on is the only way in

- **AC3 — no wrapper is no manifest**
  - Given `fixture-unsigned.txt`, `text/nfd-emoji-unsigned.txt`,
    `text/no-wrapper.txt`, `text/no-marker.txt`, `text/magic-other.txt`,
    `text/letter-in-run.txt`, text on
  - Then the extractor returns `null`; verified, `format` `text`,
    `hasManifest` false, no failure (*No claim found* in the oracle).
    `letter-in-run`'s run ends at the `x` and no longer holds the magic and
    the length it declares, so it is no wrapper.

- **AC4 — another version is not a wrapper**
  - Given `text/version-2.txt`, text on
  - Then `null`, as AC3 (*No claim found* in the oracle): a version this
    verifier does not know is not read as 1

- **AC5 — two wrappers are an error** *(required: malformed input;
  stricter than the oracle, named)*
  - Given `text/two-wrappers.txt`, text on
  - Then `ContainerException` naming the two offsets; verified, `text`,
    `hasManifest` true, `Invalid`, one `general.error`. (The oracle says
    *No claim found*.) The rule of SPEC-059 AC4 for a second store.

- **AC6 — a wrapper whose store does not fit is an error** *(required:
  malformed input; stricter than the oracle, named)*
  - Given `text/cut-in-store.txt` (the length field longer than the run)
    and `text/length-too-long.txt` (the length field one more than the
    store's LBox), text on
  - Then each is a `ContainerException` naming the declared and the
    available length; verified, `text`, `hasManifest` true, `Invalid`,
    `general.error`. (The oracle says *No claim found* to the first and
    `Valid` to the second, reading the store with a padding byte after it.)

- **AC7 — a candidate that is not version 1 is skipped, as the oracle does**
  - Given `text/bad-then-good.txt` (a wrapper with version `09`, then the
    good one), text on
  - Then the good one is read, and the file verifies `Invalid` with
    `claimSignature.validated` and `assertion.dataHash.mismatch`, as the
    oracle says

- **AC8 — what the hash judges is read and left to the hash**
  - Given `text/crlf.txt`, `text/bom-front.txt`, `text/flip-text.txt`,
    `text/nfd-text.txt`, `text/no-padding.txt`, `text/more-padding.txt`,
    `text/text-after.txt`, `text/wrapper-first.txt`,
    `text/only-wrapper.txt`, text on
  - Then each store is read, and each verifies `Invalid` with
    `claimSignature.validated` and `assertion.dataHash.mismatch`, as the
    oracle says. `nfd-text` is the same text in other bytes: no
    normalisation.

- **AC9 — the range is the wrapper, from the marker to the end of the run**
  - Given the fixture, `text/more-padding.txt` and `text/wrapper-first.txt`
  - Then the ranges are `[60, 14225]`, `[60, 14234]` (three more padding
    selectors, three bytes each) and `[0, 14165]`

- **AC10 — text that is not UTF-8 is not text**
  - Given `text/utf16le.txt`, and a file of valid UTF-8 with one invalid
    byte after the wrapper (built in the test), text on
  - Then each is `unknown`, `Invalid`, `general.error`, as with text off
    (the oracle: *text asset is not valid UTF-8*). An invalid sequence
    split over two of the reader's pieces is found as well (built in the
    test).

- **AC11 — selectors that belong to the text are not a wrapper**
  - Given `text/nfd-emoji-signed.txt` (an emoji's `U+FE0F` and a lone
    `U+FEFF` in the text), text on
  - Then it verifies as AC14 says: the lone mark and the emoji's selector
    are not candidates that count; a text of only lone marks and emoji
    (built in the test) is `null`

- **AC12 — the bounds apply before memory is spent** *(as SPEC-024)*
  - Given a wrapper whose length field declares more than the store bound
    (built in the test, with a small bound), and a run of selectors longer
    than the store bound's encoding (built in the test)
  - Then each is a `ContainerException` naming the bound, before the store
    is held; and a 64 MiB text without a wrapper (built in the test) is
    read with less than 16 MB of memory

- **AC13 — the other formats come first**
  - Given `fixture-signed.png`, `fixture-signed.jpg` and `fixture-signed.gif`,
    text on
  - Then each verifies exactly as with text off: text is only tried for a
    stream no other format claims

- **AC14 — the signed fixtures verify as the oracle says**
  - Given `fixture-signed.txt` and `text/nfd-emoji-signed.txt`, text on,
    without settings and with `trust/full.settings.json`
  - Then `Valid` and `Trusted`, state and sorted codes equal to the
    recordings under `tests/Fixtures/c2patool/text/` (made with the oracle,
    and the version named there); `text/flip-text.txt` is `Invalid` with
    `assertion.dataHash.mismatch`; `text/flip-store.txt` is `Invalid` with
    `claimSignature.mismatch`, as the oracle says

## References

- Specification: C2PA 2.4 §A.8 (read in step 183 and step 265).
- Oracle: `c2patool` 0.28.1 built with `unstable_plain_text` (c2pa-rs
  0.91.1); the two signed fixtures and the 20 variants of step 265, every
  answer in `tests/Fixtures/text/README.md`. Stock `c2patool` 0.27.22 and
  0.28.1: *Unsupported file type*.
- Read: c2pa-rs 0.91.1 `src/asset_handlers/plain_text_io.rs` (candidates,
  exactly one wrapper, padding unchecked, raw-byte hashing).
- Upstream state: c2pa-rs tracking issue #2505 (the feature flag), PR #2732
  (NFC-aware validation, open), Unicode L2/26-042.

## API sketch

```php
/** @internal SPEC-060 */
final readonly class PlainTextManifestStoreExtractor
{
    public const DEFAULT_MAX_STORE_LENGTH = 16 * 1024 * 1024;   // SPEC-024's bound
    public const PIECE = 65536;                                  // read in pieces

    public function __construct(int $maxStoreLength = self::DEFAULT_MAX_STORE_LENGTH, MemoryBudget $budget = new MemoryBudget) {}

    /** @param resource $stream  @return ManifestStoreBytes|null  @throws ContainerException */
    public function extract($stream): ?ManifestStoreBytes;

    /** @param resource $stream  whether the whole stream is valid UTF-8 */
    public function isText($stream): bool;
}
```

`Verifier` gains `?PlainTextManifestStoreExtractor $text = null` as its last
constructor parameter: `null` is text off. The CLI gains `--text`.
`FormatDetector` is unchanged; text is tried after it answers `null`.

## Open questions

1. **Opt-in, or on by default?** Proposal: opt-in, off by default, as
   c2pa-rs keeps it behind `unstable_plain_text`. The scheme may still
   change (PR #2732, L2/26-042), and a verdict format that changes under a
   caller is worse than one the caller chose. Turning it on later is an
   amendment; turning it off later would break callers. **Decided by Maurice van Loon, 2026-10-07: as proposed.**
2. **How text is recognised.** Proposal: with text on, any stream no other
   format claims and that is valid UTF-8 is `text` (AC10, AC13). The oracle
   picks the handler by the `.txt` extension; this verifier has no name.
   The consequence: with text on, an unsigned JSON or SVG file is `text`
   without a manifest, not `unknown`. Neither can yield a wrong `Valid`. **Decided by Maurice van Loon, 2026-10-07: as proposed.**
3. **A length field that does not fit (AC6): refuse, stricter than the
   oracle.** Proposal: yes. The oracle reads `length-too-long` `Valid`
   because its JUMBF reader ignores a byte after the box; here the store's
   LBox must equal the declared length, as for every other format. **Decided by Maurice van Loon, 2026-10-07: as proposed.**
4. **Two wrappers (AC5): refuse, stricter than the oracle.** Proposal: yes,
   as SPEC-059 AC4 and SPEC-003 AC7. The oracle says *No claim found*, so
   the state differs too (`Invalid` here); a text with two manifests is not
   an unsigned text. **Decided by Maurice van Loon, 2026-10-07: as proposed.**
5. **NFC: none, as the oracle.** Proposal: hash the raw bytes. Normalising
   first would say `Valid` where the oracle says `Invalid`, the dangerous
   direction, and needs `ext-intl`, which this project does not allow. If
   c2pa-rs PR #2732 lands, measure again. **Decided by Maurice van Loon, 2026-10-07: as proposed.**
6. **Amendments this forces** (named now): SPEC-013 (the `format` value
   `text`, the constructor parameter), SPEC-019 (the `--text` flag),
   SPEC-024 (the text bound in AC1's list). Proposal: written with the
   build. **Decided by Maurice van Loon, 2026-10-07: as proposed.**

## Amendments

1. **2026-10-07, step 267, approved by Maurice van Loon** — found while
   writing the tests, before any code.

   - **A — ranges as start and length.** AC1 and AC9 wrote each range as
     its start and end; everywhere else in this project (SPEC-059 AC1, the
     `ranges` of `ManifestStoreBytes`) a range is its start and length.
     The values are: AC1 `[60, 14165]`; AC9 `[60, 14165]`, `[60, 14174]`
     and `[0, 14165]`. No behaviour changes.
   - **B — `letter-in-run.txt` belongs to AC6, not AC3.** Its run holds the
     magic, version 1 and a length field of 3,526, and ends after 87 store
     bytes, at the `x`: a wrapper whose store does not fit, as
     `cut-in-store.txt`. Now an error, `hasManifest` true, `general.error`
     (the oracle: *No claim found*; stricter, named, as AC6).
     - **AC3 now** — without `text/letter-in-run.txt`.
     - **AC6 now also** — given `text/letter-in-run.txt`: a
       `ContainerException` naming 3,526 and 87.

   Approved by Maurice van Loon, 2026-10-07 (step 267).
2. **2026-10-07, step 269, approved by Maurice van Loon** — found by a
   review of steps 265–268 (an independent read of the code against
   c2pa-rs's `plain_text_io.rs`, with differential tests; no wrong `Valid`
   found).

   - **A — a run is decoded per piece, not per selector.** One selector at
     a time cost about 0.5 µs (measured: a 4 MiB store 2.2 s; a 16 MiB
     store about 9 s, with the largest padding about 18 s), so a large
     wrapper could outlast a shared host's `max_execution_time` and end in
     a fatal error instead of a report. Now each run is matched in a piece
     with one anchored expression and translated in one call.
     - **AC15 (new)** — given a wrapper with a 16 MiB store and padding of
       the same length (built in the test): the store is returned within
       3 s. And a text of one million lone marks followed by 100,000
       candidates of version 2 (built in the test) is read within 3 s.
   - **B — a second candidate of the magic and version 1 is a second
     wrapper, whole or not.** AC5 named only two whole wrappers. A good
     wrapper followed by a candidate of the magic and version 1 that does
     not fit is two wrappers; such a candidate before a good wrapper is
     AC6's error, the first one met. c2pa-rs reads the good one in both
     cases. Stricter, named.
     - **AC5 now also** — given the fixture with a cut candidate after its
       wrapper (built in the test): the two offsets named, `general.error`.
     - **AC6 now also** — given a cut candidate before the fixture's
       wrapper (built in the test): the error of a store that does not fit.
   - **C — AC12's wording.** The length field's bound applies before the
     store is held; the padding's bound applies after the store and
     while the padding is passed over, which is never held. AC12 said
     "before the store is held" of both.
   - **D — edge cases named in the README and `docs/comparison.md`**: an
     empty file is `text` without a manifest when text is on; a text that
     begins as another format (`GIF89a`, `RIFF…WEBP`, `ID3`, a `ftyp` at
     offset 4) is read as that format and never as text; a store with an
     extended LBox (`1`) is refused, as for every container; a length field
     under 8 is an error.
     - **AC16 (new)** — given an empty stream and the text `GIF89a, a
       word` (built in the test), text on: `text` without a manifest, and
       `gif`.
   - **E — the unknown-format message names text when text is on.** A file
     that is neither another format nor UTF-8 says so.
     - **AC10 now also** — given `text/utf16le.txt`, text on: the message
       contains `UTF-8`; text off, it does not.

   Approved by Maurice van Loon, 2026-10-07 (step 269).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/PlainTextTest.php :: AC1: the fixture yields the store, byte-exact, with the wrapper as its range / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: scan(), wrapper(); src/Container/SelectorReader.php :: nextMarker(), selectors() |
| AC2 | tests/Unit/Verifier/PlainTextTest.php :: AC2: text off is today\ / SPEC-060 | src/Verifier/Verifier.php :: __construct() (`$text` null by default), verify() |
| AC3 | tests/Unit/Verifier/PlainTextTest.php :: AC3: no wrapper is no manifest / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: scan() (a candidate without the magic is text) |
| AC4 | tests/Unit/Verifier/PlainTextTest.php :: AC4: another version is not a wrapper / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: scan() (`VERSION`) |
| AC5 | tests/Unit/Verifier/PlainTextTest.php :: AC5: two wrappers are an error (stricter than the oracle, named); AC5 (amendment 2): a good wrapper followed by a candidate that does not fit is two wrappers / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: scan() (two wrappers) |
| AC6 | tests/Unit/Verifier/PlainTextTest.php :: AC6: a wrapper whose store does not fit is an error (stricter than the oracle, named); AC6 (amendment 2): a candidate that does not fit before a good wrapper is a store that does not fit / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: wrapper() (the run shorter than the length), scan() (the LBox) |
| AC7 | tests/Unit/Verifier/PlainTextTest.php :: AC7: a candidate that is not version 1 is skipped, as the oracle does / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: scan() (`continue` past a candidate of another version) |
| AC8 | tests/Unit/Verifier/PlainTextTest.php :: AC8: what the hash judges is read and left to the hash, as the oracle says / SPEC-060 | src/Hash/DataHashCheck.php (unchanged); src/Container/PlainTextManifestStoreExtractor.php (no normalisation) |
| AC9 | tests/Unit/Verifier/PlainTextTest.php :: AC9: the range is the wrapper, from the marker to the end of the run; AC9 (amendment 2): the run is read the same whatever the piece size / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: wrapper() (the range); src/Container/SelectorReader.php :: skipSelectors(), offset() |
| AC10 | tests/Unit/Verifier/PlainTextTest.php :: AC10: text that is not UTF-8 is not text; AC10: a valid sequence across two pieces is text; AC10 (amendment 2): with text on, the unknown-format message names UTF-8 text / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: isText(), completeLength(); src/Verifier/Verifier.php :: verify() |
| AC11 | tests/Unit/Verifier/PlainTextTest.php :: AC11: selectors that belong to the text are not a wrapper / SPEC-060 | src/Container/SelectorReader.php :: selector() (the two ranges only) |
| AC12 | tests/Unit/Verifier/PlainTextTest.php :: AC12: the bounds apply before memory is spent; AC12: a large text without a wrapper is read in pieces / SPEC-060 | src/Container/PlainTextManifestStoreExtractor.php :: `DEFAULT_MAX_STORE_LENGTH`, `PIECE`, wrapper() (the bound, the budget, the padding); src/Container/SelectorReader.php :: fill() |
| AC13 | tests/Unit/Verifier/PlainTextTest.php :: AC13: the other formats come first / SPEC-060 | src/Verifier/Verifier.php :: verify() (text only after `FormatDetector` answers null) |
| AC14 | tests/Unit/Verifier/PlainTextTest.php :: AC14: the signed fixtures verify as the oracle says; AC14: a changed letter and a flipped signature byte fail as the oracle says / SPEC-060 | src/Verifier/Verifier.php :: verify() (the `text` arm) |
| AC15 (amendment 2) | tests/Unit/Verifier/PlainTextTest.php :: AC15 (amendment 2): a 16 MiB store with as much padding is decoded within 3 s; AC15 (amendment 2): a million lone marks and 100,000 candidates of another version are read within 3 s / SPEC-060 | src/Container/SelectorReader.php :: run() (`SELECTOR`, possessive, `bytesOf()`, `LOW`) |
| AC16 (amendment 2) | tests/Unit/Verifier/PlainTextTest.php :: AC16 (amendment 2): an empty file is text without a manifest; a text that begins as a GIF is a GIF / SPEC-060 | src/Verifier/Verifier.php :: verify() (`FormatDetector` first, then `isText()`) |
