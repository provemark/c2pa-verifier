# SPEC-043: Hostile input ends in a report or a refusal, never a crash

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-25                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The security review of 2026-09-25 found eight ways to make this verifier
crash, hang or print something other than its report, all without a key.
Steps 153 and 154 closed two of them: a missing DER element, and the cost of
converting a long INTEGER. This spec holds the remaining six. They share one
rule: **whatever the input, the verifier ends with a report (exit 0 or 1) or
a refusal naming its reason (exit 2), within bounded memory and time, and it
prints nothing but that.** A fatal error, a PHP warning on standard output or
a network request breaks that rule. Each of these is a denial of service or a
corrupted report for the caller.

They are kept in one spec at the maintainer's request (2026-09-25), to move
faster. Each keeps its own criterion, its own measurement and its own test
seen red.

Measured on 2026-09-25, before any change:

1. **CBOR memory.** `CborDecoder` bounds each container to 65,536 items but
   not the total. The review's claims, 1 MB and 4 MB of nested one-element
   arrays, took 152 MiB and 514 MiB. Under PHP's default `memory_limit` of
   128 MB, the 4 MB file is a fatal error: `Allowed memory size ...
   exhausted`, exit 255, no report. Decoded claims and assertions stay in
   the `ManifestStore`, so a bound per decode is not enough: many assertions,
   each under it, add up.
2. **ISOBMFF reads.** `merklePayload()` reads a C2PA `merkle` box whole,
   whatever size it declares: 200 MB declared was a fatal error under 128 MB,
   and 233 MiB under 1 GB. Both `merklePayload()` and `readStore()` read the
   box's purpose string one byte at a time until a NUL, with no bound: a
   20 MB box without one took 3.9 s, and the cost grows with the file.
3. **A media type that is not UTF-8.** The embedded-file description box
   (`bfdb`) of a thumbnail is read as bytes and put into the report. One
   byte 0xFF there made `toJson()` throw `JsonException`: exit 255, no
   report. Both `c2patool` versions give the file's verdict with
   `"format": ""`.
4. **OpenSSL warnings.** Some `openssl_*` calls in `Certificate`, `RsaPss`
   and `CoseSign1` are not silenced. A NUL inside the signer's UTCTime makes
   `openssl_x509_parse()` warn "Illegal length in timestamp". PHP's CLI
   prints that warning on standard output, ahead of the JSON, so the output
   is not JSON. Both `c2patool` versions refuse that certificate with
   *"COSE error parsing certificate"* and print no report.
5. **A stream that cannot seek.** A FIFO, or a pipe as `/dev/stdin`, made
   `FormatDetector` throw `InvalidArgumentException`, uncaught: exit 255,
   with a warning on standard output. A file redirected with `<` works; that
   is seekable.
6. **Stream wrappers.** `bin/c2pa-verify` passes its argument to `fopen()`
   as it is. `data:image/jpeg;base64,...` verified an inline image
   (`Valid`). `php://memory` was opened. With `allow_url_fopen` on, the
   default, `http://` would make a network request in the verification
   path, which this project never does (reasoned, not run). A real file
   named `data:,hello` could not be opened: PHP read the text "hello"
   instead. The same holds for `--settings`.

## Scope

**In scope**

- A total CBOR item budget: one shared by every claim and assertion of a
  store, and one per decode for COSE and the BMFF Merkle proof.
- A bound on the ISOBMFF purpose string, and the same size and memory bounds
  on a `merkle` box as on the store.
- An embedded-file media type that is not UTF-8 reads as `""`.
- Every `openssl_*` call silenced. A certificate whose parse raises a
  warning is unreadable.
- The command refuses a stream that cannot seek.
- The command opens only local files, for the input and for `--settings`.

**Out of scope** (each needs its own spec before it may be built)

- Reading from a pipe by copying it to a temporary file. Refusing is the
  bounded answer; a copy would need its own bound on disk.
- Changing what the library's `Verifier::verify()` accepts: it still needs a
  seekable stream, and says so with `InvalidArgumentException`. Only the
  command's handling changes.

## Behavior

- **AC1 — the CBOR item total is bounded** *(required: malformed input)*
  - Given a CBOR array of two arrays of 65,536 items (131,074 items, each
    container within its own limit); and `fixture-signed.png` with two
    unreferenced assertions of 40,000 items each added to its assertion store
  - When the first is decoded, and the second is verified
  - Then the decode is refused with `CborException` naming the total limit of
    65,536 items. The file is `Invalid` with a status naming that limit,
    although neither assertion alone exceeds it. The largest total measured
    in any real file or fixture is 5,285 items (475 files).

- **AC2 — an ISOBMFF purpose and a `merkle` box are bounded** *(required: malformed input)*
  - Given a C2PA `uuid` box of 20 MB whose purpose has no NUL; and a fragment
    whose `merkle` box declares 200 MB (a sparse file)
  - When the file is verified, and the fragment is read
  - Then the first is refused as an unterminated purpose after at most 64
    bytes, in well under a second. The second is refused before its data is
    read, naming the box limit, with less than 16 MB of memory used.

- **AC3 — a media type that is not UTF-8 reads as empty** *(required: malformed input)*
  - Given `hostile/mediatype-not-utf8.jpg`
  - When it is verified with the test trust settings and the report is
    rendered
  - Then `toJson()` succeeds, the thumbnail's `format` is `""`, and the state
    and failure codes equal both `c2patool` versions'.

- **AC4 — no OpenSSL warning escapes; a certificate that warns is unreadable** *(required: malformed input)*
  - Given `hostile/certificate-time-nul.jpg`
  - When it is verified with every PHP warning turned into an exception
  - Then nothing is raised, and the report is `Invalid` with
    `signingCredential.invalid` naming the warning. Both `c2patool` versions
    refuse the certificate too.

- **AC5 — a stream that cannot seek is refused** *(required: error path)*
  - Given `bin/c2pa-verify /dev/stdin` with a signed file piped in
  - When the command runs
  - Then it exits 2, standard output is empty, and standard error says the
    input cannot seek and must be a file.

- **AC6 — only local files are opened** *(required: error path)*
  - Given the arguments `data:image/jpeg;base64,<a signed JPEG>`,
    `php://memory`, `--settings data:,{}`, and a real file named
    `data:,hello` (a copy of a signed fixture)
  - When the command runs on each
  - Then the first three exit 2 with *No such file*, and the real file is
    verified: exit 0, and the report of the copied fixture.

## References

- Specification: RFC 8949 §5.1 (a decoder's limits are the application's);
  ISO/IEC 14496-12 §4.2 (box sizes); C2PA 2.4 §14.6 and §A.5 (the C2PA
  `uuid` box, its purpose and its `merkle` data).
- Oracle: `c2patool` 0.27.22 and 0.28.0, `--settings
  tests/Fixtures/trust/full.settings.json`, on the two files of
  `tests/Fixtures/hostile/`, answers recorded in
  `tests/Fixtures/c2patool/hostile/`.
- Measured: the review's probes, rerun this day; the CBOR item totals of
  282,702 decodes over every fixture and the 78 current-writer files of
  step 141.
- Reasoned: that `http://` would fetch. It follows from `fopen()` and
  `allow_url_fopen`, and was not run, so that no request left this machine.

## API sketch

```php
// Provemark\C2paVerifier\Cbor
final class CborBudget            // mutable on purpose: shared by several decodes
{
    public const DEFAULT_ITEMS = 65536;
    public function __construct(int $items = self::DEFAULT_ITEMS) {}
    /** @throws CborException when the budget is spent */
    public function take(int $offset): void {}
}

// CborDecoder::decode(string $bytes, ?CborBudget $budget = null): a fresh budget per decode when null
// Manifest::read(Superbox $box, ?CborBudget $budget = null); ManifestStore::fromTree() passes one per store
// IsobmffManifestStoreExtractor: MAX_PURPOSE_LENGTH = 64
// OpenSsl::quiet(callable $call, bool $drain = true)
```

## Open questions

- None blocking. The budget of 65,536 items is twelve times the largest
  measured, as the per-container limit already is.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/HostileInputTest.php :: AC1 / SPEC-043; tests/Unit/Cbor/CborDecoderTest.php :: AC6 / SPEC-006 (a wider total budget, so it still proves the per-container bound) | src/Cbor/CborBudget.php; src/Cbor/CborDecoder.php (`decode()`, `item()`); src/Manifest/Manifest.php (`read()`); src/Manifest/ManifestStore.php (`fromTree()`: one budget per store) |
| AC2 | tests/Unit/Verifier/HostileInputTest.php :: AC2 / SPEC-043 | src/Container/IsobmffManifestStoreExtractor.php (`MAX_PURPOSE_LENGTH`, `merklePayload()`, `readStore()`) |
| AC3 | tests/Unit/Verifier/HostileInputTest.php :: AC3 / SPEC-043 | src/Manifest/Manifest.php (`mediaType()`); bin/make-hostile-input-variants.php |
| AC4 | tests/Unit/Verifier/HostileInputTest.php :: AC4 / SPEC-043 | src/Trust/Certificate.php (`withoutWarnings()`, the constructor, `signedBy()`); bin/make-hostile-input-variants.php |
| AC5 | tests/Unit/Verifier/HostileInputTest.php :: AC5 / SPEC-043 | src/Cli/Command.php (`open()`: the seekable check) |
| AC6 | tests/Unit/Verifier/HostileInputTest.php :: AC6 / SPEC-043 | src/Cli/Command.php (`local()`: an absolute path behind `file://`, symlinks not resolved, so AC5 holds on Linux too; `open()`, `read()`) |

Measured 2026-09-25: 6 red (and the three parts a first failure hid, run apart) → 6 green, `composer check` exit 0, 525 tests; 19,788 runs over every signed fixture and settings file, the only change `hostile/certificate-time-nul.jpg` (still `Invalid`, now `signingCredential.invalid`); `php bin/fuzz.php 20260925 60`: 0 faults, the same 34 suspects.
