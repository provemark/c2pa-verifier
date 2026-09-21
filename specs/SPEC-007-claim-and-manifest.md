# SPEC-007: The claim and the manifest — boxes and CBOR given meaning

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

SPEC-005 gives a tree of boxes and SPEC-006 gives maps with keys. Neither
knows what a claim is. This spec does: it turns the tree into a
`ManifestStore` — its manifests, the active one, and for each manifest
the `Claim` (version 1 or 2, its fields typed), the assertions (label →
data, decoded by content type), and the signature box M3 will verify. It
resolves every JUMBF URI the claim carries to a box in the tree and
refuses a claim whose references point nowhere or at an `UnknownBox` —
which is exactly where c2patool failed on step 10's `unknown-uuid`.

It also renders the store as JSON in the shape c2patool emits, because
that is M2's "done when" (`docs/milestones.md`): the sister library's
`ManifestStoreParser::fromJson()` must accept it and give the same
accessor values it gives for c2patool's own JSON. From then on the three
readers of `provemark/content-credentials` share one contract, and
SPEC-019 AC2 there becomes the drift alarm the brief asks for.

Two measured facts shape it. The v2 claim's `claim_version` is not in the
CBOR; it comes from the box label (step 09). And the 2022 Adobe file — v1
— has no `claim_generator_info`, although the 2.4 CDDL lists it as
required for v1 too; c2patool validates that file. On that one point this
spec is deliberately more lenient than the 2.4 text (Scope), because
refusing the legacy corpus the oracle accepts would protect nobody.

Verifying the hashes in the hashed URIs is M4. Ingredients are M7. Nothing
here touches cryptography.

## Scope

**In scope**

- `ManifestStore::fromTree(Superbox $root)`: every child superbox of the
  root with UUID `c2ma` is a manifest; the **last** is the active one
  (C2PA 2.4 §11.1.4.2); a store with no manifest is an error.
- Per manifest: exactly one assertion-store superbox (`c2as`, label
  `c2pa.assertions`), exactly one claim superbox (`c2cl`), exactly one
  signature superbox (`c2cs`); each of the last two holds exactly one
  `cbor` content box (§11.1.4.4). Anything else — two claims, a claim
  with two content boxes, no assertion store — is an error.
- The claim's version from its box label: `c2pa.claim` → 1,
  `c2pa.claim.v2` → 2, anything else → error.
- The claim's fields per the CDDL of §10.2.1, typed and checked for
  presence:
  - v2: `instanceID` (text), `claim_generator_info` (one map with
    `name`), `signature` (text), `created_assertions` (≥ 1 hashed URI);
    optional `gathered_assertions`, `dc:title`, `redacted_assertions`,
    `alg`, `alg_soft`, `specVersion`.
  - v1: `claim_generator` (text), `signature`, `assertions` (≥ 1),
    `dc:format`, `instanceID`; optional `claim_generator_info` (a list of
    maps — **optional here, required in the 2.4 CDDL**: measured absent
    in `adobe-20220124-C.jpg`, which c2patool validates), `dc:title`,
    `redacted_assertions`, `alg`, `alg_soft`, `metadata`.
  - Unknown extra fields are kept, not refused (§10.2.3.2 allows
    namespaced fields).
- A hashed URI is `{url: text, hash: CborBytes, ?alg: text}`; a missing
  or mistyped `url` or `hash` is an error.
- JUMBF URI resolution: `self#jumbf=` prefix required; a path starting
  `/c2pa/` is absolute from the store root (`/c2pa/<manifest label>/…`),
  any other path is relative to the manifest; each segment is a superbox
  label. The `signature` URI must resolve to this manifest's signature
  box. Every assertion URI must resolve to a superbox inside this
  manifest's assertion store; resolving to nothing, to a box outside the
  assertion store, or to an `UnknownBox` is an error.
- Assertion data by content box: `cbor` → SPEC-006's value; `json` →
  `json_decode(assoc, JSON_THROW_ON_ERROR)`, an error on invalid JSON;
  `bfdb` + `bidb` → an embedded file (`format` from the `bfdb`
  description, the bytes); `uuid` → the raw bytes. More than one content
  box of one kind in an assertion is an error until a fixture says
  otherwise.
- The JSON view, c2patool's shape restricted to what M2 knows:
  `active_manifest`, `manifests` keyed by label with `label`,
  `claim_version`, `claim_generator_info` (always a list, as c2patool
  renders it; absent for a v1 claim without it), `claim_generator` (v1
  only), `title`, `format` (v1), `instance_id`, `thumbnail` (`format`,
  `identifier`) and `assertions` as `[{label, data}]` — every assertion
  the claim references **except** the hard-binding assertion
  (`c2pa.hash.data`, …) and the thumbnail, which c2patool also leaves out
  of that list. Labels as stored (`c2pa.actions` stays `c2pa.actions`;
  c2patool renders it `c2pa.actions.v2`, and the sister parser matches on
  the prefix, so both agree on every accessor). Byte strings inside
  assertion data as base64, as c2patool prints them. No `signature_info`,
  no `validation_*`: M3–M6 add them.

**Out of scope** (each needs its own spec before it may be built)

- Comparing a hashed URI's `hash` with the box's payload (M4).
- What any assertion means beyond decoding it — actions, ingredients,
  hash-data exclusions (M4, M7); `redacted_assertions` (M7).
- Compressed and update manifests (an error in SPEC-005 already).
- `signature_info` and `validation_*` in the JSON view (M3–M6).
- Multiple-instance assertion labels (`label__1`): kept as stored;
  interpreting the suffix is M7's concern.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-007')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The trees are those SPEC-005 yields from the four stores; the values are
step 09's and step 12's.

- **AC1 — the PNG store has one manifest, active, with a v2 claim**
  - Given the PNG store's tree
  - When `ManifestStore::fromTree()` runs
  - Then there is one manifest, labelled
    `urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0`, and it is the active
    one; its claim has `version` 2, `instanceID`
    `xmp:iid:abc42c63-7d76-437d-b2bb-b8e473a93dba`, `claimGeneratorInfo`
    `[{name: "c2pa-verifier fixtures", version: "0.0.0",
    "org.contentauth.c2pa_rs": "0.90.22"}]` (a list of one), `title`
    `fixture-signed.png`, `alg` `sha256`, one created assertion
    (`self#jumbf=c2pa.assertions/c2pa.hash.data`, a 32-byte hash) and two
    gathered (`c2pa.thumbnail.claim`, `c2pa.actions.v2`), `claimGenerator`
    `null`

- **AC2 — the JPEG and WebP stores give the same claim shape**
  - Given the JPEG and WebP trees
  - When parsed
  - Then each has one active manifest with a v2 claim whose field set,
    assertion labels and generator info equal the PNG's; only the labels
    (`urn:c2pa:4e936c4e-…`, `urn:c2pa:233f5a78-…`), the titles and the
    hashes differ

- **AC3 — assertions are decoded by content type**
  - Given the PNG manifest
  - When its assertions are read
  - Then there are three, keyed `c2pa.thumbnail.claim`, `c2pa.actions.v2`,
    `c2pa.hash.data`; the actions assertion's data is the array
    `{actions: [{action: "c2pa.created", digitalSourceType: "…/algorithmicMedia"}]}`;
    the hash-data assertion's data has `exclusions` `[{start: 33, length:
    46037}]`, `name` `jumbf manifest`, `alg` `sha256`, `hash` a 32-byte
    `CborBytes`, `pad` 8 bytes; the thumbnail is an embedded file with
    format `image/jpeg` and 32,364 bytes

- **AC4 — URIs resolve to boxes, relative and absolute**
  - Given the PNG manifest
  - When the claim's `signature` URI
    (`self#jumbf=/c2pa/urn:c2pa:488bf983-…/c2pa.signature`) and its first
    created-assertion URI (`self#jumbf=c2pa.assertions/c2pa.hash.data`)
    are resolved
  - Then the first yields the superbox at offset 33,672 (the signature
    box; its `cbor` data is 12,297 bytes) and the second the superbox at
    offset 32,831; and the manifest exposes `signatureBytes()` as those
    12,297 bytes

- **AC5 — the Adobe store gives a v1 claim without claim_generator_info**
  - Given the Adobe tree
  - When parsed
  - Then the active manifest is
    `contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc`; its claim
    has `version` 1, `claimGenerator` `make_test_images/0.16.1
    c2pa-rs/0.16.1`, `claimGeneratorInfo` `null`, `format` `image/jpeg`,
    `title` `C.jpg`, four assertions in one list (no created/gathered
    split); the assertions are `c2pa.thumbnail.claim.jpeg` (embedded file,
    `image/jpeg`, 31,608 bytes), `stds.schema-org.CreativeWork` (JSON,
    decoded to an array with keys `@context`, `@type`, `author`),
    `c2pa.actions` (two actions: `c2pa.created`, `c2pa.drawing`),
    `c2pa.hash.data`

- **AC6 — the JSON view is accepted by the sister library and agrees with c2patool**
  *(M2's "done when"; oracle: `c2patool 0.27.22 <fixture>` JSON, the
  sister library `provemark/content-credentials` as a dev dependency)*
  - Given the PNG, JPEG, WebP and Adobe stores, and for each the JSON
    c2patool prints for the fixture (recorded under
    `tests/Fixtures/c2patool/`)
  - When `ManifestStore::toJson()` is fed to
    `ManifestStoreParser::fromJson()`, and c2patool's JSON likewise
  - Then both parse, and `hasManifest()`, `isAiGenerated()`,
    `digitalSourceTypes()`, `softwareAgents()` and `declaredSpecVersion()`
    are equal for each fixture (for the PNG: `true`, `false`,
    `["http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia"]`,
    `[]`, `null`)

- **AC7 — the JSON view has c2patool's shape**
  - Given the PNG store
  - When rendered
  - Then `active_manifest` is the manifest label; `manifests[label]` has
    exactly the keys `claim_generator_info`, `title`, `instance_id`,
    `thumbnail`, `assertions`, `label`, `claim_version`; `assertions` is
    `[{label: "c2pa.actions.v2", data: {…}}]` — one entry, the hash-data
    assertion and the thumbnail left out; `thumbnail` is `{format:
    "image/jpeg", identifier: "self#jumbf=/c2pa/urn:c2pa:488bf983-…/c2pa.assertions/c2pa.thumbnail.claim"}`;
    for the Adobe store `manifests[label]` also has `claim_generator` and
    `format`, no `claim_generator_info`, and `assertions` lists
    `stds.schema-org.CreativeWork` and `c2pa.actions` (label as stored)

- **AC8 — a claim label that is neither v1 nor v2 is an error** *(required:
  error / malformed input; oracle: `c2patool` → `claim version is too new,
  not supported`)*
  - Given the PNG store with the claim label `c2pa.claim.v2` → `c2pa.claim.v3`
  - When parsed
  - Then it throws `ManifestException` naming the label and offset 33,034

- **AC9 — a claim missing a required field is an error** *(oracle:
  `c2patool` → `claim could not be converted from CBOR` for all four)*
  - Given the PNG claim with, separately, `signature`, `created_assertions`,
    `instanceID` and `claim_generator_info` removed (the map's count
    lowered, the pair cut, every enclosing LBox adjusted)
  - When parsed
  - Then each throws `ManifestException` naming the field and the claim
    version

- **AC10 — a URI that resolves to nothing, to the wrong place, or to an unknown box is an error**
  *(oracle: `c2patool` → `assertion missing: url = c2pa.hash.data` for the
  first two, `could not create valid JUMBF for claim` for the third)*
  - Given the PNG claim with its hash-data `url` changed to
    `self#jumbf=c2pa.assertions/c2pa.hash.datb`; separately to
    `self#jumbf=c2pa.claim.v2`; and `tests/Fixtures/jumbf/unknown-uuid.bin`
    (step 10, where the thumbnail assertion is an `UnknownBox`)
  - When parsed
  - Then each throws `ManifestException` naming the URI and why (not
    found; not in the assertion store; an unknown box) — the third with
    the box's UUID `ffffffff-…`

- **AC11 — a hashed URI without a byte-string hash is an error** *(oracle:
  a missing `hash` → `claim could not be converted from CBOR`; a `hash`
  re-typed as text is **read** by c2pa-rs and fails only as
  `assertion.hashedURI.mismatch` — stricter here, safe direction)*
  - Given the PNG claim with the hash-data entry's `hash` replaced by the
    text `"abc"` (same map, the byte string re-typed); and with the `hash`
    pair removed
  - When parsed
  - Then each throws `ManifestException` naming `hash` and the URI

- **AC12 — structural faults in the manifest are errors** *(oracle:
  `"c2pa" multiple claim boxes found in manifest`; `more than one claim
  description box was found for c2pa.claim.v2`; the mislabelled assertion
  store → `Invalid`, `claim.multiple` (stricter here: an error, not a
  verdict); no manifest → `C2PA provenance not found in XMP`)*
  - Given the PNG store with, separately: a second `c2pa.claim.v2`
    superbox appended to the manifest; the claim superbox holding two
    `cbor` boxes; the assertion store superbox's label changed to
    `c2pa.assertionz`; and a store whose root has no `c2ma` child (the
    manifest superbox's UUID changed to a `c2as`)
  - When parsed
  - Then each throws `ManifestException` naming the fault and the
    manifest label (or, for the last, that the store holds no manifest)

- **AC13 — invalid JSON in a json box is an error** *(oracle: `c2patool`
  reports it as a **status code**, `assertion.json.invalid`, with
  `assertion.required.missing`, and the verdict `Invalid` — not a parse
  error. Here the parse layer errs; the Verifier layer's spec maps that
  error to `assertion.json.invalid`, so the verdicts agree)*
  - Given the Adobe store with one byte of the `stds.schema-org.CreativeWork`
    JSON changed to break it (a `{` → `[`)
  - When parsed
  - Then it throws `ManifestException` naming the assertion label and the
    JSON error, never printing the bytes raw

- **AC14 — a claim_generator_info without a name is an error** *(oracle:
  `c2patool` → `claim could not be converted from CBOR`)*
  - Given the PNG claim with the key `name` in `claim_generator_info`
    renamed to `nome`
  - When parsed
  - Then it throws `ManifestException` naming `name`

## References

- Specification: C2PA 2.4 §10.2.1 Schema (the two CDDL rules, quoted
  from the published text 2026-09-21), §10.2.2 Fields, §10.2.3 Claim
  Generator Info, §11.1.4.2 (the active manifest is the last), §11.1.4.3
  and §11.1.4.4 (assertion store, claim and signature boxes); the hashed
  URI map; ISO 19566-5 C.2 for JUMBF URIs (not read; both URI forms
  measured in the stores).
- Oracle: `c2patool 0.27.22` JSON for the four fixtures (recorded under
  `tests/Fixtures/c2patool/` in the measurement step) and its `--detailed`
  view (step 09); the sister library `provemark/content-credentials`
  v0.15.1, `ManifestStoreParser::fromJson()` and the accessors of
  `tests/Integration/ReaderEquivalenceTest.php :: spec019Accessors()`
  there; the fifteen variants of AC8–AC14 through c2patool, measured
  2026-09-21 (step 14; `bin/make-claim-variants.php`,
  `tests/Fixtures/claim/README.md`); `tests/Fixtures/jumbf/unknown-uuid.bin`
  (step 10: `could not create valid JUMBF for claim`).
- Reasoned: the leniency on v1 `claim_generator_info`; keeping labels as
  stored in the JSON view; "one content box of a kind per assertion".

## API sketch

```php
// namespace Provemark\C2paVerifier\Manifest;

declare(strict_types=1);

final class ManifestException extends \RuntimeException {}

final readonly class HashedUri
{
    public function __construct(public string $url, public CborBytes $hash, public ?string $alg) {}
}

final readonly class Claim
{
    public function __construct(
        public int $version,                       // 1 or 2, from the box label
        public string $instanceId,
        public ?string $claimGenerator,            // v1
        /** @var list<array<string, mixed>>|null */ public ?array $claimGeneratorInfo,  // list, as c2patool renders it
        public string $signatureUri,
        /** @var list<HashedUri> */ public array $createdAssertions,   // v1: the one list
        /** @var list<HashedUri> */ public array $gatheredAssertions,  // v1: []
        public ?string $title,
        public ?string $format,                    // v1
        public ?string $alg,
        /** @var array<string, mixed> */ public array $other,          // every field not modelled, as decoded
    ) {}
}

final readonly class Assertion
{
    public function __construct(
        public string $label,
        public Superbox $box,
        public mixed $data,          // decoded CBOR | decoded JSON | EmbeddedFile | CborBytes (uuid)
    ) {}
}

final readonly class EmbeddedFile
{
    public function __construct(public string $format, public string $bytes) {}
}

final readonly class Manifest
{
    public string $label;
    public Claim $claim;
    /** @var array<string, Assertion> */ public array $assertions;   // by label, in store order
    public Superbox $box;
    public function signatureBytes(): string;    // the signature box's cbor data, for M3
    public function claimBytes(): string;        // the claim box's cbor data, for M3
    public function resolve(string $uri): Superbox;   // throws ManifestException
}

final readonly class ManifestStore
{
    /** @var array<string, Manifest> */ public array $manifests;
    public Manifest $active;
    public static function fromTree(Superbox $root): self;
    /** @return array<string, mixed> c2patool's shape, restricted to what M2 knows */
    public function toArray(): array;
    public function toJson(): string;
}
```

`Manifest` is the Deptrac layer that may see `Jumbf`, `Cbor`, `Report`
and `Support`. The sister library enters only as a `require-dev`
dependency, for AC6; `src/` stays free of it (ADR-0001).

## Open questions

- Resolved before approval (step 14, 2026-09-21): the fifteen variants
  are built by `bin/make-claim-variants.php` and measured; c2patool's JSON
  for the four fixtures is recorded under `tests/Fixtures/c2patool/`.
- Resolved (tests-first step, 2026-09-21): `provemark/content-credentials`
  `^0.15` in `require-dev` (v0.15.1 locked; four PSR/discovery packages
  with it); `src/` does not use it.
- **Whether `toArray()` should list the hard-binding assertion.** c2patool
  leaves it out of `assertions`; the sister parser never reads it. Kept
  out for equality with the oracle; M4 reads it from the `Manifest`, not
  from the JSON. Non-blocker.

## Amendments

1. **2026-09-21, defined in SPEC-010 and approved with it** —
   `ManifestException` carries a `StatusCode` (C2PA 2.4 §15), set at
   every throw site: `claim.missing`, `claim.multiple`,
   `claim.cbor.invalid`, `claim.malformed`, `claimSignature.missing`,
   `assertion.missing`, `assertion.json.invalid`; `general.error` where
   §15 has no word. No criterion of this spec changed; the exception
   messages are as they were.
2. **2026-09-21, defined in SPEC-011 and approved with it** —
   `Manifest::$assertionStore` is public (`readonly` as the rest), so
   that a check can walk the store's children, `Superbox` and
   `UnknownBox` alike. No criterion of this spec changed.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Manifest/ManifestStoreTest.php :: AC1: the PNG store has one manifest, active, with a v2 claim / SPEC-007 | src/Manifest/ManifestStore.php :: fromTree(); src/Manifest/Manifest.php :: fromBox(); src/Manifest/Claim.php :: fromMap(), generatorInfo(), hashedUris() |
| AC2 | tests/Unit/Manifest/ManifestStoreTest.php :: AC2: the JPEG and WebP stores give the same claim shape / SPEC-007 | src/Manifest/ManifestStore.php :: fromTree() |
| AC3 | tests/Unit/Manifest/ManifestStoreTest.php :: AC3: assertions are decoded by content type / SPEC-007 | src/Manifest/Manifest.php :: assertionData(), mediaType(); src/Manifest/Assertion.php, EmbeddedFile.php |
| AC4 | tests/Unit/Manifest/ManifestStoreTest.php :: AC4: URIs resolve to boxes, relative and absolute / SPEC-007 | src/Manifest/Manifest.php :: resolve(), signatureBytes(), claimBytes() |
| AC5 | tests/Unit/Manifest/ManifestStoreTest.php :: AC5: the Adobe store gives a v1 claim without claim_generator_info / SPEC-007 | src/Manifest/Claim.php :: fromMap() (v1 required fields; claim_generator_info optional) |
| AC6 | tests/Unit/Manifest/ManifestStoreTest.php :: AC6: the JSON view is accepted by the sister library and agrees with c2patool / SPEC-007 | src/Manifest/ManifestStore.php :: toJson(), toArray(), manifestArray(), plain() |
| AC7 | tests/Unit/Manifest/ManifestStoreTest.php :: AC7: the JSON view has c2patool's shape / SPEC-007 | src/Manifest/ManifestStore.php :: manifestArray() |
| AC8 | tests/Unit/Manifest/ManifestStoreTest.php :: AC8: a claim label that is neither v1 nor v2 is an error / SPEC-007 | src/Manifest/Manifest.php :: fromBox() (the version match) |
| AC9 | tests/Unit/Manifest/ManifestStoreTest.php :: AC9: a claim missing a required field is an error naming the field and the version / SPEC-007 | src/Manifest/Claim.php :: fromMap() |
| AC10 | tests/Unit/Manifest/ManifestStoreTest.php :: AC10: a URI that resolves to nothing, to the wrong place, or to an unknown box is an error / SPEC-007 | src/Manifest/Manifest.php :: resolve(), checkReferences() |
| AC11 | tests/Unit/Manifest/ManifestStoreTest.php :: AC11: a hashed URI without a byte-string hash is an error / SPEC-007 | src/Manifest/Claim.php :: hashedUris(); src/Manifest/HashedUri.php |
| AC12 | tests/Unit/Manifest/ManifestStoreTest.php :: AC12: structural faults in the manifest are errors / SPEC-007 | src/Manifest/Manifest.php :: fromBox(), theOne(), singleCbor(); src/Manifest/ManifestStore.php :: fromTree() |
| AC13 | tests/Unit/Manifest/ManifestStoreTest.php :: AC13: invalid JSON in a json box is an error naming the assertion, never the bytes / SPEC-007 | src/Manifest/Manifest.php :: assertionData() (json) |
| AC14 | tests/Unit/Manifest/ManifestStoreTest.php :: AC14: a claim_generator_info without a name is an error / SPEC-007 | src/Manifest/Claim.php :: generatorInfo() |
