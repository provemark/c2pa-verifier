# SPEC-011: The hashed-URI check — every assertion the claim names, hashed and compared

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-21                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

After M3 the verifier knows that the claim's bytes were signed by the leaf
certificate. That is all it knows. The COSE signature covers the claim
and nothing else; the claim reaches the assertions only through *hashed
URIs* — `{url, hash, ?alg}`, one per assertion (C2PA 2.4 §8.4.2.3,
§15.10.3). Change one assertion — `digitalSourceType` from
`trainedAlgorithmicMedia` to `digitalCapture`, say — and the signature
still verifies, because the claim did not change. Only the hash in the
claim disagrees with the box. Step 23 measured exactly that: every
assertion-level edit in `tests/Fixtures/binding/` gives
`assertion.hashedURI.mismatch` under c2patool, with the signature intact.

This is the first half of M4. The second half, `c2pa.hash.data` against the
asset, is SPEC-012 and depends on this one: the data-hash assertion is
itself reached through a hashed URI, and a data hash read from an
assertion the claim does not vouch for proves nothing.

Three decisions were taken for this spec on 2026-09-21 and are fixed here:
the check reports *every* entry and continues after a mismatch (as
c2patool does; a later spec decides what to skip); a box in the assertion
store that no entry names is `assertion.undeclared` even when it is an
`UnknownBox` (stricter than c2patool, which stops with a hard error, and
fail-closed: an unrecognised box is the one that must not pass unnoticed);
and redactions are out of scope until M7 — a claim that carries a
non-empty `redacted_assertions` is refused with `general.error`, because a
half-implemented redaction rule is a silent-`Valid` route.

## Scope

**In scope**

- `Hash\HashedUriCheck::check(Manifest $manifest): list<ValidationStatus>`,
  the same shape as `Cose\ClaimSignatureCheck`. For every entry of
  `Claim::$createdAssertions` followed by `Claim::$gatheredAssertions`
  (v1: its one `assertions` list), in claim order:
  - the box is `Manifest::resolve($entry->url)` — `Manifest::fromBox()`
    already refused any entry that resolves nowhere or outside the
    assertion store (SPEC-007 AC4/AC10, `assertion.missing`), so here a
    `ManifestException` is a defence, mapped to its own `status`;
  - the algorithm is the entry's `alg`, else the claim's `alg` (§15.4.2),
    and must be one of `sha256`, `sha384`, `sha512` (§13.1); anything
    else, including none at all, is `algorithm.unsupported` for that
    entry, and the entry is not hashed;
  - `hash($alg, $box->payload())` — the box's contents without its 8-byte
    superbox header (§8.4.2.3, `Superbox::payload()`) — compared with
    `$entry->hash` by `hash_equals()`: equal → `assertion.hashedURI.match`,
    anything else (a different digest, a digest of the wrong length) →
    `assertion.hashedURI.mismatch`.
  - The status url is the assertion's *absolute* JUMBF URI,
    `self#jumbf=/c2pa/<manifest label>/c2pa.assertions/<box label>`,
    built from the resolved box, whatever form the entry's own url took —
    the form c2patool records.
- Then every child of the assertion store that no entry resolved to,
  in store order: a `Superbox` → `assertion.undeclared` with its
  absolute URI; an `UnknownBox` → `assertion.undeclared` with the store's
  URI (`…/c2pa.assertions`) and the box's offset, type and UUID in the
  explanation. Identity, not label: a second box with a label an entry
  already resolved to is undeclared too.
- Then, if the claim's `other['redacted_assertions']` exists and is a
  non-empty list: `general.error` with the claim box's URI
  (`…/c2pa.claim` or `…/c2pa.claim.v2`) and the explanation that
  redactions are not supported before M7. An empty list is nothing.
- `StatusCode` grows by three cases, verbatim from §15.2.2:
  `assertion.hashedURI.match` (success), `assertion.hashedURI.mismatch`
  and `assertion.undeclared` (failure). `isSuccess()` now holds for two
  codes.
- The check's name in `ValidationResult::$checksPerformed` is
  `hashedUris`; SPEC-012 will add `dataHash`. Together they are what
  SPEC-010 called `hashBinding`.
- **SPEC-007 amendment 2**: `Manifest::$assertionStore` becomes public
  (`readonly` as the rest), so that a check can walk the store's children
  — the `Superbox` and `UnknownBox` alike. Nothing else changes.
- **Deptrac**: `Hash` may see `Jumbf` (it reads `Superbox::payload()`
  and tells `UnknownBox` apart). Written into `deptrac.yaml` with this
  spec's number.

**Out of scope** (each needs its own spec before it may be built)

- `c2pa.hash.data` against the asset, `claim.hardBindings.missing`,
  `assertion.dataHash.*` — SPEC-012.
- Redactions (§6.7, `redacted_assertions`), ingredient manifests,
  cross-manifest URIs — M7. `Manifest::resolve()` already refuses a URI
  into another manifest (`assertion.missing`).
- Whether an entry appears twice in the claim (the same url in
  `created_assertions` and `gathered_assertions`): two statuses today,
  each correct; a rule for it waits until a fixture shows it happening.
- Assertion *content* rules — `assertion.required.missing`,
  `assertion.action.*`, `assertion.cbor.invalid` — later specs. This one
  compares bytes, it does not read them.
- The `Verifier` layer that runs the checks in order and publishes a
  verdict.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-011')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The manifests come through SPEC-001/2/3 → 005 → 007. The variants of
`tests/Fixtures/binding/` that already exist are used as they are; the
ones this spec adds are made by a script next to
`bin/make-binding-variants.php` from the PNG fixture's store, each
measured through c2patool 0.27.22 before the tests are written, the
verdicts recorded in that directory's README and, where c2patool gives
JSON, in `tests/Fixtures/c2patool/variants/` (Open questions).

- **AC1 — the four fixtures: every entry matches, and the urls are c2patool's** *(the positive half of M4's "done when")*
  - Given the active manifest of `fixture-signed.{jpg,png,webp}` and
    `public-testfiles/adobe-20220124-C.jpg` (claim v1, four entries)
  - When `HashedUriCheck::check()` runs
  - Then it returns exactly as many statuses as the claim has entries
    (3, 3, 3, 4), every one `assertion.hashedURI.match`, and the set of
    their urls equals the set of urls of the `assertion.hashedURI.match`
    entries under `validation_results.activeManifest.success` in
    c2patool's recorded JSON for that fixture; and
    `ValidationResult::fromStatuses(…, ['hashedUris'])` is `Valid` with
    `checksPerformed` `['hashedUris']`

- **AC2 — the assertion changed, the claim not: `assertion.hashedURI.mismatch` on that entry alone**
  - Given the manifests of `binding/pad-nonzero.bin`, `alg-sha1.bin`,
    `exclusions-overlap.bin`, `exclusion-past-end.bin` (the
    `c2pa.hash.data` box edited, the claim untouched)
  - When checked
  - Then each returns three statuses: `assertion.hashedURI.mismatch` with
    url `…/c2pa.assertions/c2pa.hash.data` and `match` for
    `c2pa.thumbnail.claim` and `c2pa.actions.v2`; the result is `Invalid`;
    and for `exclusions-overlap` the code and url equal the
    `assertion.hashedURI.mismatch` entry in c2patool's recorded
    `validation_status`

- **AC3 — the claim's hash changed: mismatch, and the check goes on** *(decision 1)*
  - Given the manifests of `binding/hashed-uri-changed.bin` (one bit of
    the `hash` for `c2pa.hash.data`) and the new
    `binding/hashed-uris-two-changed.bin` (the same, plus one bit of the
    `hash` for `c2pa.thumbnail.claim`)
  - When checked
  - Then the first gives `mismatch` for `c2pa.hash.data` and `match` for
    the other two; the second gives `mismatch` for `c2pa.hash.data` *and*
    `c2pa.thumbnail.claim` and `match` for `c2pa.actions.v2` — every
    entry reported, in claim order; and c2patool's recorded JSON for
    `hashed-uri-changed` holds `assertion.hashedURI.mismatch` with the
    same url

- **AC4 — a digest of the wrong length is a mismatch, not an error** *(required: error / malformed input)*
  - Given the manifest of the new `binding/hashed-uri-truncated.bin`
    (the 32-byte `hash` for `c2pa.hash.data` cut to 31 bytes, every
    enclosing LBox adjusted)
  - When checked
  - Then `assertion.hashedURI.mismatch` for `c2pa.hash.data` whose
    explanation names both lengths (31 against 32 for sha256), `match`
    for the other two, `Invalid`; no exception escapes

- **AC5 — a box the claim does not name: `assertion.undeclared`** *(decision 2; stricter than c2patool)*
  - Given the manifests of `binding/assertion-undeclared.bin` (a copy of
    `c2pa.actions.v2` relabelled `c2pa.extraz.v2x`) and the new
    `binding/assertion-duplicate-label.bin` (a second `c2pa.actions.v2`
    box, same label, appended to the store)
  - When checked
  - Then the first gives the three `match`es and one
    `assertion.undeclared` with url `…/c2pa.assertions/c2pa.extraz.v2x`;
    the second the three `match`es and one `assertion.undeclared` with
    url `…/c2pa.assertions/c2pa.actions.v2` whose explanation gives the
    second box's offset — the entry resolved to the first box, the
    second is nobody's; both `Invalid`. c2patool 0.27.22 has no report
    for the first (`Error: assertion missing: url = c2pa.extraz.v2x`,
    step 23); its behaviour on the second is measured and recorded
    before the tests are written

- **AC6 — an unknown box in the store is undeclared too** *(decision 2)*
  - Given the manifest of the new
    `binding/assertion-undeclared-unknown-uuid.bin` (a superbox with a
    UUID this verifier does not know, label `c2pa.extraz.v2x`, in the
    assertion store, named by no entry — SPEC-005 AC7 keeps it as an
    `UnknownBox`)
  - When checked
  - Then the three `match`es and one `assertion.undeclared` with url
    `…/c2pa.assertions` and an explanation holding the box's offset and
    UUID; `Invalid`. (SPEC-007 AC10 covers the other case, an entry that
    *names* an unknown box: `assertion.missing` from `fromBox()`.)

- **AC7 — the algorithm: the entry's, else the claim's, else unsupported** *(§15.4.2, §13.1)*
  - Given the manifests of the new `binding/uri-alg-sha384.bin` (the
    `c2pa.hash.data` entry given `alg: sha384` and its `hash` recomputed
    as 48 bytes of SHA-384 over the box payload; the claim's `alg` still
    `sha256`), `binding/claim-alg-sha1.bin` (the claim's `alg` →
    `sha1`, no entry carries its own) and `binding/claim-alg-missing.bin`
    (the claim's `alg` pair removed)
  - When checked
  - Then the first gives three `match`es (SHA-384 for the one entry,
    SHA-256 for the two that fall back); the second three
    `algorithm.unsupported`, one per entry, each with the entry's url and
    `sha1` in the explanation; the third three `algorithm.unsupported`
    whose explanation says no algorithm is specified; the last two
    `Invalid`. These three variants also change the claim, so
    `ClaimSignatureCheck` on them gives `claimSignature.mismatch` — the
    two checks are independent, and the test says so

- **AC8 — redactions are refused until M7** *(decision 3)*
  - Given the manifest of the new `binding/claim-redacted.bin` (the
    claim given `redacted_assertions: ["self#jumbf=c2pa.assertions/c2pa.actions.v2"]`,
    one text entry) and the four fixtures (no such field)
  - When checked
  - Then the variant gives the three `match`es and one `general.error`
    with url `…/c2pa.claim.v2` whose explanation names
    `redacted_assertions` and M7, `Invalid`; the fixtures give no such
    status (AC1 counts them)

- **AC9 — the codes are verbatim, and success is told apart**
  - Given `StatusCode::cases()`
  - When their values are read
  - Then the enum has exactly fifteen cases: SPEC-010's twelve plus
    `assertion.hashedURI.match`, `assertion.hashedURI.mismatch`,
    `assertion.undeclared`, character for character; `isSuccess()` is
    true for exactly `claimSignature.validated` and
    `assertion.hashedURI.match`; `isFailure()` for the other two new
    codes; and a result of three `match`es and one `undeclared` is
    `Invalid`

- **AC10 — a `ManifestException` inside the check becomes its status, never escapes**
  - Given a `Manifest` and an entry whose url resolves nowhere (a
    `Manifest` cannot be built that way through `fromBox()` — the test
    reaches `HashedUriCheck` with a claim entry rewritten after
    construction, or an equivalent seam the implementation provides)
  - When checked
  - Then that entry's status is `assertion.missing` with the entry's url
    as given and the exception's message, the other entries are still
    evaluated, and no exception escapes

## References

- Specification: C2PA 2.4 §8.4.2.3 (hashing a JUMBF box: the contents
  without the superbox header), §8.4.2 / hashed-uri-map (`url`, `hash`,
  `alg`), §13.1 (the hash algorithms: `sha256`, `sha384`, `sha512`),
  §15.4.2 (the algorithm of a hashed URI: its own `alg`, else the
  claim's), §15.10.3 (validating the assertions: `assertion.hashedURI.match`,
  `.mismatch`, `assertion.missing`, `assertion.undeclared`), §15.2.2 (the
  codes, verbatim), §6.7 (redactions, deferred). Read 2026-09-21 (step 23).
- Oracle: `c2patool 0.27.22`. The four fixtures' JSON in
  `tests/Fixtures/c2patool/{jpg,png,webp,adobe-20220124-C}.json` (step
  14: three/four `assertion.hashedURI.match` under `success`, each with
  the absolute URI); `variants/exclusions-overlap.json` and
  `variants/pixel-changed.json` (step 23); step 23's measurements of the
  thirteen binding variants (`tests/Fixtures/binding/README.md`,
  `notes/step-23-binding-measured.md`): assertion edits →
  `assertion.hashedURI.mismatch` and c2patool continues; the claim's own
  `alg` is the one every fixture uses; `assertion-undeclared` → hard
  error, no JSON. The new variants of AC3–AC8 are measured through
  c2patool and recorded in the tests-first step, before any test is
  written.
- Reasoned: the mapping of a `ManifestException` to its status (AC10);
  identity rather than label for "undeclared" (AC5, the duplicate); a
  wrong-length digest as `.mismatch` rather than `general.error` (§15.10.3
  names no other code for a hash that does not match, and a short hash
  does not match); `general.error` for redactions (the table's own
  definition: a fault §15 has no better word for *here*, because the
  redaction rules are not implemented).
- Divergences from c2patool, kept: `assertion.undeclared` where it stops
  with an error (AC5, AC6) — the specification's code, and a report the
  sister library can show; `algorithm.unsupported` for a bad claim-level
  `alg` (AC7) — c2patool's answer to `alg-sha1` at the assertion level was
  `.mismatch` with "type is unsupported"; both are failures.

## API sketch

```php
// namespace Provemark\C2paVerifier\Hash;

declare(strict_types=1);

final readonly class HashedUriCheck
{
    /** The algorithms C2PA 2.4 §13.1 allows, as PHP hash() knows them. */
    private const ALGORITHMS = ['sha256' => 'sha256', 'sha384' => 'sha384', 'sha512' => 'sha512'];

    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array;
}

// namespace Provemark\C2paVerifier\Report;   (three cases added)
enum StatusCode: string
{
    // … SPEC-010's twelve …
    case AssertionHashedUriMatch = 'assertion.hashedURI.match';
    case AssertionHashedUriMismatch = 'assertion.hashedURI.mismatch';
    case AssertionUndeclared = 'assertion.undeclared';

    public function isSuccess(): bool;   // ClaimSignatureValidated || AssertionHashedUriMatch
}

// namespace Provemark\C2paVerifier\Manifest;   (SPEC-007 amendment 2)
final readonly class Manifest
{
    public Superbox $assertionStore;   // was private
}
```

Deptrac: `Hash` → `Manifest`, `Cbor`, `Report` (already), plus `Jumbf`
(this spec).

## Open questions

- Non-blocker: the new variants (`hashed-uris-two-changed`,
  `hashed-uri-truncated`, `assertion-duplicate-label`,
  `assertion-undeclared-unknown-uuid`, `uri-alg-sha384`, `claim-alg-sha1`,
  `claim-alg-missing`, `claim-redacted`) do not exist yet. They are made
  and measured in the tests-first step, like step 23's; if c2patool's
  answer to one of them contradicts a criterion above, the criterion is
  amended before approval of the tests, not after.
- Non-blocker: `Manifest::$assertionStore` public versus a method that
  returns the children. Public property, as `$box` and `$assertions`
  already are; a method would only hide a field.

## Amendments

1. **2026-09-21, with SPEC-012's implementation** — `StatusCode` grew by SPEC-012's six codes; AC9's test now asserts that this spec's fifteen are present and leaves the exact twenty-one and the informational kind to SPEC-012 AC10. No criterion changed.
2. **2026-09-21, with SPEC-014's implementation** — `StatusCode` grew by `signingCredential.trusted` (a success) and `.untrusted`; AC9's test skips them. No criterion changed.

3. **2026-09-24, step 129b, with SPEC-035's implementation** — AC8's
   refusal is lifted. A claim with `redacted_assertions` is read by
   SPEC-035's rules instead. The variant `binding/claim-redacted.bin`
   names its own actions by a relative URI, and now gives the three
   `match`es and `assertion.action.redacted` on that URI as written. It is
   still `Invalid`, as both `c2patool` versions measured it (step 21 for
   0.27.22, step 129a for 0.28.0). A redacted hard binding keeps the
   refusal (SPEC-035 amendment 2).

   **Weight B: one variant's failure code changes; its verdict does not.**

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Hash/HashedUriCheckTest.php :: AC1: the four fixtures: every entry matches, and the urls are c2patool's / SPEC-011 | src/Hash/HashedUriCheck.php :: check(), entry(); src/Report/ValidationResult.php :: fromStatuses() |
| AC2 | tests/Unit/Hash/HashedUriCheckTest.php :: AC2: the assertion changed, the claim not: assertion.hashedURI.mismatch on that entry alone / SPEC-011 | src/Hash/HashedUriCheck.php :: entry() (hash_equals) |
| AC3 | tests/Unit/Hash/HashedUriCheckTest.php :: AC3: the claim's hash changed: mismatch, and the check goes on / SPEC-011 | src/Hash/HashedUriCheck.php :: check() (every entry, in claim order) |
| AC4 | tests/Unit/Hash/HashedUriCheckTest.php :: AC4: a digest of the wrong length is a mismatch, not an error / SPEC-011 | src/Hash/HashedUriCheck.php :: entry() (ALGORITHMS digest lengths) |
| AC5 | tests/Unit/Hash/HashedUriCheckTest.php :: AC5: a box the claim does not name: assertion.undeclared / SPEC-011 | src/Hash/HashedUriCheck.php :: check() (Superbox not in $resolved, identity); src/Manifest/Manifest.php :: $assertionStore (amendment 2) |
| AC6 | tests/Unit/Hash/HashedUriCheckTest.php :: AC6: an unknown box in the store is undeclared too / SPEC-011 | src/Hash/HashedUriCheck.php :: check() (UnknownBox); deptrac.yaml (Hash → Jumbf) |
| AC7 | tests/Unit/Hash/HashedUriCheckTest.php :: AC7: the algorithm: the entry's, else the claim's, else unsupported / SPEC-011 | src/Hash/HashedUriCheck.php :: entry() ($entry->alg ?? $claim->alg, ALGORITHMS) |
| AC8 | tests/Unit/Hash/HashedUriCheckTest.php :: AC8: a redaction is read by SPEC-035's rules / SPEC-011 | src/Hash/HashedUriCheck.php :: redactions() (amendment 3) |
| AC9 | tests/Unit/Hash/HashedUriCheckTest.php :: AC9: the codes are verbatim, and success is told apart / SPEC-011 | src/Report/StatusCode.php :: AssertionHashedUriMatch, AssertionHashedUriMismatch, AssertionUndeclared, isSuccess() |
| AC10 | tests/Unit/Hash/HashedUriCheckTest.php :: AC10: a ManifestException inside the check becomes its status, never escapes / SPEC-011 | src/Hash/HashedUriCheck.php :: checkEntry(), entry() (catch ManifestException → $e->status) |
