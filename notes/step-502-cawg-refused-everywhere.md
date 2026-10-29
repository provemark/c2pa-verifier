# Step 502 — A CAWG identity assertion is refused wherever this verifier validates

*2026-10-29.* The first fix of the security review made before tagging
0.10.0 (five reviewers over the whole of `src/`, every finding re-run
before it was reported). Findings V1 and V2 of that review, both a wrong
`Trusted`. SPEC-013 amendment 24 and SPEC-021 amendment 8, approved by
Maurice before the tests were written.

## The rule, and where it did not reach

A CAWG identity assertion carries a credential of its own, a second
signature saying who made the content. `c2patool` validates it; this
verifier does not yet. Since step 39 (SPEC-013 amendment 7) such an
assertion therefore makes the report `Invalid` with a `general.error`:
this verifier does not call `Trusted` what it has not looked at.

The rule lived in one place, `Verifier::verify()`, and had two gaps:

- **V1, an instance label.** It matched `cawg.identity` and labels
  beginning with `cawg.identity.`. C2PA 2.4 §6.2.2 and §6.4 let a further
  instance of an assertion type carry `__n` after its label, and every
  other check here strips that first (`HardBindings::baseLabel()`,
  `ActionsCheck`). `cawg.identity__1` was not refused.
- **V2, an ingredient manifest.** It looked at the active manifest only,
  although SPEC-021's Scope names a CAWG identity assertion among what
  still refuses, "as in the active manifest".

## What was measured

- `c2patool` 0.28.3 refuses to sign a label ending in `__n` of the
  author's choosing: it numbers it again (`cawg.identity__1__1`, then
  "assertion missing"). So `bin/make-cawg-variants.php` signs each probe
  with a label of the same length that is no CAWG label (`cawg.identitx`,
  `cawg.identitx-i1`, `cawg.identitx-i2`) under a throw-away hierarchy,
  and renames it afterwards: the label replaced wherever it occurs (in a
  parent also in the statuses `c2patool` recorded on reading the
  ingredient), the assertion's hash recomputed, the claim signed again
  with the same key, for an ingredient the parent's digests, ingredient
  and actions hashes recomputed and its claim signed again, and the caBX
  CRC recomputed. The keys are deleted when the script ends. A first run
  signed the claim as parsed before its hash was replaced, and both
  `c2patool` and this verifier answered `claimSignature.mismatch`; the
  script now parses again before it signs, and both read the signatures
  as valid.
- `c2patool` 0.28.3 on the five files: the two controls `Trusted`; the
  three renamed files `Invalid`, each with exactly one failure,
  `assertion.required.missing` under the active manifest (also for the
  ingredient cases).
- This verifier before the change (`add5cf6`): `Trusted` on all five.
- The new tests on the old code: 3 failed, the control passed. On the new
  code: 4 passed.
- Two hand mutations: the `__n` strip removed fails AC20 and AC13's
  instance case; the manifests the graph reaches removed fails both AC13
  cases.
- The corpus before (`add5cf6`, in a worktree with `vendor/` copied, its
  own `src/` checked to be the one loaded) and after, the current
  `tests/Fixtures/` read by both, no settings and `full.settings.json`:
  2,150 runs, and only the three renamed probes differ, in both modes.
  `C_with_CAWG_data` and `c2pa-rs-cawg_ica` were `Invalid` already and do
  not move.

## What was done

The refusal now reads the base label (`__n` removed) and refuses
`cawg.identity` and every label beginning with `cawg.identity.`, in the
active manifest and in every manifest the ingredient graph reaches
(`ManifestGraph::$referenced`). A manifest no assertion reaches is not
validated and stays outside, as it stays outside every other check. The
refusal is not scoped to an ingredient, so a parent's recorded
`validationResults` can never acknowledge it away; `c2patool` lists its
failure under the active manifest too.

New: `bin/make-cawg-variants.php`, `tests/Fixtures/cawg/` (five PNGs, the
root, its settings, a README), `tests/Fixtures/c2patool/cawg/` (the five
answers of `c2patool` 0.28.3). Tests: SPEC-013 AC20, SPEC-021 AC13.
`docs/comparison.md` and the 0.10.0 CHANGELOG say what changed.

`composer check` exit 0 (1,665 passed, 1 skipped), `vendor/bin/pest
--parallel` exit 0, `bin/package-check.php` classifies every path.

## Not done

The other findings of the review, each its own step. Validating a CAWG
credential instead of refusing it remains a spec of its own.
