# Step 525 — A test that holds the box-hash refusal

*2026-11-10.* Step 524 found that this verifier refuses a box hash
(`c2pa.hash.boxes`) by name, and that nothing tests it. SPEC-012
amendment 12 adds AC13 (Maurice: "akkoord, amendement 12 approved, bouw
525").

## What was built

- `bin/make-data-hash-variants.php`: one more variant,
  `hard-binding-boxes`, made like `hard-binding-bmff`. The `c2pa.hash.data`
  box and the claim's url for it are relabelled `c2pa.hash.boxes`, one byte
  longer each, and the data hash and the claim's hashed URI are recomputed.
  Running the script again left every earlier variant byte-identical
  (`git status` showed only the two new files).
- `tests/Unit/Hash/DataHashCheckTest.php`: AC13, in two halves.
  - `DataHashCheck` gives exactly one `general.error`, with url
    `…/c2pa.assertions/c2pa.hash.boxes`, naming the label.
  - `Verifier` gives `Invalid` with that failure, and gives neither
    `assertion.dataHash.match` nor `claim.hardBindings.missing`.
- `tests/Fixtures/binding/README.md`, SPEC-012 (amendment 12 approved and
  its build recorded; a Traceability row), `docs/comparison.md` (the row
  from step 524 now names the test).

## Measured

- `c2patool` 0.28.6 and 0.28.3 on the variant: no report, `Error: could
  not decode assertion c2pa.hash.boxes … missing field boxes`.
  `c2pa-rs` reads the label as a box hash and refuses its data-hash
  content.
- `bin/c2pa-verify`: `Invalid`, with `claimSignature.mismatch`,
  `signingCredential.untrusted` and `general.error`. The signature is
  broken, as in every variant made by rewriting.
- Red, then green. The first run failed with `cannot open
  binding/hard-binding-boxes.png`, because the test came before the
  fixture. That red only proves the order. The refusal already exists, so
  the real red came from a mutation: `c2pa.hash.boxes` taken out of
  `HardBindings::FAMILIES`. The first half then failed (`general.error`
  expected, `claim.hardBindings.missing` found), and `bin/c2pa-verify`
  showed the second half would too (`claim.hardBindings.missing` in place
  of `general.error`). After the file was restored, `git diff src/` was
  empty and the test was green again (1 passed, 7 assertions).

## What it does not change

No verdict, no code, no contract. Nothing for CHANGELOG.
