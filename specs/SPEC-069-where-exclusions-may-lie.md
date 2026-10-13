# SPEC-069: Where an additional exclusion may lie, per format

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-13 (the allow-list, and "wholly inside one payload") |
| Supersedes | SPEC-012 AC4 ("additional exclusions are honoured and reported"), except where this spec allows them |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §15.12.1 lets a `c2pa.hash.data` leave byte ranges out of the
hash. §15.12.1.1 constrains only the range that holds the manifest store
(SPEC-012 amendment 7). Every other range is honoured as signed, with the
informational `assertion.dataHash.additionalExclusionsPresent`
(VAL-ASSE-0045). Nothing says *where* such a range may lie.

Step 349 measured what follows. `exclusion-coverage/idat-excluded-pixel-edited.png`
excludes the store and the PNG's image data (IDAT up to IEND). 66 bytes
are hashed and none of them is a pixel. One pixel changed after signing,
and the file is `Trusted` here, in `c2patool` 0.28.1 and in 0.28.2.
SPEC-068 does not catch it, because 66 is not 0. D. Buchanan's article
("How to Hack Time, With C2PA", 2 October 2026) names the fix: *"carefully
and explicitly specify which parts of a file are allowed to be excluded"*,
and have verifiers enforce it. The C2PA specification does not do that
yet. This spec does it for this verifier.

**Why an allow-list, not a deny-list.** A deny-list ("not the image data")
has to know every byte that changes what is shown or heard: in PNG alone
that is IHDR, PLTE, IDAT, but also tRNS, gAMA, cHRM, sRGB, iCCP and the
APNG chunks. A missing entry is a wrong `Trusted`. An allow-list names the
few places a measured writer excludes and refuses the rest. A missing
entry is then a wrong `Invalid`, which is the error this project prefers
(brief §4, "fail closed").

**What real files do.** Step 349's scan of 708 fixture files found 393
data-hash bindings and **one** with additional exclusions:
`writers/google-20250919-pixel10-npld-picnic-table.jpg` (Google Pixel 10).
It has four, each exactly the payload of an APP1 segment in the primary
image, after the marker and the length field:

| exclusion | segment | identifier |
|---|---|---|
| `[6, 43697]` | APP1 at 2, 43,701 bytes | `Exif\0\0` |
| `[50643, 1158]` | APP1 at 50,639 | `http://ns.adobe.com/xap/1.0/\0` |
| `[51805, 65458]` | APP1 at 51,801 | `http://ns.adobe.com/xmp/extension/\0` |
| `[117267, 24606]` | APP1 at 117,263 | `http://ns.adobe.com/xmp/extension/\0` |

None of them touches the quantisation or Huffman tables, the frame
header, the scan, or the MPF gain-map image after the first EOI.

## Scope

**In scope**

- Every additional exclusion of a `c2pa.hash.data` (one that holds no part
  of the store), in every format that uses it.
- The allow-list:
  - **JPEG**: an exclusion lying wholly inside the payload of one APP1
    segment of the primary image (before its first SOS) whose identifier is
    `Exif\0\0`, `http://ns.adobe.com/xap/1.0/\0` or
    `http://ns.adobe.com/xmp/extension/\0`. "Payload" means after the
    two-byte marker and the two-byte length.
  - **PNG, WebP, GIF, WAV, AVI, MP3, FLAC, plain text**: nothing. No
    measured writer excludes anything beyond the store in these formats.

**Out of scope** (each needs its own spec before it may be built)

- BMFF exclusions (box paths, `c2pa.hash.bmff.v2`/`v3`): not measured.
  `assertion.bmffHash.additionalExclusionsPresent` stays informational.
- Widening the allow-list. Each new entry needs a real file from a real
  writer, the measured ranges, and an amendment.
- Whether excluding Exif is wise: Exif carries a capture date and GPS that
  the signature then does not cover. That is a writer's choice, and what
  this verifier reports about the claim's own metadata does not change.
- The secondary (gain-map) image of an MPF/Ultra HDR JPEG. Exclusions
  there are refused, since no writer was measured making one.

## Behavior

- **AC1 — the Pixel 10 file is unchanged**
  - Given `writers/google-20250919-pixel10-npld-picnic-table.jpg`
  - When verified
  - Then the same state and the same status list as before. The four
    exclusions are allowed, and the informational
    `assertion.dataHash.additionalExclusionsPresent` is still reported, now
    with "inside APP1 Exif/XMP payloads" in its explanation.

- **AC2 — an exclusion over image data is a mismatch** *(required: error path)*
  - Given `exclusion-coverage/idat-excluded.png` and
    `idat-excluded-pixel-edited.png`
  - When verified, with and without `throw-away-root.settings.json`
  - Then exactly one status for `c2pa.hash.data`,
    `assertion.dataHash.mismatch`, whose explanation names the exclusion,
    the format, and that the format allows no exclusion beyond the store.
    The file is not hashed. The verdict is `Invalid`.

- **AC3 — the three-range shape and the one-byte floor** *(error path)*
  - Given `exclusion-coverage/all-but-store.png`,
    `all-but-store-pixel-edited.png` and SPEC-068's `all-but-one-byte.png`
  - When verified
  - Then `assertion.dataHash.mismatch` for each. For the first two
    SPEC-068 answers first (no byte hashed). For the third this spec
    answers, naming the first exclusion it refuses.

- **AC4 — a JPEG exclusion outside the allowed payloads** *(error path)*
  - Given new JPEG fixtures, built from `fixture-signed.jpg` in the step-349
    way and re-signed:
    - `jpeg-scan-excluded.jpg`: an exclusion inside the scan data;
    - `jpeg-app1-header-excluded.jpg`: an Exif APP1 exclusion that starts
      at the marker, not the payload;
    - `jpeg-icc-excluded.jpg`: the payload of the APP2 ICC profile, which
      changes the colours and is not on the list;
    - `jpeg-exif-allowed.jpg`: exactly the Exif APP1 payload (the positive
      control).
  - When verified under the throw-away root
  - Then the first three are `Invalid` with `assertion.dataHash.mismatch`
    naming the segment, or "no segment", that the exclusion falls in. The
    fourth is `Trusted` with `dataHash.match` and the informational.

- **AC5 — malformed structure refuses, never allows** *(required: malformed input)*
  - Given a JPEG whose segment walk cannot reach the first SOS (a length
    past the end of the file, or a marker without 0xFF), and a JPEG with
    more than the walk's segment limit before SOS
  - When an additional exclusion has to be placed
  - Then `assertion.dataHash.mismatch`, explaining that the allowed
    payloads could not be established. It is never allowed by default. A
    file with no additional exclusions never walks the segments and is
    unaffected.

- **AC6 — nothing else moves**
  - Given the drift-alarm corpora and every fixture outside
    `exclusion-coverage/`, the new JPEG fixtures and `binding/`
  - When verified before and after
  - Then no state or status code changes, except the files named under
    *Changed answers* below.

**Changed answers** (SPEC-012's fixtures, whose signature is already
broken): `binding/exclusion-extra.png` and `binding/exclusions-unsorted.png`
(an extra range of 64 bytes over IDAT data) go from `dataHash.match` plus
the informational to `assertion.dataHash.mismatch`. Both stay `Invalid`,
and SPEC-012 AC4/AC5 get an amendment that points here.

## References

- Specification: C2PA 2.4 §15.12.1, §15.12.1.1, §18.5, VAL-ASSE-0045 (as
  cited in SPEC-012); ITU-T T.81 §B.1.1.4 and B.2.4 (JPEG markers and the
  segment length); the PNG spec §5.4 (chunk naming) for why a PNG
  deny-list would be long.
- Oracle: `c2patool` 0.28.1 and 0.28.2: `Trusted` for every
  `exclusion-coverage` file except `whole-file`. **This spec diverges
  from them deliberately**, as SPEC-012 amendment 7 does. The divergence
  goes into `docs/comparison.md`, and upstream should hear of it before
  this ships (see Open questions).
- Measured (step 349, and for this spec): the four Pixel 10 ranges and
  the segments they sit in (a marker walk over the file); one file in 708
  with additional exclusions.
- Reasoned: that other cameras or apps exclude the same APP1 payloads.
  The allow-list holds what was measured and nothing else.

## API sketch

```php
// namespace Provemark\C2paVerifier\Hash;

/** Where a format allows an exclusion beyond the store; empty = nowhere. @internal */
interface ExclusionAllowance
{
    /**
     * @param  resource  $stream
     * @return list<array{start: int, length: int, what: string}>  e.g. 'APP1 Exif payload'
     * @throws ContainerException  when the structure cannot be walked (AC5)
     */
    public function allowed($stream): array;
}

final readonly class JpegExclusionAllowance implements ExclusionAllowance { /* bounded marker walk to the first SOS */ }
final readonly class NoExclusionAllowance implements ExclusionAllowance { /* [] */ }

// DataHashCheck::check(..., ExclusionAllowance $allowance = new NoExclusionAllowance)
// The Verifier passes the JPEG one when the format is 'jpeg'. The walk runs
// only when $others !== [], so files without extras pay nothing.
```

## Amendments

### Amendment 1 — say that the allowed bytes are not signed (proposed 2026-10-13, step 353; awaiting approval)

**Why.** Golaszewski, Krawetz, Sherman et al., "Verifying Provenance of
Digital Media: Why the C2PA Specifications Fall Short" (arXiv 2604.24890,
IACR ePrint 2026/804) report that the Google Pixel 10 Pro puts GPS
information in an exclusion range. They changed the location, and a
conforming validator (Proofmode Verify) showed the false one. That is the
range this spec allows: the Exif APP1 payload. The verdict stays right,
since the image is bound. But the informational now says only "honoured as
signed, each inside an allowed region (APP1 Exif payload, …)", which a
reader can take to mean that the Exif data is signed. It is the opposite.

**Change.** The informational's explanation ends with one sentence:
*"these bytes are not covered by the signature: what they hold (Exif or
XMP metadata such as a GPS position or a capture time) is not verified"*.
The code, the url, the state and the allow-list do not change.

- **AC1 (amended).** As AC1, and the informational's explanation contains
  "these bytes are not covered by the signature".
- **AC4 (amended).** The same for `jpeg-exif-allowed.jpg`.

**Reasoned, not measured.** Whether the Pixel 10 file in `writers/`
carries GPS in its Exif was not checked. The sentence is about what the
range *may* hold, not what this file holds.

## Open questions

- **Upstream (non-blocking, Maurice's call).** The same wrong `Trusted`
  is in `c2patool` 0.28.2 and so in c2pa-rs. Report it to the CAI before,
  or together with, a release that names it? The article is public, but
  the three-range and image-data shapes are not in it.
  *Status 2026-10-13 (step 352):* open, for Maurice.
- **Release and propagation (non-blocking).** 0.6.2 with SPEC-068 and
  SPEC-069, then the WordPress plugin and the demo? It falls inside the
  consolidation until ~22 October, so it needs Maurice's decision, as
  SPEC-067 did.
  *Status 2026-10-13 (step 352):* open, for Maurice.
- **Allow-list wording (blocking for approval).** "Wholly inside one
  payload" admits a part of a payload as well as the whole of one. The
  Pixel excludes whole payloads. Allowing parts is no weaker, since the
  rest is hashed, but say so explicitly or require exact payloads?
  Proposal: wholly inside.
  *Status 2026-10-13 (step 352):* decided by Maurice: wholly inside one
  payload. The allow-list itself was approved with it.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Hash/ExclusionAllowanceTest.php :: keeps the Pixel 10 file as it was, its four exclusions allowed as APP1 Exif and XMP payloads / SPEC-069 | src/Hash/JpegExclusionAllowance.php :: regions(), ALLOWED_APP1; src/Hash/DataHashCheck.php :: check() (the informational names the regions); src/Verifier/Verifier.php :: exclusionAllowance() |
| AC2 | tests/Unit/Hash/ExclusionAllowanceTest.php :: reports a PNG exclusion over the image data as a mismatch, without hashing (2 files × 2 modes) / SPEC-069 | src/Hash/NoExclusionAllowance.php; src/Hash/DataHashCheck.php :: check() |
| AC3 | tests/Unit/Hash/ExclusionAllowanceTest.php :: answers the three-range shape through SPEC-068 and the one-byte floor here / SPEC-069; tests/Unit/Hash/DataHashCoversNothingTest.php :: does not refuse a data hash that leaves one byte: the floor / SPEC-068 | src/Hash/DataHashCheck.php :: check() (SPEC-068's check first) |
| AC4 | tests/Unit/Hash/ExclusionAllowanceTest.php :: refuses a JPEG exclusion outside the allowed payloads, naming where it falls (3 files); allows exactly the Exif payload of a JPEG: the positive control; allows an exclusion only wholly inside an allowed region (4 cases); maps a JPEG's segments up to the first SOS / SPEC-069 | src/Hash/JpegExclusionAllowance.php :: regions(), name(); src/Hash/DataHashCheck.php :: check() (wholly inside; where it falls); bin/make-exclusion-coverage-variants.php (the `jpeg-*` files) |
| AC5 | tests/Unit/Hash/ExclusionAllowanceTest.php :: refuses to map a JPEG whose segments cannot be walked to the first SOS (7 cases); refuses, never allows, when the allowed regions cannot be established; a file without extras never asks / SPEC-069 | src/Hash/JpegExclusionAllowance.php :: regions() (DEFAULT_MAX_SEGMENTS); src/Hash/DataHashCheck.php :: check() (the ContainerException) |
| AC6 | the corpus before and after (notes/step-352-where-exclusions-may-lie.md); tests/Unit/Hash/DataHashCheckTest.php :: AC4, AC5 (SPEC-012 amendment 11); tests/Unit/Verifier/VerifierTest.php :: AC10 (the drift alarm names the divergence) | src/Hash/ExclusionAllowance.php, src/Hash/ExclusionRegion.php |

The tests were run red first: 21 failed (all 19 SPEC-069 cases, plus
SPEC-012's AC4 and AC5 as amended), because the classes and the parameter
did not exist yet. After the change, `composer check` failed twice more:
SPEC-068 AC3's floor (now answered by this spec, as its AC3 says) and the
drift alarm on `variants/exclusion-extra` (the changed answer). Both tests
were brought in line. Mutations watched failing: a start-only containment,
no allowance for JPEG, no rewind after the walk, any region counting
whether allowed or not, the whole segment allowed instead of the payload,
and any APPn checked for the identifiers. That last one survived the first
round, and an Exif identifier in APP2 was added to the segment-map test.
`composer check` green (1,058 passed); the public contract unchanged
(`bin/api-check.php`, 135 symbols).
