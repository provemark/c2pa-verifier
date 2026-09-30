# Step 188 — SPEC-052 built: an ingredient reference is hashed as `c2patool` hashes it

*2026-09-30. SPEC-052 with amendment 1, built tests-first. PHP 8.5.8,
OpenSSL 3.6.3.*

## The tests, red first

`tests/Unit/Verifier/IngredientHashAlgorithmTest.php`, on step 187's
fixtures. Before the change, `vendor/bin/pest --group=SPEC-052`:
**8 failed**.

| test | red because |
|---|---|
| AC1 `crc32b`, AC2 claim signature, AC3 `sha384`/`sha512` | no mismatch status; the reports said `validated` |
| AC5 on the three JPEGs | state `Trusted`, `c2patool` says `Invalid` |
| AC5 on the PNG | same state, but `ingredient.claimSignature.validated` where `c2patool` has `mismatch` |

## What changed

All in `src/Verifier/IngredientManifestCheck.php`:

- `hash()` computes the box hash with the ingredient claim's `alg` (SHA-256
  when absent), never the reference's. The pre-1.3 hash over the claim
  bytes keeps the reference's `alg`, else the claim's.
- Each route runs only for `sha256`, `sha384` or `sha512`. A name outside
  the three computes nothing, so its route cannot match. The
  `hash_algos()` tests and their `algorithm.unsupported` are gone.
- A mismatch says why when it can: an algorithm outside the three
  (naming it and §13.1), or a reference that names another algorithm than
  the box hash uses.
- `claimSignature()` reports `ingredient.claimSignature.mismatch` for an
  ingredient claim `alg` outside the three.

After: `vendor/bin/pest --group=SPEC-052` **8 passed**; `composer check`
exit 0; `bin/api-check.php`: the recorded surface matches.

## The corpus, before and after

Step 185's script: 844 runs over 422 fixtures (with and without
`trust/full.settings.json`) plus the fragmented set, with today's
timestamps masked. **Only the four `spec052/` variants moved**, as
intended. Every other report is identical.

## What stays different from `c2patool`

The redacted parent in `crc32b-claim-signature.png` has claim `alg`
`crc32b`. Its own hashed URIs are `algorithm.unsupported` here, from
`HashedUriCheck`, and `assertion.hashedURI.mismatch` at `c2patool`. The
state is the same. This is recorded in `docs/comparison.md` under the
differences by design.

`SECURITY.md` records the wrong `Trusted`, together with SPEC-051's crash.
Both were present from `0.1.0` to `0.2.7`.
