# SPEC-070: Ingredient failures the signer acknowledged are reported, not dropped

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-13 (with release 0.7.0)  |
| Supersedes | SPEC-021 AC4 in one respect: what the ingredient assertion recorded is no longer absent from the validation results |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

C2PA 2.4 §18.16.12.4: a failure recorded in an ingredient assertion
(`validationStatus` in v1/v2, `validationResults` in v3) *"is considered an
explicit statement by the claim generator that an actor has acknowledged
validation errors in the ingredient's C2PA Claim itself and has chosen to
proceed"*. `c2pa-rs` implements that by dropping every status the
ingredient assertion recorded from the report. SPEC-021 AC4 copies it
(Maurice's decision, 2026-09-22, step 53 §4.2), with `c2pa-rs`'s guard
that no status about the active manifest is ever dropped (CAI-12751). So
`public-testfiles/adobe-20220124-CIE-sig-CA.jpg`, whose ingredient's
signature is genuinely broken, is `Trusted` here and in `c2patool`, and its
validation results say nothing about that ingredient.

N. Krawetz, "C2PA Issues and Evaluation" (2025-06-09, "Malicious nested
manifest alteration"), describes the abuse. A signer may alter an
ingredient's manifest and record its failures, and every validator that
follows the rule then hides the alteration. The record stays visible in
the manifest rendering (`manifests[<label>].ingredients[].validation_status`,
SPEC-020). But a caller who reads only the validation results, as the
WordPress plugin and the demo do, sees a clean `Trusted` and nothing more.

The rule itself is the specification's, and the verdict stays as it is.
What this spec changes is that the dropped statuses are reported as
informational, with an explanation that says who acknowledged them, so the
fact is in the validation results and never silent.

## Scope

**In scope**

- Every status `IngredientManifestCheck::drop()` removes today: it is kept,
  marked as acknowledged, and rendered under that ingredient's
  `validationDeltas.informational`.
- The verdict: an acknowledged status counts as neither a failure nor a
  success (`ValidationResult::fromStatuses()`).

**Out of scope** (each needs its own spec before it may be built)

- Changing the rule: acknowledged failures still do not make a file
  `Invalid`. That would be a refusal against the specification, and the
  maintainer decided otherwise in step 53.
- The guard (never dropped about the active manifest, nor about an update
  manifest's binding manifest): unchanged, these stay failures.
- What the plugin and the demo display: each repository decides that for
  itself, from the new informational.

## Behavior

- **AC1 — an acknowledged failure is reported as informational**
  - Given `public-testfiles/adobe-20220124-CIE-sig-CA.jpg`,
    `c2pa-rs/CIE-sig-CA.jpg` and `c2pa-rs/CACAE-uri-CA.jpg` (SPEC-021 AC4)
  - When the Verifier runs, with and without the full trust settings
  - Then the state is what it is today (`Trusted` / `Valid`, as
    `c2patool`), and each status the ingredient assertion recorded appears
    once under that ingredient's `validationDeltas.informational`, with
    its own code and url. Its explanation starts with *"acknowledged by the
    signer of <manifest label> in its ingredient assertion (C2PA 2.4
    §18.16.12.4), not counted in the verdict:"* and then gives the original
    explanation. It is not in `validation_status`, which holds failures only.

- **AC2 — the guard is unchanged** *(required: error path)*
  - Given the cases that pin the guard today
    (`tests/Unit/Verifier/IngredientManifestCheckTest.php`: a recorded
    status whose url names the active manifest, and a recorded failure
    about an update manifest's binding manifest, SPEC-022 amendment 6)
  - When `drop()` runs
  - Then those statuses come back unmarked, so they stay failures and
    make the result `Invalid`. Every other recorded status comes back
    marked as acknowledged. The existing tests that expect it to be absent
    (`drop()` returning `[]`) change to expect it marked. That is the
    one place SPEC-021's tests move.

- **AC3 — the verdict ignores an acknowledged status**
  - Given a `ValidationResult` built from one success
    (`signingCredential.trusted`) and one acknowledged
    `claimSignature.mismatch` scoped to an ingredient
  - When its state is computed
  - Then it is `Trusted`. Without the acknowledged mark, the same statuses
    give `Invalid`.

- **AC4 — nothing else moves**
  - Given the corpora and every fixture
  - When verified before and after
  - Then no state changes. The only new lines are the informational of
    AC1, on the files whose ingredient assertions recorded failures.

## References

- Specification: C2PA 2.4 §18.16.12.4 (recorded validation results),
  §15.11.3 (ingredient validation).
- Oracle: `c2patool` 0.28.1/0.28.2 report nothing for these statuses. This
  verifier says more, in informational only. The state is equal, as the
  drift alarm checks.
- Prompted by: N. Krawetz, "C2PA Issues and Evaluation" (2025-06-09),
  "Malicious nested manifest alteration" and "Mandatory nested manifest
  alteration".
- Reasoned: that no caller treats an informational as a failure. The flat
  `validation_status` list (what the sister library reads) holds failures
  only, so it does not change.

## API sketch

```php
// Report\ValidationStatus — one new constructor parameter, last, default false:
public function __construct(
    public StatusCode $code,
    public string $url,
    string $explanation,
    public ?string $ingredientUri = null,
    public bool $acknowledged = false,   // SPEC-070: recorded by a signer, reported, not counted
) {}

// Report\ValidationResult::fromStatuses(): skip $status->acknowledged when looking for
// success or failure. toArray(): $kind = 'informational' when acknowledged.
// Verifier\IngredientManifestCheck::drop(): instead of filtering, return the status
// re-made with acknowledged: true and the explanation prefixed.
```

## Amendments

### Amendment 1 — failures only (decided by Maurice van Loon, 2026-10-13, step 355)

**Found while building.** An ingredient assertion records successes and
informational lines too (`claimSignature.validated`,
`assertion.hashedURI.match`, `ingredient.unknownProvenance`), and
`drop()` removed those as well. Read to the letter, "every status
`drop()` removes" would bring all of them back as acknowledged, which is
noise that hides nothing. `RedactionTest` AC1 showed it: five extra
lines on one ingredient.

**Change.** Only a recorded **failure** comes back marked as
acknowledged. A recorded success or informational line is dropped as
before. This matches the title and the Problem section, and the AC1
fixtures, all of which record failures. In Scope, read "every status" as
"every failure". AC1's assertion is unchanged.

### Amendment 2 — in v3, the list a failure was recorded in counts (approved by Maurice van Loon, 2026-10-22, step 363, and built in it)

**Found by measuring.** `recorded()` keys a recorded status by code and url
only, and `acknowledge()` compares those keys. The list a v3 assertion put
the status in (`success`, `informational` or `failure`) is read and then
lost. So a `claimSignature.mismatch` recorded under `success` acknowledges
the failure exactly as one recorded under `failure` does. This is the
deviation `docs/reading-c2pa-2.4.md` records under §18.16.12.4 (P08-7:
"the recorded type is not compared, as in `c2pa-rs`").

The specification compares on the kind. C2PA 2.4 §18.16.12.4.3: the
comparison *"shall consider the status type (success, informational, or
failure), code, and url, ignoring other fields"*. §15.11.3.3 asks the
validator for an *"equivalent entry"*. §18.16.12.4.1 makes the
acknowledgement a statement about a recorded *failure status*. A failure
code filed under `success` is not that statement.

Measured on 2026-10-22, outside this repository, with c2pa-python 0.38.1 and
the c2patool `sample/` test certificates. The setup: an ingredient X whose
claim signature was broken by one flipped byte (`Invalid`,
`claimSignature.mismatch`), and an asset that takes X as its parent and
records that code in a different place each time:

| X's `claimSignature.mismatch` recorded under | c2patool 0.28.2 and 0.27.22 | this verifier, v0.7.0 |
|---|---|---|
| `failure` | `Valid`, not reported | `Valid`, informational, acknowledged |
| nothing | `Invalid` | `Invalid` |
| `success` | `Valid`, not reported | `Valid`, informational, acknowledged |
| `informational` | `Valid`, not reported | `Valid`, informational, acknowledged |

c2pa-rs reads the list and then replaces the kind with one derived from
the code (`validation_results.rs`, `from_store`: `log_kind(s.code())`),
because a v1/v2 `validationStatus` has no lists. That reason holds for
v1/v2 only.

**How far it reaches, measured.** Every file under `tests/Fixtures` that
c2patool 0.27.22 reads (929 files) was scanned for what its ingredient
assertions record. No v3 record holds a failure code under `success` or
`informational`, so no fixture's verdict should move. The v2 records do
hold failures (`claimSignature.mismatch` in `c2pa-rs/CIE-sig-CA.jpg`,
`public-testfiles/adobe-20220124-CIE-sig-CA.jpg`,
`public-testfiles/adobe-20220124-E-uri-CIE-sig-CA.jpg` and
`ingredient-manifest/records-active-fault.png`;
`assertion.hashedURI.mismatch` in `c2pa-rs/CACAE-uri-CA.jpg`). Those must
stay as they are. Files c2patool cannot read were not in that scan; AC8
covers them.

**Change.**

- A v3 record (`validationResults`: `activeManifest` and every
  `ingredientDeltas[].validationDeltas`) keys each status by **kind**, code
  and url, where the kind is the list it was found in.
- A v1/v2 record (`validationStatus`) has no lists, so its kind is the
  code's own kind (`StatusCode`), as today.
- `acknowledge()` compares the reader's status by its own kind, code and
  url. A failure is acknowledged only when the record holds it as a
  failure.
- Nothing else changes: the guard, Amendment 1 (a matching recorded
  success or informational line is still dropped), and the pool
  `recordedInStore()` builds over the graph (SPEC-021 amendment 6).

So a failure recorded under `success` or `informational` is not
acknowledged. It stays a failure under that ingredient's
`validationDeltas.failure` and in `validation_status`, and the file reads
`Invalid`, as when nothing is recorded. This verifier then differs from
`c2patool` on such a file, by design; `docs/comparison.md` says so.

**New criteria.**

- **AC5 — a failure filed in another list is not acknowledged**
  *(required: error path)*
  - Given an asset whose v3 ingredient assertion records the ingredient's
    real `claimSignature.mismatch` under `success`, and one that records
    it under `informational`
  - When the Verifier runs, with and without the full trust settings
  - Then the state is `Invalid`, and the mismatch appears unmarked under
    that ingredient's `validationDeltas.failure` and in
    `validation_status`. `c2patool` 0.28.2 reads both `Trusted` with the
    settings and `Valid` without; the difference is stated in
    `docs/comparison.md`.

- **AC6 — the same failure under `failure` is still acknowledged**
  - Given the same asset with the mismatch recorded under `failure`
  - When the Verifier runs
  - Then the result is AC1's: `Valid`, the mismatch under
    `validationDeltas.informational`, marked as acknowledged.

- **AC7 — v1/v2 records are unchanged**
  - Given AC1's fixtures (all v2) and
    `ingredient-manifest/records-active-fault.png`
  - When the Verifier runs
  - Then every result is what it is on v0.7.0, line for line.

- **AC8 — nothing else moves**
  - Given the corpora and every fixture
  - When verified before and after
  - Then no state and no line changes, except on the AC5 and AC6
    fixtures.

**Fixtures.** AC5 and AC6 need three new signed files, made by a generator
in the style of `bin/make-ingredient-manifest-variants.php`: one
ingredient with a broken claim signature, and an active manifest whose v3
ingredient assertion records the mismatch under `failure`, `success` or
`informational`. Signed with throw-away keys, as that generator does
(Maurice's decision of 2026-09-21). The measurement above used
c2pa-python, which this repository does not depend on; its files are not
the fixtures.

**Open, for the maintainer.**

- **Release.** No public symbol moves (the contract stays at 136), but a
  crafted file that reads `Valid` on 0.7.0 reads `Invalid` after.
  Proposal: 0.7.1, with the CHANGELOG naming the behaviour change.
  *Decided by Maurice, 2026-10-22:* 0.7.1.
- **A success code recorded under `failure`.** With the kind in the key,
  it no longer matches the reader's own success, so that success line is
  kept instead of dropped. No verdict changes, and the scan found no such
  record. Proposal: accept it as a consequence rather than add a rule.
  *Approved with the amendment.*

## Open questions

- **The public contract (blocking).** `ValidationStatus` is one of the
  contract's classes, and `acknowledged` adds one symbol (135 → 136). It is
  additive: a caller constructing statuses by position is unaffected. Does
  that make the release 0.7.0 rather than 0.6.2? Proposal: 0.7.0, since
  the contract grows, with SPEC-068, SPEC-069 and its amendment 1 in the
  same release.
  *Status 2026-10-13 (step 355):* decided by Maurice: 0.7.0.
- **The code (non-blocking).** C2PA 2.4 has no code for "acknowledged".
  The proposal keeps the original code and marks it, rather than inventing
  one (brief §4: no vocabulary of our own). The cost is that a caller who
  reads only codes, not kinds, sees `claimSignature.mismatch` under
  informational. The kind and the explanation say what it is.
  *Status 2026-10-13 (step 355):* approved with the spec: the original
  code, marked. Checked on the callers this project knows: the WordPress
  plugin reads `validation_status` (failures only), and the sister
  library's `ManifestStoreParser` reads only `success` and `failure` from
  an ingredient's deltas. Neither sees the new lines as failures.

### Amendment 3 — a record speaks only for what lies below it (approved by Maurice van Loon, 2026-10-23, step 389)

**Found by review (2026-10-23).** `recordedInStore()` pools the recorded
statuses of every ingredient assertion of every manifest the graph
reaches, and `acknowledge()` matches a status against that pool by code,
url and kind, whoever recorded it. So a manifest's record can acknowledge
a status *about that manifest itself*. Replace ingredient B of a signed
file with anything, and give B an ingredient assertion of its own whose
record lists B's `claimSignature.mismatch` and A's
`ingredient.manifest.mismatch` of B: both are acknowledged, and the file
is `Valid`, or `Trusted`, while B is forged. Confirmed with the classes
themselves (`ManifestGraph::fromIngredients()`, `recordedInStore()`,
`acknowledge()`, `ValidationResult::fromStatuses()`: `Invalid` before,
`Valid` after). SPEC-021 AC11 is titled "only a *validated* manifest on
the graph may acknowledge a fault"; its amendment 6 built "reached".

`c2pa-rs` 0.91.1 does the same, read in its source
(`validation_results.rs`, `from_store()`): it collects the statuses of the
ingredient assertions of every claim the active manifest's tree
references, and drops a status that matches one "by content, not by
URI". Not run against a file.

C2PA 2.4 §18.16.12.4 makes the record "an explicit statement by the
claim generator that an actor has acknowledged validation errors in the
ingredient's C2PA Claim": a statement by the manifest that takes the
ingredient, about that ingredient. Nothing lets a manifest acknowledge
its own errors.

**The rule.** A recorded status acknowledges a status about manifest X
(the label in the status's url, `self#jumbf=/c2pa/X…`) only when the
manifest that holds the record is a **proper ancestor** of X in the
ingredient graph: X is reachable from it through ingredient references,
and it is not X. A record never acknowledges a status about the manifest
that holds it, nor about one beside or above it. A status whose url names
no manifest is never acknowledged. The guards of the active manifest and
of an update manifest's binding manifest stay.

So A's record of B's broken signature still acknowledges it
(`public-testfiles/adobe-20220124-CIE-sig-CA.jpg`, §18.16.12.4, unchanged),
and B's record of B's own faults does not: the file is `Invalid`.

**Criteria added.**

- **AC9 — a manifest cannot acknowledge its own faults** *(required: error
  path)*: a store where the active manifest A takes B, B is altered (its
  signature fails, A's hash of B fails), and B's own ingredient assertion
  records both faults; then neither is acknowledged and the verdict is
  `Invalid`. A unit test over the real classes and, where a generator can
  make it, a signed fixture.
- **AC10 — a record still speaks for what lies below it**: A's record of
  B's fault acknowledges it; a record held by B about its ingredient C
  acknowledges C's fault; a record held by C about B (C below B) does not.
- **AC11 — nothing else moves**: every fixture and corpus file, before and
  after, the same state, codes and acknowledgements, as measured with a
  copied `vendor/`.

### Amendment 4 — no record speaks for a cycle (approved by Maurice van Loon, 2026-10-24, step 410: "akkoord, begin met keuze 2")

**Found by the graph fuzzer.** A reference that leads back into the path
of the walk is a cycle, reported as `assertion.ingredient.malformed` on the
assertion that closes it (SPEC-020). Under amendment 3 a manifest above
that assertion could acknowledge it, and in step 407's random plans most
acknowledgements were of exactly this status. With it acknowledged, a
cyclic store came out `Valid` or `Trusted`. That was the route step 409's
redaction of the active manifest used (SPEC-035 amendment 7).
`c2patool` 0.28.2 refuses every cyclic store ("cyclic ingredient found in
path", exit 1, no report).

C2PA 2.4 §18.16.12.4 lets a claim generator acknowledge validation errors
"in the ingredient's C2PA Claim". A cycle is not an error in one
ingredient's claim. It is the shape of the store as a whole, and it
involves the acknowledging manifest's own reference. There is nothing a
signer could have checked and accepted.

**The rule.** `ManifestGraph` names the assertions whose reference closes a
cycle (`$cyclic`). `IngredientManifestCheck::recordedInStore()` leaves out
every record of `assertion.ingredient.malformed` on one of those
assertions, so no record acknowledges it and a cyclic store is `Invalid`.
A malformed assertion that closes no cycle (one this verifier could not
decode) can still be acknowledged under amendment 3. Other faults in a
cyclic store can be too.

**Criteria added.**

- **AC12 — a cycle is never acknowledged** *(required: error path)*: the
  graph names the closing assertion of a short and a long cycle and none
  in a chain; a record of that status held by the active manifest
  acknowledges nothing, and the verdict is `Invalid`; in the same cyclic
  graph a record of another fault below it still acknowledges that fault,
  and a non-cyclic malformed assertion is still acknowledged; end to end,
  `graph-fuzz-cycle-acknowledged/cycle-back-to-the-active-acknowledged.png`
  and `cycle-below-the-active-acknowledged.png` are `Invalid` with only
  `assertion.ingredient.malformed` unacknowledged, and `a-chain-of-three.png`
  is `Trusted`.
- **AC13 — nothing else moves**: no corpus file holds a cyclic reference
  (measured: 0 cyclic statuses in 7,159 runs, every fixture without
  settings, with `trust/full.settings.json` and with each directory's own
  settings), and every corpus run is the same before and after.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/AcknowledgedIngredientTest.php :: reports a failure the ingredient assertion recorded as informational, the verdict unchanged (4 cases) / SPEC-070 | src/Verifier/IngredientManifestCheck.php :: acknowledge() (was drop()); src/Report/ValidationResult.php :: toArray() (the informational kind); src/Report/ValidationStatus.php :: $acknowledged |
| AC2 | tests/Unit/Verifier/AcknowledgedIngredientTest.php :: leaves the guard as it was / SPEC-070; tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC4 (SPEC-021, the active-manifest case), AC11 | src/Verifier/IngredientManifestCheck.php :: acknowledge() (the guard) |
| AC3 | tests/Unit/Verifier/AcknowledgedIngredientTest.php :: lets an acknowledged status count for nothing in the verdict, and renders it as informational / SPEC-070 | src/Report/ValidationResult.php :: fromStatuses() |
| AC4 | the corpus before and after (notes/step-355-acknowledged-ingredient-failures.md) | — |
| Amendment 1 | tests/Unit/Manifest/RedactionTest.php :: AC1 (a recorded success stays dropped) | src/Verifier/IngredientManifestCheck.php :: acknowledge() (failures only) |
| AC5 (amendment 2) | tests/Unit/Verifier/AcknowledgedKindTest.php :: AC5 (amendment 2): a failure recorded under success or informational is not acknowledged (4 cases, each against c2patool 0.28.2's `Trusted`); amendment 2: the same holds for the deltas a v3 record carries for its own ingredients; amendment 2: a code this verifier does not know is kept, whatever list it is in / SPEC-070 | src/Verifier/IngredientManifestCheck.php :: kinds() (an entry counts only in its code's own list) |
| AC6 (amendment 2) | tests/Unit/Verifier/AcknowledgedKindTest.php :: AC6 (amendment 2): the same failure recorded under failure is still acknowledged / SPEC-070 | src/Verifier/IngredientManifestCheck.php :: kinds() |
| AC7 (amendment 2) | tests/Unit/Verifier/AcknowledgedKindTest.php :: amendment 2: a v3 record counts only where its list is the code's own kind; a v2 record keeps every entry / SPEC-070; tests/Unit/Verifier/AcknowledgedIngredientTest.php (AC1, unchanged); tests/Unit/Verifier/IngredientManifestCheckTest.php :: AC5 (SPEC-021, `records-active-fault.png`) | src/Verifier/IngredientManifestCheck.php :: recorded() (v1/v2 unfiltered) |
| AC8 (amendment 2) | the corpus before and after (notes/step-363-acknowledged-kind.md) | — |
| AC9 (amendment 3) | tests/Unit/Verifier/AcknowledgementScopeTest.php :: AC9: a manifest cannot acknowledge its own faults, nor its parent's hash of it; AC9: a cycle does not put a manifest below itself (2 datasets) / SPEC-070 | src/Verifier/IngredientManifestCheck.php :: recordedInStore(), below(), recordedBelow(), acknowledge() |
| AC10 (amendment 3) | tests/Unit/Verifier/AcknowledgementScopeTest.php :: AC10: a record speaks for what lies below it, and for nothing above or beside it (6 datasets); AC10: a status whose url names no manifest is never acknowledged / SPEC-070 | src/Verifier/IngredientManifestCheck.php :: recordedBelow() |
| AC11 (amendment 3) | the corpus before and after (notes/step-389-acknowledgement-scope.md); every existing test, four of them given the new record shape | — |
| AC12 (amendment 4) | tests/Unit/Verifier/AcknowledgementScopeTest.php :: AC12 (five tests, seven cases) / SPEC-070 | src/Manifest/ManifestGraph.php :: $cyclic, descend(); src/Verifier/IngredientManifestCheck.php :: recordedInStore() |
| AC13 (amendment 4) | the corpus before and after (notes/step-410-cycle-not-acknowledged.md) | — |

The tests were run red first: 6 failed (the property, the method and the
rendering did not exist yet). Building showed that recorded successes came
back too, and amendment 1 (failures only) was decided by Maurice the same
day. Existing tests that moved, all as this spec says: SPEC-021's `drop()`
cases (now `acknowledge()`, expecting the mark), its failure helper (skips
acknowledged), SPEC-063 AC7 (the recorded `cloud-data.hardBinding` is now
reported, acknowledged), and SPEC-025's surface count (135 → 136).
Mutations watched failing: the verdict counting acknowledged statuses (10
tests), rendering by code kind (5), an acknowledged `untrusted` holding
`Trusted` down (1), the active-manifest guard removed (2), successes
acknowledged too (3). `composer check` green (1,064 passed); the contract
136 symbols.

Amendment 2 (step 363): the tests were run red first, 5 failed (AC5's
four cases and the `recorded()` seam); AC6 passed before, as it pins what
0.7.0 already does. Mutations watched: dropping the list filter (5 tests
fail). Keeping or dropping an entry whose code this verifier does not know
changed no test at first, and cannot change a result: no status of its
own carries such a code. An independent review found that gap and a
second one (the filter on `ingredientDeltas[].validationDeltas` was not
exercised either); two tests were added for them, and each fails under its
mutation (the deltas left unfiltered, an unknown code dropped). Corpus against `v0.7.0` (921 fixture files × 2 modes, the new
directory included): only `acknowledged-kind/recorded-success.jpg` and
`recorded-informational.jpg` differ. `composer check` green (1,117
passed, 1 skipped); the contract stays at 136 symbols.

Amendment 3 (step 389): the new tests were run red first against the
pooled records, 5 of 8 failing (every case where a record spoke for its
own manifest or one above it); the three allowed cases passed and pin
§18.16.12.4. Three mutations were then watched: the old pooling (4
failed), a url without a manifest acknowledged (2 failed), and the holder
counted below itself, which first survived (no test had a cycle) and
failed once two cycle cases were added to AC9. The corpus before and
after: 0 of 1,854 runs moved. Four existing tests were given the new
record shape (`spec070Records()` in `tests/Shared.php`).
