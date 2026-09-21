# SPEC-010: The report — status codes for the claim signature, verbatim from C2PA 2.4 §15

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Every layer so far throws — `ContainerException`, `JumbfException`,
`CborException`, `ManifestException`, `CoseException` — and
`SignatureVerifier` answers `true` or `false`. None of them speaks C2PA.
The brief fixes the language of the verdict: c2patool's
`validation_state` and the status codes of C2PA 2.4 §15, verbatim, and
no vocabulary of our own. M3's "done when" — `claimSignature.validated`
equals c2patool on every fixture, one altered byte gives
`claimSignature.mismatch` — cannot be measured until something says
those words.

This spec adds the `Report` layer (the value objects that carry a
verdict), gives `ManifestException` and `CoseException` a status code so
that a fault is named where it is found, and defines one check — the
claim signature — as a list of statuses. It is small code and large
decisions: every outcome of M1–M3 maps to exactly one code, every
exception becomes a failure and never an empty list, and `general.error`
is used only where §15 has no word.

One thing it must not do is say `Valid` to the world. After this spec a
file whose signature verifies has no hash binding checked (M4) and no
chain to an anchor (M5): mathematically valid under any certificate,
self-signed included. So the result names the checks it performed, and
the `Verifier` layer — its own spec — may only publish a verdict when
that list is complete.

## Scope

**In scope**

- `Report\StatusCode`: a string-backed enum whose values are the codes of
  C2PA 2.4 §15.2.2 this verifier can emit today —
  `claimSignature.validated` (success), and the failures
  `claimSignature.mismatch`, `claimSignature.missing`,
  `algorithm.unsupported`, `signingCredential.invalid`, `claim.missing`,
  `claim.multiple`, `claim.cbor.invalid`, `claim.malformed`,
  `assertion.json.invalid`, `assertion.missing`, `general.error`. New
  codes enter through the spec that emits them, never ad hoc.
  `isSuccess()` / `isFailure()` per the table's three kinds.
- `Report\ValidationStatus`: `code`, `url` (the JUMBF URI of the box the
  status is about, absolute, as c2patool prints it), `explanation` (our
  own message — offsets, hex, the clause cited; c2patool's are terse,
  ours are the added value, and they are not a second vocabulary: the
  code is the word, the explanation the reason).
- `Report\ValidationState`: `Valid`, `Invalid`. `Trusted` arrives with
  M5. `Valid` = no failure among the statuses.
- `Report\ValidationResult`: the statuses, the state, and
  `checksPerformed` — the names of the checks that produced them
  (`signature` here; `hashBinding`, `trust`, `timestamp` later). `toArray()`
  in c2patool's shape: `validation_status` holding the failures and
  informational statuses (what the sister parser's `validationCodes()`
  reads), `validation_results.activeManifest.{success, informational,
  failure}` holding all, `validation_state`; plus `checks_performed`,
  a key c2patool does not have, so that a consumer can tell a partial
  report from a verdict.
- **SPEC-007 amendment 1**: `ManifestException` carries a `StatusCode`
  (`Manifest` may see `Report`), set at each throw site: no manifest →
  `claim.missing`; two claim boxes → `claim.multiple`; claim CBOR that
  does not decode → `claim.cbor.invalid`; a claim box with the wrong
  content boxes, a wrong label, a missing or mistyped field, a bad
  hashed URI → `claim.malformed`; the signature URI unresolvable or not
  the signature box → `claimSignature.missing`; an assertion URI to
  nothing, outside the store, or to an `UnknownBox` → `assertion.missing`;
  invalid JSON in an assertion → `assertion.json.invalid`; everything
  else → `general.error`.
- **SPEC-008/009 amendment 1**: `CoseException` carries a `StatusCode`
  (`Cose` may see `Report`): an unsupported `alg`, and EdDSA without an
  extension to verify it → `algorithm.unsupported` ("the algorithm is
  unspecified or unsupported" — here, unsupported by this installation);
  a key that does not fit the algorithm, a leaf that is not X.509 or
  whose key cannot be read, a missing or empty chain →
  `signingCredential.invalid` (§15.7: "the credential is not acceptable
  per the requirements of the credential's type"); the structural faults
  of SPEC-008 (tag, item count, present payload, protected header not a
  map, no `alg`) → `general.error` with the message.
- `Cose\ClaimSignatureCheck::check(Manifest $manifest): list<ValidationStatus>`
  — `CoseSign1::fromBytes(signatureBytes())`, then
  `SignatureVerifier::verify()`: `true` → `claimSignature.validated`;
  `false` → `claimSignature.mismatch`; a `CoseException` → its code; all
  with the signature box's absolute URI
  (`self#jumbf=/c2pa/<manifest label>/c2pa.signature`) and the message.
  `ValidationResult::fromStatuses($statuses, ['signature'])` assembles
  the result.
- The mapping of the leaf layers' exceptions (`Container`, `Jumbf`,
  `Cbor`, which may not see `Report`): `general.error` with the message
  and the `url` of the store (`self#jumbf=/c2pa`), by the caller — in
  this spec's tests, later in the `Verifier` layer.

**Out of scope** (each needs its own spec before it may be built)

- The `Verifier` layer: choosing the container, running every check,
  publishing a verdict. This spec's result is a partial report; only the
  tests plumb the layers together.
- `signingCredential.trusted` / `.untrusted`, `Trusted` (M5);
  `claimSignature.insideValidity` / `.outsideValidity`, `timeStamp.*`
  (M6); `assertion.dataHash.*`, `assertion.hashedURI.*`,
  `claim.hardBindings.missing` (M4); `ingredient.*` (M7). Their codes
  enter the enum with their specs.
- The `signature_info` block of c2patool's JSON (alg, issuer, CN, serial):
  M5, which reads the certificate.
- Mapping c2patool's *messages* — only codes are compared; c2patool's
  wording is neither stable nor specified.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-010')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The manifests come through SPEC-001/2/3 → 005 → 007; the variants are
those of steps 14, 17 and 19; c2patool's `validation_status` per fixture
is in `tests/Fixtures/c2patool/*.json`, per variant in
`tests/Fixtures/c2patool/variants/*.json` (Open questions).

- **AC1 — the four fixtures: `claimSignature.validated`, and the words are c2patool's** *(M3's "done when")*
  - Given the active manifest of each fixture
  - When `ClaimSignatureCheck::check()` runs
  - Then it returns exactly one status: code `claimSignature.validated`,
    url `self#jumbf=/c2pa/<label>/c2pa.signature` (for the PNG:
    `…/urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0/c2pa.signature`),
    equal to the code and url of the `claimSignature.validated` entry
    under `validation_results.activeManifest.success` in c2patool's
    recorded JSON; and `ValidationResult::fromStatuses(…, ['signature'])`
    has state `Valid` and `checksPerformed` `['signature']`

- **AC2 — one altered byte: `claimSignature.mismatch`, as c2patool** *(M3's "done when")*
  - Given the manifests of `cose/claim-title-changed.bin` and
    `cose/signature-changed.bin`
  - When checked
  - Then each returns exactly `claimSignature.mismatch` with the signature
    box's url, the result is `Invalid`, and c2patool's recorded
    `validation_status` for the same variant contains
    `claimSignature.mismatch` with the same url

- **AC3 — cannot verify: the codes §15.7 names**
  - Given the manifests of `cose/alg-eddsa-with-ec-key.bin` and the
    vectors `es256-p256k1`, `ps256-rsa1024`, `eddsa-rsa` (key does not
    fit), `alg-unsupported` (unknown alg), and the `eddsa-ed25519` vector
    with both Ed25519 paths disabled
  - When checked
  - Then the key cases give `signingCredential.invalid`, the unknown alg
    and the disabled paths `algorithm.unsupported`, each with the
    signature url and the `CoseException`'s message as explanation, and
    `Invalid`

- **AC4 — structural COSE faults: `general.error` with the message**
  *(required: error / malformed input)*
  - Given the manifests of `cose/tag-19.bin`, `cose/payload-present.bin`,
    `cose/alg-missing.bin`
  - When checked
  - Then each gives `general.error` whose explanation is the
    `CoseException` message (`expected tag 18 …`, `the payload must be
    detached …`, `the protected header has no alg …`), and `Invalid`

- **AC5 — chain faults: `signingCredential.invalid`**
  - Given the manifests of `cose/x5chain-missing.bin`,
    `cose/leaf-der-broken.bin`, `cose/chain-empty.bin`
  - When checked
  - Then each gives `signingCredential.invalid` with the message

- **AC6 — the Manifest layer's faults carry their codes**
  - Given the stores of the step-14 variants, parsed with
    `ManifestStore::fromTree()`
  - When the `ManifestException` is caught
  - Then its `status` is: `claim-no-signature`, `claim-no-created-assertions`,
    `claim-no-instanceid`, `claim-no-claim-generator-info`,
    `generator-info-no-name`, `hash-as-text`, `hash-missing`,
    `claim-label-v3`, `two-cbor-boxes`, `assertion-store-label` →
    `claim.malformed`; `second-claim` → `claim.multiple`; `no-manifest` →
    `claim.missing`; `uri-not-found`, `uri-wrong-place` and
    `jumbf/unknown-uuid` → `assertion.missing`; `json-broken` →
    `assertion.json.invalid`; and `cbor/claim-indefinite-array`,
    `cbor/claim-duplicate-key` (a claim whose CBOR SPEC-006 refuses) →
    `claim.cbor.invalid`

- **AC7 — `assertion.json.invalid` agrees with c2patool** *(the open item
  since step 14)*
  - Given the `json-broken` variant
  - When its `ManifestException` is mapped to a status with the assertion's
    url (`self#jumbf=/c2pa/<label>/c2pa.assertions/stds.schema-org.CreativeWork`)
  - Then the code equals the `assertion.json.invalid` entry in c2patool's
    recorded `validation_status` for that variant, url included

- **AC8 — the leaf layers' faults become `general.error`**
  - Given `tests/Fixtures/jpeg/truncated-in-piece-2.jpg`,
    `jumbf/lbox-zero.bin` and a claim box whose CBOR is `1c` (reserved)
  - When the `ContainerException` / `JumbfException` / `CborException` is
    mapped as the tests do (the `Verifier` layer later)
  - Then each is a `ValidationStatus` with code `general.error`, url
    `self#jumbf=/c2pa` and the exception's message, and a result built
    from it is `Invalid`

- **AC9 — the array shape is c2patool's, plus the checks performed**
  - Given the PNG's result and the `claim-title-changed` result
  - When `toArray()` runs
  - Then the first is `{validation_status: [], validation_results:
    {activeManifest: {success: [{code, url, explanation}], informational:
    [], failure: []}}, validation_state: "Valid", checks_performed:
    ["signature"]}`; the second has the mismatch under both
    `validation_status` and `…failure`, `validation_state: "Invalid"`;
    and `ManifestStoreParser::fromJson()` of a store JSON extended with
    the first gives `validationCodes()` `[]` and `validationState()`
    `Valid`, with the second `['claimSignature.mismatch']` and `Invalid`

- **AC10 — every code is verbatim, and success and failure are told apart**
  - Given `StatusCode::cases()`
  - When their values are read
  - Then each is one of the twelve strings above, character for
    character; `isSuccess()` is true only for `claimSignature.validated`;
    and a `ValidationResult` with one success and no failure is `Valid`,
    with any failure `Invalid`, with no statuses at all `Invalid` — an
    empty report is not a clean one

## References

- Specification: C2PA 2.4 §15.2.1 (results as a consolidated set),
  §15.2.2 (the standard status codes: success, informational, failure —
  the twelve used here quoted from the table), §15.6 (locating and
  validating the claim: `claim.missing`, `claim.multiple`,
  `claim.cbor.invalid`, `claim.malformed`), §15.7 (validate the
  signature: `claimSignature.missing`, `signingCredential.invalid`,
  `algorithm.unsupported`, `claimSignature.mismatch`,
  `claimSignature.validated`). Read 2026-09-21.
- Oracle: `c2patool 0.27.22`'s JSON — `validation_status` (failures and
  informational), `validation_results.activeManifest.{success,
  informational, failure}`, each entry `{code, url, explanation}`,
  `validation_state` — recorded for the four fixtures in step 14 and, for
  the variants named in AC2 and AC7, in a measurement step before
  approval (Open questions); the sister library's
  `ManifestStoreParser::validationCodes()` reading `validation_status`.
- Reasoned: the mapping table (which exception becomes which code) —
  §15.6 and §15.7 decide most rows; `general.error` for the rest, by the
  table's own definition; "an empty report is `Invalid`".
- Measured, and kept for M5: c2patool's PNG result has the failure
  `signingCredential.untrusted` *and* `validation_state: "Valid"` —
  untrusted is not invalid. `ValidationState` will need that nuance when
  M5 emits the code; this spec's "Valid = no failure" holds for the
  codes it can emit.

## API sketch

```php
// namespace Provemark\C2paVerifier\Report;

declare(strict_types=1);

enum StatusCode: string
{
    case ClaimSignatureValidated = 'claimSignature.validated';
    case ClaimSignatureMismatch = 'claimSignature.mismatch';
    case ClaimSignatureMissing = 'claimSignature.missing';
    case AlgorithmUnsupported = 'algorithm.unsupported';
    case SigningCredentialInvalid = 'signingCredential.invalid';
    case ClaimMissing = 'claim.missing';
    case ClaimMultiple = 'claim.multiple';
    case ClaimCborInvalid = 'claim.cbor.invalid';
    case ClaimMalformed = 'claim.malformed';
    case AssertionJsonInvalid = 'assertion.json.invalid';
    case AssertionMissing = 'assertion.missing';
    case GeneralError = 'general.error';

    public function isSuccess(): bool;
    public function isFailure(): bool;
}

enum ValidationState: string { case Valid = 'Valid'; case Invalid = 'Invalid'; }

final readonly class ValidationStatus
{
    public function __construct(public StatusCode $code, public string $url, public string $explanation) {}
    /** @return array{code: string, url: string, explanation: string} */ public function toArray(): array;
}

final readonly class ValidationResult
{
    /** @param list<ValidationStatus> $statuses  @param list<string> $checksPerformed */
    public static function fromStatuses(array $statuses, array $checksPerformed): self;
    public ValidationState $state;
    /** @return array<string, mixed> c2patool's shape plus checks_performed */ public function toArray(): array;
}

// namespace Provemark\C2paVerifier\Manifest;   (amendment 1)
final class ManifestException extends \RuntimeException
{
    public function __construct(string $message, public readonly StatusCode $status = StatusCode::GeneralError, ?\Throwable $previous = null) {}
}
// namespace Provemark\C2paVerifier\Cose;       (amendment 1) — the same shape for CoseException

// namespace Provemark\C2paVerifier\Cose;
final readonly class ClaimSignatureCheck
{
    public function __construct(private SignatureVerifier $verifier = new SignatureVerifier) {}
    /** @return list<ValidationStatus> */
    public function check(Manifest $manifest): array;
}
```

Deptrac: `Report` is a leaf; `Manifest` and `Cose` may see it (they do
already); `Cose` may see `Manifest` (the arrow exists, unused until now).

## Open questions

- **c2patool's JSON for the variants of AC2 and AC7** (`claim-title-changed`,
  `signature-changed`, `json-broken`) is recorded under
  `tests/Fixtures/c2patool/variants/` in a measurement step before
  approval — three `c2patool <variant>.png` runs, saved as they come.
  Blocker for approval (AC2 and AC7 compare urls, not only codes).
- **Where `general.error`'s url for a store-level fault should point**:
  `self#jumbf=/c2pa` (the store) is proposed; c2patool reports such
  faults as a top-level error with no url at all. Non-blocker.
- **Whether `explanation` should ever carry the offset-and-hex detail**
  the layers produce, or a shorter sentence with the detail elsewhere.
  Proposal: the full message; a consumer that shows it to a user should
  treat it as untrusted text (it already is hex-only for file bytes).
  Non-blocker.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

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
| AC10                 | —                           | —                    |
