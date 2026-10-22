# Step 363 — SPEC-070 amendment 2: in v3, the list a failure was recorded in counts

*2026-10-22.*

Maurice approved SPEC-070 amendment 2 and put it in 0.7.1.

## Why

C2PA 2.4 §18.16.12.4.1 makes a recorded **failure** in an ingredient
assertion the writer's acknowledgement, and §18.16.12.4.3 compares a
recorded status on its *"status type (success, informational, or failure),
code, and url"*. `recorded()` kept the code and url and dropped the list,
so a `claimSignature.mismatch` filed under `success` excused the failure
as one filed under `failure` does. `c2pa-rs` does the same: in
`validation_results.rs`, `from_store` replaces the recorded type with one
derived from the code (`log_kind`), because a v1/v2 `validationStatus` has
no lists. This verifier followed it, and `docs/reading-c2pa-2.4.md` said
so under P08-7.

## What changed

`IngredientManifestCheck::kinds()` keeps an entry of a v3 map
(`activeManifest` and every `ingredientDeltas[].validationDeltas`) only in
the list of its code's own kind. The reader's own status always has its
code's kind, so this is the type comparison the specification asks for,
without changing the keys `acknowledge()` compares. A v1/v2
`validationStatus` is read as before. A failure filed under `success` or
`informational` now excuses nothing: it stays a failure and the file is
`Invalid`.

`bin/make-acknowledged-kind-variants.php` builds three fixtures from
`ingredient-manifest/ingredient-signature-broken.jpg` (an ingredient whose
claim signature is broken and unrecorded): the same mismatch recorded
under `failure`, `success` and `informational`. The assertion keeps its
length (the recorded successes lose their explanation text, the new entry
is padded), so the store and its data hash do too, and the active claim
is re-signed with a throw-away hierarchy. The leaf now names its
`authorityKeyIdentifier` explicitly. OpenSSL 4.0.3 does not add it by
itself, and the first run's leaf was `signingCredential.invalid`.

## Measured

| recorded under | c2patool 0.27.22 / 0.28.2 | here before | here after |
|---|---|---|---|
| `failure` | `Trusted` | `Trusted`, acknowledged | `Trusted`, acknowledged |
| `success` | `Trusted` | `Trusted`, acknowledged | `Invalid`, `claimSignature.mismatch` |
| `informational` | `Trusted` | `Trusted`, acknowledged | `Invalid`, `claimSignature.mismatch` |

With `acknowledged-kind/throw-away-root.settings.json`; without it, `Valid`
for `Trusted`. The same shape was measured first outside this repository,
with c2pa-python 0.38.1 and the c2patool `sample/` certificates, on a JPEG
of its own.

- Before deciding: every fixture `c2patool` 0.27.22 reads (929 files) was
  scanned for what its ingredient assertions record. No v3 record files a
  code outside its own kind's list; the v2 records that hold failures
  (`CIE-sig-CA.jpg`, the two Adobe public test files, `CACAE-uri-CA.jpg`,
  `records-active-fault.png`) are untouched by the change.
- Tests first: 5 failed, then green. One mutation caught (the filter
  removed: 5 fail). The branch for an unknown code is not observable.
- Corpus against `v0.7.0` (a worktree with `vendor/` copied, 921 files × 2
  modes): only `recorded-success.jpg` and `recorded-informational.jpg`
  differ.
- `composer check`: 1,115 passed, 1 skipped.

## Not a security fix

The specification lets a signer acknowledge any ingredient failure by
filing it under `failure`. Filing it elsewhere hides it no better from this
verifier, which reports acknowledged failures since SPEC-070, so this is
conformance, not a closed hole. The difference from `c2patool` is
recorded in `docs/comparison.md`.
