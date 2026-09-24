# SPEC-034: icon references — `claim_generator_info`, software agents and templates

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

A generator-info map may carry an `icon`: a hashed URI to an embedded
`c2pa.icon` data assertion, or a hashed external URI (C2PA 2.4 §10.2.3.2).
So may the software agents of an actions assertion, a single action's
`softwareAgent`, and an action template (§18.15). The specification asks
the validator to check every one of them. §15.10.3.3 (*Validation of
References*) is invoked for the claim's `claim_generator_info` (§15.6.2), for a software agent's icon, and for a template's
icon (§15.10.3.2.3). It says:

- a hashed URI whose destination cannot be located fails with
  `hashedURI.missing`;
- a missing `hash` field, or a hash that does not match the box, fails
  with `hashedURI.mismatch`.

This verifier reads `claim_generator_info` and requires its `name`
(SPEC-007). It carries `icon` along unexamined, and it does not look at
actions icons at all. Issue #11 names the first half. SPEC-033 named the
second half out of scope, as *"the icon checks (`c2pa-rs` 2.e, 2.f, 2.h)"*.

`c2pa` 0.91.0 checks all four places through `verify_icons()`. For each
icon that is a hashed URI:
- it finds the assertion the url names in the claim's own assertion list;
- it compares the icon's `hash` with the hash the claim records for that
  assertion, and a difference is `assertion.hashedURI.mismatch`, on the
  icon's url;
- if the url names no claimed assertion but a data box, it accepts it
  without checking the hash;
- otherwise it reports `assertion.missing` (*"could not resolve icon
  address"*).

The icons in the actions assertion are checked only in v2 claims (v1
claims leave `verify_actions` early). The `claim_generator_info` icons
are checked in every claim version.

Measured for this draft: one corpus file carries an icon,
`writers/openai-20260826-c2pa_2x.png`. Its `claim_generator_info.icon`
names `self#jumbf=c2pa.assertions/c2pa.icon`, with a hash. No corpus file
has an icon in an actions assertion.

None of this lets a changed byte through. An icon is presentation, and
`c2pa.icon`'s own entry in the claim is already hash-checked (SPEC-011).
What is missing is the check that the icon *reference* is the one the
claim vouched for. It is category 2 of `docs/conformance.md`, and it is
the last of `c2pa-rs`'s actions and claim rules this verifier does not
apply.

## Scope

**In scope**

- Every `icon` in:
  - `claim_generator_info` (any claim version);
  - an actions assertion's `softwareAgents`;
  - an action's `softwareAgent`, when that is a generator-info map;
  - an actions assertion's `templates`.

  The last three apply in v2 claims only. Each is checked as `c2pa-rs`
  checks it. The rule lives in a new `@internal`
  `Manifest\IconReferenceCheck`, called where `ExternalReferenceCheck` is
  (the `Verifier` and `IngredientManifestCheck`).
- **A hashed URI into this manifest** (`url` naming a `c2pa.icon`, or any
  label): its label is looked up in the claim's `created_assertions` and
  `gathered_assertions`.
  - Found: the icon's `hash` must equal the hash the claim records for
    that entry, else `assertion.hashedURI.mismatch` on the icon's url.
    SPEC-011 has already compared that entry with the box.
  - Not found: the result is `assertion.missing` on the icon's url
    (*could not resolve icon address*).
  - A missing `hash` field counts as a mismatch, as §15.10.3.3 says.
- **An icon whose `url` points outside the manifest** (a hashed external
  URI) is `assertion.missing` (*could not resolve icon address*), as
  `c2pa-rs` reports it and as §10.2.3.2 requires (amendment 1). Its data
  is never fetched.
- `checks_performed` names `icons` only where the manifest carries an
  icon, as `externalReferences` does (SPEC-032).
- No new status code; the contract does not change.

**Out of scope**

- The data box fallback. `c2pa-rs` accepts an icon url that names a data
  box without checking it. Data boxes were removed from the
  specification (§18.12.1: *"In previous versions of this specification, a
  concept of a data box … was used"*) and this verifier does not read them. An icon that names one is
  `assertion.missing` here (open question 2).
- Checking that the icon's `c2pa.icon` assertion holds an image of the
  declared format.
- `activeManifest` in `c2pa.ingredient.v3`, which §15.10.3.3 exempts.

## Behavior

Probes are `fixture-unsigned.jpg`, signed under a throwaway root,
intermediate and leaf (a `bin/make-*` script, keys shredded). The builder
of `c2patool` 0.28.0 embeds an icon from a small image resource where it
can. A shape it cannot write (a hash that does not match, an icon naming
nothing) is made by rewriting the claim's CBOR and re-signing it with
the same throwaway keys, using the helpers the `bin/make-*-variants.php`
scripts share. Both `c2patool` versions judge every probe, whoever built
it (open question 3).

- **AC1 — an icon that matches passes**
  - Given the OpenAI file, and a probe whose `claim_generator_info` icon
    was embedded by `c2patool`'s builder
  - When verified (the probe with its root as anchor)
  - Then no status from this rule, `icons` in `checks_performed`, and the
    same verdict as both oracles. The OpenAI file's report does not
    change at all (AC6).

- **AC2 — a `claim_generator_info` icon whose hash differs** *(error path)*
  - Given the probe with one byte of the icon's `hash` changed in the
    claim, re-signed
  - When verified
  - Then exactly one `assertion.hashedURI.mismatch` on the icon's url, and
    `Invalid`, with the code and url `c2patool` 0.28.0 records.

- **AC3 — an icon that resolves to nothing** *(error path)*
  - Given the probe with the icon's url turned into
    `self#jumbf=c2pa.assertions/c2pa.icon__9`, re-signed
  - When verified
  - Then `assertion.missing` on that url, and `Invalid`, as `c2patool`
    0.28.0.

- **AC4 — icons in the actions assertion**
  - Given probes with a matching and a mismatching icon in `softwareAgents`,
    in an action's `softwareAgent`, and in `templates` (each built by the
    builder, or by rewriting where it cannot)
  - When verified
  - Then the matching ones give no status. Each mismatching one gives
    `assertion.hashedURI.mismatch` on its url, as `c2patool` 0.28.0. In a
    v1 claim (through the `ActionsCheck` seam, open question 4) nothing
    is reported for actions icons.

- **AC5 — an external icon resolves to nothing, and is not fetched** *(amendment 1)*
  - Given a probe whose `claim_generator_info` icon is a hashed external
    URI (`https://example.com/icon.png` with `alg` and `hash`), which the
    builder writes
  - When verified
  - Then `assertion.missing` on that url and `Invalid`, as `c2patool`
    0.28.0, with no network.

- **AC6 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after
  - When compared
  - Then no verdict and no failure code changes outside the new probes.
    The OpenAI file keeps its report byte for byte, except for
    `checks_performed`, which gains `icons`.

## References

- Specification: C2PA 2.4 §10.2.3.2 (*Generator Info Map*: *"The value of
  the icon field, if present, shall be a hashed URI"*), §15.6.2 (the claim
  validation step that sends `claim_generator_info`'s icon to
  §15.10.3.3), §18.12.1 (data boxes, from earlier versions), §15.10.3.2.3 (software agent and template icons),
  §15.10.3.3 (*Validation of References*), §15.10.4 (external data not
  retrieved). Read in the 2.4 HTML of `c2pa-org/specifications` at
  `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22. `c2pa` 0.91.0 `claim.rs`
  `verify_icons()`, its calls from `verify_actions()` (2.e.i, 2.f, 2.h)
  and from the claim's own validation (`claim_generator_info`), read.
- Measured: a corpus scan (one icon, OpenAI's `claim_generator_info`).

## API sketch

Illustrative.

```php
// Manifest\IconReferenceCheck (new, internal, final readonly)
/** @return list<ValidationStatus> */
public function check(Manifest $manifest): array;
public static function present(Manifest $manifest): bool;   // for checks_performed
```

## Open questions

*Answered on approval, 2026-09-24:* question 2 by the maintainer (the
proposal: an icon naming a data box is `assertion.missing`). Questions 1,
3 and 4 were settled by adopting their proposals.

1. **Compare with the claim's recorded hash, or rehash the box?**
   `c2pa-rs` compares the icon's hash with the hash the claim records for
   the named assertion. §15.10.3.3 says to hash the box. SPEC-011 has
   already proved the claim's hash equals the box's, so the two readings
   give the same answer whenever SPEC-011 passes. Proposal: compare with
   the claim's recorded hash, as the oracle does. It is one lookup, and
   it cannot disagree with §15.10.3.3 on a file SPEC-011 accepts.
   *(not a blocker)*
2. **Data boxes.** `c2pa-rs` accepts an icon that names a data box,
   without a hash check. Proposal: report `assertion.missing`. Data boxes
   are gone from 2.4, no corpus file has one, and an unchecked reference
   is exactly what this spec exists to refuse. This is stricter than the
   oracle, and it is named. *(blocker: your call)*
3. **Probes built by rewriting.** A mismatching icon cannot come from
   `c2patool`'s builder. Proposal: rewrite the claim's CBOR and re-sign
   with the throwaway keys, as `make-absence-variants.php` does, and then
   let both `c2patool` versions judge the result. The oracle stays an
   oracle, and only the building is ours. *(not a blocker)*
4. **v1 claims.** Actions icons in a v1 claim would need a v1 probe,
   which this project cannot sign from its v2 fixtures (SPEC-018 amendment
   1). Proposal: test the v1 exemption through the existing
   `ActionsCheck` seam. `claim_generator_info` icons in v1 claims are
   checked like v2's, and no v1 corpus file carries one. *(not a blocker)*

## Amendments

1. **2026-09-24, step 126a, measured and read before the tests; decided by
   Maurice van Loon.**
   - **AC5 turns around.** The draft left an external icon unchecked.
     `c2patool` 0.28.0, while signing that probe, already reports
     `assertion.missing` (*"could not resolve icon address
     (https://example.com/icon.png)"*). §10.2.3.2 says a claim generator's
     icon *"shall be a hashed URI. This hashed URI shall be to an embedded
     data assertion whose label is c2pa.icon"*. An external icon is
     therefore not a valid icon. AC5 and the scope now say
     `assertion.missing`, and nothing is fetched.
   - **Data boxes: refused, knowingly against a *should*.** The same
     paragraph of §10.2.3.2 goes on: *"Manifest Consumers should also
     support the data box approach recommended by earlier versions of this
     specification."* The maintainer weighed that against open question
     2 and kept the refusal (option A): no fixture and no corpus file has
     a data box, `c2pa-rs` accepts one without any hash check, and
     supporting it properly would mean reading data boxes, which this
     verifier does not. It is named as a departure from a *should* in
     `docs/comparison.md`. A real file with one would be its own spec.
   - The builder of `c2patool` 0.28.0 embeds an icon in all four places
     (`claim_generator_info`, `softwareAgents`, an action's
     `softwareAgent`, `templates`) as a hashed URI into a `c2pa.icon`
     assertion. Only the failing shapes need rewriting.

   Weight A for AC5 (an outcome changed before any test existed); the
   rest records a decision.

2. **2026-09-24, step 126a, measured on the probes before the tests.**
   - `c2patool` 0.28.0's builder leaves a `softwareAgents` icon as a
     resource reference (`{format, identifier}`) and embeds no `c2pa.icon`
     for it. `templates` and an action's `softwareAgent` get a proper hashed
     URI. `c2pa-rs` checks only hashed-URI icons, so the resource-reference
     form is `Trusted` in both oracles. This verifier does the same: an icon
     map without a `url` is not a reference and is not checked. A
     mismatching `softwareAgents` icon cannot be built, so AC4 covers
     `templates` and `softwareAgent` with failing probes, and
     `softwareAgents` with the resource-reference form only. All three run
     through the same code.
   - After an icon that resolves to nothing (AC3, AC5), `c2pa-rs` adds a
     second `assertion.missing` on the manifest itself (*"Failed to load
     manifest"*), because its `failure(...)?` stops loading there. That is
     how `c2pa-rs` aborts, not a rule. This verifier reports the icon's
     fault only, and the tests compare the entries on icon urls.
   - The probes are PNG, not JPEG: the store sits in one `caBX` chunk, so a
     same-length patch plus a recomputed chunk CRC keeps everything else
     byte for byte.

   Weight C: no rule changed; how AC4 is covered, and what is compared.

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
