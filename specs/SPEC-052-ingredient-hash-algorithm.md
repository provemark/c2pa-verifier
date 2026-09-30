# SPEC-052: An ingredient reference hashes with one of the three algorithms

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

An ingredient assertion refers to the ingredient's manifest by a hashed
URI (C2PA 2.4 §8.4.2.3). `IngredientManifestCheck::hash()` (SPEC-021)
recomputes that hash over the manifest box. When the store has redacted
assertions from a v2 manifest, `claimSignature()` (SPEC-035) compares the
hash the ingredient recorded over the signature box instead.

C2PA 2.4 §13.1 allows `sha256`, `sha384` and `sha512`. Both routes accept
any name PHP knows (read at `ff24836`):

```php
// IngredientManifestCheck::hash()
$alg = $reference->alg ?? $manifest->claim->alg ?? 'sha256';
if (! in_array($alg, hash_algos(), true)) { /* algorithm.unsupported */ }

// IngredientManifestCheck::claimSignature()
$alg = $manifest->claim->alg ?? 'sha256';
if (! in_array($alg, hash_algos(), true)) { /* algorithm.unsupported */ }
```

So `md5`, `crc32b` or `fnv132` is computed, and a match counts as
`ingredient.manifest.validated`. Reasoned, not measured:

- **No crash.** The `hash_algos()` test already stops a name PHP does not
  know, so this is not SPEC-051's `ValueError`.
- **A weak binding.** A 32-bit `crc32b` can be matched by another manifest
  in seconds. That needs a signer who chose `crc32b`, and the ingredient
  assertion is signed, so honest files are not at risk. It still breaks
  fail closed: an algorithm outside the specification is `Invalid`, not
  "accepted when it matches".
- **Inconsistent.** `HashedUriCheck` and `DataHashCheck` already refuse
  every name outside the three with `algorithm.unsupported` and a
  reference to §13.1.

What the oracle does (read in `contentauth/c2pa-rs` main,
`sdk/src/utils/hash_utils.rs`, 2026-09-30): an unknown name makes no
hasher, and `hash_by_alg` returns an empty hash, so the comparison always
fails. Which status `c2patool` reports for it is not yet measured; the
fixtures below make that measurable.

## Scope

**In scope**

1. **Both routes accept only the three.** `hash()` and
   `claimSignature()` refuse any other name with `algorithm.unsupported`,
   scoped to the ingredient as today, before any hash is computed. The
   check is a local constant, as in `HashedUriCheck` (Maurice's decision
   of 2026-09-30: sharing one list across classes is a separate tidy-up).
2. **The explanation cites §13.1**, in the words the other two checks use:
   "the hash algorithm X is not one of sha256, sha384, sha512 (C2PA 2.4
   §13.1)".
3. **Signed fixtures**, so the refusal is shown to come from the
   algorithm and not from a broken signature: a new script
   `bin/make-spec052-variants.php` with throw-away keys (the rule decided
   on 2026-09-21: tooling may sign with throw-away keys, the product never
   signs; keys stay outside the repository and are deleted after the run).

**Out of scope** (each needs its own spec before it may be built)

- **Which claim's `alg` is the default.** `hash()` falls back to the
  *ingredient* manifest's claim `alg`; §15.4.2 says a hashed URI without
  `alg` takes the `alg` of the claim that holds it, which is the
  *referring* claim. Every corpus file uses `sha256` throughout, so the
  two have not differed yet. Likewise `claimSignature()` takes the
  ingredient claim's `alg` and not the `alg` of the recorded
  `claimSignature` hashed URI. Noted here, not changed.
- One shared list of algorithms for all hash checks.
- The BMFF exclusion limit, and the order of Merkle `location` values
  (`c2pa-rs` #2702).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and
will be covered by a Pest test tagged `->group('SPEC-052')`. The fixtures
live in `tests/Fixtures/spec052/` with their throw-away settings.

- **AC1 — a `crc32b` reference is refused even when it matches**
  *(malformed input)*
  - Given `c2pa-rs/CACA.jpg` (two manifests; the base of step 56's
    `ingredient-signature-broken.jpg`), its active ingredient reference
    changed to `alg: crc32b` with the correct `crc32b` of the ingredient
    manifest box, and every hash above it recomputed and signed again with
    a throw-away key: the active claim's hashed URI for that assertion and
    the active signature
  - When it is verified with its throw-away settings
  - Then the report holds `algorithm.unsupported` scoped to that
    ingredient, whose explanation names `crc32b` and §13.1; it holds no
    `ingredient.manifest.validated` for that ingredient; the state is
    `Invalid`; and the active claim signature is `claimSignature.validated`
    (the refusal is not a broken signature)

- **AC2 — the claim-signature route refuses it too** *(malformed input)*
  - Given `redactions/redacted-with-action.png` (step 128: a v2 parent
    manifest with an assertion redacted by its child, so the
    claim-signature route applies), with the parent claim's `alg` set to
    `crc32b` and the child's recorded claim-signature hash its `crc32b`,
    both claims signed again with a throw-away key
  - When it is verified with its throw-away settings
  - Then the report holds `algorithm.unsupported` from
    `claimSignature()`, naming `crc32b` and §13.1, and no
    `ingredient.manifest.validated` for that ingredient. (The parent
    manifest's own hashed URIs then fail on `crc32b` too, through
    `HashedUriCheck`; the test asserts the ingredient-scoped status by its
    explanation, not only its code.)

- **AC3 — the other two C2PA algorithms still pass**
  - Given AC1's fixture built with `sha384` and with `sha512` instead
  - When each is verified with its throw-away settings
  - Then each holds `ingredient.manifest.validated` for the ingredient and
    no `algorithm.unsupported`

- **AC4 — genuine files do not move**
  - Given every fixture under `tests/Fixtures/` with two settings (none,
    and `trust/full.settings.json`), and the fragmented set
  - When each is verified before and after the change, with today's
    timestamps masked (step 185's method)
  - Then every report is identical

- **AC5 — the oracle is recorded**
  - Given AC1's and AC3's fixtures
  - When `c2patool` 0.27.22 reads them with the same settings
  - Then its JSON is stored next to each fixture, and the note names every
    difference from this verifier's report. A difference is either
    adopted into this spec by amendment or recorded in
    `docs/comparison.md` with its reason; it is not left unexplained

## References

- C2PA Technical Specification 2.4, §13.1 (the hash algorithms),
  §8.4.2.3 (the ingredient manifest reference), §15.4.2 (hashed URI
  `alg`), §15.11.3.3.1 (the claim-signature hash of a redacted manifest).
- Oracle: `c2patool` 0.27.22, measured in AC5; `c2pa-rs` main
  `hash_utils.rs`, read.
- Governing rules: SPEC-021 (ingredient manifests), SPEC-035 (redaction
  and the claim-signature route), SPEC-051 (the same rule for the merkle
  map), fail closed.

## API sketch

Illustrative only. The public API does not change.

```php
// namespace Provemark\C2paVerifier\Verifier;

final class IngredientManifestCheck
{
    /** C2PA 2.4 §13.1, as HashedUriCheck and DataHashCheck hold them (SPEC-052). */
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];
}
```

## Open questions

- None blocking. If AC5 shows `c2patool` reporting a different code
  (for example `ingredient.manifest.mismatch`), that is a proposed
  amendment for Maurice, not a silent change.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  |                             |                      |
| AC2                  |                             |                      |
| AC3                  |                             |                      |
| AC4                  |                             |                      |
| AC5                  |                             |                      |
