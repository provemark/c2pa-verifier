# Step 27 — `DataHashCheck`: the asset hashed, ten tests red → green; M4 complete

*2026-09-21.* SPEC-012 implemented. The verifier now reads the *asset*:
every byte of the file except the exclusions goes through a hash, in
64 KiB chunks, and the result is compared with what the signed claim
vouches for. M4's "done when" is measured end to end — one changed pixel
byte gives `assertion.dataHash.mismatch`, the untouched file
`assertion.dataHash.match` — on all four fixtures and every variant.

## What was built

`src/Hash/DataHashCheck.php`, ~200 lines, second file of the `Hash` layer:

- **Exactly one hard binding**, counted as boxes in the assertion store
  (two boxes under one label are two): none →
  `claim.hardBindings.missing`, several → `assertion.multipleHardBindings`,
  both on the manifest's URI as c2patool records it (step 26); any other
  hard-binding label (`c2pa.hash.bmff*`, `c2pa.hash.boxes*`,
  `c2pa.hash.collection.data*`) → `general.error` naming M8.
- **Shape**, fail-closed: `exclusions` a list of maps of non-negative
  integers, at most `maxExclusions` (1024); `alg` text; `hash` bytes.
  Every fault is `assertion.dataHash.malformed` naming the field — except
  a missing `hash`, which §15.12.1 calls `.mismatch`.
- **Algorithm**: the assertion's `alg`, else the claim's; `sha256`,
  `sha384`, `sha512`; a digest of the wrong length is `.mismatch` naming
  both lengths.
- **Exclusions sorted**; an overlap → `.malformed`; a range past the
  file's end → `.mismatch`, before anything is read.
- **The store's exclusion**: `ManifestStoreBytes::$ranges` must be one
  range (the gap JPEG has two → `.mismatch` naming them), and exactly one
  exclusion must equal it (`===` on the shape) — otherwise `.mismatch`
  naming the store's range and the nearest exclusion, and the file is
  not hashed. Every other exclusion is honoured and reported once as the
  informational `assertion.dataHash.additionalExclusionsPresent`.
- **The hash**: `rewind()`, then `StreamReader::readExactly()` in chunks
  of `chunkSize` up to each exclusion, `skip()` over it, on to the end;
  `hash_equals()`. The explanation of a match says how many of the
  file's bytes were hashed (`313 of 100956` for the WebP, the pad byte
  among them); of a mismatch, both digests in hex.

Around it: `ManifestStoreBytes` gained `$ranges`, filled by the three
extractors (JPEG per APP11 piece `[offset, 2 + length]`, PNG
`[offset, 12 + store]`, WebP `[offset, 8 + store]`) and merged in the
value object's constructor when contiguous — SPEC-001/002/003 amendment;
`StatusCode` grew by six, with `isInformational()` real for the first
time; `ValidationResult::toArray()` puts failures only under
`validation_status`, as measured in step 26, and `Valid` now needs at
least one success as well as no failure — SPEC-010 amendment 2; Deptrac
lets `Hash` see `Container`.

## Measured

- Before: `vendor/bin/pest --group=SPEC-012` → `10 failed (3
  assertions)` — step 26b.
- First run of the implementation: `4 failed, 6 passed`. Three of the
  four were the tests' fault, not the code's — see below — and one was
  the code's: a report of one informational status alone came out
  `Valid`, because `fromStatuses()` only asked "any failure?". Now it
  asks "any success, and no failure?".
- After: `composer check` → Pint passed, PHPStan `[OK] No errors`,
  Deptrac `Violations 0`, Pest **`173 passed (1342 assertions)`** on the
  163 of SPEC-011 plus ten.
- AC9, the memory bound: a 48 MiB file (the PNG fixture plus 48 × 1 MiB
  written in chunks) hashed with the default 64 KiB `chunkSize`; peak
  memory grew by less than 4 MiB; 0.13 s.
- The oracle, c2patool 0.27.22's recorded JSON: `assertion.dataHash.match`
  with the assertion's absolute URI on all four fixtures;
  `assertion.dataHash.mismatch` on `pixel-changed`; the informational
  and the match on `exclusion-extra`; `assertion.dataHash.match` on
  `alg-sha384`; `assertion.multipleHardBindings` with the manifest's URI
  on `hard-bindings-two` — every one equal in code and url.

## Two things the tests taught

1. **Pest's `toContain()` is variadic.** `->toContain('exclusion',
   $variant)` does not attach a message; it looks for two needles, and
   the second was a file name. Three assertions failed on their own
   wording. The gotcha was already in this project's notes from step 22;
   it bit anyway. Fixed by dropping the second argument.
2. **AC1's pad-byte clause could not be true.** It said: flip the WebP
   pad byte in a copy, get `.mismatch`. SPEC-003 refuses a non-zero pad
   byte in the extractor (`pad byte at offset 100955 is 01, not 00`) —
   fail-closed one layer earlier, and correctly so. The clause now proves
   the same fact from the match's own numbers (313 bytes hashed, the
   store's range ending at 100,955) and asserts SPEC-003's refusal:
   SPEC-012 amendment 3.

## Divergences from c2patool, kept (all measured, all failures on both sides)

- `.malformed` for an overlap, for a text-string hash, for 1,025
  exclusions and for the three undecodable shapes, where c2patool says
  `.mismatch` or exits without a report.
- `algorithm.unsupported` for `sha1`, where it says `.mismatch`.
- The exact-range rule for the store's exclusion, where it takes the
  range literally and lets the hash decide — the same verdict on every
  variant, reached without reading the file.

## M4 complete

| "done when" | measured |
|---|---|
| one changed pixel byte → `assertion.dataHash.mismatch` | `pixel-changed.png`, `.jpg`; code and url equal to c2patool's (AC2) |
| untouched file `Valid` | four fixtures: `hashedURI.match` × 3–4 (SPEC-011) and `dataHash.match` (SPEC-012), each `ValidationResult` `Valid` (AC1 of both) |

What "Valid" still does not mean: the certificate chain reaches no
anchor (M5), and the timestamp is not read (M6). The `Verifier` layer,
which will run the checks in order and publish one verdict, is the next
spec after M5's trust work — or before it, if a partial verdict with
`checks_performed` is wanted earlier.
