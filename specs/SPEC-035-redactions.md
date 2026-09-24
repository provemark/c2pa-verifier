# SPEC-035: redactions — a redacted ingredient assertion, and the claim-signature method

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

A claim may redact assertions of an ingredient's manifest (C2PA 2.4 §6.8).
The assertion's box is removed or zeroed, and the redacting claim records
the URI in its `redacted_assertions` (§10.2.2, §10.3.2.1). The redacted
manifest's own claim still lists the assertion. Its box hash, and so the
ingredient's hash of the whole manifest, no longer matches.

SPEC-021 refused every store holding a claim with a non-empty
`redacted_assertions`, *"until a fixture exists"*. Step 128 made one with
`c2patool` 0.28.0's builder. A child made with `-p parent.jpg` redacts
`com.example.secret` from its parent, and both `c2patool` versions call
it `Trusted`. The ingredient delta holds `ingredient.claimSignature.validated`
(informational in 0.28.0). This verifier calls it **`Invalid`**
(`assertion.missing`): its manifest reader refuses the parent's listed
assertion before any redaction is read. That is a valid file refused, on
a shape the reference tool writes today.

The specification's validation steps for redaction:

- **§15.10.3.1.** Before an assertion is resolved, the validator checks
  whether its URI is in the list of redacted assertions.
  - If it is, a `c2pa.actions`/`c2pa.actions.v2` assertion is rejected
    with `assertion.action.redacted`, and any other is *"considered
    valid"*.
  - A claim whose `redacted_assertions` points into its own manifest is
    rejected with `assertion.selfRedacted`.
- **§15.11.3.3 and §15.11.3.3.1** (the ingredient's claim-signature hash
  method). When an ingredient manifest's box hash does not match
  because of redaction, the ingredient assertion's `claimSignature` is
  compared with that manifest's signature box instead:
  `ingredient.claimSignature.validated`, `.mismatch`, or `.missing` if
  the field is absent.
  - A URI declared redacted whose assertion is still present with
    content other than zero bytes is `assertion.notRedacted`.

`c2pa` 0.91.0 (`store.rs` `ingredient_checks()`) takes the claim-signature
route only when the manifest hashes differ **and** the manifest has
redactions **and** the ingredient assertion is v2 or later. A difference
without a redaction stays `ingredient.manifest.mismatch`.

None of this lets a changed byte of the asset through. The hard binding
belongs to the active manifest, and redaction removes assertions from
ingredient manifests only. What is at stake is refusing a valid file, and,
on the other side, never accepting a redaction the specification forbids.

## Scope

**In scope**

1. **The reader.** An assertion listed by a claim but absent from its
   store is accepted when another manifest in the store declares that
   assertion's absolute URI (`self#jumbf=/c2pa/<label>/c2pa.assertions/<x>`)
   in its `redacted_assertions`. Otherwise `assertion.missing` stays, as
   today. The redaction set is collected from every claim in the store
   before any manifest's references are checked.
2. **The hashed-URI check (§15.10.3.1).** An entry whose URI is redacted
   is not hashed. If its label is an actions label, it is
   `assertion.action.redacted`. Otherwise it passes.
3. **Self-redaction (§15.10.3.1).** A claim whose `redacted_assertions`
   names an assertion of its own manifest is `assertion.selfRedacted`.
4. **Not redacted (§15.11.3.3.1).** A URI declared redacted whose box is
   still present, with any content box or padding holding a non-zero
   byte, is `assertion.notRedacted`.
5. **The ingredient's claim-signature method** (§15.11.3.3.1, as `c2pa-rs`
   applies it). When SPEC-021's box hash does not match, the ingredient
   manifest has redactions declared against it, and the ingredient
   assertion is v2 or v3:
   - its `claimSignature` must be present, else
     `ingredient.claimSignature.missing`;
   - it must hash-match the ingredient manifest's signature box, giving
     `ingredient.claimSignature.validated` (informational, as 0.28.0), else
     `ingredient.claimSignature.mismatch`.

   Without a redaction, a mismatch stays `ingredient.manifest.mismatch`.
6. **SPEC-021's refusal is lifted.** Its out-of-scope items for redaction
   (the claim-signature method and `assertion.notRedacted`) are brought in
   by this spec.
- `StatusCode` grows by six cases: `assertion.action.redacted`,
  `assertion.notRedacted`, `assertion.selfRedacted` (failures), and
  `ingredient.claimSignature.validated` (informational),
  `ingredient.claimSignature.mismatch` and
  `ingredient.claimSignature.missing` (failures). The surface grows by six
  (a SPEC-025 amendment).

**Out of scope**

- The `c2pa.redacted` action's own `redacted` parameter
  (`assertion.action.redactionMismatch`, §15.10.3.2.3; `c2pa-rs` 2.d). Step
  123 found no oracle that refuses a `c2pa.redacted` without it
  (open question 3).
- The generator rule that an update manifest must not redact the hard
  binding that applies to the asset (§6.8). No validator step or code for
  it was found in 2.4 (open question 4).
- Redaction of data boxes, which SPEC-034 does not read.

## Behavior

The fixtures are built by a `bin/make-*` script under a throwaway CA.

1. A parent is signed with an extra `com.example.secret` assertion.
2. Children are made from it with `c2patool` 0.28.0's `-p` and
   `redactions`.
3. Where the builder will not write a shape, it is made by the
   same-length patch and re-sign of SPEC-034.

Both `c2patool` versions judge every file.

- **AC1 — a redacted ingredient assertion is accepted**
  - Given the step-128 shape: a child redacting `com.example.secret` from
    its parent, with and without a `c2pa.redacted` action
  - When verified with the root
  - Then `Trusted`, as both oracles. The ingredient delta holds
    `ingredient.claimSignature.validated`, informational, as 0.28.0
    records it. No `assertion.missing` appears, and no
    `ingredient.manifest.mismatch`.

- **AC2 — an ingredient's claim signature that does not match** *(error path)*
  - Given AC1's child with its ingredient assertion's `claimSignature`
    hash changed by one byte (re-signed)
  - When verified
  - Then `ingredient.claimSignature.mismatch`, scoped to the ingredient,
    and the verdict `c2patool` 0.28.0 gives.

- **AC3–AC5 together: SPEC-021's `ingredient-manifest/redacted.png`** *(error path)*
  - Given the existing fixture, a claim whose `redacted_assertions` names
    **its own** `c2pa.actions.v2` while the box is still present
  - When verified with its root
  - Then `assertion.selfRedacted`, `assertion.action.redacted` and
    `assertion.notRedacted`, each on the actions assertion's url, and
    `Invalid`: the three codes `c2patool` 0.28.0 records (0.27.22 records
    the first two). Today this verifier refuses it with `general.error`.
    The three criteria below separate the rules where a probe can hold
    one alone.

- **AC3 — a redaction of an actions assertion** *(error path)*
  - Given a child whose `redacted_assertions` names the parent's
    `c2pa.actions.v2`, if the builder writes it
  - When verified
  - Then `assertion.action.redacted` and `Invalid`, as `c2patool` 0.28.0.

- **AC4 — self-redaction** *(error path)*
  - Given a child whose `redacted_assertions` names an assertion of its own
    manifest (by rewriting, if the builder refuses)
  - When verified
  - Then `assertion.selfRedacted` and `Invalid`.

- **AC5 — declared redacted but still there** *(error path)*
  - Given a child declaring the parent's `com.example.secret` redacted,
    while the parent's box still holds its content (built by redacting
    nothing and adding the declaration by rewriting)
  - When verified
  - Then `assertion.notRedacted` and `Invalid`.

- **AC6 — a mismatch without a redaction stays a mismatch**
  - Given SPEC-021's existing fixture where an ingredient manifest's hash
    does not match and nothing is redacted
  - When verified
  - Then `ingredient.manifest.mismatch`, exactly as today. The
    claim-signature route is not taken.

- **AC7 — nothing else moves**
  - Given the whole corpus under the three standard settings, before and
    after
  - Then no verdict and no failure code changes outside the new fixtures,
    and the drift alarms pass.

- **AC8 — the vocabulary grows by six codes, verbatim**
  - Then the six cases exist with the values above.
    `ingredient.claimSignature.validated` is informational and the other
    five are failures. Each new symbol is in the recorded surface.

## References

- Specification: C2PA 2.4 §6.6, §6.8 (*Redaction of Assertions*), §10.2.2,
  §10.3.2.1, §15.10.3.1, §15.11.3.3, §15.11.3.3.1. Read in the 2.4 HTML of
  `c2pa-org/specifications` at `4eb2c67`.
- Oracles: `c2patool` 0.28.0 and 0.27.22 on step 128's child (both
  `Trusted`; 0.28.0's ingredient delta holds
  `ingredient.claimSignature.validated`, informational). `c2pa` 0.91.0
  `store.rs` `ingredient_checks()` (the conditions for the claim-signature
  route) and `claim.rs` (the redaction checks around its
  `redacted_assertions`), read.
- Measured: step 128. Also, for this draft: SPEC-021's
  `ingredient-manifest/redacted.png` under both oracles (0.27.22:
  `selfRedacted`, `action.redacted`; 0.28.0: `notRedacted` as well, all on
  the actions assertion's url).

## API sketch

Illustrative.

```php
// Report\StatusCode (contract): six cases more
case AssertionActionRedacted = 'assertion.action.redacted';
case AssertionNotRedacted = 'assertion.notRedacted';
case AssertionSelfRedacted = 'assertion.selfRedacted';
case IngredientClaimSignatureValidated = 'ingredient.claimSignature.validated';   // informational
case IngredientClaimSignatureMismatch = 'ingredient.claimSignature.mismatch';
case IngredientClaimSignatureMissing = 'ingredient.claimSignature.missing';

// Manifest\ManifestStore::fromTree(): collect every claim's redacted_assertions first, and give
// each Manifest the set that names its own assertions; Manifest::checkReferences() accepts those.
```

## Open questions

*Answered on approval, 2026-09-24:* question 1 by the maintainer (the
proposal: the union of every claim's `redacted_assertions` in the store).
Questions 2, 3 and 4 were settled by adopting their proposals.

1. **Where the redaction set comes from.** §15.10.3.1 speaks of *"the list
   of redacted assertions"* without saying whose. Proposal: the union of
   every claim's `redacted_assertions` in the store, because the redacting
   claim is not the redacted one. That is what `c2pa-rs`'s `has_redactions`
   reads, as far as the code shows. *(blocker: your call)*
2. **`ingredient.claimSignature.validated` as informational.** 0.28.0
   records it informational. In 2.4's status table it is not among the
   success codes as far as read. Proposal: informational, as the oracle
   does. *(not a blocker)*
3. **The `c2pa.redacted` action.** §15.10.3.2.3 wants its `redacted`
   field present and resolvable (`assertion.action.redactionMismatch`),
   but no oracle refused a bare `c2pa.redacted` (step 123). Proposal:
   leave it out of this spec and name it. *(not a blocker)*
4. **Hard-binding redaction.** §6.8 forbids redacting the hard binding in
   an update manifest, as a generator rule. Proposal: out of scope until a
   validation step or an oracle's behaviour is found. *(not a blocker)*

## Amendments

1. **2026-09-24, step 129a, measured before the tests.**
   `c2patool` 0.28.0's builder refuses the shapes AC3 and AC4 asked for:
   - a child redacting its parent's `c2pa.actions.v2`: *"assertion could
     not be redacted"*;
   - a child redacting its own assertion: *"could not find the assertion to
     redact"*.

   It also **removes** a redacted box rather than zeroing it, so AC5's
   *still there* shape cannot be made from a builder file at the same
   length. Surgery that inserts boxes is not done here (as SPEC-033 open
   question 4).

   All three rules are therefore covered by the combined criterion on
   `ingredient-manifest/redacted.png`, where `c2patool` 0.28.0 records
   `assertion.notRedacted`, `assertion.selfRedacted` and
   `assertion.action.redacted` on the same url. The tests assert each of
   the three codes there, one by one. AC3, AC4 and AC5 stand as the
   rules; their evidence is that fixture.

   Weight C: how the rules are evidenced, not what they say.

   Confirmed by Maurice van Loon, 2026-09-24 (step 129).

2. **2026-09-24, step 129a, measured and read before the tests.**
   A second existing fixture carries a redaction: SPEC-010's
   `binding/claim-redacted.png`. Its claim names its own actions
   assertion by a **relative** URI, `self#jumbf=c2pa.assertions/c2pa.actions.v2`.
   Both `c2patool` versions record `assertion.action.redacted` on that
   URI as written, and nothing else from these rules. 0.28.0 is
   re-measured here and 0.27.22 is recorded in step 21. This verifier says
   `general.error` today.

   `c2pa` `claim.rs` and `store.rs` (read at `6c92bc3`) explain the
   difference with `redacted.png`:
   - the three claim-level codes carry the `redacted_assertions` entry
     **verbatim** as their url;
   - `assertion.selfRedacted` applies when the entry contains the claim's
     own label, so a relative entry never gives it;
   - `assertion.action.redacted` applies when the entry contains
     `c2pa.actions`;
   - `assertion.notRedacted` applies when the entry resolves to a box that
     is still present and whose content is not all zero bytes. A relative
     entry does not resolve.

   The rules of AC3–AC5 are read that way. `claim-redacted.png` is AC3's
   evidence on its own, and it is the one existing file outside the new
   fixtures whose result AC7 lets move: `general.error` becomes
   `assertion.action.redacted`, still `Invalid`.

   The same code also refuses a redaction of a hard-binding assertion
   (`c2pa.hash.data`, `.boxes`, `.bmff`, `.collection`) with
   `assertion.hardBinding.redacted`. That is open question 4, left out of
   scope. Fail closed: a claim whose `redacted_assertions` names a
   hard-binding label keeps SPEC-021's refusal (`general.error`), so
   lifting the refusal never lets that case through. Adding the seventh
   code is a later choice.

   Weight B: one existing file changes its failure code, and one refusal
   stays.

   Confirmed by Maurice van Loon, 2026-09-24 (step 129).

3. **2026-09-24, step 129b, read while building.** `c2pa`'s
   `ingredient_checks()` takes the claim-signature route on the
   ingredient **claim's** version (2 or later), not the ingredient
   assertion's version. `has_redactions` means that an entry names that
   manifest. When the manifest has redactions but a v1 claim, `c2pa-rs`
   checks neither the box hash nor the claim signature, and says nothing.
   Here that manifest stays with the box hash, which then fails
   (`ingredient.manifest.mismatch`): a manifest that nothing binds is not
   passed on trust. No file has one.

   Also: `assertion.notRedacted` for a redacted box that is still present
   in an **ingredient** manifest is reported under that ingredient's delta
   here. `c2pa-rs` reports it at store level. The verdict is `Invalid`
   either way, and no fixture holds the case; `redacted.png`'s own box
   sits in the active manifest.

   **Weight B: a divergence that fails closed, and a difference of scope.**

   Confirmed by Maurice van Loon, 2026-09-24 (step 129).

4. **2026-09-24, step 130b, with SPEC-036's implementation** — amendment
   2's last paragraph is superseded. An entry naming a hard-binding label
   is no longer refused with `general.error`. It is
   `assertion.hardBinding.redacted`, as `c2pa-rs` reports it (SPEC-036).
   The verdict stays `Invalid`.

   **Weight C: a code where a refusal stood; no verdict changed.**

   Confirmed by Maurice van Loon, 2026-09-24 (step 130).

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/RedactionTest.php :: AC1: a redacted ingredient assertion is accepted / SPEC-035 | src/Manifest/Manifest.php :: redactionsOf(), withRedactions(), checkReferences(); src/Manifest/ManifestStore.php :: fromTree(); src/Hash/HashedUriCheck.php :: check() (a redacted entry that is gone); src/Verifier/IngredientManifestCheck.php :: hash(), claimSignature() |
| AC2 | tests/Unit/Manifest/RedactionTest.php :: AC2: an ingredient's claim signature that does not match / SPEC-035 | src/Verifier/IngredientManifestCheck.php :: claimSignature() |
| AC3 | tests/Unit/Manifest/RedactionTest.php :: AC3: a redaction of an actions assertion / SPEC-035; AC3–AC5 together / SPEC-035; tests/Unit/Hash/HashedUriCheckTest.php :: AC8 / SPEC-011 | src/Hash/HashedUriCheck.php :: redactions() |
| AC4 | tests/Unit/Manifest/RedactionTest.php :: AC4: self-redaction / SPEC-035; AC3–AC5 together / SPEC-035 | src/Hash/HashedUriCheck.php :: redactions() |
| AC5 | tests/Unit/Manifest/RedactionTest.php :: AC5: declared redacted but still there / SPEC-035; AC3–AC5 together / SPEC-035 | src/Hash/HashedUriCheck.php :: notRedacted() |
| AC6 | tests/Unit/Manifest/RedactionTest.php :: AC6: a mismatch without a redaction stays a mismatch / SPEC-035 | src/Verifier/IngredientManifestCheck.php :: hash() (the route only when the manifest has redactions and a v2 claim) |
| AC7 | tests/Unit/Manifest/RedactionTest.php :: AC7: nothing else moves / SPEC-035; the drift alarms (SPEC-013 AC10–AC13); the before/after run of step 129b | — |
| AC8 | tests/Unit/Manifest/RedactionTest.php :: AC8: the vocabulary grows by six codes, verbatim / SPEC-035 | src/Report/StatusCode.php (six cases; isInformational()); tests/Fixtures/api/public-surface.txt |
