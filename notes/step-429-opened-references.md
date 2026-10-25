# Step 429 — Every reference of a `c2pa.opened` is the one parent (SPEC-033 amendment 3)

*2026-10-25.* The third question of group A (step 426): a `c2pa.opened`
action with more than one ingredient reference. Reading `c2pa-rs`
(`verify_actions`, rule 2.b.iv) on 2026-10-09 suggested that it lets the
last reference decide, while this verifier counted the good ones.

## What the specification says

C2PA 2.4 §15.10.3.2.3, for `c2pa.opened`: *"Check that the ingredients
field … contains exactly one valid hashed URI that can be resolved to an
ingredient assertion in the current manifest whose relationship field is
parentOf."* §15.10.3.3: a hashed URI without a destination is a failure
(`hashedURI.missing`).

## What was measured

`bin/make-opened-reference-variants.php` has `c2patool` 0.28.3 sign
`fixture-unsigned.jpg` with a throw-away P-256 hierarchy. The builder keeps
the references as given, so no byte surgery was needed. The keys are
deleted at the end.

| `c2pa.opened` with | `c2patool` 0.28.1 and 0.28.3 | here, before | here, after |
|---|---|---|---|
| the parent | `Trusted` | `Trusted` | `Trusted` |
| the parent, then a reference to nothing | `Invalid` | **`Trusted`** | `Invalid` |
| a reference to nothing, then the parent | `Trusted` | `Trusted` | `Invalid` |
| the parent twice | `Trusted` | `Invalid` | `Invalid` |
| the parent, then a `componentOf` ingredient | `Invalid` | **`Trusted`** | `Invalid` |

The fifth row was not part of the question. It was added to show what
the first proposal (unresolvable references only) would leave, and it
showed the same leniency. Maurice chose the wider rule.

The same pairs for `c2pa.placed`, `c2pa.removed`, `c2pa.transcoded` and
`c2pa.repackaged` were measured in scratch first: `Trusted` in both
`c2patool` versions and here, in either order. Two `placed` probes are kept
as fixtures to pin that.

## What changed

- `ActionsCheck::contentRules()`: for `c2pa.opened`, the list must hold
  exactly one reference and it must resolve to a `parentOf` ingredient.
  Anything else is `assertion.action.ingredientMismatch`.
- SPEC-033 amendment 3 and AC10; `tests/Unit/Manifest/OpenedReferencesTest.php`,
  red first (`Trusted` where `Invalid` was expected), then green.
- `docs/comparison.md`: a row under *differs by design*. This verifier is
  now stricter than `c2patool` in two of the five shapes, and never more
  lenient in any.

## Measured and reasoned

- Measured: seven probes with `c2patool` 0.28.1 and 0.28.3 and with this
  verifier; the test red, then green; every fixture (957 files) run before
  and after the change: only three of the new probes move (the fourth,
  the parent twice, was already `Invalid`).
- Reasoned: no asset byte is at stake. The references are the signer's,
  inside the signed claim. The gain is that this verifier no longer says
  `Trusted` where `c2patool` says `Invalid`, and that its answer does not
  depend on the order of a list.
- Not changed: the hash inside a reference is still not compared, in
  either tool (P06-5).

**Weight A: files wrongly `Trusted` become `Invalid`.** A verdict change,
so the next release's CHANGELOG names it.
