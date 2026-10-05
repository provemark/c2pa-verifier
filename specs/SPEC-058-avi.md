# SPEC-058: AVI — RIFF `C2PA` → manifest store bytes, verified

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

AVI is the third RIFF form C2PA 2.4 §A.3.7 names, beside WAV and WebP: the
store is the data of a `C2PA` chunk, *"the last sub-chunk of the first RIFF
header chunk"*. Step 209 (`notes/step-209-avi-measured.md`) signed
`tests/Fixtures/fixture-signed.avi` with `c2patool` 0.27.22 and measured 16
variants with 0.27.22 and 0.28.1, including the one thing AVI has that WAV
and WebP do not: an OpenDML file over about 1 GB is several RIFF chunks in a
row, `AVI ` then `AVIX`. `c2patool` puts the store at the end of the first
RIFF chunk and calls such a file `Valid`; it reads only the first chunk and
hashes the rest.

Step 209 left one question open: what follows the first RIFF chunk. SPEC-003
amendments 3 and 4 answered it for every RIFF form since: the walk ends
where the first RIFF chunk ends, and what follows is left to the data hash.
A probe in step 239 ran the existing RIFF walk with the form `AVI ` over
every AVI fixture: each gives what `c2patool` gives, or the named stricter
answer SPEC-003 and SPEC-055 already give. So this spec, like SPEC-055,
adds a form, not a rule.

## Scope

**In scope**

- `RiffManifestStoreExtractor` with the form `AVI ` and the name `AVI`, as
  WAV's; a `WavManifestStoreExtractor`-like class for AVI.
- Detection: `RIFF` with the form type `AVI ` is `avi`.
- The verifier route; the unknown-format message names AVI; the article in
  the walk's messages follows the name ("an AVI").

**Out of scope**

- Checking the AVI structure (`hdrl`, `movi`, `idx1`, OpenDML indexes): the
  data hash covers it; `c2patool` does not check it.
- RF64 and every other RIFF form: still `unknown`.

## Behavior

Variants are under `tests/Fixtures/avi/` (`bin/make-avi-variants.php`,
step 209); every `c2patool` answer is in that directory's README.

- **AC1 — the fixture yields the store, byte-exact, with its range**
  - Given `tests/Fixtures/fixture-signed.avi`
  - Then 13,463 bytes, SHA-256 beginning `83b33b30ffa81b3c` (the full value
    in the test, from step 209), first bytes `00 00 34 97 6a 75 6d 62`, one
    range `[11700, 13471]`

- **AC2 — no `C2PA` chunk in the first RIFF chunk is no manifest**
  - Given `fixture-unsigned.avi`, `avi/unsigned-avix.avi`,
    `avi/c2pa-in-movi.avi`, `avi/c2pa-only-in-avix.avi`
  - Then `null`; verified, `avi`, `hasManifest` false, no failure
    (*No claim found* in both versions)

- **AC3 — a file of several RIFF chunks verifies as `c2patool` says**
  - Given `avi/signed-avix.avi` (`AVI ` then `AVIX`, signed by `c2patool`)
  - Then `avi`, `Valid` without settings, state and sorted codes equal to
    `c2patool` 0.27.22's and 0.28.1's (recorded in step 239)
  - And given `avix-byte-flipped`, `avix-truncated`, `avix-size-plus-one`,
    `avix-trailing-bytes`, `second-form-avi`, `avix-with-c2pa`: each is
    `Invalid` with `claimSignature.validated` and
    `assertion.dataHash.mismatch`, as both versions say

- **AC4 — the rules SPEC-003 and SPEC-055 already hold** *(stricter than
  the oracle where marked, as there)*
  - Given `two-c2pa` (stricter), `lbox-differs` (stricter: `Valid` in both
    versions), `length-differs`, `pad-nonzero` (stricter),
    `riff-size-plus-one`, `truncated-in-c2pa`
  - Then each is `avi`, `hasManifest` true, `Invalid`, one `general.error`;
    and `c2pa-before-idx1` and `trailing-bytes` are read and fail the data
    hash, as both versions say

- **AC5 — detection and the signed fixture**
  - Given the fixture, without settings and with
    `trust/full.settings.json`
  - Then `avi`, `Valid` and `Trusted`, code for code with `c2patool`
    0.27.22's and 0.28.1's recordings; one byte of `movi` flipped is
    `Invalid` with `assertion.dataHash.mismatch`; WAV and WebP are still
    `wav` and `webp`; the unknown-format message names AVI; a two-C2PA
    message reads "an AVI"

## References

- Specification: C2PA 2.4 §A.3.7.
- Oracle: `c2patool` 0.27.22 and 0.28.1; step 209's fixtures and answers;
  the probe of step 239.
- Reasoned: none beyond SPEC-003 and SPEC-055.

## API sketch

```php
/** @internal SPEC-058 */
final readonly class AviManifestStoreExtractor   // as WavManifestStoreExtractor, with 'AVI ' and 'AVI'
```

`FormatDetector` returns `'avi'`; `Verifier` gains the extractor as its last
constructor parameter.

## Open questions

1. **Amendments this forces** (named now): SPEC-013 (`format` `avi`, the
   message, the constructor), SPEC-024 (the AVI bound in AC1's list).
   Non-blocker.

## Amendments

None.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/AviTest.php :: AC1: the fixture yields the store, byte-exact, with its range / SPEC-058 | src/Container/AviManifestStoreExtractor.php :: extract(); src/Container/RiffManifestStoreExtractor.php :: extract() |
| AC2 | tests/Unit/Verifier/AviTest.php :: AC2: no C2PA chunk in the first RIFF chunk is no manifest (four datasets) / SPEC-058 | src/Container/RiffManifestStoreExtractor.php :: walk() |
| AC3 | tests/Unit/Verifier/AviTest.php :: AC3: a file of several RIFF chunks verifies as c2patool says (two datasets); AC3: a change after the first RIFF chunk fails the data hash, as c2patool says (six datasets) / SPEC-058 | src/Container/RiffManifestStoreExtractor.php :: extract() (the walk ends at the first RIFF chunk's end) |
| AC4 | tests/Unit/Verifier/AviTest.php :: AC4: the rules SPEC-003 and SPEC-055 already hold (six datasets); AC4: a C2PA chunk not last, and bytes after the RIFF chunk, are judged by the data hash (two datasets) / SPEC-058 | src/Container/RiffManifestStoreExtractor.php :: walk(), readPad() |
| AC5 | tests/Unit/Verifier/AviTest.php :: AC5: the signed fixture verifies as c2patool 0.27.22 and 0.28.1 say (four datasets); AC5: detection, a flipped movi byte, the message and its article / SPEC-058 | src/Container/FormatDetector.php :: detect(); src/Verifier/Verifier.php :: __construct() (`$avi`), verify(); src/Container/RiffManifestStoreExtractor.php :: walk() (the article) |
