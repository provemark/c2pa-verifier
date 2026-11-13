# Step 533 — SPEC-090 built: the general box hash for JPEG

*2026-11-13.* Maurice:
- "ga verder met de code";
- SPEC-090 amendment 1 (AC12's foreign APP11 is no `JP` piece);
- SPEC-041 amendment 5 and SPEC-042 amendment 1, agreed when the
  conflicts came up.

## What changed

- **`src/Hash/JpegBoxMap.php`.** The JPEG's boxes in file order:
  - one per marker segment, named by its T.81 Table B.1 symbol;
  - `SOS` and each `RSTn` with the entropy-coded data after them, up to
    the next marker that is not a stuffed `FF00`;
  - one `C2PA` box per contiguous run of the store's APP11 pieces (the
    extractor's ranges);
  - `c2pa.after` for bytes after `EOI`.

  The walk is bounded: 1,024 segments before the first scan (SPEC-069's
  bound), 65,536 boxes in all. Fill bytes and unknown markers end the
  walk.
- **`src/Hash/AssetBox.php`.** One box: its name, start and length.
- **`src/Hash/BoxHashCheck.php`.** Checks in this order:
  1. The structure → `malformed`.
  2. The `alg` chain of every map that is hashed → `algorithm.unsupported`.
  3. Bytes after EOI → `unknownBox`, listed or not (open question 4).
  4. A name the asset holds more often than the list → `unknownBox`.
  5. Any other position difference → `mismatch`.
  6. Each map's hash over its range with its exclusions left out.
     - An exclusion past its box → `mismatch`.
     - An exclusion outside SPEC-069's APP1 Exif/XMP payloads →
       `mismatch`.
     - Exclusions out of order → `malformed`.
     - A hash that differs → `mismatch`.

  `excluded: true` skips a box. An allowed exclusion or an excluded box
  adds `additionalExclusionsPresent`.
- **`src/Report/StatusCode.php`.** The five `assertion.boxesHash.*` codes
  (217 contract symbols).
- **`src/Verifier/Verifier.php`.**
  - A JPEG whose hard binding is `c2pa.hash.boxes` goes to `BoxHashCheck`.
  - `checks_performed` says `boxHash`.
  - The new check is the constructor's last parameter.
- **`src/Hash/DataHashCheck.php`.** The refusal in other containers now
  names JPEG as the one where the box hash is verified.
- **Fixtures.** `bin/make-spec090-variants.php` writes two kinds:
  - five `bing-*` variants: asset bytes changed, store untouched;
  - fourteen `written-*` variants, built from `fixture-signed.jpg` with an
    APP1 Exif segment added. The `c2pa.hash.data` assertion is replaced by
    a box hash and the claim re-signed under a throw-away P-256 root. No
    padding is needed, because the `C2PA` box is not hashed.

  Both `c2patool` answers are under `tests/Fixtures/c2patool/box-hash/`.
- **Tests.** `tests/Unit/Hash/BoxHashCheckTest.php`, AC1–AC14.
  - The drift alarms grow by five codes.
  - SPEC-041 amendment 5: AC1 expects no `general.error` at all.
  - SPEC-042 amendment 1: AC1 expects `Valid` with `boxesHash.match`, and
    the AC7 guard lists the five Bing variants.
- **Docs.**
  - `docs/conformance.md` `PRED-CONT-006`.
  - `docs/comparison.md`: the *can do more* row is narrowed to other
    containers, and eight *by design* rows are added:
    - out of order;
    - a listed `c2pa.after`;
    - `C2PA` sharing a map;
    - the `c2patool` aborts and `pad`;
    - a `JP` piece of another box;
    - APP13/`COM` exclusions;
    - restart markers;
    - `redacted_assertions` spelling.
  - `docs/reading-c2pa-2.4.md`: §5.1, §9.2.2, C.1, §15.12.3, §18 intro,
    §18.7, and the status-code inventory.
  - ADR-0005's consequence.

## Measured

### Oracles

`c2patool` 0.28.6 and 0.27.22 on every variant (settings with the
throw-away root for `written-*`):

| variant | 0.28.6 | 0.27.22 | here |
|---|---|---|---|
| `bing-pixel-changed` | `mismatch` | `mismatch` | `mismatch` |
| `bing-unlisted-com` | `unknownBox` | `mismatch` | `unknownBox` |
| `bing-after-eoi` | `unknownBox` | `Valid` | `unknownBox` |
| `bing-out-of-order` | `unknownBox` | `mismatch` | `mismatch` (the letter) |
| `bing-foreign-app11` | `unknownBox` | `mismatch` | `unknownBox` |
| `written` | `Trusted` | `Trusted` | `Trusted` |
| `written-exif-exclusion` | `Trusted` (+ informational) | `mismatch` | `Trusted` (+ informational) |
| `written-excluded` | `Trusted` (+ informational) | `Trusted` | `Trusted` (+ informational) |
| `written-dqt-exclusion`, `-past-end-exclusion` | `mismatch` | `mismatch` | `mismatch` |
| `written-empty-exclusions` | `malformed` | `Trusted` | `malformed` |
| `written-box-index-beyond`, `-overlapping-exclusions` | `malformed` | `mismatch` | `malformed` |
| `written-c2pa-shared` | `mismatch` | `mismatch` | `malformed` |
| `written-no-boxes`, `-no-names`, `-no-pad` | abort: *missing field* | abort | `malformed`; `no-pad` `Trusted` |
| `written-no-alg` | abort: *unknown algorithm* | abort | `algorithm.unsupported` |
| `written-listed-after` | `Trusted` | `mismatch` | `unknownBox` |

Three facts came from building the variants:

- **`c2pa-rs` requires `pad` in every box-hash-map**, although the CDDL
  makes it optional: *"missing field `pad`"*, and the manifest is not
  loaded. Every written map now carries an empty one; `written-no-pad`
  records the refusal.
- **`c2pa-rs` cannot parse an APP11 of fewer than 28 bytes**: *"Invalid
  JPEG CAI JUMBF block"*, reported as `mismatch`. The foreign segment is
  longer.
- **This verifier's JPEG extractor refuses a `JP` piece with another En**
  (SPEC-001 AC11), so a JPEG 360-style piece never reaches the box hash.
  This is SPEC-090 amendment 1.

### Red before green

Before `src/` changed:
- 18 of the 20 SPEC-090 tests were red;
- the AC7 oracle record and the AC14 guard were green from the start.

AC8 was green at first. The claim without `alg` already fails its
hashed URIs, so the test now asserts the box hash's own refusal
(explanation naming the box-map, and no `general.error`). AC11 was first
written over two PNGs. `hard-binding-redacted/hash-boxes-relative.png`
never reaches a binding check (it fails on the redacted hard binding),
so it was dropped; the spec names one PNG.

One defect was found by the tests: `StreamReader::readExactly()` reads
from where the stream stands, and its offset only names the place in an
error. `JpegBoxMap` and `BoxHashCheck::hash()` now seek first.

After the change:
- SPEC-090 20/20 green;
- the suite showed seven other reds:
  - four drift alarms (contract size, enum size, and two success/
    informational lists) grew by the five codes;
  - SPEC-041 AC1 and SPEC-042 AC1/AC7 were amended as above.

### Mutations

Each was run against SPEC-090, SPEC-041, SPEC-042 and SPEC-012, and each
was caught:

| mutation | red |
|---|---|
| M1: `SOS` without its entropy-coded data | 9 |
| M2: no per-name count (no `unknownBox`) | AC3, AC12 |
| M3: a listed `c2pa.after` accepted | AC4 |
| M4: an exclusion allowed anywhere | AC9 |
| M5: `excluded: true` ignored | AC10 |
| M6: the hash never compared | AC2 |
| M7: the box hash routed in every container | SPEC-090 AC11, SPEC-012 AC13 |
| M8: overlapping exclusions accepted | AC7 |
| M9: no `alg` falls through | AC8 |
| M10: out of order reported as `unknownBox` | AC5 |

A first run reported every mutation green: `pest --group=A,B` finds no
tests. Pass `--group` once per group.

### Corpus, before and after

Both sides ran:
- the worktree at `e4c2f3f`, with `vendor/` copied (each side loaded its
  own `src/`, checked);
- this tree.

Each read the current `tests/Fixtures/`:
- 1,097 files;
- three trust configurations, each without and with a revocation source
  answering null;
- validation time 2026-11-14T00:00:00Z, after the throw-away
  certificates.

6,582 runs per side, 0 exceptions.

- **Output** differs in 126 runs, 21 files, six runs each:
  - the 19 new variants;
  - the Bing file;
  - `binding/hard-binding-boxes.png` (the refusal's new text only).
- **Verdicts** move only for four written variants, `Invalid` → `Valid`
  in all six configurations: `written`, `written-exif-exclusion`,
  `written-excluded`, `written-no-pad`. Without the throw-away root they
  are untrusted.
- **The Bing file stays `Invalid` at that time**, because its leaf
  expired on 2026-10-01. At 2026-07-01 it is `Valid` (SPEC-090 AC1).

### Status-code inventory

The 2.4 CDDL `$status-code` list (113 codes) against the enum (77) with
`comm`: 39 not held. The inventory in `docs/reading-c2pa-2.4.md` said 45
and still listed `hashedURI.missing`, which the enum holds since
SPEC-034 amendment 3. Both are corrected.

### The rest of `composer check`

Exit 0:
- spec-check: 91 specs, SPEC-090 implemented;
- api-check: 217 symbols;
- pint;
- phpstan: no errors;
- deptrac: 0 errors;
- pest.

`pest --parallel`: 1,746 passed, 1 skipped.

## Not covered

- **Restart markers.** Here each `RSTn` is a box, as §18.7.3.1's example
  lists them. `c2pa-rs` treats them as entropy data inside `SOS`. No
  box-hash file with restart markers has been seen. Recorded in
  `docs/comparison.md`, not measured.
- **The release.** Five contract symbols make it a minor (`0.11.0`), per
  open question 3. Nothing is tagged.
