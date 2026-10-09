# SPEC-063: The cloud-data assertion's structure — refused where C2PA 2.4 §15.10.3.2.1 refuses it

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-09                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A `c2pa.cloud-data` assertion stands in for an assertion that is kept
elsewhere: it holds the remote assertion's `label`, its `size` and a hashed
`location` (C2PA 2.4 §18.11). A validator never needs to fetch it, but
§15.10.3.2.1 tells it to check the stand-in's structure:

- `label`, `size` and `location` are present, else
  `assertion.cloud-data.malformed`;
- the label is not one a cloud-data assertion may not stand for. A hard
  binding gives `assertion.cloud-data.hardBinding`; the actions,
  ingredient and cloud-data labels give `assertion.cloud-data.malformed`;
- in an update manifest, an actions label gives
  `assertion.cloud-data.actions`.

This verifier reads no cloud-data assertion at all. SPEC-039 left the
three codes out "by name" because no writer produced them. The reading of
C2PA 2.4 (step 312, candidate L7) and step 313 measured what that costs:

| probe (step 313, scratch) | `c2patool` 0.28.1 | 0.27.22 | here |
|---|---|---|---|
| cloud data whose `label` is `c2pa.hash.data` | `Invalid` | `Trusted` | **`Trusted`** |
| cloud data whose `size` is 0 | `Invalid` | `Trusted` | **`Trusted`** |

A signer can thus claim that the hard binding lives elsewhere, or point
at nothing, and stay `Trusted` here where `c2patool` 0.28.1 refuses it. It
is the same kind of rule as SPEC-032's for `c2pa.external-reference`, which
this verifier already checks.

## Scope

**In scope**

- Every `c2pa.cloud-data` assertion the claim lists, created or gathered,
  any instance (`c2pa.cloud-data__1`, …), in the active manifest and in
  every ingredient manifest that is validated (as SPEC-032 rule B).
  Assertions whose hashed URI did not match are left unread: the file is
  already refused, and their contents are not what the signer saw.
- **Structure** (`assertion.cloud-data.malformed`): the assertion is a CBOR
  map; `label` is non-empty text; `size` is an integer of at least 1;
  `location` is a map with a non-empty text `url`, a text `alg` and a
  byte-string `hash`. §15.10.3.2.1 asks only that `size` is present.
  `c2pa-rs` asks at least 1, and so does this spec: a remote assertion of
  no bytes is no assertion. `location` is the hashed external URI §18.11
  requires ("its location is specified via a hashed URI"); `c2pa-rs` cannot
  decode one without `alg` or `hash`.
- **Labels.** A hard binding (`c2pa.hash.data`, `c2pa.hash.boxes`,
  `c2pa.hash.collection.data`, `c2pa.hash.multi-asset`,
  `c2pa.hash.bmff.v2`, `c2pa.hash.bmff.v3`) gives
  `assertion.cloud-data.hardBinding`. `c2pa.action`, `c2pa.actions`,
  `c2pa.actions.v2`, `c2pa.cloud-data`, `c2pa.ingredient`,
  `c2pa.ingredient.v2` and `c2pa.ingredient.v3` give
  `assertion.cloud-data.malformed`. In an update manifest, `c2pa.actions`
  and `c2pa.actions.v2` also give `assertion.cloud-data.actions`. That is
  how `c2pa-rs` 0.91.1 splits §15.10.3.2.1's two lists, which in the
  specification's text are the same list.
- The three codes enter `StatusCode` verbatim, as failures.
- The report names the check (`cloudData`) only where the claim carries a
  cloud-data assertion, as SPEC-032 does for external references.
- A probe builder and fixtures, judged by both `c2patool` versions.

**Out of scope** (each needs its own spec before it may be built)

- Retrieving the remote assertion, and `assertion.cloud-data.labelMismatch`
  (§15.10.3.2.1 last bullet, §15.10.4.2): this verifier has no network in
  the verification path, and never will.
- `content_type` (deprecated, §18.11.1) and `metadata` (§15.10.3.1: never
  validated).
- The four other candidates of the same reading (L4 metadata `@context`,
  L6 certificate status, L8 soft binding, P08-3 action field types): by
  design. §15.10.3.2 lists no validation for them. Maurice decided on
  2026-10-09 to name the difference with `c2patool` in a separate step
  (amendment 2, AC9).

## Behavior

- **AC1 — a hard binding stored as cloud data is refused** *(error path; oracle: `c2patool` 0.28.1)*
  - Given `manifest-probes/cloud-hash-data.png`, a cloud-data assertion
    whose `label` is `c2pa.hash.data`
  - When the Verifier runs under the probes' settings
  - Then it is `Invalid` with `assertion.cloud-data.hardBinding`, naming
    the label, as in `c2patool` 0.28.1

- **AC2 — a size below 1 is malformed** *(error path; oracle: `c2patool` 0.28.1)*
  - Given `manifest-probes/cloud-size-zero.png`, `size` 0
  - When the Verifier runs
  - Then it is `Invalid` with `assertion.cloud-data.malformed` naming the
    size, as in `c2patool` 0.28.1

- **AC3 — a forbidden label other than a hard binding is malformed** *(error path; oracle: `c2patool` 0.28.1)*
  - Given `manifest-probes/cloud-actions.png`, `label` `c2pa.actions.v2`,
    in a manifest that is not an update manifest
  - When the Verifier runs
  - Then it is `Invalid` with `assertion.cloud-data.malformed` naming the
    label, and without `assertion.cloud-data.actions`

- **AC4 — a missing field is malformed** *(error paths; oracle: `c2patool` 0.28.1 where a probe exists)*
  - Given `manifest-probes/cloud-no-location.png` (the key renamed), and
    in the unit test the decoded maps without `label`, without `size`, with
    `size` as text, with `location` not a map, without `location.url`, with
    an empty `url`, without `alg`, without `hash`, with `hash` as text
  - When checked
  - Then each gives `assertion.cloud-data.malformed` naming the field

- **AC5 — an actions label in an update manifest is `assertion.cloud-data.actions`** *(error path)*
  - Given the decoded map with `label` `c2pa.actions.v2`, checked as part
    of an update manifest (unit test: no writer makes this probe)
  - When checked
  - Then it gives `assertion.cloud-data.actions` and
    `assertion.cloud-data.malformed`, as `c2pa-rs` does

- **AC6 — a well-formed cloud-data assertion passes, and nothing is fetched**
  - Given `manifest-probes/cloud-ok.png`, a cloud-data assertion for a
    permitted label with a size and a hashed location whose `url` names a
    host that does not exist
  - When the Verifier runs
  - Then it is `Trusted` as in both `c2patool` versions, the report names
    the check `cloudData`, and no status names the url's contents

- **AC7 — in an ingredient manifest too** *(amendment 1)*
  - Given `cloud-in-ingredient-unrecorded.png` (signed by `c2patool`
    0.27.22 with `cloud-hash-data.png` as its parent, so the ingredient
    records no cloud-data failure) and `cloud-in-ingredient.png` (the same
    signed by 0.28.1, which records it)
  - When the Verifier runs
  - Then the first is `Invalid` with `assertion.cloud-data.hardBinding`
    for that ingredient, and the second is `Trusted`: a recorded failure
    is no delta (SPEC-021). Both as in `c2patool` 0.28.1

- **AC8 — the vocabulary grows by three codes, verbatim**
  - `assertion.cloud-data.malformed`, `assertion.cloud-data.hardBinding`
    and `assertion.cloud-data.actions` are in `StatusCode` exactly as
    `c2pa-rs` 0.91.1 spells them, and all three are failures

## References

- Specification: C2PA 2.4 §15.10.3.2 (specific assertion validation),
  §15.10.3.2.1 (`c2pa.cloud-data` validation), §18.11 (cloud data, its
  schema), §15.10.4 (external references: not retrieved).
- Oracle: `c2patool` 0.28.1 and 0.27.22, on fixtures from
  `bin/make-manifest-probe-variants.php` under
  `tests/Fixtures/manifest-probes/`, with
  `--settings throw-away-root.settings.json`. 0.27.22 does not check
  cloud data (step 313); 0.28.1 does.
- Read: `c2pa-rs` 0.91.1 `claim.rs` `verify_cloud_data()`,
  `assertions/cloud_data.rs` (`CloudData`, `HashedExtUri`, `is_forbidden()`),
  `assertions/labels.rs` `is_hard_binding_label()`.
- Reasoned: that the specification's two identical lists in §15.10.3.2.1
  are one list split by purpose, as `c2pa-rs` splits them.

## API sketch

```php
namespace Provemark\C2paVerifier\Manifest;

/** @internal SPEC-025 */
final readonly class CloudDataCheck
{
    public const LABEL = 'c2pa.cloud-data';
    public const HARD_BINDING_LABELS = [/* six */];
    public const FORBIDDEN_LABELS = [/* seven */];

    /** @param list<string> $unreadable @return list<ValidationStatus> */
    public function check(Manifest $manifest, array $unreadable = []): array;

    public static function present(Manifest $manifest): bool;

    /** @return list<array{StatusCode, string}> the faults of one decoded assertion */
    public static function faults(mixed $data, bool $updateManifest): array;
}
```

## Amendments

1. **2026-10-09, step 321, measured while building** *(confirmed by Maurice van Loon, 2026-10-09)* —
   Two things the approved text did not foresee, as its open question
   said the tests would follow:
   - **`location.hash` as text.** `c2patool` 0.28.1 writes the hash from
     a manifest definition as base64 text, not a byte string, and calls
     the result `Trusted`; `c2pa-rs` reads either. Requiring a byte string,
     as §18.11's CDDL says, would refuse what `c2patool` writes. The check
     takes a byte string or non-empty text; `cloud-hash-bytes.png` covers
     the byte string.
   - **AC7 by probes, not a unit test.** `c2pa-rs` checks cloud data in
     ingredient manifests too, and reports a failure the ingredient did not
     record as a delta. One recorded at signing (0.28.1 records it) is not
     reported again. Both probes agree with 0.28.1, so this verifier is not
     stricter there.

   **Weight B: no verdict differs from `c2patool` 0.28.1 on any probe.**

2. **2026-10-09, step 322, decided by Maurice van Loon** *(confirmed by Maurice van Loon, 2026-10-09)* —
   The four other candidates of the reading, out of scope above, are
   named. §15.10.3.2 says an assertion not on its list *"does not require
   any additional validation steps"*, and §15.10.3.2.4 says *"No
   assertion-specific validation is required for c2pa.metadata"*. None of
   the four feeds a verdict here: not the binding, not the signer, not
   the timestamp. So their shape is not judged, and `c2patool`'s
   stricter decoding is a named difference.

   - **AC9 (new) — unlisted shapes are not judged.** Given
     `unlisted-control.png` (a well-formed actions `when`, metadata,
     certificate status and soft binding, signed by `c2patool` 0.28.1) and
     four probes that break one each: metadata without `@context` (L4),
     certificate status without `ocspVals` (L6), soft binding without
     `blocks` (L8), an action's `when` as the integer 123 (P08-3). Then all
     five are `Trusted` here. 0.28.1 cannot decode three and calls the
     soft binding `Invalid`; 0.27.22 also refuses the control (its old
     `@context` rule).

   **Weight B: four named differences, from the specification's own
   validator rule.**

## Open questions

- None blocking. If `c2patool` 0.28.1 reports a code set other than the
  one above on a probe, the tests follow the measurement and this spec
  gets an amendment before it is approved.
  *Status 2026-10-09 (step 321):* it happened after the approval: the
  hash as text and the ingredient case, recorded in amendment 1, confirmed
  by Maurice van Loon on 2026-10-09.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC1: a hard binding stored as cloud data is refused / SPEC-063 | src/Manifest/CloudDataCheck.php :: faults(), HARD_BINDING_LABELS; bin/make-manifest-probe-variants.php |
| AC2 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC2, AC3, AC4: size 0, an actions label and a missing location are malformed / SPEC-063 | src/Manifest/CloudDataCheck.php :: faults() (size) |
| AC3 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC2, AC3, AC4 / SPEC-063 | src/Manifest/CloudDataCheck.php :: faults(), FORBIDDEN_LABELS |
| AC4 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC4: each missing or mistyped field is malformed / SPEC-063 | src/Manifest/CloudDataCheck.php :: faults() |
| AC5 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC5: an actions label in an update manifest / SPEC-063 | src/Manifest/CloudDataCheck.php :: faults() ($updateManifest) |
| AC6 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC6: a well-formed cloud-data assertion passes / SPEC-063 | src/Manifest/CloudDataCheck.php :: check(), present(); src/Verifier/Verifier.php (cloudData) |
| AC7 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC7: in an ingredient manifest too / SPEC-063 | src/Verifier/IngredientManifestCheck.php :: manifest() |
| AC8 | tests/Unit/Manifest/CloudDataCheckTest.php :: AC8: three codes, verbatim, all failures / SPEC-063 | src/Report/StatusCode.php; tests/Fixtures/api/public-surface.txt |
| AC9 (amendment 2) | tests/Unit/Manifest/CloudDataCheckTest.php :: AC9: assertions §15.10.3.2 lists no validation for are not judged by shape / SPEC-063 | — (no code: nothing reads those shapes); bin/make-manifest-probe-variants.php |
