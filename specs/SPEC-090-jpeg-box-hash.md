# SPEC-090: The general box hash (`c2pa.hash.boxes`) for JPEG

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-11-13                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A manifest's hard binding ties it to the bytes of the asset. This verifier
verifies two kinds:
- `c2pa.hash.data` (SPEC-012);
- the BMFF hashes (SPEC-027, SPEC-028).

A third, the general box hash `c2pa.hash.boxes` (C2PA 2.4 §9.2.2, §18.7,
validation §15.12.3), is refused by name: `DataHashCheck` answers it with
`general.error` *"not supported yet"* (`PRED-CONT-006`, SPEC-012 AC13). Such a
file is never `Valid` here, however sound it is.

That refusal now has a real file behind it:
- **Microsoft Bing Image Creator signs with a box hash.** Step 529 found it
  in the corpus (`writers/microsoft-20260609-bing-fast-heartbeat.jpg`), and
  step 141 measured nine Bing files on Commons, all written the same way.
- **The verifier now reaches that refusal.** Since SPEC-042 (step 531) it
  reads Bing's slash-less URIs, so the file stops at the box hash.
  `c2patool` 0.27.22 calls it `Valid`, with `assertion.boxesHash.match`.

ADR-0005 decided that this verifier is stricter than `c2patool` only where
the strictness prevents a wrong `Valid`. A refusal of a hard binding that
`c2pa-rs` verifies, and that the spec defines in full, prevents nothing.

**Measured for this draft (2026-11-13).** The Bing file's assertion, read
with this verifier's manifest reader:
- `alg: "sha256"`, and twelve box-hash-maps of one name each: `SOI`,
  `C2PA` (hash `00`), `APP0`, `DQT`, `DQT`, `SOF0`, four `DHT`, `SOS`,
  `EOI`;
- no `exclusions`, no `excluded`, empty `pad`.

The file is one JPEG with a single APP11 piece, the store (13,093 bytes
from offset 2), and no data after EOI. A scratch script hashed each listed
box as its segment, from the marker to the end of the segment. It counted
`SOS` together with its entropy-coded data up to the next marker that is
neither a stuffed `FF00` nor `RSTn`. **All eleven hashed boxes match.**

**C2PA 2.5 was read too** (`c2pa-org/specifications`, build of 2.5 at
`f314ed9`, 2026-10-16). Its box-hash text (§14.12.3, §16.3.3) is the 2.4
text renumbered, with "JPEG" written "JPEG 1". This spec cites 2.4, the
version the verifier targets.

## Scope

**In scope**

1. **JPEG only.** A JPEG whose active manifest's hard binding is
   `c2pa.hash.boxes` is verified as C2PA 2.4 §15.12.3 and §15.12.3.1
   describe. JPEG is the only format a writer has been seen to use it
   with. A box hash in any other container stays refused by name, as
   today (AC11).
2. **The JPEG box list**, as §18.7.3.1 and `c2pa-rs` `jpeg_io.rs`
   `get_box_map` (read at `58b9a092`) define it:
   - one box per marker segment, named by its ITU-T T.81 Table B.1 symbol
     (`SOI`, `APPn`, `DQT`, `SOFn`, `DHT`, `DRI`, `SOS`, `RSTn`, `COM`,
     `EOI`, …);
   - `SOS` and `RSTn` include the entropy-coded data that follows them;
   - every APP11 segment of the C2PA manifest store together is one box
     named `C2PA`;
   - an APP11 segment that is not part of the store is an ordinary `APP11`
     box;
   - bytes after `EOI` are a box `c2pa.after`, as `c2pa` 0.91.11 does it
     (upstream #2949).

   The walk is bounded like every other here: SPEC-024's limits, and
   SPEC-069's 1,024 segments before `SOS`.
3. **Validation, in the order of §15.12.3:**
   - **`assertion.boxesHash.malformed`:**
     - no `boxes`;
     - a box-hash-map without `names`;
     - an empty `exclusions` array;
     - a `boxIndex` that names no box, or none given where the map has
       more than one;
     - exclusions out of order or overlapping;
     - a negative `start` or `length`;
     - `C2PA` sharing a box-hash-map with another name.
   - **`algorithm.unsupported`:** no `alg` in the box-hash-map, the
     box-map or the claim, or one outside §13.1 (the rule
     `HashedUriCheck` already applies).
   - **`assertion.boxesHash.unknownBox`:** a box in the file that the list
     does not name in its place, and so a `c2pa.after` box the list does
     not name.
   - **`assertion.boxesHash.mismatch`:**
     - a listed name that differs from the box at its place: the
       specification says *mismatch* for out of order (open question 2);
     - a hash that differs;
     - a box-hash-map whose `hash` is missing;
     - an exclusion that reaches past its box, or that is not wholly
       inside the store or a payload SPEC-069 allows (APP1 Exif or XMP).
   - **`assertion.boxesHash.match`** when every hashed box-hash-map
     matches.
   - **`assertion.boxesHash.additionalExclusionsPresent`**
     (informational) when an exclusion lies outside the store, or a
     non-`C2PA` box is `excluded: true`, as `c2pa-rs` sets it.
4. **What a range of names and the special fields mean:**
   - a box-hash-map naming several boxes hashes from the start of the
     first to the end of the last, bytes between them included;
   - `excluded: true` skips the box and its `exclusions`;
   - the `C2PA` box's hash is not compared, so its `00` is never computed;
   - `pad` and `pad2` are ignored.
5. **Five status codes**, verbatim from C2PA 2.4 §15.2.2 (the `StatusCode`
   contract grows by five, open question 3):
   - `assertion.boxesHash.match` (success);
   - `assertion.boxesHash.mismatch`, `assertion.boxesHash.malformed` and
     `assertion.boxesHash.unknownBox` (failures);
   - `assertion.boxesHash.additionalExclusionsPresent` (informational).
6. **Docs:**
   - `docs/conformance.md`: `PRED-CONT-006` goes from *closed* to *yes* for
     JPEG and *closed* elsewhere;
   - `docs/comparison.md`: the box-hash row;
   - `docs/reading-c2pa-2.4.md`: §9.2.2, §15.12.3 and §18.7;
   - README: the hard bindings it verifies.

**Out of scope** (each needs its own spec before it may be built)

- **The box hash in other containers:** PNG (`PNGh`), GIF, RIFF (WebP, WAV,
  AVI), MP3, FLAC, JPEG XL, fonts and Ogg. Each has its own box naming
  (§18.7.3), and no writer has been seen using one. Until each is built,
  it stays refused by name.
- **The multi-asset hash fallback** (`c2pa.hash.multi-asset`, §15.12.4,
  `PRED-ASSE-021`, issue #7). A box-hash mismatch stays a mismatch, which
  is stricter than the rule, as for the data hash today.
- **A `c2pa.after` box the list does name.** `c2pa-rs` hashes it and
  accepts it. Here it is refused with `assertion.boxesHash.unknownBox`
  (open question 4), as are JPEG Multi-Picture Format files listed as one
  sequence of boxes.
- **`c2pa-rs`'s `PNGh` leniency** (`box_hash.rs`: a PNG box list without
  `PNGh` skips the 8-byte header unhashed). PNG is out of scope anyway.
- **Writing a box hash.** This verifier only reads.

## Behavior

Fixtures come from two places:
- **From the Bing file**, changed in its bytes but not re-signed: its claim
  and signature stay valid, only the asset changes. Judged at
  `2026-07-01T00:00:00Z`, because its leaf expired on 2026-10-01 (SPEC-041
  open question 3).
- **From this project's own writer**, re-signed under a throwaway root by
  SPEC-036's route: a box-hash JPEG built from `fixture-signed.jpg`, with
  its `c2pa.hash.data` replaced by a `c2pa.hash.boxes`, for every case
  that needs a different assertion.

Every variant is measured with `c2patool` 0.28.6 and 0.27.22 before its
test is written.

- **AC1 — the Bing file's box hash matches**
  - Given `writers/microsoft-20260609-bing-fast-heartbeat.jpg`, without
    trust settings, at 2026-07-01
  - When verified
  - Then:
    - `assertion.boxesHash.match` on
      `self#jumbf=/c2pa/urn:uuid:2b35d21e-b8eb-4e52-ba67-29bc413dfea6/c2pa.assertions/c2pa.hash.boxes`;
    - no `general.error`;
    - `Valid` with `signingCredential.untrusted`, as `c2patool` 0.27.22.

    (0.28.x says `Invalid` on the Microsoft EKU: step 112's divergence,
    recorded and unchanged.)

- **AC2 — a changed pixel is a mismatch** *(error path)*
  - Given the Bing file with one byte of its entropy-coded data changed
  - When verified at 2026-07-01
  - Then `assertion.boxesHash.mismatch` on that url, and `Invalid`.

- **AC3 — an unlisted segment is an unknown box** *(error path)*
  - Given the Bing file with a `COM` segment inserted before `DQT`
  - When verified at 2026-07-01
  - Then `assertion.boxesHash.unknownBox`, and `Invalid`.

- **AC4 — data after EOI is an unknown box** *(error path)*
  - Given the Bing file with a few bytes appended after `EOI` (the
    polyglot case of upstream #2949)
  - When verified at 2026-07-01
  - Then `assertion.boxesHash.unknownBox`, and `Invalid`, as `c2patool`
    0.28.6. 0.27.22 predates #2949; what it says is recorded, not
    followed.

- **AC5 — boxes out of order** *(error path)*
  - Given the Bing file with its `SOF0` segment moved before the two `DQT`
    segments
  - When verified at 2026-07-01
  - Then `assertion.boxesHash.mismatch` (§15.12.3: *"If the boxes appear
    out of order"*), and `Invalid`. Open question 2 records what
    `c2pa-rs` reports instead.

- **AC6 — a well-formed box hash from this project's writer**
  - Given `box-hash/written.jpg`, built and re-signed with the throwaway
    root:
    - box-hash-maps with a range of names (`["DQT", "DQT", "SOF0"]`);
    - one with `alg` set on the map rather than the box-map;
    - `pad` and `pad2` filled with zeros.
  - When verified with that root as anchor
  - Then `Trusted` with `assertion.boxesHash.match`, as both `c2patool`
    versions.

- **AC7 — malformed box-maps** *(error path)*
  - Given written variants:
    - without `boxes`;
    - with a box-hash-map without `names`;
    - with `exclusions: []`;
    - with a `boxIndex` beyond its names;
    - with two overlapping exclusions;
    - with `["C2PA", "APP0"]` in one map.
  - When verified
  - Then `assertion.boxesHash.malformed` for each, and `Invalid`.

- **AC8 — no algorithm anywhere** *(error path)*
  - Given a written variant with no `alg` on the box-hash-map, the box-map
    or the claim
  - When verified
  - Then `algorithm.unsupported`, and `Invalid`.

- **AC9 — exclusions: allowed inside an Exif payload, refused elsewhere**
  - Given written variants:
    - an exclusion wholly inside an APP1 Exif payload;
    - an exclusion inside a `DQT` segment;
    - an exclusion reaching past the end of its box.
  - When verified
  - Then, in order:
    - the first is `Trusted` with `assertion.boxesHash.match` and the
      informational `assertion.boxesHash.additionalExclusionsPresent`;
    - the second is `assertion.boxesHash.mismatch` (*error path*);
    - the third is `assertion.boxesHash.mismatch` (*error path*).

- **AC10 — `excluded: true`**
  - Given a written variant whose APP1 Exif box is `excluded: true`, its
    hash written `00`
  - When verified
  - Then `Trusted` with `assertion.boxesHash.match` and
    `assertion.boxesHash.additionalExclusionsPresent`.

- **AC11 — a box hash in another container stays refused** *(error path)*
  - Given `binding/hard-binding-boxes.png` (SPEC-012 AC13, a PNG)
  - When verified
  - Then `general.error` *"not supported yet"*, as today, now naming JPEG
    as the one container whose box hash is verified.

- **AC12 — a foreign APP11 must be listed** *(error path)*
  - Given the Bing file with an APP11 segment inserted that is not part of
    the C2PA store and is no JPEG XT piece (amendment 1)
  - When verified at 2026-07-01
  - Then `assertion.boxesHash.unknownBox` (§15.12.3.1), and `Invalid`.

- **AC13 — the vocabulary grows by five codes, verbatim**
  - Given `StatusCode`
  - Then the five values exist with the kinds named in Scope 5, and
    `tests/Fixtures/api/public-surface.txt` records them.

- **AC14 — nothing else in the corpus moves**
  - Given the corpus, before and after, under the three trust
    configurations, without and with a revocation source, at a validation
    time after every fixture's notBefore
  - Then:
    - only the Bing file and this spec's new fixtures change output;
    - the Bing file at now stays `Invalid`, its leaf expired;
    - `binding/hard-binding-boxes.png` and
      `hard-binding-redacted/hash-boxes-relative.png` keep their verdicts.

## References

- **Specification:** C2PA 2.4 (`c2pa-org/specifications` at `4eb2c67`):
  - §9.2.2 (general box hash);
  - §15.12.3 and §15.12.3.1 (validation, JPEG);
  - §18.7.1–18.7.3.1 (the assertion, JPEG naming, the `C2PA` box, range
    hashing, `excluded`, `exclusions`, `pad`/`pad2`);
  - the CDDL `box-map`;
  - §15.2.2 (the five codes);
  - ITU-T T.81 Table B.1 (marker symbols).

  C2PA 2.5 §14.12.3 and §16.3.3 were compared and change nothing here.
- **Oracle:** `c2patool` 0.28.6 (`c2pa` 0.91.11, with #2949) and 0.27.22,
  on the Bing file (recorded under `tests/Fixtures/c2patool/writers/` and
  `first-piece-z/`) and on every variant before its test.
- **Reasoned from source:** `c2pa-rs` at `58b9a092` (2026-10-06):
  - `sdk/src/assertions/box_hash.rs` (`verify_stream_hash_with_progress`,
    `split_exclusions`);
  - `sdk/src/asset_handlers/jpeg_io.rs` (`get_box_map`).

  Upstream #2949 (merged 2026-11-02) is read from its description, not
  its code.
- **Measured:** the Bing assertion and its eleven box hashes recomputed
  (above).

## API sketch

Illustrative only. No public API changes beyond the five `StatusCode`
cases.

```php
// namespace Provemark\C2paVerifier\Hash;

/** @internal SPEC-025 */
final readonly class BoxHashCheck
{
    public const LABEL = 'c2pa.hash.boxes';

    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest, $stream, ManifestStoreBytes $store): array;
}

/** @internal SPEC-025 — one named box of the file: its name, its range, and where an exclusion may fall. */
final readonly class AssetBox
{
    public function __construct(public string $name, public int $start, public int $length) {}
}

/** @internal SPEC-025 — the JPEG box list of §18.7.3.1, bounded; throws ContainerException on a walk it cannot finish. */
final readonly class JpegBoxMap
{
    /** @return list<AssetBox> */
    public function boxes($stream, ManifestStoreBytes $store): array;
}
```

The `Verifier` routes a JPEG manifest whose hard binding is
`c2pa.hash.boxes` to `BoxHashCheck`, as it routes a BMFF binding to
`BmffHashCheck`. `DataHashCheck`'s refusal message changes for every other
container (AC11). Exclusion allowances reuse SPEC-069's
`JpegExclusionAllowance`.

## Open questions

1. **JPEG only?** Proposal: yes. It is the one container a writer has
   been seen to use. PNG, GIF and RIFF have their own box naming, and each
   needs fixtures `c2patool` cannot write (step 524: with
   `prefer_box_hash`, 0.28.6 still writes a data hash), and so a writer of
   this project's own. One spec per container, when a writer appears.
   *(blocker: decides the scope)*
   *Status 2026-11-13 (step 532):* decided by Maurice: the proposal.
2. **Out of order: `mismatch` or `unknownBox`?** §15.12.3 says *"If the
   boxes appear out of order, then the manifest shall be rejected with a
   failure code of `assertion.boxesHash.mismatch`"*. `c2pa-rs` matches
   names position by position, so an out-of-order list is reported where
   the first name differs, with `assertion.boxesHash.unknownBox`
   (`box_hash.rs`, read). Proposal: the letter, `mismatch`. ADR-0005
   allows reporting by the letter where both codes fail the file, as
   SPEC-088 did. It is recorded in `docs/comparison.md` once AC5's
   variant has measured what `c2patool` says. *(not a blocker)*
   *Status 2026-11-13 (step 532):* decided by Maurice: the proposal.
3. **A contract change: minor or patch?** Five `StatusCode` cases make 217
   symbols. The project's rule puts a contract change in a minor
   (`0.11.0`). 0.10.1 made one exception, for one symbol. Proposal: a
   minor. A caller that matches every case needs five more arms, and the
   verdict of a box-hash file moves from `Invalid` to `Valid` or
   `Trusted`, which a caller should opt into. *(not a blocker for the
   build; it decides the release)*
   *Status 2026-11-13 (step 532):* decided by Maurice: the proposal.
4. **A listed `c2pa.after`.** `c2pa-rs` accepts a `c2pa.after` box that
   the list names and hashes correctly. The specification allows it only
   for multi-part assets (§18.7.2), which this verifier does not read.
   Proposal: refuse it as `unknownBox` until a multi-part spec exists. A
   listed trailer is exactly where a polyglot would hide, and no writer
   has been seen using it. *(not a blocker)*
   *Status 2026-11-13 (step 532):* decided by Maurice: the proposal.
5. **SPEC-042 open question 3, answered by reading.** `c2pa-rs`
   (`store.rs` `is_uri_redacted`, `labels.rs` `to_absolute_uri`, at
   `58b9a092`) does not normalise `redacted_assertions` entries. It
   compares the entry as written with `to_absolute_uri()` of the
   reference, and that returns a slash-less reference unchanged. So
   `c2pa-rs` matches a redaction only when the reference and the entry
   share their spelling. This verifier compares the normalised reference
   with slashed entries only:
   - it is stricter for a slash-less entry;
   - it is more lenient for a slash-less reference with a slashed entry.

   Both denote the same box, and the redacting manifest's signer vouches
   for the entry. No file carries either case. Proposal: record it in
   `docs/comparison.md` and change nothing. *(not a blocker)*
   *Status 2026-11-13 (step 532):* decided by Maurice: the proposal.

## Amendments

1. **2026-11-13, step 533, measured.** AC12's foreign APP11 was a JPEG
   360-style `JP` piece of another box (another box instance number,
   En). This verifier's JPEG extractor collects every `JP` piece as one
   store and refuses one with another En as a container error (SPEC-001
   AC11: *"box instance number … differs from …"*). So that file never
   reaches the box hash. `c2patool` 0.28.6 skips the piece and reports
   `assertion.boxesHash.unknownBox`. Changing the extractor is SPEC-001's
   concern, not this spec's. AC12 now inserts an APP11 segment that is no
   JPEG XT piece (no `JP`), which the extractor already passes over.
   `c2pa-rs` cannot parse an APP11 of fewer than 28 bytes (*"Invalid JPEG
   CAI JUMBF block"*), so the segment is longer than that. 0.28.6 then
   reports `unknownBox`, as for the `JP` piece. The `JP` case is recorded
   in `docs/comparison.md` as a difference; it needs a spec of its own if
   a file ever shows it.

   Weight B: a criterion's Given changed, the behaviour it asks for
   unchanged.

   Confirmed by Maurice van Loon, 2026-11-13 (step 533).

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC1: the Bing file's box hash matches" / `SPEC-090` | `Hash\BoxHashCheck::check()`, `Hash\JpegBoxMap::boxes()`, `Verifier::verify()` routing |
| AC2 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC2: a changed pixel is a mismatch" / `SPEC-090` | `BoxHashCheck::check()` (each map's hash), `JpegBoxMap::entropyEnd()` |
| AC3 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC3: an unlisted segment is an unknown box" / `SPEC-090` | `BoxHashCheck::check()` (counts per name) |
| AC4 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC4: data after EOI is an unknown box, listed or not" / `SPEC-090` | `JpegBoxMap::boxes()` (`c2pa.after`), `BoxHashCheck::check()` |
| AC5 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC5: boxes out of order are a mismatch, by the letter" / `SPEC-090` | `BoxHashCheck::check()` (position by position) |
| AC6 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC6: a well-formed box hash from this project's writer" / `SPEC-090` | `BoxHashCheck::check()` (ranges, the `alg` chain, `pad`/`pad2` ignored) |
| AC7 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC7: malformed box-maps" (six datasets); "AC7: what c2patool says about the malformed variants" / `SPEC-090` | `BoxHashCheck::check()` (structure, exclusion order) |
| AC8 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC8: no algorithm anywhere" / `SPEC-090` | `BoxHashCheck::check()` (the `alg` chain) |
| AC9 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC9: an exclusion inside an Exif payload is allowed, elsewhere it is a mismatch" / `SPEC-090` | `BoxHashCheck::check()` with `JpegExclusionAllowance::regions()` |
| AC10 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC10: excluded: true skips the box" / `SPEC-090` | `BoxHashCheck::check()` (`excluded`) |
| AC11 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC11: a box hash in another container stays refused" / `SPEC-090` | `Verifier::verify()` (JPEG only), `DataHashCheck::check()` refusal text |
| AC12 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC12: a foreign APP11 must be listed" / `SPEC-090` | `JpegBoxMap::boxes()` (APP11 outside the store ranges), `BoxHashCheck::check()` |
| AC13 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC13: the vocabulary grows by five codes, verbatim" / `SPEC-090` | `Report\StatusCode` (five cases, `isSuccess()`, `isInformational()`); `tests/Fixtures/api/public-surface.txt` |
| AC14 | `tests/Unit/Hash/BoxHashCheckTest.php` :: "AC14: nothing else in the corpus carries a box hash" (guard); the before/after run in step 533 / `SPEC-090` | — |
