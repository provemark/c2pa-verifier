# Step 125 — SPEC-033: the actions content rules

*2026-09-24. SPEC-033 approved the same day. On open question 2 the
maintainer chose to follow `c2pa-rs` (references resolved by label);
questions 1, 3 and 4 adopted their proposals.*

## 125a — the probes, and the tests seen red

`bin/make-spec033-variants.php <c2patool-0.28.0> <c2patool-0.27.22>` signs 21
probes with `c2patool` 0.28.0 under a throwaway root, intermediate and leaf
(keys shredded, and a surviving key is an error). Where a probe needs a
real ingredient, the builder adds one (`ingredients` with a
`relationship`, linked through `parameters.ingredientIds`), and it writes
the hashed URI to the `c2pa.ingredient.v3` itself. Both versions' answers
are recorded with the root as anchor. Every probe could be written, so no
shape was left to the seam.

What the two oracles said, with the root as anchor:

| probe | 0.27.22 | 0.28.0 |
|---|---|---|
| `created-then-opened` | `Invalid` | `Invalid` (*more than one*, on the claim label; `ingredientMismatch`) |
| `edited-then-created` | `Invalid` | `Invalid` (only SPEC-018's *first action must be created or opened*) |
| `placed-no-parameters`, `-no-ingredients`, `-empty-ingredients`, `-unresolvable` | `Invalid` | `Invalid` (`ingredientMismatch`) |
| `placed-parent`, `opened-component`, `transcoded-component` | `Invalid` | `Invalid` (`ingredientMismatch`) |
| `placed-component`, `opened-parent`, `repackaged-no-reference` | `Trusted` | `Trusted` |
| `translated-no-parameters`, `-source-only` | `Invalid` | `Invalid` (`malformed`) |
| `translated-both` | `Trusted` | `Trusted` |
| `related-empty`, `-missing`, `-actions` | `Trusted` | `Invalid` (`malformed`; for a reference fault, the url is the reference itself) |
| `related-note` | `Trusted` | `Trusted` |
| `watermarked-no-soft-binding` | `Trusted` | `Invalid` (`softBindingMissing`) |
| `watermarked-with-soft-binding` | `Trusted` | `Trusted` |

Three things were learned before any test was written, and are recorded
as SPEC-033 amendment 1:

- `edited-then-created` gets **only** SPEC-018's fault, because `c2pa-rs`
  returns after it.
- The first watermark control carried a soft binding whose block `value`
  was text. `c2pa-rs` wants bytes, so 0.28.0 said `claim.malformed`. The
  probe was rebuilt with a list of byte values, and it is `Trusted` in
  both.
- A `relatedAssertions` fault sits on the reference's url.

`tests/Unit/Manifest/ActionsContentTest.php`, run as
`vendor/bin/pest --group=SPEC-033`: **8 failed, 1 passed.** Each failure
is this verifier reporting none of the `assertion.action.*` faults the
oracle recorded, or AC9's missing codes. **AC8 is green from birth**, as a
guard: the corpus's four `c2pa.opened` actions must not gain an
`ingredientMismatch`.

Committed locally, not pushed.
