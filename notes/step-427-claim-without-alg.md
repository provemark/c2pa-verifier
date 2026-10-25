# Step 427 — A claim without `alg` (SPEC-027 amendment 9)

*2026-10-25.* The first of the four questions step 426 left in group A:
can a claim without `alg` give a `Valid` here that `c2patool` would not
give?

## What the specification says

C2PA 2.4 §15.4.1: a hard binding's hash algorithm is its own `alg`, else
the claim's; when neither names one, the validator reports
`algorithm.unsupported`. §15.4.2 says the same for a hashed URI. A version
2 claim need not carry `alg` (§10.2.2: "when present").

## What was measured

`bin/make-claim-alg-variants.php` has `c2patool` 0.28.3 sign
`fixture-unsigned.mp4` with a throw-away P-256 hierarchy, then edits the
claim without changing its length and signs it again. The keys live in a
scratch directory and are deleted at the end.

The first attempt showed why the case is narrower than the reading said:
`c2patool` writes no `alg` on a hashed-URI entry. A claim without `alg`
written that way already fails here, as `algorithm.unsupported` for every
entry. To reach the BMFF check, the entries must name their own; the
script moves the claim's `alg: sha256` pair into both entries and takes the
11 bytes the second copy needs off the `instanceID`.

| file | `c2patool` 0.28.1 and 0.28.3 | here, before | here, after |
|---|---|---|---|
| `control.mp4` | `Trusted` | `Trusted` | `Trusted` |
| `entries-alg.mp4` (claim without `alg`, the entries and the BMFF hash name their own) | error: *unknown algorithm*, no report | `Trusted` | `Trusted` |
| `entries-alg-bmff-no-alg.mp4` (the same, the BMFF hash without `alg`) | error: *unknown algorithm* | **`Trusted`** | `Invalid`, `algorithm.unsupported` |

The third was `Trusted` because `BmffHashCheck::assertionOf()` fell back on
SHA-256 when neither the assertion nor the claim named an algorithm.
`DataHashCheck` already reported `algorithm.unsupported` in that case.

## What changed

- `assertionOf()` falls back on the claim's `alg` and on nothing else;
  with neither, `check()` reports `algorithm.unsupported` naming §15.4.1
  before a byte of the asset is read.
- SPEC-027 amendment 9 and AC10; `tests/Unit/Hash/ClaimAlgTest.php`, red
  first (`Trusted` where `Invalid` was expected), then green.
- `entries-alg.mp4` stays `Trusted`: by the letter of §15.4.1 and §15.4.2,
  each hash names the algorithm it was made with. `c2patool` refuses any
  claim without `alg`. Maurice chose to follow the specification here and
  record the difference: a row in `docs/comparison.md` (*differs by
  design*).

## Measured and reasoned

- Measured: the three probes against `c2patool` 0.28.1 and 0.28.3 (the
  answers are in `tests/Fixtures/c2patool/claim-alg/`); the test red, then
  green; every fixture (949 files) run through the verifier after the
  change, and only `entries-alg-bmff-no-alg.mp4` reaches the new status.
- Reasoned: the risk was small. The bytes were hashed, and SHA-256 is
  strong; but the algorithm was one this verifier assumed, not one the
  signer named, and a fail-closed verifier does not assume.
- Not in scope: the SHA-256 fallbacks for an ingredient's reference in
  `IngredientManifestCheck` (SPEC-052 amendment 1). They hash an ingredient
  manifest whose own hashed URIs already fail without an algorithm.

**Weight A: a file wrongly `Trusted` becomes `Invalid`.** A verdict
change, so the next release's CHANGELOG names it.
