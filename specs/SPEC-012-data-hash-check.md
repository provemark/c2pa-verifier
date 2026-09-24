# SPEC-012: The data-hash check — `c2pa.hash.data` against the asset, streamed

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-21                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

After SPEC-011 the verifier knows that the claim was signed and that every
assertion is the one the signer saw. It still knows nothing about the
*pixels*: a signed manifest store can sit, byte for byte intact, in a file
whose image data was changed afterwards. The one thing that ties the
store to the asset is the **hard binding** — for JPEG, PNG and WebP the
`c2pa.hash.data` assertion (C2PA 2.4 §18.5): a hash over every byte of the
file except the *exclusions*, the ranges the signer could not know in
advance, chiefly the one that holds the store itself. Step 23 measured
that binding for all four fixtures and thirteen variants: one flipped
pixel bit is `assertion.dataHash.mismatch` under c2patool, the untouched
file `match`. That pair is M4's "done when", and it is this spec.

The check is the first in this verifier that reads the *asset*, not the
store. That brings the brief's performance rule into force: stream,
never `file_get_contents`, and never the head-and-tail probe that gives
false negatives. And it brings the fail-closed rule to a new place: the
exclusion that hides the store from the hash is exactly where an
attacker would hide something else. §15.12.1 says that range "may
contain only the C2PA Manifest Store and any appropriate padding"; step
23 measured what that is for the three formats — the store plus its
container framing, 32/12/8 bytes for the JPEG (two segments), PNG and
WebP fixtures, 12 for the Adobe JPEG (one segment) — so the check can
compare, not assume.

## Scope

**In scope**

- **SPEC-001/002/003 amendment**: `ManifestStoreBytes` gains
  `public array $ranges` — `list<array{start: int, length: int}>`, the
  byte ranges of the *file* the store and its container framing occupy,
  in file order, one per piece: for JPEG each APP11 segment from its
  marker to its last data byte (marker, length, CI, En, Z, and the
  repeated LBox/TBox of pieces after the first, are framing); for PNG the
  `caBX` chunk from its length field to its CRC; for WebP the `C2PA`
  chunk from its FourCC to its last data byte, the pad byte excluded
  (measured, step 23: the pad byte is hashed). Contiguous pieces are one
  range after merging; a gap between pieces (SPEC-001 AC4) leaves two.
  Nothing else in those specs changes; their bytes are as they were.
- `Hash\DataHashCheck::check(Manifest $manifest, $stream, ManifestStoreBytes $store): list<ValidationStatus>`:
  1. **Exactly one hard binding** (§15.10.1.2). The assertions labelled
     `c2pa.hash.data` are counted — boxes in the assertion store, not
     labels, so that two boxes under one label count as two: none →
     `claim.hardBindings.missing` on the manifest's URI
     (`self#jumbf=/c2pa/<label>`, amendment 1), and nothing is hashed;
     more than one → `assertion.multipleHardBindings` on the manifest's
     URI, and nothing is hashed. Any other hard-binding label (`c2pa.hash.bmff*`,
     `c2pa.hash.boxes*`, `c2pa.hash.collection.data*`) → `general.error`
     naming the label and the spec that will handle it (M8); it does not
     count as the one.
  2. **Shape** (§18.5, fail-closed). The assertion is a CBOR map with
     `hash` a byte string, optional `alg` text, optional `exclusions` a
     list of maps `{start, length}` of non-negative integers, at most
     `maxExclusions` (default 1024) of them; `name` and `pad` are read
     past. `hash` absent → `assertion.dataHash.mismatch` (§15.12.1 says
     so, measured in step 23 as c2patool's hard error); any other fault
     — `exclusions` not a list, an entry not a map, `start`/`length`
     missing, negative or not an integer, `alg` not text, too many
     exclusions — → `assertion.dataHash.malformed` with the fault named.
  3. **Algorithm**: the assertion's `alg`, else the claim's (§15.4.2),
     one of `sha256`/`sha384`/`sha512` (§13.1); else, or none at all,
     `algorithm.unsupported`, nothing hashed. A `hash` whose length is
     not the digest length → `assertion.dataHash.mismatch` naming both.
  4. **Exclusions in order**: sorted by `start`; two ranges that overlap
     → `assertion.dataHash.malformed` (§15.12.1; c2patool says
     `.mismatch` plus the informational, step 23 — the spec's word is
     kept); a range that ends past the file's end →
     `assertion.dataHash.mismatch` (§15.12.1) naming the range and the
     file length.
  5. **The store's exclusion** (amendment 7, which reverses amendment 5):
     every range of the store (`ManifestStoreBytes::$ranges`) must lie
     inside an exclusion, else `assertion.dataHash.mismatch` naming the
     uncovered piece and the nearest exclusion. **An exclusion that holds
     any part of the store must hold nothing else**: its length must
     equal the sum of the store pieces it holds (C2PA 2.4 VAL-ASSE-0043/0044:
     only the store and padding, and in JPEG, PNG and WebP the padding is
     inside the store). Otherwise the result is `assertion.dataHash.mismatch`
     naming how many bytes before and after the store the exclusion takes
     in, and the file is not hashed. Every exclusion that holds no part of
     the store is honoured and reported once as the informational
     `assertion.dataHash.additionalExclusionsPresent` (VAL-ASSE-0045).
  6. **The hash, streamed**: `hash_init($alg)`, the file read through
     `StreamReader` from offset 0 in chunks of `chunkSize` (default
     64 KiB), each exclusion skipped with `skip()`, bytes after the last
     exclusion hashed to the end (`bytes-appended` → mismatch, step 23);
     the file is never held in memory. `hash_equals()` against the
     assertion's `hash`: `assertion.dataHash.match` or `.mismatch`, url
     `self#jumbf=/c2pa/<label>/c2pa.assertions/c2pa.hash.data`.
- `StatusCode` grows by six, verbatim from §15.2.2:
  `assertion.dataHash.match` (success), `assertion.dataHash.mismatch`,
  `assertion.dataHash.malformed`, `claim.hardBindings.missing`,
  `assertion.multipleHardBindings` (failures), and
  `assertion.dataHash.additionalExclusionsPresent` — the first
  **informational** code: `isInformational()` true, neither success nor
  failure, and `ValidationResult` keeps `Valid` in its presence.
- The check's name in `checksPerformed` is `dataHash`.
- **Deptrac**: `Hash` may see `Container` (`StreamReader`,
  `ManifestStoreBytes`).
- **Bounded**: `maxExclusions`, `chunkSize`; the store's `ranges` come
  from parsers that are already bounded (SPEC-001/002/003).

**Out of scope** (each needs its own spec before it may be built)

- Skipping this check when SPEC-011 reported `assertion.hashedURI.mismatch`
  for `c2pa.hash.data` (decision 1 of SPEC-011, second half): that is
  ordering, and it lives in the `Verifier` layer's spec. This check
  evaluates the assertion as found, as c2patool does (step 23:
  `pad-nonzero` → `hashedURI.mismatch` *and* `dataHash.match`).
- BMFF (`c2pa.hash.bmff*`, Merkle trees), `c2pa.hash.boxes`,
  collections — M8 and later; a manifest that carries one gets
  `general.error` here, never a guess.
- Sidecar manifests (no store in the file, no exclusion), update
  manifests adjusting the store exclusion (§15.12.1's second rule) — M7.
- "Appropriate padding" beyond the container framing measured for JPEG,
  PNG and WebP: a format whose writer pads differently gets its rule
  with its own container spec, from a measurement.
- Formats beyond the three (GIF, TIFF, …): their `ranges` arrive with
  their container specs.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-012')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The stores come through SPEC-001/2/3 → 005 → 007; the stream is the
fixture or variant file itself, opened `rb`. Existing variants are those
of steps 23 and 24 (`tests/Fixtures/binding/`, `tests/Fixtures/jpeg/`);
the ones this spec adds are made by a script next to the other two, each
measured through c2patool 0.27.22 before the tests are written and
recorded in the READMEs (Open questions).

- **AC1 — the four fixtures: `assertion.dataHash.match`, and the words are c2patool's** *(M4's "done when", the untouched half)*
  - Given `fixture-signed.{jpg,png,webp}` and
    `public-testfiles/adobe-20220124-C.jpg`, each extracted (its
    `ranges` recorded: JPEG one merged range of 94,772 bytes at 20 with
    32 bytes of framing, PNG 46,037 at 33, WebP 100,643 at 312, Adobe
    51,130 at 20) and parsed
  - When `DataHashCheck::check()` runs with the file's stream
  - Then it returns exactly one status, `assertion.dataHash.match`, url
    `self#jumbf=/c2pa/<label>/c2pa.assertions/c2pa.hash.data`, equal to
    the `assertion.dataHash.match` entry's code and url under
    `validation_results.activeManifest.success` in c2patool's recorded
    JSON; and `ValidationResult::fromStatuses(…, ['dataHash'])` is
    `Valid`. For the WebP the pad byte (offset 100,955) lies outside the
    store's range (which ends there) and inside the hashed bytes: the
    match's explanation says `313 of 100956 bytes`. A non-zero pad byte
    never reaches this check — SPEC-003 refuses it in the extractor
    (amendment 3)

- **AC2 — one changed pixel byte: `assertion.dataHash.mismatch`, as c2patool** *(M4's "done when", the tampered half)*
  - Given `binding/pixel-changed.png`, `binding/pixel-changed.jpg`,
    `binding/bytes-appended.png`, `binding/bytes-appended.jpg` — the
    store byte for byte the original
  - When checked
  - Then each gives exactly one status, `assertion.dataHash.mismatch`,
    with the assertion's url and an explanation holding both digests in
    hex; the result is `Invalid`; and for `pixel-changed.png` the code
    and url equal the `assertion.dataHash.mismatch` entry in c2patool's
    recorded `validation_status`

- **AC3 — the store's exclusion holds the store and nothing else** *(C2PA 2.4 VAL-ASSE-0043/0044; amendment 7 reverses amendment 5)*
  - Given `binding/bytes-inserted-before-store.png` (the store moved 16
    bytes, the exclusion not), `binding/exclusion-shifted.png` (`start`
    33 → 32), `binding/exclusion-past-end.png` (`length` → 65,535) and
    `jpeg/gap-between-pieces.jpg` (a COM segment between the two APP11
    pieces: `ranges` has two entries, the second ending past the
    exclusion), and `public-testfiles/truepic-20230212-camera.jpg`
    (the exclusion `[0, 206316]` covers the store at `[13617, 192699]`
    and everything before it: SOI and a 13,613-byte EXIF segment), and
    that file with its EXIF capture date changed in memory (2023 → 2019
    at offsets 202, 616 and 636; nothing is written to disk)
  - When checked
  - Then the first four give `assertion.dataHash.mismatch` whose
    explanation names the store's range (or pieces) and the exclusion
    that leaves part of it uncovered, the file is not hashed for the
    first three (the explanation carries no digest), and `Invalid`;
    c2patool's recorded verdict for each is `assertion.dataHash.mismatch`
    too (steps 02 and 23); the Truepic file and its changed copy each
    give exactly one `assertion.dataHash.mismatch` whose explanation
    names the 13,617 bytes the exclusion takes in before the store and
    carries no digest (not hashed), and `Invalid`. `c2patool` 0.27.22's
    recorded `assertion.dataHash.match` for this file is a named
    divergence; `c2patool` 0.28.0 gives the mismatch (step 108)

- **AC4 — additional exclusions are honoured and reported** *(informational)*
  - Given the new `binding/exclusion-extra.png` (a second range over 64
    bytes of IDAT data; the data hash recomputed with both ranges
    skipped and the claim's hashed URI for the assertion recomputed, so
    that only the signature is broken — the test says so)
  - When checked
  - Then two statuses: `assertion.dataHash.match` and
    `assertion.dataHash.additionalExclusionsPresent`, both with the
    assertion's url; `isInformational()` is true for the second, false
    for every other code; `ValidationResult` is `Valid` and `toArray()`
    lists the informational under `…activeManifest.informational` only —
    `validation_status` holds failures alone, as c2patool's
    `exclusion-extra.json` shows (amendment 2); the informational and
    success pairs equal the oracle's

- **AC5 — overlapping exclusions: `assertion.dataHash.malformed`** *(required: error / malformed input)*
  - Given `binding/exclusions-overlap.png` (the second range inside the
    first) and the new `binding/exclusions-unsorted.png` (the same two
    ranges as `exclusion-extra`, written in reverse order)
  - When checked
  - Then the first gives `assertion.dataHash.malformed` naming both
    ranges, nothing hashed, `Invalid` — c2patool's recorded
    `.mismatch` + `additionalExclusionsPresent` is the divergence step
    23 recorded; the second is sorted first and gives the same two
    statuses as AC4

- **AC6 — shape faults: `malformed`, and a missing hash is a mismatch**
  - Given `binding/hash-missing.png` (§15.12.1: `.mismatch`) and the new
    `binding/exclusions-not-list.png` (`exclusions` a map),
    `binding/exclusion-start-negative.png` (`start` −1, CBOR major type
    1), `binding/exclusion-length-text.png` (`length` `"46037"`),
    `binding/hash-as-text.png` (`hash` a text string),
    `binding/exclusions-too-many.png` (1,025 zero-length ranges, with
    `maxExclusions` 1024)
  - When checked
  - Then `hash-missing` gives `assertion.dataHash.mismatch` ("no hash");
    each of the others `assertion.dataHash.malformed` naming the fault;
    nothing is hashed; all `Invalid`; no exception escapes

- **AC7 — the algorithm: the assertion's, else the claim's, else unsupported**
  - Given `binding/alg-sha1.png`, the new `binding/alg-missing.png`
    (the assertion's `alg` pair removed; the claim's `sha256` applies)
    and `binding/alg-sha384.png` (`alg` `sha384`, the 48-byte SHA-384 of
    the asset minus the exclusion written as `hash`; hashed URI
    recomputed, signature therefore broken)
  - When checked
  - Then `alg-sha1` gives `algorithm.unsupported` naming `sha1`, nothing
    hashed (c2patool: `.mismatch` "type is unsupported", step 23 —
    divergence kept); `alg-missing` gives `assertion.dataHash.match`;
    `alg-sha384` gives `assertion.dataHash.match`

- **AC8 — exactly one hard binding**
  - Given the new `binding/hard-binding-missing.png` (the `c2pa.hash.data`
    box and its claim entry relabelled `c2pa.other.data`),
    `binding/hard-binding-bmff.png` (relabelled `c2pa.hash.bmff.v2`) and
    `binding/hard-bindings-two.png` (the box duplicated under the same
    label with a second claim entry)
  - When checked
  - Then the first gives `claim.hardBindings.missing` with the
    manifest's url `self#jumbf=/c2pa/<label>`; the second `general.error`
    naming `c2pa.hash.bmff.v2` and the check that does verify it
    *(amended 2026-09-22, see Amendments 6)*; the third
    `assertion.multipleHardBindings` with the manifest's url, equal to
    the code and url of that entry in c2patool's recorded
    `validation_status` (step 26); nothing hashed; all `Invalid`

- **AC9 — streamed, not slurped**
  - Given a temporary file: `fixture-signed.png` with 48 MiB of bytes
    appended (a mismatch by construction — the exclusion still holds
    the store), and `memory_get_peak_usage()` read before the check
  - When checked with the default `chunkSize`
  - Then the status is `assertion.dataHash.mismatch` and the peak memory
    grew by less than 4 MiB — the file was never in memory

- **AC10 — the codes are verbatim, and informational is a third kind**
  - Given `StatusCode::cases()`
  - When their values are read
  - Then the enum has exactly twenty-one cases: SPEC-011's fifteen plus
    the six above, character for character; `isInformational()` is true
    for exactly `assertion.dataHash.additionalExclusionsPresent`;
    `isSuccess()` for exactly `claimSignature.validated`,
    `assertion.hashedURI.match`, `assertion.dataHash.match`;
    `isFailure()` for the rest; and a result of one `match` and one
    informational is `Valid`, of one informational alone `Invalid` (no
    success is not a clean report)

## References

- Specification: C2PA 2.4 §18.5 (the data hash assertion: `exclusions`
  `{start, length}`, `name`, `alg`, `hash`, `pad`), §15.10.1.2 (exactly
  one hard binding per standard manifest: `claim.hardBindings.missing`,
  `assertion.multipleHardBindings`), §15.12.1 (validating the data hash:
  the exclusion holding the store, overlapping/negative ranges →
  `.malformed`, a range past the end or no `hash` → `.mismatch`,
  additional exclusions → the informational, `algorithm.unsupported`),
  §15.4.2 (the algorithm fallback), §13.1 (the algorithms), §15.2.2 (the
  codes). Read 2026-09-21 (step 23).
- Oracle: `c2patool 0.27.22` — the four fixtures' JSON
  (`tests/Fixtures/c2patool/*.json`, `assertion.dataHash.match` under
  `success`); `variants/pixel-changed.json` (`.mismatch`, the M4 "done
  when"); `variants/exclusions-overlap.json` (`.mismatch` +
  `additionalExclusionsPresent`, the only recorded informational);
  step 23's table for `bytes-appended`, `bytes-inserted-before-store`,
  `exclusion-shifted`, `exclusion-past-end`, `hash-missing`, `alg-sha1`,
  `pad-nonzero`; step 02 for `gap-between-pieces`. Step 23's probe: the
  streaming hash minus the exclusion matches for all four; the exclusion
  is the store plus 32/12/8/12 bytes of framing; the WebP pad byte is
  hashed. The new variants of AC4–AC8 are measured and recorded in the
  tests-first step.
- Reasoned: the *exact-range* rule for the store's exclusion (AC3) —
  §15.12.1's "only the store and any appropriate padding", narrowed to
  what the three writers measured actually do; a writer that pads
  otherwise will show up as a `.mismatch` on a genuine file, which is
  the safe direction, and gets its rule from a measurement. `.malformed`
  for shape faults beyond the two §15.12.1 names — the table's word for
  an assertion that cannot be read as its type. `general.error` for
  other hard-binding labels — not implemented, said so.
- Divergences from c2patool, kept: `.malformed` for an overlap (it says
  `.mismatch`); `algorithm.unsupported` for `sha1` (it says `.mismatch`);
  a report for `hash-missing` (it exits); the store-exclusion rule (it
  takes the range literally and lets the hash decide — same verdict on
  every variant measured, by a different road).

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;   (SPEC-001/002/003 amendment)
final readonly class ManifestStoreBytes
{
    /** @param list<array{start: int, length: int}> $ranges the file ranges the store and its framing occupy, in file order */
    public function __construct(public string $bytes, public array $ranges) {}
}

// namespace Provemark\C2paVerifier\Hash;

final readonly class DataHashCheck
{
    public const DEFAULT_MAX_EXCLUSIONS = 1024;
    public const DEFAULT_CHUNK_SIZE = 64 * 1024;

    public function __construct(
        private int $maxExclusions = self::DEFAULT_MAX_EXCLUSIONS,
        private int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {}

    /**
     * @param  resource  $stream  the asset, readable and seekable
     * @return list<ValidationStatus>
     */
    public function check(Manifest $manifest, $stream, ManifestStoreBytes $store): array;
}

// namespace Provemark\C2paVerifier\Report;   (six cases added)
enum StatusCode: string
{
    // … SPEC-011's fifteen …
    case AssertionDataHashMatch = 'assertion.dataHash.match';
    case AssertionDataHashMismatch = 'assertion.dataHash.mismatch';
    case AssertionDataHashMalformed = 'assertion.dataHash.malformed';
    case AssertionDataHashAdditionalExclusionsPresent = 'assertion.dataHash.additionalExclusionsPresent';
    case ClaimHardBindingsMissing = 'claim.hardBindings.missing';
    case AssertionMultipleHardBindings = 'assertion.multipleHardBindings';

    public function isInformational(): bool;   // AdditionalExclusionsPresent
}
```

Deptrac: `Hash` → `Manifest`, `Cbor`, `Report`, `Jumbf` (already), plus
`Container` (this spec).

## Open questions

- Non-blocker: the new variants (`exclusion-extra`, `exclusions-unsorted`,
  `exclusions-not-list`, `exclusion-start-negative`,
  `exclusion-length-text`, `hash-as-text`, `exclusions-too-many`,
  `alg-missing`, `alg-sha384`, `hard-binding-missing`,
  `hard-binding-bmff`, `hard-bindings-two`) are made and measured in the
  tests-first step; a c2patool answer that contradicts a criterion
  amends the criterion before the tests.
- Non-blocker: whether `ranges` should be a small value object rather
  than a shape. A shape today: three producers, one consumer, no
  behaviour.
- Non-blocker: the PNG and WebP extractors return `null` when no store
  is found; `ranges` is then irrelevant. The JPEG extractor's `null` the
  same.

## Amendments

1. **2026-09-21, step 26a, before the tests (per the first Open
   question)** — the url of `claim.hardBindings.missing` and
   `assertion.multipleHardBindings` is the *manifest's* URI
   (`self#jumbf=/c2pa/<label>`), not the claim box's: c2patool 0.27.22
   records `assertion.multipleHardBindings` with exactly that url on
   `hard-bindings-two` (`explanation: claim has multiple data bindings`),
   and the missing case, a hard error there, follows by analogy. The
   count is of boxes in the store, not of labels. AC8 and Scope item 1
   changed accordingly; nothing else.
2. **2026-09-21, step 26b, before the tests** — c2patool's
   `validation_status` holds *failures only*: on `exclusion-extra.json`
   and `exclusions-overlap.json` the informational
   `additionalExclusionsPresent` appears under
   `activeManifest.informational` and nowhere else. SPEC-010 described
   `validation_status` as "failures and informational" and `toArray()`
   was built that way, untested for want of an informational code. AC4
   now asserts the measured shape; `ValidationResult::toArray()` changes
   with this spec's implementation (SPEC-010 amendment 2, recorded
   there). The sister library's `validationCodes()` reads
   `validation_status`, so the shape matters: it must show what
   c2patool would show.
3. **2026-09-21, step 27, at implementation** — AC1's last clause said
   that flipping the WebP pad byte in a copy gives `.mismatch`. It cannot:
   SPEC-003 (approved, implemented) refuses a non-zero pad byte with a
   `ContainerException` before any check runs — fail-closed one layer
   earlier. The clause now proves the same fact from the match itself
   (313 of 100,956 bytes hashed, the store's range ending at 100,955)
   and asserts SPEC-003's refusal. No outcome changed.
4. **2026-09-21, with SPEC-014's implementation** — `StatusCode` grew by the two trust codes; AC10's test now asserts this spec's twenty-one are present and skips the two (SPEC-014 AC10 asserts the twenty-three). AC4's test expects `validation_status` absent rather than `[]` (SPEC-013 amendment 3). No criterion changed in outcome.
5. **2026-09-21, step 38, decided by Maurice van Loon after step 37** *(confirmed by Maurice van Loon, 2026-09-22)* — the store's exclusion must *cover* the store, not equal it. `truepic-20230212-*.jpg` (the C2PA's own test files) exclude `[0, 206316]` for a store at `[13617, 192699]`: the file head as well; c2patool takes the range as written and the data hash matches. The exclusion sits inside the signed claim's hashed URI: a writer that excludes more than the store hides bytes from its own binding, which the signer chose and vouched for; what a verifier must require is that the store lies inside the excluded region. Scope item 5 and AC3 changed; every step-23 variant still fails (part of the store uncovered). No other criterion changed.

6. **2026-09-22, step 87b, while implementing SPEC-029** — AC8 asked for
   the word "M8" in the answer this check gives a manifest whose hard
   binding is `c2pa.hash.bmff.v2`. M8 is finished and that binding is
   verified, by `BmffHashCheck`; `Verifier` routes a manifest carrying one
   there and never here. The status stays `general.error` — a caller who
   asks this check about a binding it does not own gets an answer, never a
   silence — and its message now names the check that does verify it
   instead of a milestone that has passed. The unversioned `c2pa.hash.bmff`
   and the box and collection hashes keep the older message: those really
   are not implemented.

   **Weight A: no verdict changed.** Through the public API that fixture is
   `Invalid` before and after; what differs is one sentence, and it stopped
   being true on the day M8 closed.

   Confirmed by Maurice van Loon, 2026-09-22 (step 88).

7. **2026-09-24, step 108, decided by Maurice van Loon: amendment 5 is
   reversed.** Amendment 5 let the store's exclusion *cover* more than the
   store, on two grounds: `c2patool` 0.27.22 accepted the Truepic files,
   and the signer vouched for its own exclusion. The second ground is what
   C2PA 2.4 forbids. VAL-ASSE-0043 says the exclusion range containing the
   store holds only the store and padding. VAL-ASSE-0044 makes anything
   else `assertion.dataHash.mismatch`. VAL-ASSE-0077 names the attack.
   Measured in step 108: a copy of `truepic-20230212-camera.jpg` with its
   EXIF capture date changed stayed `Trusted` with the Truepic root as the
   anchor. `c2patool` 0.28.0 (`c2pa` 0.91.0) rejects it with an
   exact-equality rule. Over the 165 corpus files with a store and a data
   hash, the new rule changes the verdict of the three Truepic files and
   of no other file; 11 negative variants that are already `Invalid` may
   report this failure where they reported another, and each is
   recorded when its test is read.

   Scope item 5 and AC3 changed. The rule is written as "an exclusion
   holding part of the store holds nothing else" rather than "equals the
   store's span", so that a multi-piece store is judged piece by piece
   and a gap between pieces can never be excluded along with them.

   **Weight A: three verdicts change, from `Trusted`/`Valid` to
   `Invalid`, on files the C2PA published as test files.**


## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Hash/DataHashCheckTest.php :: AC1: the four fixtures: assertion.dataHash.match, and the words are c2patool's / SPEC-012 | src/Hash/DataHashCheck.php :: check(), hashExcept(); src/Container/ManifestStoreBytes.php :: $ranges; src/Container/{Jpeg,Png,Webp}ManifestStoreExtractor.php :: extract() (the ranges) |
| AC2 | tests/Unit/Hash/DataHashCheckTest.php :: AC2: one changed pixel byte: assertion.dataHash.mismatch, as c2patool / SPEC-012 | src/Hash/DataHashCheck.php :: check() (hash_equals), hashExcept() |
| AC3 | tests/Unit/Hash/DataHashCheckTest.php :: AC3: an exclusion must cover the store / SPEC-012 | src/Hash/DataHashCheck.php :: check() (every store piece inside an exclusion; past-end); src/Container/ManifestStoreBytes.php :: __construct() (merging) |
| AC4 | tests/Unit/Hash/DataHashCheckTest.php :: AC4: additional exclusions are honoured and reported / SPEC-012 | src/Hash/DataHashCheck.php :: check() ($others); src/Report/StatusCode.php :: isInformational(); src/Report/ValidationResult.php :: fromStatuses(), toArray() (SPEC-010 amendment 2) |
| AC5 | tests/Unit/Hash/DataHashCheckTest.php :: AC5: overlapping exclusions: assertion.dataHash.malformed / SPEC-012 | src/Hash/DataHashCheck.php :: check() (usort, overlap) |
| AC6 | tests/Unit/Hash/DataHashCheckTest.php :: AC6: shape faults: malformed, and a missing hash is a mismatch / SPEC-012 | src/Hash/DataHashCheck.php :: check() (shape, $maxExclusions) |
| AC7 | tests/Unit/Hash/DataHashCheckTest.php :: AC7: the algorithm: the assertion's, else the claim's, else unsupported / SPEC-012 | src/Hash/DataHashCheck.php :: check() ($data['alg'] ?? $claim->alg, ALGORITHMS) |
| AC8 | tests/Unit/Hash/DataHashCheckTest.php :: AC8: exactly one hard binding / SPEC-012 | src/Hash/DataHashCheck.php :: check() ($bindings, isOtherHardBinding()) |
| AC9 | tests/Unit/Hash/DataHashCheckTest.php :: AC9: streamed, not slurped / SPEC-012 | src/Hash/DataHashCheck.php :: hashExcept() (StreamReader, $chunkSize) |
| AC10 | tests/Unit/Hash/DataHashCheckTest.php :: AC10: the codes are verbatim, and informational is a third kind / SPEC-012 | src/Report/StatusCode.php :: the six cases, isSuccess(), isInformational(), isFailure(); src/Report/ValidationResult.php :: fromStatuses() |
