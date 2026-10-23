# Step 379 — SPEC-077: the two trust lists as input, built

*2026-10-23.* Approved unchanged and built tests-first. With it the
harness the conformance programme asks for is one command:
`bin/c2pa-verify --crjson --trust-list <pem> --tsa-trust-list <pem> --at
<RFC 3339> <asset>`, written down in `docs/conformance-harness.md`.

## What changed

- `TrustSettings::fromTrustLists(?string $signers, ?string $tsas)`: one
  `"manifest"` and one `"tsa"` entry; a list without a certificate is a
  `TrustException`; two nulls too.
- `--trust-list` and `--tsa-trust-list` on the command line, exclusive
  with `--settings`; every fault is exit 2 with the usage.
- The official lists at `conformance-public` `da71a72` as fixtures
  (`tests/Fixtures/trust/conformance-public/`, CC BY 4.0, with their
  SHA-256s).
- `docs/conformance-harness.md`.
- The contract: 139 → 140 symbols.

## Measured

- Red: 7 of 15 first; the eight refusals passed for the wrong reason
  (unknown options exit 2 already) and were tightened to their own
  messages; then 15 of 15 red.
- Green: 15 of 15. Mutations, each failing: the kinds swapped; both
  lists as signer anchors; an empty list accepted; `--settings` with a
  list let through.
- AC1 stays green when the kinds are swapped: the official lists share
  16 of their anchors, as measured in step 378. `control.png`'s
  throw-away roots carry the separation (AC2, AC3).
- `composer check`: 1,209 passed, 1 skipped. The library's default path
  did not change (a new static method, two new options), so no corpus
  run was made.
