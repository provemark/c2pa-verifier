# Step 22 — The Report layer (SPEC-010 implemented); M3 complete

*2026-09-21. Oracle: c2patool 0.27.22's JSON for the four fixtures (step
14) and three variants (step 21); the sister library's parser.*

## What was built

`src/Report/` — the first layer that speaks C2PA:

- `StatusCode`, a string-backed enum of the twelve §15.2.2 codes this
  verifier can emit, verbatim; `isSuccess()` (only
  `claimSignature.validated`), `isInformational()` (none yet),
  `isFailure()` (the rest).
- `ValidationStatus` — code, the absolute JUMBF URI of the box, and our
  own explanation.
- `ValidationState` — `Valid`, `Invalid`.
- `ValidationResult::fromStatuses(statuses, checksPerformed)` — `Valid`
  only when there is at least one status and no failure; `toArray()` in
  c2patool's shape (`validation_status` = failures + informational,
  `validation_results.activeManifest.{success, informational, failure}`,
  `validation_state`) plus `checks_performed`.

`src/Cose/ClaimSignatureCheck` — `check(Manifest)` and
`checkBytes(signature, claim, url)`: parse (SPEC-008), verify (SPEC-009),
one status.

And the two amendments SPEC-010 defined: `ManifestException` and
`CoseException` now carry a `StatusCode`, set at every throw site per the
spec's table — 24 throw sites in the Manifest layer, 20 in the Cose
layer; the structural ones stay `general.error` by design.

## Measured

- Red: 10 tests on the missing classes (`a00c969`).
- First run: **6 passed, 4 failed** — all four in the test file. Three
  were one Pest idiom: `toContain()` takes *several needles*, not a
  needle and a message, so `->toContain($message, $name)` had also looked
  for the variant's name. The fourth: `mismatch` sorts before `missing`
  (`mism` < `miss`); the expected list had them the other way round.
- PHPStan: 21 findings, all in the test file — the oracle JSON is
  `mixed` and the tests now narrow it with asserts that are themselves
  checks on the recorded files; one `0` still passed where a `StatusCode`
  was expected in `CoseSign1::decode()`. Then `composer check` → exit 0:
  spec-check `OK: 11 spec(s), 11 test file(s)`, Pint passed, PHPStan `No
  errors`, Deptrac 0 violations (`Manifest` and `Cose` see `Report`,
  `Cose` sees `Manifest` — arrows that existed unused since M0), Pest
  **153 passed (938 assertions)**.

## M3's "done when", measured end to end

For each of the four fixtures the check returns exactly one status,
`claimSignature.validated`, with the same code and url as the entry in
c2patool's `validation_results.activeManifest.success` (AC1). For the
two one-byte variants it returns `claimSignature.mismatch` with the same
url as c2patool's `validation_status` entry (AC2). The sister library
reads our `toArray()` merged into SPEC-007's store array and gives
`validationStatusCodes()` `[]` / `['claimSignature.mismatch']` and
`validationState()` `Valid` / `Invalid` (AC9). `docs/milestones.md`
marks M3 done.

## What the verifier still must not say

`Valid` from `ValidationResult` means: no failure among the checks that
ran, and the only check that runs is the signature. No hash binding
(M4), no chain to an anchor (M5), no validity window (M6). The
`checks_performed` key is in every `toArray()` so that nothing
downstream can mistake this for a verdict; the `Verifier` layer's spec
is where a verdict is first allowed to leave the library.

## Reasoned, not measured

- c2patool's `signingCredential.untrusted` + `Valid` (step 14's PNG
  JSON): "untrusted" is not "invalid" in c2pa-rs's state machine. Kept
  for M5, where `ValidationState` will need that nuance.
- The structural COSE faults as `general.error`: §15 has no
  "claimSignature.malformed"; the maintainer chose the honest default
  over stretching `signingCredential.invalid`.
