# SPEC-068: A data hash that covers no byte of the file is a mismatch

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-13                      |
| Supersedes | SPEC-012 AC4 in one case: additional exclusions that leave nothing to hash are no longer "honoured as signed" |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A `c2pa.hash.data` assertion binds a manifest to its asset by a hash over
the file minus its `exclusions` (C2PA 2.4 §15.12.1, §18.5). SPEC-012
amendment 7 makes the exclusion holding the manifest store hold the store
and nothing else (§15.12.1.1, VAL-ASSE-0043/0044). Every *other* exclusion
is honoured as signed and reported with the informational
`assertion.dataHash.additionalExclusionsPresent` (VAL-ASSE-0045, SPEC-012
AC4).

D. Buchanan, "How to Hack Time, With C2PA" (2 October 2026), signs one
exclusion over the whole file. The hash then covers nothing, so the image
can change under a valid signature and a valid timestamp. Amendment 7
already refuses that exact shape. Step 349 measured that the same effect
survives in three ranges: `[0, store start]`, the store, and
`[store end, file end]`. `tests/Fixtures/exclusion-coverage/all-but-store-pixel-edited.png`
has one image byte changed after signing and is `Trusted` here, in
`c2patool` 0.28.1 and in `c2patool` 0.28.2. It is a wrong `Trusted`, the
one risk this project puts first.

A hash over zero bytes binds the manifest to nothing. It is the SHA-256
of the empty string whatever the file holds. The article calls this case
"trivial to detect". This spec detects it.

This is a floor, not the fix. A signer who leaves one byte unexcluded gets
past it. SPEC-069 is the fix, and this spec stays as the plain answer for
the plainest case, with an explanation a reader can act on.

## Scope

**In scope**

- `c2pa.hash.data` (and its instances, SPEC-012 amendment 9) in every
  format that uses it: JPEG, PNG, WebP, GIF, WAV, AVI, MP3, FLAC and plain
  text.
- Update manifests: the check runs after the store exclusion is adjusted
  (SPEC-012's `adjustForUpdate`), on the ranges actually skipped.

**Out of scope** (each needs its own spec before it may be built)

- Exclusions that leave *some* bytes: SPEC-069.
- BMFF hashes (`c2pa.hash.bmff.v2`/`v3`), whose exclusions are box paths
  (an excluded `mdat`). Not measured.
- Sidecar and remote manifests: there is no store in the file, and an
  asset whose exclusions cover all of it is still caught by AC1. No other
  case is added.

## Behavior

- **AC1 — every byte excluded is a mismatch** *(required: error path)*
  - Given `tests/Fixtures/exclusion-coverage/all-but-store.png` and
    `all-but-store-pixel-edited.png` (exclusions `[0, 33]`, the store,
    `[store end, 1666]`; 0 of 47,773 bytes hashed)
  - When verified, with and without `throw-away-root.settings.json`
  - Then `c2pa.hash.data` gives exactly one status,
    `assertion.dataHash.mismatch`, whose explanation says the exclusions
    leave no byte of the file to hash and names the file's length and the
    exclusions. The file is not hashed. `additionalExclusionsPresent` is
    not emitted. The verdict is `Invalid`.

- **AC2 — the article's own shape keeps amendment 7's answer**
  - Given `exclusion-coverage/whole-file.png` (one exclusion `[0, 47735]`)
  - When verified
  - Then the answer is unchanged: `assertion.dataHash.mismatch` with
    amendment 7's explanation (the store's range holds 33 bytes before the
    store and 1,666 after it). The amendment 7 check runs first.

- **AC3 — one byte left is not this spec's case**
  - Given a variant of `all-but-store.png` whose last exclusion stops one
    byte short of the end of the file (new fixture,
    `exclusion-coverage/all-but-one-byte.png`, made by the same script)
  - When verified
  - Then this spec does not refuse it: `assertion.dataHash.match` plus the
    informational, as today. The test shows where the floor is. SPEC-069
    answers this file.

- **AC4 — nothing else moves**
  - Given the drift-alarm corpora (`SPEC013_CORPUS`, `SPEC013_PUBLIC_CORPUS`,
    `SPEC013_RS_CORPUS`, `SPEC013_WRITERS_CORPUS`) and every fixture outside
    `exclusion-coverage/`
  - When verified before and after the change
  - Then no state and no status code changes. Step 349's scan of 708
    fixture files found 393 `dataHash.match` statuses. The fewest bytes
    hashed was 51 (`text/nfd-emoji-signed.txt`). No file hashed zero bytes.

## References

- Specification: C2PA 2.4 §15.12.1 and §15.12.1.1 (exclusions and the
  store's range), §18.5 (the assertion), VAL-ASSE-0043/0044/0045 as cited
  in SPEC-012.
- Oracle: `c2patool` 0.28.1 and 0.28.2 under
  `tests/Fixtures/c2patool/exclusion-coverage/`: `Trusted` with
  `assertion.dataHash.match` and the informational for both `all-but-store`
  files. **This spec diverges from them deliberately**, as amendment 7
  does. The divergence goes into `docs/comparison.md`.
- Measured (step 349): the table in `notes/step-349-exclusion-coverage.md`,
  and the scan over 708 fixtures (fewest bytes hashed: 51).
- Reasoned: that no legitimate signer excludes the whole file. No measured
  file does, and a binding over nothing binds nothing.

## API sketch

```php
// src/Hash/DataHashCheck.php, inside check(), after the store-exclusion
// checks and before hashExcept():
$hashed = $end - array_sum(array_column($exclusions, 'length'));
if ($hashed === 0) {
    return [new ValidationStatus(StatusCode::AssertionDataHashMismatch, $url, sprintf(
        'the exclusions leave no byte of the %d-byte file to hash: %s; a data hash over nothing binds nothing',
        $end, /* the ranges */))];
}
```

No new class, no new status code. The ranges are already sorted,
non-overlapping and in bounds at that point, so the sum is exact.

## Open questions

- None blocking. SPEC-069 subsumes this spec for every format it covers.
  It is kept anyway: it can ship first, as a single line, and it keeps
  the plainest case's explanation plain.
  *Status 2026-10-13 (step 351):* settled. Maurice approved this spec on
  its own, ahead of SPEC-069.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Hash/DataHashCoversNothingTest.php :: reports a data hash whose exclusions cover every byte as a mismatch, without hashing (2 files × 2 modes) / SPEC-068 | src/Hash/DataHashCheck.php :: check() (the zero-byte check, after the store-exclusion checks) |
| AC2 | tests/Unit/Hash/DataHashCoversNothingTest.php :: keeps amendment 7's answer for one exclusion over the whole file / SPEC-068 | src/Hash/DataHashCheck.php :: check() (the order: amendment 7 first) |
| AC3 | tests/Unit/Hash/DataHashCoversNothingTest.php :: does not refuse a data hash that leaves one byte: the floor / SPEC-068 | src/Hash/DataHashCheck.php :: check(); bin/make-exclusion-coverage-variants.php (`all-but-one-byte`) |
| AC4 | the corpus before and after (notes/step-351-data-hash-covers-nothing.md); every existing test unchanged | — |

The AC1 tests were run red first (4 failed: two statuses, `match` and the
informational, where one `mismatch` was expected). AC2 and AC3 pin
behaviour that was already there, and were green from the start. Two
mutations were watched failing: the bound at `<= 1` (AC3 fails) and the
check moved before amendment 7's (AC2 fails). `composer check` green.

Step 352 (SPEC-069): the verifier now refuses `all-but-one-byte.png`
through SPEC-069. AC3's test checks this spec alone, with an allowance
that lets every range stand (`match`, one byte hashed), and that the
verifier's refusal carries SPEC-069's explanation, not this one's.
