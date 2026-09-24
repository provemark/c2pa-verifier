# SPEC-033: the actions content rules — one opening, ingredient references, translation, related assertions, watermarks

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-24                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-018 checks two things about actions: the first action of the first
actions assertion is `c2pa.created` or `c2pa.opened`, and every actions
assertion is well-formed. It put the rest of `c2pa-rs`'s `verify_actions`
out of scope by name. SPEC-032 took one rule back (`c2pa.created` needs its
`digitalSourceType`).

Step 123 measured what is left with signed probes. **Five are `Invalid` in
`c2patool` 0.27.22 *and* 0.28.0, and `Trusted` here**:
- `c2pa.created` followed by `c2pa.opened`;
- `c2pa.placed` without parameters;
- `c2pa.placed` with an empty `ingredients` list;
- `c2pa.placed` naming an ingredient that does not resolve;
- `c2pa.translated` without languages.

**Two more are `Invalid` in 0.28.0 only**: an empty `relatedAssertions`, and
a watermark action without a soft-binding assertion.

Most of them are the specification's own validation steps. C2PA 2.4
§15.10.3.2.3 (*c2pa.actions validation*) rejects:
- a `c2pa.created` or `c2pa.opened` that is not the first action of the
  first actions assertion;
- a `c2pa.opened`, `c2pa.placed` or `c2pa.removed` without ingredient
  references of the right relationship (`assertion.action.ingredientMismatch`);
- a `c2pa.transcoded` or `c2pa.repackaged` whose references are not
  `parentOf`;
- a `relatedAssertions` that is empty, unresolvable, or names an
  ingredient or actions assertion;
- a watermark action without a soft binding
  (`assertion.action.softBindingMissing`).

The `c2pa.translated` rule is `c2pa-rs`'s reading of §18.15.4.7, where the
`sourceLanguage` and `targetLanguage` parameters *"shall contain"* language
codes.

None of these lets a changed byte through. They are rules about what a
manifest may say (`docs/conformance.md`, category 2). This verifier's
first design rule is that its verdict means what `c2patool`'s means, and
here it does not.

Measured for this draft: the corpus's v2 claims contain no `c2pa.placed`,
`c2pa.removed`, `c2pa.transcoded`, `c2pa.repackaged`, `c2pa.translated`,
watermark action or `relatedAssertions`, and no manifest with more than
one opening. They hold **four `c2pa.opened` actions in two files**
(`c2pa-rs/CACA.jpg`,
`ingredient-manifest/ingredient-signature-broken.jpg`), each naming one
ingredient. `c2patool` accepts `CACA.jpg`, so its references must
resolve.

## Scope

**In scope** — v2 claims only. v1 claims stay as SPEC-018 leaves them,
as `c2pa-rs` does without `strict_v1_validation`. The rules apply in
`ActionsCheck`, which runs on the active manifest and, through SPEC-021,
on ingredient manifests. Each fault yields one status on the url
`c2patool` records, with its words where they can be measured, and
`Invalid`:

1. **One opening.** Across all actions assertions of the claim, at most
   one action is `c2pa.created` or `c2pa.opened`, else
   `assertion.action.malformed` (*"cannot have more than one c2pa.created
   or c2pa.opened action"*). An opening in any actions assertion other
   than the first is `assertion.action.malformed` (*"only first action can
   be created or opened"*). An opening that is not the first action of its
   assertion is `assertion.action.malformed` (*"created or opened must be
   first action"*). SPEC-018's rule 1 stays, and these three complete it.
2. **Ingredient references for opened, placed, removed** (§15.10.3.2.3).
   The action needs `parameters`, which hold `ingredients` (a non-empty
   list of hashed URIs) or the v1-style `ingredient`. Each reference must
   resolve, **by label, in this claim**, to an ingredient assertion
   (SPEC-020) whose `relationship` is `parentOf` for `c2pa.opened` and
   `componentOf` for `c2pa.placed` and `c2pa.removed`. `c2pa.opened` must
   resolve to exactly one. Otherwise the result is
   `assertion.action.ingredientMismatch`.
3. **Ingredient references for transcoded, repackaged** (§15.10.3.2.3,
   `c2pa-rs` 2.c). If `ingredient` or `ingredients` is present, each
   reference must resolve to a `parentOf` ingredient assertion, else
   `assertion.action.ingredientMismatch`. With no reference, nothing is
   required.
4. **Translation** (§18.15.4.7, as `c2pa-rs` reads it). A
   `c2pa.translated` action's `parameters` carry non-empty
   `sourceLanguage` and `targetLanguage` strings, else
   `assertion.action.malformed`. Whether they are valid BCP 47 tags is not
   checked, and `c2pa-rs` does not check it either.
5. **Related assertions** (§15.10.3.2.3). If `parameters.relatedAssertions`
   is present, it must be a non-empty list. Each entry must resolve to an
   assertion in the current manifest, and none may be an ingredient or
   actions assertion. Otherwise the result is `assertion.action.malformed`.
6. **Watermarks** (§15.10.3.2.3). A `c2pa.watermarked` (deprecated) or
   `c2pa.watermarked.bound` action requires at least one soft-binding
   assertion (`c2pa.soft-binding`, any instance) in the claim, else
   `assertion.action.softBindingMissing`.
- `StatusCode` grows by two cases, `AssertionActionIngredientMismatch =
  'assertion.action.ingredientMismatch'` and
  `AssertionActionSoftBindingMissing = 'assertion.action.softBindingMissing'`,
  both failures. The surface goes 112 → 114 (a SPEC-025 amendment).
- SPEC-018's out-of-scope paragraph loses these items (a SPEC-018
  amendment).

**Out of scope** (each needs its own spec before it may be built)

- **Icons** in `softwareAgents`, `templates` and an action's
  `softwareAgent` (`c2pa-rs` 2.e, 2.f, 2.h; §15.10.3.3). A failing icon
  needs a hashed-URI probe built by surgery. It belongs with issue #11,
  the `claim_generator_info` icon, as one spec about icon references.
- `c2pa.redacted` and `assertion.notRedacted` (`c2pa-rs` 2.d): no oracle
  refused step 123's probe, and redactions are refused here until a
  fixture exists (SPEC-021).
- Verifying the *hash* of an ingredient reference. `c2pa-rs` resolves by
  label, and so does this spec (open question 2).
- `digitalSourceType` on actions other than `c2pa.created` (issue #2's
  general case), and anything in v1 claims.

## Behavior

Each probe is `fixture-unsigned.jpg` signed by `c2patool` 0.28.0 under a
throwaway root, intermediate and leaf (a `bin/make-*` script, keys
shredded). Where `c2patool`'s builder adds ingredients from a file, it
does so. Both `c2patool` versions' reports are recorded.

- **AC1 — one opening**
  - Given probes with `c2pa.created` then `c2pa.opened`, and with
    `c2pa.edited` then `c2pa.created`
  - When verified with the root
  - Then the first gives `assertion.action.malformed` naming *more than
    one* (and whatever `ingredientMismatch` its opened action earns under
    AC2). The second gives SPEC-018's existing fault **and nothing more**
    (amendment 1). Both are `Invalid`, with the codes and urls `c2patool`
    0.28.0 records.

- **AC2 — opened, placed, removed without references** *(error paths)*
  - Given probes with `c2pa.placed` without `parameters`, with
    `parameters` but no `ingredients`, with `ingredients: []`, and with an
    `ingredients` entry that resolves to no ingredient assertion
  - When verified with the root
  - Then each gives `assertion.action.ingredientMismatch` on the actions
    assertion's url and `Invalid`, as both `c2patool` versions do.

- **AC3 — references of the wrong relationship**
  - Given probes with a real ingredient added by the builder: a
    `c2pa.placed` naming a `parentOf` ingredient, and a `c2pa.opened`
    naming a `componentOf` one
  - When verified
  - Then `assertion.action.ingredientMismatch` and `Invalid`. Their
    controls (the relationship right) give no such status.

- **AC4 — transcoded and repackaged**
  - Given a `c2pa.transcoded` naming a `componentOf` ingredient, and a
    `c2pa.repackaged` naming none
  - When verified
  - Then the first gives `assertion.action.ingredientMismatch`, and the
    second gives no status from this rule.

- **AC5 — translation**
  - Given `c2pa.translated` without parameters, with only
    `sourceLanguage`, and with both
  - When verified
  - Then the first two give `assertion.action.malformed` and `Invalid`,
    as both oracles. The third gives none.

- **AC6 — related assertions**
  - Given `relatedAssertions: []`, an entry naming an assertion not in the
    manifest, an entry naming the actions assertion itself, and an entry
    naming the thumbnail
  - When verified
  - Then the first three give `assertion.action.malformed` and `Invalid`
    (the first as 0.28.0 records it). The fourth gives none. 0.27.22's
    `Trusted` on the first is recorded as a named change.

- **AC7 — watermarks**
  - Given `c2pa.watermarked.bound` without a soft-binding assertion, and
    with one
  - When verified
  - Then the first gives `assertion.action.softBindingMissing` and
    `Invalid`, as 0.28.0. The second gives none.

- **AC8 — nothing else moves**
  - Given the whole corpus under the three standard settings, run with
    the code before and after
  - When compared
  - Then no verdict and no failure code changes outside the new probes.
    That includes the four `c2pa.opened` actions of `CACA.jpg` and
    `ingredient-signature-broken.jpg`, whose references resolve. The
    drift alarms pass unchanged.

- **AC9 — the vocabulary grows by two codes, verbatim**
  - Given `StatusCode`
  - When read
  - Then it has both new cases, each `isFailure()`, and the recorded
    surface holds 114 symbols (SPEC-025 amendment).

## References

- Specification: C2PA 2.4 §15.10.3.2.3 (*c2pa.actions validation*),
  §18.15.4.7 (action parameters, `c2pa.translated`), §15.10.3.3
  (*Validation of References*, for the icons named out of scope). Read
  in the 2.4 HTML of `c2pa-org/specifications` at `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22. `c2pa` 0.91.0 `claim.rs`
  `verify_actions()` read: the inception count, 2.a, 2.b.i–iv, 2.c,
  translated, 2.f, and the soft-binding check.
- Measured: step 123 (probes, both oracles), and this draft's corpus
  scan of v2 claims (only four `c2pa.opened` actions, none of the other
  shapes).

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): two cases more
case AssertionActionIngredientMismatch = 'assertion.action.ingredientMismatch';
case AssertionActionSoftBindingMissing = 'assertion.action.softBindingMissing';

// Manifest\ActionsCheck (internal): the six rules inside checkAssertions(), v2 claims only;
// the ingredient lookups reuse SPEC-020's IngredientAssertion on the manifest's own assertions.
```

## Open questions

*Answered on approval, 2026-09-24:* question 2 by the maintainer (follow
`c2pa-rs`: resolution by label). Questions 1, 3 and 4 were settled by
adopting their proposals.

1. **The urls and words.** `c2pa-rs` puts the *more than one* fault on the
   bare claim label and the per-action faults on the actions assertion's
   url. Proposal: copy both, measured per probe in the tests-first step,
   as SPEC-018 amendment 2 did. *(not a blocker)*
2. **Resolution by label, not by hash.** `c2pa-rs` matches a reference to
   an ingredient assertion by its label only. A reference whose hash is
   wrong but whose label matches passes here as it does there. Proposal:
   follow `c2pa-rs` in this spec, and name it. The hashed-URI check
   (SPEC-011) already verifies the claim's own references to its
   assertions. An action parameter's hash is a second, separate check,
   and it belongs with the icon references. *(blocker: your call)*
3. **`c2pa.removed` and "another manifest".** §15.10.3.2.3 says a removed
   action's references resolve to a `componentOf` ingredient *in another
   manifest*. `c2pa-rs` looks in the current claim, as for placed.
   Proposal: follow `c2pa-rs` and name the difference, because no
   fixture shows either reading. *(not a blocker)*
4. **Probes the builder cannot write.** Step 123 could not embed a second
   actions assertion. Proposal: where `c2patool` will not write a shape,
   leave it to the seam `ActionsCheck::checkAssertions()` (SPEC-018
   amendment 1), and do not build it by surgery. *(not a blocker)*

## Amendments

1. **2026-09-24, step 125a, measured before the tests.**
   - AC1's second probe (`c2pa.edited` then `c2pa.created`) gets exactly
     one failure in both oracles: `assertion.action.malformed`, *"first
     action must be created or opened"*, on the bare claim label. That is
     SPEC-018's rule. `c2pa-rs` returns after that failure, so its per-action
     *"created or opened must be first action"* never runs for it.
     Criterion and rule 1 follow the oracle: when the opening rule has
     already refused the manifest, the position of a later opening is not
     reported a second time. The position rule still applies to a
     manifest whose first action *is* an opening.
   - The watermark control needed a soft-binding assertion `c2pa-rs` can
     decode. Its block `value` is a byte string, and the first build gave
     text, which 0.28.0 called `claim.malformed`. Rebuilt, it is `Trusted`
     in both oracles.
   - The url `c2patool` gives a `relatedAssertions` fault is the reference
     itself (`self#jumbf=c2pa.assertions/<label>`), not the actions
     assertion. Open question 1 said to copy the urls as measured, so the
     test compares them.

   Weight C: a criterion's wording, following the oracle. No rule of this
   spec changed in outcome.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
