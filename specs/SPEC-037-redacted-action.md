# SPEC-037: the `c2pa.redacted` action — its reference must resolve

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

A claim that redacts an assertion is *"strongly recommended"* to record a
`c2pa.redacted` action whose `parameters.redacted` holds the JUMBF URI of
the redacted assertion (C2PA 2.4 §6.8, §18.15.4.7). Validation step
§15.10.3.2.3 checks that reference. This verifier does not check it.

Step 131 measured the gap. It used nine children built by `c2patool`
0.28.0's builder, each redacting its parent's `com.example.secret` and
carrying one `c2pa.redacted` action. **Four are `Invalid` in both
`c2patool` versions and `Trusted` here:**
- `parameters` without `redacted`;
- a relative `redacted`;
- a `redacted` naming a manifest that is not in the store;
- a `redacted` naming a label the parent's claim does not list.

No byte of the asset is involved. What is wrong is the claim's own account
of what was removed: its reference points at nothing. Both versions agree
on all nine probes. Every fault sits on the url of the actions assertion
that holds the action.

**What 2.4 says** (§15.10.3.2.3, read at `4eb2c67`): *"If the action field
is `c2pa.redacted`: Check the `redacted` field that is a member of the
`parameters` object for the presence of a JUMBF URI. If the JUMBF URI is
not present, or cannot be resolved to an assertion, the claim shall be
rejected with a failure code of `assertion.action.redactionMismatch`."*

**What `c2pa-rs` does** (`claim.rs` `verify_actions`, rule 2.d, read at
`6c92bc3`). It is narrower than 2.4 in two places:
- it checks only v2 claims (v1 only in a strict mode `c2patool` does not
  use), and only an action that **has `parameters`**. A bare
  `c2pa.redacted` passes, which step 123 measured;
- when the named manifest is in the store but its claim lists no assertion
  whose url contains the label, it reports `assertion.notRedacted` (*"The
  assertion was not redacted"*). 2.4 would say `redactionMismatch` there.

Otherwise it gives `assertion.action.redactionMismatch` (*"redaction uri
must be a valid reference"*) when `redacted` is absent, names no manifest
(a relative URI), or names a manifest that is not in the store. A
reference to an assertion that resolves passes. That holds whether or not
the assertion was redacted, and whether or not the claim has a
`redactions` list: step 131 measured both as `Trusted`, and 2.4 asks only
that it resolve.

## Scope

**In scope**

1. `StatusCode` gains `AssertionActionRedactionMismatch =
   'assertion.action.redactionMismatch'`, a failure.
2. In v2 claims, `ActionsCheck` applies rule 2.d to every `c2pa.redacted`
   action that has `parameters`, as `c2pa-rs` does:
   - `parameters.redacted` absent, not a string, not an absolute
     `self#jumbf=/c2pa/<label>/…` URI, or naming a manifest not in the
     store → `assertion.action.redactionMismatch`;
   - the named manifest is in the store, but its claim lists no assertion
     whose url contains the label after `c2pa.assertions/`, or the URI
     names no assertion label → `assertion.notRedacted`;
   - otherwise nothing.

   Each fault carries the url of the actions assertion that holds the
   action, as both oracles report it.
3. To do so, `ActionsCheck` learns the other manifests in the store: the
   labels each claim lists, keyed by manifest label. The Verifier and
   `IngredientManifestCheck` pass them from the `ManifestStore`. The class
   is `@internal`, so the public surface gains only the code.
4. Fixtures: the step-131 shapes, built by a new
   `bin/make-spec037-variants.php` with `c2patool` 0.28.0's builder (no
   surgery), judged by both versions.

**Out of scope** (each needs its own spec before it may be built)

- A `c2pa.redacted` without `parameters`: it passes, as in both oracles
  (open question 1).
- The `reason` field (§18.15.4.2, *"shall contain the rationale"*): no
  validation step names it, and no oracle checks it.
- Data-box references. `c2pa-rs` also accepts a `redacted` naming a
  `c2pa.databoxes` URI that the claim's own `redactions` list, which the
  builder cannot write. See open question 3.
- v1 claims, as in `c2pa-rs`.

## Behavior

The fixtures are step 131's children: a parent with an extra
`com.example.secret`, and children built with `-p parent` and
`redactions`, verified with the throwaway root.

- **AC1 — the shapes both oracles accept stay `Trusted`**
  - Given the children whose `c2pa.redacted` action has
    - `redacted` naming the redacted secret;
    - no `parameters` at all;
    - `redacted` naming the parent's actions, which were not redacted;
    - `redacted` naming the secret, with no `redactions` list
  - When verified
  - Then `Trusted`, with no `assertion.action.*` and no
    `assertion.notRedacted` fault, as both oracles.

- **AC2 — `parameters` without `redacted`** *(error path)*
  - Given the child whose action has `parameters` but no `redacted`
  - When verified
  - Then `assertion.action.redactionMismatch` on the actions assertion's
    url, and `Invalid`, as both oracles.

- **AC3 — a reference that names no manifest here** *(error path)*
  - Given the children whose `redacted` is relative, or names a manifest
    label that is not in the store
  - When verified
  - Then `assertion.action.redactionMismatch` on the actions assertion's
    url, and `Invalid`, as both oracles.

- **AC4 — a label the named manifest does not list** *(error path)*
  - Given the child whose `redacted` names
    `…/<parent>/c2pa.assertions/com.example.nothing`
  - When verified
  - Then `assertion.notRedacted` on the actions assertion's url, and
    `Invalid`, as both oracles.

- **AC5 — a `redacted` that is not a string** *(error path)*
  - Given, through `ActionsCheck`'s seam (the builder refuses to write
    it), a v2 `c2pa.redacted` action whose `parameters.redacted` is `42`
  - When checked
  - Then `assertion.action.redactionMismatch` (open question 2).

- **AC6 — v1 claims are not checked**
  - Given, through the seam, AC2's action in a v1 claim
  - When checked
  - Then no redaction-action fault, as `c2pa-rs` without strict mode.

- **AC7 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after, with ingredient deltas compared
  - Then no verdict and no failure code changes outside the new fixtures,
    and the drift alarms pass.

- **AC8 — the vocabulary grows by one code, verbatim**
  - Then `StatusCode::AssertionActionRedactionMismatch` exists with the
    value `assertion.action.redactionMismatch`, is a failure, and is in the
    recorded surface (121 → 122).

## References

- Specification: C2PA 2.4 §6.8, §15.10.3.2.3 (the `c2pa.redacted` step),
  §18.15.4.2 (`reason`), §18.15.4.7 (`redacted`). Read in the 2.4 HTML of
  `c2pa-org/specifications` at `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22 on step 131's probes (both
  versions agree on all nine), recorded again with the fixtures in the
  tests-first step.
- Reasoned: `c2pa` `claim.rs` `verify_actions` rule 2.d, read at
  `6c92bc3`: the `parameters` guard, `manifest_label_from_uri`,
  `assertion_label_from_uri`, the label matched by substring against the
  named claim's assertion urls, and the data-box branch.

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): one case more
case AssertionActionRedactionMismatch = 'assertion.action.redactionMismatch';

// Manifest\ActionsCheck (@internal): the store's claims, for rule 2.d
/** @param array<string, list<string>> $storeLabels manifest label => the assertion labels its claim lists */
public function check(Manifest $manifest, array $unreadable = [], array $storeLabels = []): array
```

## Open questions

*Answered on approval, 2026-09-24:* question 1 by the maintainer (follow
`c2patool`: a bare `c2pa.redacted` passes). Questions 2, 3 and 4 were
settled by adopting their proposals.

1. **A bare `c2pa.redacted`.** 2.4 rejects an action without a `redacted`
   field. Both oracles accept it when `parameters` is absent altogether,
   and step 123's and step 131's probes show it. Proposal: follow the
   oracles, name it in `docs/comparison.md`, and keep the verdicts equal.
   *(blocker: your call)*
2. **A `redacted` that is not a string.** The builder refuses to write
   it. `c2pa-rs`'s reader would fail to decode the whole actions
   assertion, a different fault, and no file shows which. Proposal:
   `assertion.action.redactionMismatch` (*"not a JUMBF URI"*). It fails
   closed, and it is what 2.4's wording gives. *(not a blocker)*
3. **Data-box references.** `c2pa-rs` accepts a `redacted` naming a
   `c2pa.databoxes` URI if the claim's own `redactions` list holds it.
   Neither builder writes one, so it cannot be measured. Proposal: do not
   copy that pass route. Such a reference gets `assertion.notRedacted`,
   as any label the named claim does not list; named in
   `docs/comparison.md`. *(not a blocker)*
4. **`notRedacted` or `redactionMismatch` for an unlisted label.**
   `c2pa-rs` says `assertion.notRedacted`, and 2.4 says `redactionMismatch`.
   Proposal: `assertion.notRedacted`, as both oracles report it, so that
   the codes compare. *(not a blocker)*

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
