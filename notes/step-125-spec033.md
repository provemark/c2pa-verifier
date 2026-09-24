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

## 125b — built

- **`ActionsCheck::contentRules()`**, called from `checkAssertions()` for
  v2 claims once the opening rule has passed (amendment 1), and for
  update manifests, which are exempt from the opening rule only. It
  holds six rules:
  - one opening across the claim, on its bare label;
  - opened, placed and removed need references (both of `c2pa-rs`'s
    faults for an empty list), resolving by label to `parentOf` (opened,
    exactly one) or `componentOf` (placed, removed, at least one);
  - transcoded and repackaged need a `parentOf` when they name any;
  - `c2pa.translated` needs both languages;
  - `relatedAssertions` must be non-empty and resolve in this manifest,
    faults on the reference's own url, and must not name actions or
    ingredients;
  - a watermark action needs a `c2pa.soft-binding`.
- `check()` now passes the claim's labels and its ingredients'
  relationships.
- Two `StatusCode` cases, and the surface goes 112 → 114.

### What the red-to-green run found

- Every probe's `assertion.action.*` faults equal `c2patool` 0.28.0's,
  code and url, on the first green run.
- **Two specs' tests asserted the surface's total.** SPEC-032 AC7 said 112
  and this spec's AC9 said 114, so every later spec would break an older
  test. The total now lives only in `ApiSurfaceTest`, and the specs'
  tests assert their own symbols (SPEC-032 amendment 2, SPEC-033
  amendment 2).
- SPEC-015 AC10's code count goes 46 → 48.
- `composer check`: 450 passed, clean.

### Measured: what changed across the corpus

966 lines (322 files × three settings), old code in a worktree with its
own `vendor/`: the 42 probe lines moved as their criteria ask, and
**one other file**. `update-manifest/ingredient-inputto.jpg` gains
`assertion.action.ingredientMismatch`, and its verdict stays `Invalid`.
Its update manifest opens with an ingredient whose relationship SPEC-022's
variant turned into `inputTo`. Both `c2patool` versions refuse the file
before they read the actions, so this code has no oracle (amendment 2).

### The public record

- `docs/conformance.md`: `PRED-ASSE-023` narrowed (a second opening is
  refused), and *Outside the catalogue* names the actions rules now
  enforced and the icons and `c2pa.redacted` that are not.
- `docs/comparison.md`: two rows (0.28.0-only rules; resolution by label,
  and `c2pa.removed` in the current claim).
- `CHANGELOG.md`: a new `Unreleased` section.
