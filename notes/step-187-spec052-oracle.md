# Step 187 — SPEC-052's fixtures, and what the oracle said

*2026-09-30. SPEC-052 approved the same day. PHP 8.5.8, OpenSSL 3.6.3,
`c2patool` 0.27.22.*

## The fixtures

`bin/make-spec052-variants.php` builds four signed variants with a
throw-away P-256 root. The keys are deleted when the run ends:

| file | base | edit |
|---|---|---|
| `crc32b-reference.jpg` | `c2pa-rs/CACA.jpg` | the active ingredient's `activeManifest`: `alg` `crc32b`, hash the `crc32b` of the ingredient manifest box |
| `sha384-reference.jpg` | the same | `sha384` |
| `sha512-reference.jpg` | the same | `sha512` |
| `crc32b-claim-signature.png` | `redactions/redacted-with-action.png` | the redacted parent's claim `alg` `crc32b`, the parent signed again; the child's `claimSignature` reference `alg` `crc32b` with that hash |

A hash of another length changes the ingredient assertion's size. The
enclosing boxes are adjusted, and the re-signed COSE_Sign1 in the same
manifest takes up the difference in its pad. The store keeps its length,
so the asset's data hash still holds. The claim's hashed URI for the
edited assertion is recomputed, and the claim is signed again, so every
signature over an edit verifies.

The script ran twice with fresh keys. `c2patool`'s answers were the same
both times (`tests/Fixtures/c2patool/spec052/`).

## What was measured

| variant | this verifier at `2e36eca` | `c2patool` 0.27.22 |
|---|---|---|
| `crc32b-reference.jpg` | **`Trusted`**, `ingredient.manifest.validated` | `Invalid`, `ingredient.manifest.mismatch` |
| `sha384-reference.jpg` | `Trusted`, validated | `Invalid`, `ingredient.manifest.mismatch` |
| `sha512-reference.jpg` | `Trusted`, validated | `Invalid`, `ingredient.manifest.mismatch` |
| `crc32b-claim-signature.png` | `Invalid`, `ingredient.claimSignature.validated` | `Invalid`, `ingredient.claimSignature.mismatch` |

The first row is a wrong `Trusted`, worse than the spec had reasoned. It
needs a signer who chose `crc32b` for the reference.

## Why `c2patool` refuses `sha384` too

Read in `c2pa-rs` 0.90.22, `sdk/src/store.rs`:

- `ingredient_checks` compares the reference's hash with
  `get_manifest_box_hashes(ingredient)`.
- That function computes the box hash with `claim.alg()`, the
  **ingredient claim's** algorithm.
- The reference's `alg` is used only for the pre-1.3 hash over the claim
  bytes (`verify_by_alg`).
- The claim-signature hash uses `claim.alg()` too.
- An unknown name gives no hash, so the comparison fails.

So SPEC-052's AC3 ("`sha384` and `sha512` still pass") contradicted the
oracle. Two options went to Maurice:

- **A:** follow `c2pa-rs`.
- **B:** honour the reference's `alg` as §15.4.2 reads, and record a
  difference in which this verifier says `Trusted` where `c2patool` says
  `Invalid`.

He chose A. That is SPEC-052 amendment 1, confirmed the same day.
