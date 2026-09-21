# Step 36 — The official test files: 26 JPEGs from `c2pa-org/public-testfiles`, and what they found on first contact

*2026-09-21.* Maurice asked whether what stands can be tested with
fixtures found online. The C2PA's own `public-testfiles` repository
(CC BY-SA 4.0; commit `22beccc07570`, 2025-12-05) holds, under
`legacy/1.4/image/jpeg/`, 26 JPEGs with named expectations — Adobe's
2022 set of created/action/ingredient combinations and deliberate
faults, one Nikon and three Truepic camera files. Its `2.2/` tree is
empty placeholders (READMEs and `.gitkeep`). All 26 were run through
`Verifier::verify()` with the full test settings and through c2patool
0.27.22 with the same settings, side by side. No verifier code changed
in this step; the two findings below are the next two.

## Measured: 26 files, 20 states equal

| kind | files | ours | c2patool |
|---|---|---|---|
| a valid Adobe file, one manifest | `C`, `CA`, `CAI`, `CI`, `CII` | `Trusted` | `Trusted` |
| a valid Adobe file, several manifests | `CACA`, `CACAICAICICA` (4), `CAIAIIICAICIICAIICICA` (6), `CAICA`, `CAICAI`, `CICA`, `CICACACA`, `CIE-sig-CA` | `Trusted` | `Trusted` |
| a deliberate fault in the active manifest | `E-sig-CA`, `E-dat-CA`, `E-uri-CA`, `XCA`, `XCI` | `Invalid`, the same code | `Invalid` — `claimSignature.mismatch`, `assertion.dataHash.mismatch` ×3, `assertion.hashedURI.mismatch` |
| a fault in a *referenced* claim | `E-clm-CAICAI` | `Invalid`, `assertion.hashedURI.mismatch` | `Invalid`, that plus `ingredient.manifest.missing` (M7) |
| no manifest | `A`, `I` | `Invalid`, `hasManifest` false | `Error: No claim found` |
| **a fault only in an ingredient manifest** | `E-uri-CIE-sig-CA` | **`Trusted`** | `Invalid`, `assertion.hashedURI.mismatch` |
| **camera files** | `nikon-20221019-building`, `truepic-20230212-{camera,landscape,library}` | **`general.error`** at parse | `Invalid` (Nikon: `signingCredential.expired`, `.untrusted`); `Valid` ×3 (Truepic: `.untrusted`) |

Thirteen `Trusted` and five `Invalid` verdicts agree code for code on
files this project never saw — written by Adobe's tooling in 2022,
claim v1, PS256, with up to six manifests in one store. That is what
the drift alarm was built for, and it held.

## Finding 1: floats — SPEC-006 was too strict for the world

`nikon-20221019-building.jpeg`: `assertion stds.exif: invalid CBOR:
float at offset 471 is not supported`. The three Truepic files:
`assertion com.truepic.custom.odometry: invalid CBOR: float at offset 20
is not supported`. SPEC-006 refuses CBOR major type 7 with additional
information 25/26/27 (half, single, double floats) — fail-closed by
design, with the sentence that foresaw this: *"If a real file ever needs
floats, that is a fixture and an amendment."* GPS coordinates and
odometry are floats, and camera manufacturers write them. Decoding them
touches no verification (every hash is over bytes), so the amendment
is safe: SPEC-006 amendment 2, step 37, these four files as the fixture.

## Finding 2: an ingredient's fault is invisible — fail closed until M7

`E-uri-CIE-sig-CA`: the active manifest is intact; the tampered
assertion is in the ingredient's manifest, which this verifier does not
validate (SPEC-013 Scope: "today only the active manifest is checked").
So it says `Trusted` where c2patool says `Invalid` — the one direction
the brief calls the only risk that counts. Two ways out were put to
Maurice: fail closed now (a store with more than one manifest is
`Invalid` with a `general.error` until M7 validates ingredients; eight
correctly-`Trusted` files of this set become `Invalid` for the interim)
or leave it with `checks_performed` as the warning. **He chose to fail
closed** ("optie 1: fail closed tot M7"). SPEC-013 amendment 5, step 38.

## What this settles

- The official corpus is in `tests/Fixtures/public-testfiles/` (26
  files, 20 MB — the three Truepic files are 10 MB of it) and its
  oracle in `tests/Fixtures/c2patool/public-testfiles/` (24 JSONs).
- After steps 37 and 38 the drift alarm grows from 34 to 60 files, and
  the expectation per file is c2patool's state except where the
  multi-manifest rule makes it `Invalid` on purpose — named per file.
- M6 (the timestamp) waits until then. The Nikon file, once readable,
  is a second timestamped fixture (`signingCredential.expired` at
  c2patool: a certificate that has expired since, with a timestamp that
  M6 will consult).
