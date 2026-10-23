# SPEC-075: The crJSON document

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-23                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The C2PA Conformance Program's test harness takes an asset, a test Trust
List, a test TSA Trust List and a validation time, and answers in crJSON
(*Additional Conformance Requirements* v0.2; step 367). This verifier
has the four inputs (SPEC-031, SPEC-073) and the value mapping (SPEC-074),
but writes no crJSON document.

What the programme reads of one, measured in the 34 rubric files of
`c2pa-org/conformance-public` `da71a72`: `validationResults` (72
mentions), `assertions` (142), `label` (52), `claim.v2` with its
`created_assertions` and `claim_generator_info[0].specVersion`. Not one
mentions `signature`, `certificateInfo`, `jsonGenerator` or
`validationTime`.

C2PA 2.4 crJSON says what the document holds:

- §3.1: `@context`, `manifests`, `jsonGenerator`, all required.
- §3.3: `jsonGenerator` has `name` and `version` (SemVer 2.0).
- §3.4: every manifest of the store, in reverse store order, the active
  manifest first; each with `label`, `assertions`, `signature`,
  `validationResults`, one of `claim` or `claim.v2`, optionally
  `ingredientDeltas`, and nothing else.
- §3.5: the claim "serialised in the same manner as a CBOR serialised
  assertion", named after its box; an absent `gathered_assertions` or
  `redacted_assertions` written as `[]`.
- §3.6: `assertions` keyed by label. JSON-LD as it is; CBOR by Table 1
  (SPEC-074); `{}` where nothing can be derived.
- §3.8: per manifest, `success`, `informational`, `failure`,
  `specVersion` and `validationTime` (RFC 3339); ingredient deltas in the
  same manifest's `ingredientDeltas`.

Measured in steps 370 and this one, against `c2patool` 0.28.2 `--crjson`
on the 59 files of `tests/Fixtures/c2patool/crjson/`:

- The manifests are this verifier's store order reversed on 59 of 59.
- `manifests[0].validationResults` equals `c2patool`'s ordinary
  `activeManifest` on 62 of 62, and each delta sits on the manifest that
  holds its ingredient assertion. Each non-active manifest's own results
  equal the delta that names it (31 of 31); a manifest no delta names
  has empty results.
- This verifier's deltas sit on the same manifests as `c2patool`'s in 56
  of 59 files. In the other three (`c2pa-rs/exp-test1.png`,
  `public-testfiles/adobe-20220124-CACAICAICICA.jpg`,
  `adobe-20220124-CAIAIIICAICIICAIICICA.jpg`) a manifest is named by two
  ingredient assertions, and SPEC-021 AC9 validates it once, under the
  first. `c2pa-rs` lists it under both.
- The codes themselves differ in 42 of 59 files, all by divergences
  `docs/comparison.md` already names (an unanchored TSA's
  `timeStamp.untrusted`, `signingCredential.ocsp.skipped`, an expired
  signer behind an untrusted TSA, CAWG). The report's codes are tested
  against `c2patool` already (SPEC-010, SPEC-013, SPEC-021); this spec
  does not test them again.
- Claims mapped faithfully by SPEC-074: all 18 v2 claims are
  schema-valid. 53 of 74 v1 claims are not, 49 of them because they have
  no `claim_generator_info`, which the v1 schema requires and C2PA 1.x
  did not. `c2pa-rs` makes one up from `claim_generator`, and writes
  v1's relative URIs as absolute ones.
- Thumbnails and icons, in `c2patool`'s crJSON: `{format, identifier,
  hash}` on all 156 (`identifier` the assertion's absolute JUMBF URI,
  `hash` the claim's hash of it).

## Scope

**In scope**

- `VerificationReport::toCrJson(): string`, and `bin/c2pa-verify
  --crjson`, which prints it instead of the report.
- The report records the validation time it used: SPEC-073's, or the
  clock read once at the start of `verify()`.
- `Verifier::VERSION`, the package version as SemVer, for
  `jsonGenerator`.
- Root, manifests, claims, assertions, per-manifest validation results
  and deltas.
- A test validator for the schema (ADR-0006).

**Out of scope** (each needs its own spec before it may be built)

- `signature`: SPEC-076. Until then each manifest's `signature` is `{}`,
  which the schema refuses; the whole-document schema criterion is
  SPEC-076's. No release falls between the two.
- Any change to the checks or their codes.
- Remote manifests, sidecars: no store, see AC8.

## Behavior

- **AC1 — the root**
  - Given any fixture with a manifest store
  - When `toCrJson()` is called
  - Then the document has exactly `@context` (`{"@vocab":
    "https://c2pa.org/crjson", "extras":
    "https://c2pa.org/crjson/extras"}`, the form of the page's minimal
    example and of `c2pa-rs`), `jsonGenerator` (`{"name":
    "provemark/c2pa-verifier", "version": Verifier::VERSION}`) and
    `manifests`.

- **AC2 — the manifests, in reverse store order**
  - Given the 59 files of `tests/Fixtures/c2patool/crjson/`
  - When each is written as crJSON
  - Then `manifests` holds every manifest of the store, the active one
    first, and the labels in the order `c2patool` gives them, on 59 of
    59; each manifest has exactly `label`, `assertions`, `signature`,
    `validationResults` and `claim` or `claim.v2` (by the claim box's
    label), plus `ingredientDeltas` where a delta belongs.

- **AC3 — the claim, faithfully**
  - Given the same files
  - When written
  - Then the claim is SPEC-074's mapping of the claim's CBOR (decoded
    again with `keepMaps`), with `gathered_assertions` and
    `redacted_assertions` added as `[]` where a v2 claim has none, and
    `redacted_assertions` as `[]` where a v1 claim has none. Nothing is
    added from elsewhere and no URI is rewritten. Against `c2patool`'s
    claim, under SPEC-074 AC7's normalisations: equal for every v2 claim;
    for v1, different only in the three named ways (relative URIs,
    `signature` relative, `claim_generator_info` absent where the claim
    has none). Open question 1.

- **AC4 — the assertions**
  - Given the same files
  - When written
  - Then `assertions` has one key per assertion of the manifest, in
    store order, its label as the key: a `cbor` assertion is SPEC-074's
    mapping, `{}` where SPEC-074 refuses it; a `json` assertion is its
    JSON as it is (objects stay objects); an embedded file (thumbnail,
    icon) is `{"format", "identifier", "hash"}`, `identifier` the
    absolute JUMBF URI, `hash` the claim's hashed-URI hash as `b64'…'`,
    `hash` left out where the claim does not reference it; any other
    content is `{}`. Against `c2patool`: every key present on both sides,
    and every embedded-file value equal.

- **AC5 — the validation results, per manifest**
  - Given the same files, and the report of each
  - When written
  - Then `manifests[0].validationResults` holds the report's active
    statuses, grouped as `toArray()` groups them (`code`, `url`,
    `explanation`), with `specVersion` `"2.4.0"` and `validationTime`;
    each delta is in the `ingredientDeltas` of the manifest whose label
    its `ingredientAssertionURI` names; each other manifest's
    `validationResults` holds the delta that names it, and empty lists
    where none does. Against `c2patool`: each delta on the same manifest
    in 56 of 59 files, the three of SPEC-021 AC9 named.

- **AC6 — the validation time**
  - Given a fixture verified with SPEC-073's `2026-10-09T05:31:00Z`, and
    one verified without a time
  - When written
  - Then every `validationTime` of the first is `2026-10-09T05:31:00Z`,
    and of the second the time `verify()` read when it started, as RFC
    3339 UTC with a `Z`; every manifest of one document carries the same.

- **AC7 — schema-valid outside the signature**
  - Given every crJSON written for the 59 files and for the fixtures of
    AC6, and the schema copied under `tests/Fixtures/crjson/` (ADR-0006)
  - When validated with `opis/json-schema`
  - Then there is no error outside `manifests[*].signature`, except those
    open question 1 accepts for v1 claims, each by file and path. Before
    that, the validator reproduces step 370's findings on `c2patool`'s
    own answers.

- **AC8 — no store, no manifests** *(required: error path)*
  - Given a file without a manifest store (`fixture-unsigned.png`), one
    with a broken store (a JUMBF fault fixture), and an unsupported file
  - When written
  - Then `manifests` is `[]` and the root is otherwise as AC1. On the
    command line `--crjson` prints that document and exits as the report
    would (1 for `Invalid`); the report's failure, which crJSON has no
    place for, goes to standard error as one line. Open question 2.

- **AC9 — the command line**
  - Given `bin/c2pa-verify --crjson` with and without `--settings` and
    `--at`, and `--crjson` twice
  - When run
  - Then standard output is `toCrJson()` of the same call plus one
    newline, the exit status is the report's, and `--crjson` given twice
    is a usage fault (exit 2).

## References

- Specification: C2PA 2.4 crJSON §3.1–§3.8, §5 (the schema); RFC 3339
  §5.6; C2PA Conformance Program v0.2, *Additional Conformance
  Requirements*, "Support of crJSON format".
- Oracle: `c2patool` 0.28.2 `--crjson` under
  `tests/Fixtures/c2patool/crjson/` (59 answers), for order, placement,
  keys, claims and embedded files; not for codes (see Problem).
- Measured: the counts in Problem; the rubric field counts.
- Reasoned: that the programme compares what its rubrics read; that a
  faithful claim is what §3.5.1 asks.

## API sketch

```php
// src/Verifier/Verifier.php
public const string VERSION = '0.8.0';   // the release commit sets it; bin/package-check.php checks it against the tag

// src/Verifier/VerificationReport.php
public ?\DateTimeImmutable $validationTime;   // the time judged at where no trusted timestamp gave one
public function toCrJson(): string;

// src/Verifier/CrJsonDocument.php — @internal: builds the array toCrJson() encodes (Verifier may use every layer; Report is a leaf)
```

`toCrJson()` encodes with `JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`
and throws on a JSON error, as `toJson()` does. The contract grows by
three symbols (`VERSION`, `$validationTime`, `toCrJson()`); 0.8.0.

## Open questions

1. **v1 claims without `claim_generator_info`.** The v1 schema requires
   it; C2PA 1.x did not, and 49 of the 74 v1 claims here lack it.
   *Proposal:* faithful, as §3.5.1 says, and AC7 accepts that error by
   name for a v1 claim that lacks the field in its CBOR. *Alternative:*
   `c2pa-rs`'s `[{"name": <claim_generator>}]`, which passes the schema
   but writes a value the claim does not hold. The programme supports
   spec versions 2.2 and 2.4, whose claims are v2. Blocking for AC3/AC7.
   *Status 2026-10-23 (step 375):* settled as proposed: faithful.
2. **A file without a store.** *Proposal:* `manifests: []`, the failure
   on standard error, the exit status as the report's. *Alternative:* no
   crJSON at all (exit 2), as `c2patool` gives no output. Not blocking.
   *Status 2026-10-23 (step 375):* settled as proposed: `manifests: []`.

## Amendments

1. **2026-10-23, step 375, decided by Maurice van Loon** *(confirmed by Maurice van Loon, 2026-10-23: "akkoord")* — building AC3 and AC7 against the 59 answers showed more ways in which a faithful claim differs from `c2pa-rs`'s and from the v1 schema than the draft named. Nothing in the output changed; the comparisons name what they measured.
   - AC3, against `c2patool`, the named ways become five: (a) `c2pa-rs` writes the claim's `signature` URI absolute, in v1 and in v2 (one v2 claim here holds it relative); (b) it writes v1's `assertions` URIs absolute and leaves v2's `created_assertions` and `gathered_assertions` as they are; (c) where the claim's `claim_generator_info` is absent, `null` or empty, it makes one up from `claim_generator`; (d) it writes `redacted_assertions: []` for v2 claims only, where §3.5.1 asks it of every claim, as this verifier does; (e) it leaves out v1 fields the v1 schema does not name (`claim_generator_hints`). Each is a normalisation of the comparison, and the test names it.
   - AC7 accepts **every** schema error inside a v1 `claim`, not only a missing `claim_generator_info`: open question 1 settled on writing v1 claims as their CBOR holds them, and C2PA 1.x claims break the v1 schema in four measured ways (49 without `claim_generator_info`, 2 with it `null`, 1 with it empty, 1 with `metadata` an array, which `c2patool`'s own crJSON has too). v2 claims stay under the whole schema.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC1: the root holds @context, jsonGenerator and manifests, nothing else / SPEC-075 | src/Verifier/CrJsonDocument.php :: of(), CONTEXT; src/Verifier/Verifier.php :: VERSION |
| AC2 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC2: every manifest, in reverse store order, with exactly its fields / SPEC-075 | src/Verifier/CrJsonDocument.php :: of() |
| AC3 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC3: the claim is faithful; against c2patool equal for v2, and for v1 but for five named ways; AC3: a v2 claim without gathered or redacted assertions writes them as empty lists / SPEC-075 | src/Verifier/CrJsonDocument.php :: claim() |
| AC4 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC4: one key per assertion in store order; embedded files as c2patool writes them / SPEC-075 | src/Verifier/CrJsonDocument.php :: assertions(), assertion() |
| AC5 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC5: the results, per manifest; deltas on the manifest that holds the ingredient; AC5: an ingredient manifest holds the delta that names it, and empty lists where none does / SPEC-075 | src/Verifier/CrJsonDocument.php :: of(), ownResults() |
| AC6 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC6: every manifest carries the validation time the report judged at / SPEC-075 | src/Verifier/Verifier.php :: verify(), inspect(); src/Verifier/VerificationReport.php :: $validationTime |
| AC7 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC7: the validator first reproduces step 370 on c2patool's own answers; AC7: every crJSON written is schema-valid but for the accepted v1 claims / SPEC-075 | tests/Fixtures/crjson/crJSON.schema.json and README (ADR-0006); composer.json `require-dev` opis/json-schema |
| AC8 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC8: without a store, manifests is empty and the root is otherwise as AC1 (3 datasets); AC8: on the command line, the report's failure goes to standard error and the exit is the report's / SPEC-075 | src/Verifier/CrJsonDocument.php :: of(); src/Cli/Command.php :: run() |
| AC9 | tests/Unit/Verifier/CrJsonDocumentTest.php :: AC9: --crjson prints toCrJson() of the same call, with --settings and --at / SPEC-075 | src/Cli/Command.php :: run() (`--crjson`); src/Verifier/VerificationReport.php :: toCrJson() |

`Verifier::VERSION` is `0.8.0-dev`, not `0.8.0` as the API sketch had it:
SemVer allows a pre-release, and the release commit sets the final
number. `CrJsonDocument` lives in `src/Verifier/`, as the sketch's
corrected line says.

The tests were run red first: 14 of 15 failed on the missing method; the
fifteenth checks the validator itself against step 370 and was green, as
it should be. Four mutations were watched failing: every delta on the
active manifest (AC5), store order not reversed (AC2, AC5), the clock in
place of the report's time (AC6), the embedded hash left out (AC4). The
ordinary report over the corpus, before and after: 0 of 1,842 runs
moved. The contract grew by three symbols, to 139. `composer check`:
1,182 passed, 1 skipped.
