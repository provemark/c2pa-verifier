# SPEC-034: icon references — `claim_generator_info`, software agents and templates

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
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
   *Status 2026-10-08 (step 279):* about a verdict, equal whenever SPEC-011 passes, as its text shows. Reasoned.
2. **Data boxes.** `c2pa-rs` accepts an icon that names a data box,
   without a hash check. Proposal: report `assertion.missing`. Data boxes
   are gone from 2.4, no corpus file has one, and an unchecked reference
   is exactly what this spec exists to refuse. This is stricter than the
   oracle, and it is named. *(blocker: your call)*
   *Status 2026-10-08 (step 279):* about a verdict, stricter than `c2pa-rs`, named.
3. **Probes built by rewriting.** A mismatching icon cannot come from
   `c2patool`'s builder. Proposal: rewrite the claim's CBOR and re-sign
   with the throwaway keys, as `make-absence-variants.php` does, and then
   let both `c2patool` versions judge the result. The oracle stays an
   oracle, and only the building is ours. *(not a blocker)*
   *Status 2026-10-08 (step 279):* a process question, no verdict.
4. **v1 claims.** Actions icons in a v1 claim would need a v1 probe,
   which this project cannot sign from its v2 fixtures (SPEC-018 amendment
   1). Proposal: test the v1 exemption through the existing
   `ActionsCheck` seam. `claim_generator_info` icons in v1 claims are
   checked like v2's, and no v1 corpus file carries one. *(not a blocker)*
   *Status 2026-10-08 (step 279):* a process question, no verdict.

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

   Confirmed by Maurice van Loon, 2026-09-24 (step 126).

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

   Confirmed by Maurice van Loon, 2026-09-24 (step 126).

3. **2026-11-10, step 522, measured in step 521 and read** *(approved by Maurice van Loon, 2026-11-10, with `hashedURI.missing` for open question 1)* —
   An icon that is not a hashed URI is `hashedURI.missing`.

   **Why.** Amendment 2 left an icon map without a `url` unchecked,
   because `c2pa` 0.91.0 did. `c2pa` 0.91.11 no longer does
   (CAI-13410, ported to the stable line in upstream #3003, from #2919).
   `verify_icons()` now takes every icon, and one that decodes as a
   resource reference fails:
   *"icon must contain a hashed URI, found resource reference: …"*,
   `hashedURI.missing`. Read in `sdk/src/claim/claim_verify.rs` at tag
   `c2pa-v0.91.11`. Measured in step 521: `icons/agents-icon.png` (a
   `softwareAgents` icon `{format, identifier}`) is `Invalid` in
   `c2patool` 0.28.6, with `hashedURI.missing` on
   `self#jumbf=/c2pa/<label>/c2pa.assertions/c2pa.actions.v2`. It is
   `Valid` here (`Trusted` with its root). This is the one file of the
   corpus on which this verifier is now more lenient than its oracle.

   The specification has said this all along. C2PA 2.4 §10.2.3.2: *"The
   value of the icon field, if present, shall be a hashed URI."* A
   resource reference binds nothing. A reader that shows the icon shows
   something the signature does not cover. Under the project's
   fail-closed rule that is refused, not skipped.

   **The rule (changes amendment 2's first point).**
   - An `icon` in any of the four places (Scope) that is **not a hashed
     URI** is `hashedURI.missing` (failure), one per icon, with an
     explanation that names the form found and §10.2.3.2.
   - The url is that of what holds the icon, as in `c2pa-rs`:
     - the actions assertion's URI for `softwareAgents`, an action's
       `softwareAgent` and `templates` (v2 claims only, as before);
     - the claim's URI for `claim_generator_info`
       (`self#jumbf=/c2pa/<label>/c2pa.claim.v2`, or `…/c2pa.claim` in a
       v1 claim), in every claim version. `to_claim_uri()` builds it that
       way (read).
   - **Not a hashed URI** means either of these:
     1. a map with a text `format` and a text `identifier`, a resource
        reference, even when it also has a `url`. `c2pa-rs` decodes
        `UriOrResource` untagged with `ResourceRef` first and does not
        deny unknown fields, so such a map is a resource reference there
        (read; open question 2);
     2. any other value that is not a map with a text `url`: a text, a
        number, an empty map.
   - A map with a text `url` and no resource-reference fields is checked
     as before (AC1 to AC5, SPEC-067).
   - Ingredient manifests are checked under the same rule, since
     `IngredientManifestCheck` calls the same check.
   - The new code is `hashedURI.missing`, as §15.10.3.3 and the §15.2.2
     table spell it and as `c2patool` 0.28.6 reports it. It is one new
     contract symbol, `StatusCode::HashedUriMissing` (open question 1).
     This resolves SPEC-088's F7 for icons only. Other hashed URIs
     without a destination stay `assertion.missing`, as before and as
     in `c2pa-rs`.

   **Criteria.**
   - **AC4 (changed):** the `softwareAgents` icon in resource-reference
     form (`agents-icon.png`) is no longer the passing case. It is
     `Invalid` with `hashedURI.missing` on the actions assertion's URI,
     equal to `c2patool` 0.28.6 in code and url.
   - **AC7 (new) — an icon that is not a hashed URI** *(error path)*
     - Given `agents-icon.png`, and new probes in which one icon is
       rewritten and the claim re-signed with throw-away keys:
       - a `claim_generator_info` icon as `{format, identifier}`;
       - a `templates` icon as `{format, identifier}`;
       - an action's `softwareAgent` icon as `{format, identifier}`;
       - a `claim_generator_info` icon with `url`, `alg`, `hash` *and*
         `format`, `identifier` (open question 2);
       - a `claim_generator_info` icon that is a text.
     - When verified, with the probes' root as anchor
     - Then each gives exactly one `hashedURI.missing` at the url above,
       and `Invalid`. Each probe is compared with `c2patool` 0.28.6 and
       0.28.3. Where 0.28.6 answers otherwise, the difference is named in
       an amendment before the tests. The text probe is the likely one:
       there `c2pa-rs` cannot decode the generator-info map at all.
   - **AC6 (changed):** over the corpus only `icons/agents-icon.png`
     changes its verdict (`Valid` → `Invalid`, `Trusted` → `Invalid`
     with its root). Measured in step 521: 0.28.6 reports
     `hashedURI.missing` on no other corpus file. Since it applies the
     same rule, no other corpus file holds an icon in resource-reference
     form.

   **Probes.** A new `bin/make-icon-form-variants.php` builds them. It
   reuses the rewrite-and-re-sign helpers, as amendment 2 did, and stores
   the answers of 0.28.6 and 0.28.3 under
   `tests/Fixtures/c2patool/icon-forms/`; the probes go under
   `tests/Fixtures/icon-forms/`. `bin/make-spec034-variants.php` and its
   pinned pair (0.28.0, 0.27.22) stay as they are.

   **Open questions.**
   1. **Which code: `hashedURI.missing` or `assertion.missing`?**
      `hashedURI.missing` is the letter of §15.10.3.3 and what
      `c2patool` 0.28.6 says, but it adds a symbol to the contract, so
      the release that carries it is a minor (0.11.0).
      `assertion.missing` is already in the enum and allows a patch
      (0.10.1), but this verifier would then report a different code
      from `c2patool` for the same fault: a named difference, and a
      second truth. The verdict is `Invalid` either way. Proposal:
      `hashedURI.missing`, kept on `main` until the next minor gathers
      it with other contract changes, unless you want it out sooner.
      *(blocker: your call)*
   2. **A map that is both.** A map with a valid `url` and `hash` and
      also `format` and `identifier` is a resource reference in
      `c2pa-rs` and `hashedURI.missing`. Reading only the `url`, it
      would pass here. §10.2.3.2 asks for a hashed URI, and the
      `hashed-uri-map` of the CDDL has no such keys. Proposal: follow
      `c2pa-rs`, measured on the probe first. *(not a blocker)*

   **Weight A:** a verdict moves (one corpus file, `Valid` →
   `Invalid`), and the contract gains a symbol.

4. **2026-11-10, step 522, measured on the probes before the tests** *(approved by Maurice van Loon, 2026-11-10, with `hashedURI.missing` for an icon that is a text)* —
   `bin/make-icon-form-variants.php` built three controls with
   `c2patool` 0.28.6's builder and five probes from them. Each probe
   replaces one icon with CBOR of the same length and is signed again
   with the throw-away leaf. Both versions judged each one with the
   probes' root as anchor. This verifier said `Trusted` for all eight
   before any change.

   | probe | 0.28.6 | 0.28.3 |
   |---|---|---|
   | the three controls | `Trusted` | `Trusted` |
   | `generator-icon-resource` | `Invalid`, `hashedURI.missing` on `…/c2pa.claim.v2` | `Trusted` |
   | `templates-icon-resource` | `Invalid`, `hashedURI.missing` on `…/c2pa.assertions/c2pa.actions.v2` | `Trusted` |
   | `action-agent-icon-resource` | `Invalid`, `hashedURI.missing` on `…/c2pa.assertions/c2pa.actions.v2` | `Trusted` |
   | `generator-icon-both` | `Trusted` | `Trusted` |
   | `generator-icon-text` | no report: *"claim could not be converted from CBOR: claim_generator_info is missing or invalid"* | the same |

   The resource references give exactly what amendment 3 said, in code
   and url. Two probes differ from it:

   - **A map with both forms is a hashed URI (open question 2
     answered by measurement).** Amendment 3 read it as a resource
     reference. That reading missed a type. `ResourceRef`'s `hash` is a
     text, so a map with a byte-string `hash` cannot decode as
     `ResourceRef`, and `c2pa-rs` takes the `HashedUri`. Rule point 1
     of "not a hashed URI" is therefore narrowed. **A map with a text
     `url` is checked as a hashed URI, whatever other keys it holds**:
     as before, and as both oracles do. Only a map without a text `url`
     is `hashedURI.missing`. AC7's probe `generator-icon-both` passes,
     with no status from this rule.
     A reasoned difference that stays: a map with a text `url`, a text
     `format` and `identifier`, and no `hash` is a resource reference in
     `c2pa-rs` (`hashedURI.missing`). Here it is a hashed URI without a
     hash, `assertion.hashedURI.mismatch` (AC6 of the original text).
     Both are `Invalid`; it was not probed.
   - **An icon that is a text.** `c2patool` cannot decode the claim and
     gives no report (exit 1). In this verifier such an icon is
     `hashedURI.missing` and `Invalid`, as amendment 3 says. The claim
     is read, the fault is named at the claim's URI, and the rest of the
     report is kept. Neither says `Valid`. Following `c2patool` would
     mean refusing the claim as malformed (`claim.malformed`). For a
     field whose only rule is §10.2.3.2's *"shall be a hashed URI"*,
     that would be a second rule for the same fault. Proposal: keep
     `hashedURI.missing`, a named code difference.

   - **AC7 (changed):** `generator-icon-resource`,
     `templates-icon-resource`, `action-agent-icon-resource`,
     `generator-icon-text` and `icons/agents-icon.png` each give exactly
     one `hashedURI.missing` at the url above, and `Invalid`. The first
     three are equal to 0.28.6 in code and url; `generator-icon-text` is
     the named difference. `generator-icon-both` and the three controls
     are `Trusted` with no status from this rule, as in both versions.

   **Weight B:** no verdict moves beyond amendment 3, except that one
   probe passes where amendment 3 expected a failure, as both oracles
   decide. One named code difference.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/IconReferenceTest.php :: AC1: an icon that matches passes / SPEC-034 | src/Manifest/IconReferenceCheck.php :: check(), present(); src/Verifier/Verifier.php :: check() (icons) |
| AC2 | tests/Unit/Manifest/IconReferenceTest.php :: AC2: a claim_generator_info icon whose hash differs / SPEC-034 | src/Manifest/IconReferenceCheck.php :: check() (the recorded hash) |
| AC3 | tests/Unit/Manifest/IconReferenceTest.php :: AC3: an icon that resolves to nothing / SPEC-034 | src/Manifest/IconReferenceCheck.php :: check() (assertion.missing) |
| AC4 (amendment 3) | tests/Unit/Manifest/IconReferenceTest.php :: AC4: icons in the actions assertion / SPEC-034 | src/Manifest/IconReferenceCheck.php :: actionsIcons(), icons(), check() (hashedURI.missing) |
| AC5 | tests/Unit/Manifest/IconReferenceTest.php :: AC5: an external icon resolves to nothing, and is not fetched / SPEC-034 | src/Manifest/IconReferenceCheck.php :: check() (a url outside the manifest) |
| AC6 | tests/Unit/Manifest/IconReferenceTest.php :: AC6: nothing else moves / SPEC-034 | src/Manifest/IconReferenceCheck.php; src/Verifier/IngredientManifestCheck.php |
| AC7 (amendments 3, 4) | tests/Unit/Manifest/IconReferenceTest.php :: AC7: an icon in resource-reference form is hashedURI.missing where it is held, as c2patool 0.28.6; AC7: an icon that is a text is hashedURI.missing on the claim; c2patool does not read the claim (amendment 4); AC7: a map with a url and a hash is a hashed URI whatever else it holds, and the controls pass (amendment 4); AC7: hashedURI.missing is a failure code / SPEC-034 | src/Manifest/IconReferenceCheck.php :: check(), icons() (the holder's url), form(); src/Report/StatusCode.php :: HashedUriMissing; bin/make-icon-form-variants.php; tests/Fixtures/icon-forms/ |
