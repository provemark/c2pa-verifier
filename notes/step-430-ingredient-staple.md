# Step 430 — An ingredient's own stapled `revoked` (SPEC-066 amendment 2)

*2026-10-25.* The fourth and last question of group A (step 426): an
ingredient manifest whose signer stapled a `revoked` OCSP response, in a
store that carries no certificate-status assertion. SPEC-066 amendment 1
left that staple unread. Would `c2patool` say `Invalid` where this
verifier says `Trusted`?

## What the specification says

C2PA 2.4 §15.11.3.3.1: an ingredient's manifest is validated as the
active one is, its signer's revocation (§15.9) included.

## What was measured

`bin/make-ingredient-staple-variants.php` builds the probes the way
`bin/make-revocation-variants.php` does: a throw-away root, intermediate
and two signers, OCSP responses from `openssl ocsp`, and a response
written into the COSE `pad` of the parent's signature (the unprotected
header is not signed). The child is signed by `c2patool` 0.28.3 with that
parent as its ingredient, so the parent's manifest, staple included,
travels in the child's store.

| file | `c2patool` 0.28.1 and 0.28.3 | here, before | here, after |
|---|---|---|---|
| parent, no response | `Trusted` | `Trusted` | `Trusted` |
| parent, its own `revoked` | `Trusted`, no OCSP code | `Invalid` (SPEC-030) | `Invalid` |
| parent, its own `good` | `Trusted`, no OCSP code | `Trusted` | `Trusted` |
| child of the plain parent | `Trusted` | `Trusted` | `Trusted` |
| child of the revoked parent | `Trusted` | `Trusted` | **`Invalid`** |
| child of the good parent | `Trusted` | `Trusted` | `Trusted` |

`c2patool` reports no OCSP code for these responses at all, on the active
manifest or on an ingredient; SPEC-030's open question 2 had found the
same for the active manifest. So the question's answer is *equal*: no
leniency.

## What changed, and why anyway

The measurement showed an inconsistency of this verifier's own: a revoked
signer on the active manifest made a file `Invalid`, a revoked signer of
an ingredient did not. Maurice chose to make them agree.

- `IngredientManifestCheck::manifest()`: without certificate-status
  responses, the ingredient's own staple is read, and only a
  `signingCredential.ocsp.revoked` is kept, under that ingredient.
  `notRevoked` and `skipped` are not reported for it, so the
  `c2pa-rs/ocsp.jpg` delta that amendment 1 avoided does not appear.
- SPEC-066 amendment 2 and AC8; `tests/Unit/Trust/IngredientStapleTest.php`,
  red first (`Trusted` where `Invalid` was expected), then green.
- `docs/comparison.md`: a row under *differs by design*, stricter than
  `c2patool`.

## Measured and reasoned

- Measured: the six probes with `c2patool` 0.28.1, 0.28.2 (scratch) and
  0.28.3 and with this verifier; the test red, then green; every fixture
  (963 files) before and after, every code compared, ingredient-scoped
  ones included: only `child-staple-revoked.png` moves.
- Not measured: why `c2patool` ignores these responses. Its reason is not
  needed for this verifier's rule, which follows SPEC-030.

## Group A, closed

| question | answer | step |
|---|---|---|
| a claim without `alg` | more lenient: fixed (SPEC-027 amendment 9) | 427 |
| a claim `alg` outside §13.1 | equal | 428 |
| a `c2pa.opened` with more than one reference | more lenient: fixed (SPEC-033 amendment 3) | 429 |
| an ingredient's own stapled `revoked` | equal; made consistent (SPEC-066 amendment 2) | 430 |

**Weight A: a file wrongly `Trusted` becomes `Invalid`.** The third verdict
change since 0.8.2 (with steps 427 and 429); the next release's CHANGELOG
names all three.
