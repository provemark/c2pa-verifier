# SPEC-032: a `c2pa.created` without `digitalSourceType`, and the external-reference assertion

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

Step 121 put four known gaps to both oracles with signed probe files. Two
of them are rules `c2patool` enforces and this verifier does not. In each
case a manifest the oracle calls `Invalid` is `Trusted` here.

1. **A `c2pa.created` action without `digitalSourceType`, in a v2 claim.**
   `Invalid` in `c2patool` 0.27.22 *and* 0.28.0, with
   `assertion.action.malformed` (*"c2pa.created action must have a
   digitalSourceType"*). The rule has been out of scope by name since
   SPEC-018, as part of *"the content family of c2pa-rs's `verify_actions`
   2.b–2.f"*: *"a manifest that breaks one of them and nothing else is
   `Valid` here and `Invalid` at c2patool"*. It is `c2pa-rs` rule 2.b.v.
   C2PA 2.4 states it as a duty of the claim generator, in §18.15.2:
   *"a corresponding digitalSourceType field, with an appropriate value,
   shall be recorded with the c2pa.created action"*. The validation steps
   of §15.10.3.2.3 do not repeat it. `c2pa-rs` enforces it at validation
   for v2 claims. For v1 claims it skips everything beyond "one actions
   assertion", unless its non-default `strict_v1_validation` is set.
2. **A `c2pa.external-reference` assertion whose `label` names a forbidden
   assertion.** `Invalid` in 0.28.0 with
   `assertion.external-reference.malformed`; 0.27.22 said `Trusted`. C2PA
   2.4 §15.10.3.2.2 makes three checks *shall*: `location` holds a `url`,
   `alg` and `hash` come together or not at all, and a `label` is none of
   thirteen (the actions, cloud-data, external-reference, hash and
   ingredient labels). `StatusCode` has no such case.

Neither lets a changed byte through. Both are rules about what a manifest
may say (`docs/conformance.md`, category 2). This verifier's first design
rule is that its verdict means what `c2patool`'s means. For the first rule
that already held against the pinned oracle, and a named leniency stood
in the way. For the second, it holds against the current oracle.

Measured in step 121: across 151 `c2pa.created` actions in the corpus, 19
lack a `digitalSourceType`. Eighteen are in v1 claims, which `c2patool`
does not check, and one (`c2pa-rs/no_alg.jpg`) is refused by `c2patool`
for its algorithm before the actions are read. The corpus holds **no**
external-reference assertion.

## Scope

**In scope**

- **Rule A.** In a **v2 claim**, every action whose `action` is
  `c2pa.created` in every actions assertion (`c2pa.actions.v2`, created or
  gathered, any instance) must carry a `digitalSourceType` string. If one
  does not, the result is `assertion.action.malformed`, with the url and
  explanation `c2patool` records (fixed in the tests-first step), and
  `Invalid`. v1 claims are untouched, as `c2pa-rs` leaves them. It lives in
  `ActionsCheck` (SPEC-018), which already runs on the active manifest and,
  through SPEC-021, on ingredient manifests.
- **Rule B.** Every assertion labelled `c2pa.external-reference` (any
  instance, created or gathered, any claim version) must be a CBOR map
  with a `location` map whose `url` is a non-empty string. `alg` and
  `hash` must be both present and non-empty, or both absent. A `label`, if
  present, must not be one of the forbidden labels (open question 1).
  Otherwise the result is one `assertion.external-reference.malformed`
  per assertion, on that assertion's url, naming the first fault, and
  `Invalid`. **Nothing is fetched**: the data behind `url` is never
  retrieved (no network in the verification path).
- `StatusCode` grows by one case, `AssertionExternalReferenceMalformed =
  'assertion.external-reference.malformed'`, a failure. The public
  contract grows by one symbol (SPEC-025 amendment).
- SPEC-018's out-of-scope list loses the `digitalSourceType` item (a
  SPEC-018 amendment). The rest of the content family stays out of scope.

**Out of scope** (each needs its own spec before it may be built)

- `assertion.external-reference.hashMismatch` and `.labelMismatch`: both
  need the referenced data fetched, and this verifier never does that.
  Named, not built.
- `digitalSourceType` on actions other than `c2pa.created` (issue #2's
  general case). Neither oracle enforces it (step 121), and §15 does not
  ask for it.
- Whether a `digitalSourceType` value is one of the IPTC or C2PA terms
  (§18.15.x). `c2pa-rs` does not check it either.
- `reviewRatings` beside a `humanEntry` data source (issue #1): no oracle
  enforces it. An `icon` in `claim_generator_info` (issue #11): no probe
  could be built.
- The rest of `c2pa-rs`'s actions content family (a second
  `created`/`opened`, ingredient parameters for `opened`/`placed`/`removed`,
  `softwareAgent` indices, templates, `c2pa.translated` languages).

## Behavior

- **AC1 — a `c2pa.created` without `digitalSourceType` in a v2 claim is malformed**
  - Given a signed probe: `fixture-unsigned.jpg` with one
    `c2pa.actions.v2` whose single action is `c2pa.created` without
    `digitalSourceType`, signed by a throwaway leaf under a throwaway
    intermediate and root (a `bin/make-*` script that shreds its keys)
  - When verified without settings and with the root as anchor
  - Then `assertion.action.malformed` with the url `c2patool` 0.28.0
    records, an explanation naming `c2pa.created` and
    `digitalSourceType`, and `Invalid` both times. `c2patool` 0.27.22 and
    0.28.0 recorded `Invalid` with the same code.

- **AC2 — v1 claims keep their verdicts**
  - Given the 18 corpus manifests in v1 claims whose `c2pa.created` has no
    `digitalSourceType` (step 121's list, among them
    `public-testfiles/adobe-20220124-C.jpg` and the parent of
    `c2pa-rs/update_manifest.jpg`)
  - When verified as the drift alarms already verify them
  - Then no report changes. Over the whole corpus, run before and after
    under the three standard settings, no verdict and no failure code
    moves.

- **AC3 — the probe's control stays `Trusted`**
  - Given the same probe family with `digitalSourceType` present, and a
    second action `c2pa.edited` without one (enforced by no oracle,
    step 121)
  - When verified with the root
  - Then `Trusted`, as both oracles.

- **AC4 — a forbidden external-reference label is malformed**
  - Given a signed probe with an `c2pa.external-reference` assertion
    `{label: "c2pa.actions.v2", location: {url: "https://example.com/x"}}`
    beside a valid actions assertion
  - When verified with the root
  - Then exactly one `assertion.external-reference.malformed` on that
    assertion's url, its explanation naming the label, and `Invalid`.
    `c2patool` 0.28.0 recorded the same code, and 0.27.22 recorded
    `Trusted` (named in `docs/comparison.md` as 0.28.0's rule).

- **AC5 — the location must hold a url, and a hash its algorithm** *(error paths)*
  - Given signed probes whose external-reference assertion has, in turn:
    no `location`; a `location` without `url`; an empty `url`; `alg`
    without `hash`; `hash` without `alg`; and data that is not a map
  - When verified
  - Then each yields one `assertion.external-reference.malformed` naming
    its fault, and `Invalid`. How each probe is built, and what
    `c2patool` 0.28.0 says of it where its builder can write it at all, is
    measured in the tests-first step (open question 3).

- **AC6 — a well-formed external reference passes, and nothing is fetched**
  - Given signed probes with an unhashed external reference (a `url` and
    no `label`), and a hashed one (`url`, `alg`, `hash`, and a `label` not
    forbidden)
  - When verified with the root
  - Then `Trusted` with no `assertion.external-reference.*` status. The
    verification opens no network connection, which is checked the way
    the README's no-network claim already is.

- **AC7 — the vocabulary grows by one code, verbatim**
  - Given `StatusCode`
  - When read
  - Then it has `AssertionExternalReferenceMalformed` with the value
    `assertion.external-reference.malformed`, `isFailure()` true. The
    recorded API surface grows from 111 to 112 symbols (SPEC-025
    amendment), and the drift alarms still pass.

## References

- Specification: C2PA 2.4 §15.10.3.2.2 (*c2pa.external-reference
  validation*), §15.10.3.2.3 (*c2pa.actions validation*), §18.15.2
  (*Mandatory presence of at least one actions assertion*: the
  `digitalSourceType` duty), §18.24 (*External Reference*). All were read
  in the 2.4 HTML of `c2pa-org/specifications` at `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22 on step 121's probes (to be
  rebuilt as committed fixtures). `c2pa` 0.91.0 `claim.rs`
  `verify_actions()` (rule 2.b.v, the v1 skip) and
  `verify_external_reference()`, and
  `assertions/external_reference.rs` `validate()`, read.
- Measured: step 121 (the probes, both oracles) and this spec's corpus
  scan (151 `c2pa.created` actions, 19 without `digitalSourceType`, 18 in
  v1 claims, one refused earlier; no external-reference assertion).

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): one case more
case AssertionExternalReferenceMalformed = 'assertion.external-reference.malformed';

// Manifest\ActionsCheck (internal): rule A inside the existing per-action loop, v2 claims only

// Manifest\ExternalReferenceCheck (new, internal, final readonly)
/** @return list<ValidationStatus> one per malformed external-reference assertion */
public function check(Manifest $manifest): array;
```

## Open questions

*Answered on approval, 2026-09-24:* question 2 by the maintainer (follow
`c2patool`). Questions 1, 3 and 4 were settled by adopting their
proposals.

1. **Thirteen labels or fourteen?** §15.10.3.2.2 lists thirteen. `c2pa-rs`
   forbids fourteen, adding `c2pa.action`, which is not a C2PA label.
   Proposal: fourteen, following the oracle. The extra entry can only
   refuse a reference to a label that does not exist, which is the
   fail-closed direction and costs no real file. *(not a blocker)*
2. **Rule A is `c2patool`'s, not §15's.** 2.4 states it as a generator
   duty (§18.15.2), and the validation steps are silent. Proposal: follow
   `c2patool`, which is this project's first design rule, and write the
   provenance into the explanation and `docs/comparison.md`, so that
   nobody takes it for a §15 rule. *(blocker: your call)*
3. **How the malformed-location probes are built.** `c2patool`'s builder
   refused an invalid `icon` outright in step 121, and may refuse a
   `location` without `url` too. Proposal: try `c2patool` first. Where it
   will not write the shape, re-sign a modified store with the CBOR and
   signing helpers the `bin/make-*-variants.php` scripts already use,
   throwaway keys shredded. Such a probe has no oracle, and the test says
   so, as SPEC-030 AC3 did. *(not a blocker)*
4. **`checks_performed`.** Rule B is a check of its own. Naming it there
   (`externalReferences`) would change the report of every file, a drift
   touching every recorded expectation for a check that finds nothing in
   the whole corpus. Proposal: name it only when the manifest carries an
   external-reference assertion. *(not a blocker)*

## Amendments

1. **2026-09-24, step 122b, at implementation.**
   - (a) AC5's two lone-field probes (`alg` without `hash`, `hash` without
     `alg`) are `Trusted` in both oracles, because `c2pa-rs` reads them as
     unhashed. AC5 follows §15.10.3.2.2, and its test records the
     oracles' answer per probe. This was measured in 122a, before any
     code.
   - (b) AC1's test compared the oracle's whole failure list. Without
     settings that list also holds `signingCredential.untrusted`, so the
     test now compares this rule's entries only. The url matched from the
     first run.
   - (c) AC7 finds the code among `StatusCode::cases()` rather than through
     `tryFrom()`, which PHPStan flags as always non-null once the case
     exists.
   - (d) Two older tests counted what this spec grows: SPEC-015 AC10 (45 →
     46 codes) and SPEC-025's surface (111 → 112, SPEC-025 amendment 5).
     SPEC-018 AC2 now expects `c2pa-rs/no_alg.jpg` to carry rule A's
     fault (SPEC-018 amendment 4).

   Weight C: no criterion changed in outcome.

   Confirmed by Maurice van Loon, 2026-09-24 (step 122).

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/AssertionRulesTest.php :: AC1: a c2pa.created without digitalSourceType in a v2 claim is malformed / SPEC-032 | src/Manifest/ActionsCheck.php :: checkAssertions() (rule A) |
| AC2 | tests/Unit/Manifest/AssertionRulesTest.php :: AC2: v1 claims keep their verdicts / SPEC-032 | src/Manifest/ActionsCheck.php :: checkAssertions() (v1 returns before rule A) |
| AC3 | tests/Unit/Manifest/AssertionRulesTest.php :: AC3: the probe's control stays Trusted / SPEC-032 | src/Manifest/ActionsCheck.php :: checkAssertions() |
| AC4 | tests/Unit/Manifest/AssertionRulesTest.php :: AC4: a forbidden external-reference label is malformed / SPEC-032 | src/Manifest/ExternalReferenceCheck.php :: check(), fault(), FORBIDDEN_LABELS; src/Verifier/Verifier.php :: check() |
| AC5 | tests/Unit/Manifest/AssertionRulesTest.php :: AC5: the location must hold a url, and a hash its algorithm / SPEC-032 | src/Manifest/ExternalReferenceCheck.php :: fault() |
| AC6 | tests/Unit/Manifest/AssertionRulesTest.php :: AC6: a well-formed external reference passes, and nothing is fetched / SPEC-032 | src/Manifest/ExternalReferenceCheck.php :: present(); src/Verifier/Verifier.php :: check() (externalReferences); src/Verifier/IngredientManifestCheck.php |
| AC7 | tests/Unit/Manifest/AssertionRulesTest.php :: AC7: the vocabulary grows by one code, verbatim / SPEC-032 | src/Report/StatusCode.php :: AssertionExternalReferenceMalformed |
