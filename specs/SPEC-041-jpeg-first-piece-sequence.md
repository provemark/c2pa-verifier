# SPEC-041: A JPEG store whose first APP11 piece carries Z = 0

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

A JPEG carries its manifest store in APP11 marker segments ("pieces"). Each
piece starts with a 16-byte header: CI `JP`, the box instance number En, the
packet sequence number Z, then LBox and TBox (C2PA 2.4 §A.3.1, which
defers the layout to ISO/IEC 18477-3 and ISO/IEC 19566-5:2023 D.2). SPEC-001
requires the n-th piece to carry Z = n, so the first piece must carry
Z = 1. Anything else is a `ContainerException`, which the verifier reports
as `general.error`.

Step 141 found that **Microsoft Bing Image Creator writes Z = 0**. Each of
the nine Bing images measured on Wikimedia Commons holds its whole store in
a single piece with Z = 0. This verifier refuses all nine before reading a
box, and both `c2patool` versions read them. It is the only case in step
141 where this verifier alone refuses a file from a mass-market writer.
The project's rule that a verdict means what `c2patool`'s means is broken
here for a detail of the container. That detail protects nothing: the
bytes of the store are the same whatever Z says, and the claim signature
and the hash binding are checked afterwards as for any file.

**What the norm says is not known.** ISO/IEC 18477-3 is paywalled and was
not read. ISO/IEC 19566-5:2023's Annex D is *informative* (its table of
contents, in ISO's public sample). The JPEG committee's reference software
(`thorfdbg/libjpeg`, `boxes/box.cpp`, at `702114c`) writes Z from 1 with
the comment `// FIX: Z starts with 1.`, and reads by sorting the pieces on
Z, rejecting no starting value. Bing therefore very likely writes against
the norm. A reader that accepts it is still within what the reference
reader accepts.

## Scope

**In scope**

- A first piece with Z = 0 is accepted. The pieces after it are held to
  SPEC-001's rule unchanged: piece n (n ≥ 2) carries Z = n.

**Out of scope** (each needs its own spec before it may be built)

- Any other first Z (2, 7, …) and gaps between pieces (Z = 1, 3). Both
  `c2patool` versions accept them (measured below), and no writer has been
  seen to produce them. See open question 1.
- Several stores in one JPEG, or pieces of different box instances
  interleaved: unchanged from SPEC-001.
- The EKU of the Microsoft signer, on which `c2patool` 0.28.0 calls the
  Bing files `Invalid`: step 112's divergence, recorded in
  `docs/comparison.md`.

## Behavior

- **AC1 — a single piece with Z = 0 is read**
  - Given `tests/Fixtures/writers/microsoft-20260609-bing-fast-heartbeat.jpg`
    (a Bing Image Creator file, single piece, Z = 0, public domain, from
    Commons; sha1 `cf21a619deb9e6d27da99c8ee36e3d81dd0d82b4`)
  - When the JPEG extractor runs
  - Then it returns the store: 13 081 bytes, equal to the piece's data
    after CI, En and Z. When the file is verified, the report carries no
    `general.error` *(amendment 1: `claimSignature.validated` moved to
    SPEC-042)*.

- **AC2 — two pieces numbered 0, 2 are read**
  - Given `fixture-signed.jpg` with its first piece's Z rewritten from 1
    to 0 (pieces 0, 2; the Z field lies inside the data hash's exclusion,
    so the binding still holds)
  - When verified
  - Then `Valid`, with the same status codes as `fixture-signed.jpg`, as in
    both `c2patool` versions.

- **AC3 — after a first Z = 0 the second piece still needs Z = 2**
  *(error path)*
  - Given `fixture-signed.jpg` with its pieces numbered 0, 1
  - When the JPEG extractor runs
  - Then `ContainerException` naming the expected (2) and found (1)
    sequence numbers, and no bytes. Both `c2patool` versions:
    `Error: invalid embedded file box`.

- **AC4 — any other first Z stays refused** *(error path)*
  - Given `fixture-signed.jpg` with its pieces numbered 7, 8, and the
    single-piece `public-testfiles/adobe-20220124-C.jpg` with Z = 7
  - When the JPEG extractor runs
  - Then `ContainerException` naming the found sequence number 7. This is
    a known difference: both `c2patool` versions read both files (open
    question 1). It is recorded in `docs/comparison.md`.

- **AC5 — SPEC-001 AC3 is unchanged**
  - Given the fixture with its two pieces swapped (Z = 2 before Z = 1)
  - When the JPEG extractor runs
  - Then the same `ContainerException` as today, *"expected 1, found 2"*.

## References

- Specification: C2PA 2.4 §A.3.1 "Embedding manifests into JPEG" (quoted
  in `notes/step-02-jpeg-fixture.md`). ISO/IEC 18477-3 and ISO/IEC
  19566-5:2023 D.2 are **not read** (paywalled). 19566-5's Annex D is
  informative according to its table of contents.
- Oracle: `c2patool` 0.27.22 and 0.28.0, `c2patool <file>` without
  settings. Measured 2026-09-25 on variants of `fixture-signed.jpg`
  (two pieces) and `public-testfiles/adobe-20220124-C.jpg` (one piece),
  with only the Z fields rewritten:

  | Z of the pieces | 0.27.22 | 0.28.0 | this verifier today |
  |---|---|---|---|
  | 1 (original, one piece) | `Valid` | `Valid` | `Valid` |
  | 0 (one piece) | `Valid` | `Valid` | `Invalid`, `general.error` |
  | 7 (one piece) | `Valid` | `Valid` | `Invalid`, `general.error` |
  | 1, 2 (original) | `Valid` | `Valid` | `Valid` |
  | 0, 2 | `Valid` | `Valid` | `Invalid`, `general.error` |
  | 0, 1 | error | error | `Invalid`, `general.error` |
  | 1, 1 | error | error | `Invalid`, `general.error` |
  | 2, 1 | error | error | `Invalid`, `general.error` |
  | 1, 3 | `Valid` | `Valid` | `Invalid`, `general.error` |
  | 7, 2 | `Valid` | `Valid` | `Invalid`, `general.error` |
  | 7, 8 | `Valid` | `Valid` | `Invalid`, `general.error` |

  The Bing files: step 141, nine files, both versions read them.
- Reasoned, from source:
  - `c2pa-rs` `sdk/src/asset_handlers/jpeg_io.rs` (`ada3e4a`) recognises
    the first piece by the C2PA superbox type and never reads its Z. A
    later piece with the same En passes when Z is larger than the number
    of pieces already taken. The table above matches that rule exactly.
  - `TrustNXT/c2pa-ts` (`src/asset/JPEG.ts`) requires Z = 1 on the first
    piece. `richardwooding/c2pa` (`c2pa.go`, `jpegJUMBF`) keeps the first
    piece's LBox and TBox only when Z = 1, so a Z = 0 store would be
    reassembled without them. Neither was run on a Bing file.

## API sketch

No public API changes. Inside `JpegManifestStoreExtractor::extract()`
(`@internal`), the sequence check becomes:

```php
// piece 1 may carry Z = 0 (Bing Image Creator; SPEC-041), every later piece Z = n
$expected = $pieceNumber;
if ($fields['z'] !== $expected && ! ($pieceNumber === 1 && $fields['z'] === 0)) {
    throw new ContainerException(/* as today */);
}
```

The error message stays as it is: SPEC-001 AC3's test pins it.

## Open questions

*Answered on approval, 2026-09-25:* question 1 narrow, as proposed;
questions 2 and 3 as proposed.

1. **Narrow or `c2pa-rs`'s rule?** The proposal accepts exactly what a
   writer has been seen to produce (a first Z = 0) and keeps SPEC-001's
   strictness everywhere else. The alternative is `c2pa-rs`'s rule: any
   first Z, and each later Z larger than the number of pieces taken. That
   makes the table above equal line for line. Neither choice can create a
   wrong `Valid`, because the store's bytes are signed and bound whatever
   Z says. The proposal leaves four synthetic cases (7; 1, 3; 7, 2; 7, 8)
   refused here and read by `c2patool`. The alternative would also change
   SPEC-001 AC3's message (a first Z = 2 would pass, and the error would
   come at the second piece), so it would need an amendment to SPEC-001.
   Proposal: narrow. *(blocker: decides AC4)*
2. **The fixture.** One Bing file, public domain, about 107 kB, in
   `tests/Fixtures/writers/` with a line in its README and its
   `c2patool` JSON in `../c2patool/writers/`, as the other writer files.
   Proposal: `Fast heartbeat.jpg`, the smallest. *(not a blocker)*
3. **The signer expires on 2026-10-01.** The Microsoft leaf is valid until
   2026-10-01 17:43:59 UTC. From then on, the file's verdict at *now*
   changes. AC1 therefore asserts on the extractor's bytes and on
   `claimSignature.validated` and the absence of `general.error`, not on
   `validation_state`. Proposal: as written. *(not a blocker)*

## Amendments

1. **2026-09-25, step 142b, measured.** With the first piece accepted, the
   Bing file is read, but its claim names its signature as
   `self#jumbf=c2pa/urn:uuid:…/c2pa.signature`: a path without a leading
   slash that still starts with `c2pa/` and the manifest label. This
   verifier reads a path without a slash as relative to the manifest,
   finds no `c2pa` box there, and reports `claimSignature.missing`. Both
   `c2patool` versions find the signature. All nine Bing files of step 141
   carry this form. It is a question of JUMBF URI resolution, not of the
   JPEG container, so AC1's `claimSignature.validated` moves to SPEC-042,
   and AC1 keeps what belongs to this spec: the store is read, and no
   `general.error`.

   Weight C: a criterion narrowed to its own concept, no behaviour
   changed.

   Confirmed by Maurice van Loon, 2026-09-25 (step 142).

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Container/FirstPieceSequenceTest.php :: AC1: a single piece with Z = 0 is read / SPEC-041 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$firstPieceZero`) |
| AC2 | tests/Unit/Container/FirstPieceSequenceTest.php :: AC2: two pieces numbered 0, 2 are read / SPEC-041 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$firstPieceZero`) |
| AC3 | tests/Unit/Container/FirstPieceSequenceTest.php :: AC3: after a first Z = 0 the second piece still needs Z = 2 / SPEC-041 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$firstPieceZero`) |
| AC4 | tests/Unit/Container/FirstPieceSequenceTest.php :: AC4: any other first Z stays refused / SPEC-041 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$firstPieceZero`) |
| AC5 | tests/Unit/Container/FirstPieceSequenceTest.php :: AC5: SPEC-001 AC3 is unchanged / SPEC-041 | src/Container/JpegManifestStoreExtractor.php :: extract() (`$firstPieceZero`); src/Container/ContainerException.php |
