# Step 485 — Only CBOR that is not valid is `assertion.cbor.invalid`

*2026-10-27.* Finding 3 of the review of step 482, fixed before 0.9.0 is
tagged: SPEC-088 amendment 1, approved by Maurice. No contract symbol
changes, no verdict changes.

## Why

SPEC-088 (step 481) made every fault of the CBOR decoder in an assertion
`assertion.cbor.invalid`, the limits included. But a limit of this decoder
(items, depth, the store-wide budget of SPEC-043) or an item outside the
subset it reads (an integer or tag above 2^63 − 1, the simple value
`undefined`, a map key that is not an integer or text) says nothing about
the file: the CBOR may be perfectly valid, and only this verifier stops.
C2PA 2.4 §15.10.3.1's code means the CBOR is not valid; `c2pa-rs` logs it
only when the bytes do not decode as a generic CBOR value.

## What changed

- `CborException::LIMIT` (internal): the code on every exception for a
  limit or an item outside the subset. Everything else — not well-formed or
  not valid CBOR (RFC 8949 §5.3): reserved bytes, truncation, misplaced
  indefinite lengths and chunks, bytes after the item, text that is not
  UTF-8, a duplicate map key — keeps code 0.
- `Manifest::decodeCbor()`: for an assertion, a `LIMIT` fault is
  `general.error` with the explanation "CBOR beyond what this verifier
  decodes: …"; any other fault stays `assertion.cbor.invalid`. The claim
  keeps `claim.cbor.invalid` for every fault (SPEC-013). The store is
  refused in every case, as before.

## Measured

- Seven new variants from `bin/make-claim-variants.php`, the same length as
  the original (no box moves): the actions assertion with bytes after the
  item, text that is not UTF-8, a duplicate key, depth 33, `undefined`,
  2^64 − 1; and the claim with `undefined`. `c2patool` 0.28.3 refuses each
  with an error and no report. Only the UTF-8 case fails in its CBOR
  decoder; the others decode and fail against the actions schema ("invalid
  type: …", "missing field `actions`"). So depth 33, `undefined` and
  2^64 − 1 are CBOR `c2pa-rs` reads.
- Tests in `tests/Unit/Report/StatusCodesByTheLetterTest.php`: before the
  build 9 failed (the three limit probes, six decoder inputs) and the cases
  that stay `assertion.cbor.invalid` passed. The claim guard was added
  after mutation M2 (the limit rule applied to the claim too) survived.
- Mutations (`StatusCodesByTheLetterTest`, `tests/Unit/Cbor`,
  `tests/Unit/Manifest`): twelve, all caught after the guard.
- Corpus, 3,102 runs (no settings, `trust/full.settings.json`, the
  programme's lists) at `fa6914d` (worktree with a copied `vendor/`) and
  now: only the three limit probes differ, in code and explanation; every
  state the same. No earlier test changed.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
