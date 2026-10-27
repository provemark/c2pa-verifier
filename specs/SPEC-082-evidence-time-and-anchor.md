# SPEC-082: Evidence in the report — the time judged at, and the anchor reached

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The report says *that* a manifest is `Trusted`, not *why*. Two facts decide
it, and the verifier computes both and throws them away:

- **The time judged at.** A signer's certificate is judged at a trusted
  time-stamp's `genTime`, else at the time the caller gave (SPEC-073), else
  at the clock (C2PA 2.4 §15.8.2). `Verifier::check()` knows which
  (`'from the trusted timestamp'`, `'no timestamp'`, …) and uses it only in
  a sentence. `VerificationReport::$validationTime` (SPEC-075) holds the
  given time or the clock, not the time a timestamp supplied, and holds one
  value for the whole store, while each ingredient manifest is judged at its
  own time (SPEC-021, `IngredientManifestCheck`).
- **The anchor reached.** `ChainCheck::checkCertificates()` walks the chain
  to an anchor, or finds the leaf on an allowed list (C2PA 2.4 §14.4.1,
  §14.4.3, §15.7), and answers with a status whose explanation names the
  anchor's common name. Which certificate it was, which list it came from
  and which stretch of that list's dates counted (SPEC-079) is not handed
  out. A common name does not identify a certificate: two of the test roots
  share one subject and differ in key (the reason ChainCheck compares keys).
  The same holds for the time-stamping authority's anchor (§14.4.2).

An archive that keeps a verdict for years needs both to show later what the
verdict rested on; the signer's certificate itself lives a year at most.
Re-deriving them outside means re-implementing the verifier.

This is the first of four specs that hand out the evidence behind a verdict
(candidate C1 of the revocation-and-evidence proposal, split by Maurice's
decision of 2026-10-27): time and anchor here; the `x5chain` certificates,
the time-stamps found and the OCSP responses each later, as further
properties of the same object.

Measured on 2026-10-27, `timestamp/tsa-matrix/control.png` with its
settings: `Trusted`; the signer's chain reaches `TSA Matrix root (control)`
at depth 2, SHA-256 of its DER
`dfc7fc93b63a3b31c666f40da3f10902cd7ad0a77c33e197d3801545d7d3ec98`; the
TSA's chain reaches `TSA Matrix tsa-root (control)` at depth 2,
`48f98f4727139f87f872f31de665b28fee7182a4adc00b3048dd742c5836fbb9`. The
token's time is 2026-10-09T05:30:24Z (SPEC-079's measurement).

## Scope

**In scope**

- `VerificationReport::evidence()`: one `Verifier\ManifestEvidence` per
  manifest whose signature was judged, keyed by its label: the active
  manifest first, then each ingredient manifest in the order checked.
- On each: `judgedAt` (epoch seconds) and `timeSource` (`timestamp`,
  `given` or `clock`), and `signer` and `tsa`, each a
  `Trust\AnchorEvidence` or null (amendment 1).
- `AnchorEvidence`: how the chain was trusted (`anchor` or `allowedList`),
  the SHA-256 of the anchor's DER (of the listed leaf for `allowedList`),
  the depth (links from the leaf to the anchor: 0 when the leaf is itself
  an anchor or on the allowed list), the `TrustListIdentity` of the list
  that made it count (SPEC-081; null for the legacy `trust_anchors` and for
  a settings document), and the stretch of that list's dates that held the
  time judged at (`trustedFrom`, `trustedUntil`; null for an open end, both
  null when the list has no dates).
- `signer` is set exactly when the manifest's signature has
  `signingCredential.trusted`; `tsa` exactly when its time-stamp has
  `timeStamp.trusted` and supplied the time judged at.
- The contract (SPEC-025) grows by the two classes and the method, for the
  same minor as SPEC-081.

**Out of scope** (each needs its own spec before it may be built)

- The certificates of `x5chain`, the time-stamps found (also the ignored
  ones) and the OCSP responses: the next three specs of C1.
- Evidence for an *untrusted* outcome (the anchor a chain would have
  reached, a list that did not trust it then): the status explanations say
  it today; a structured form is a later question.
- The hard binding as a separate outcome: the statuses carry it, and its
  C2PA 2.5 reading waits for `c2patool`.
- The evidence in `toArray()`, `toJson()`, `toCrJson()` or the CLI.

## Behavior

- **AC1 — a trusted signer and a trusted time-stamp**
  - Given `timestamp/tsa-matrix/control.png` with
    `control.settings.json`
  - When verified
  - Then `evidence()` holds the active manifest alone, with `timeSource`
    `timestamp`, `judgedAt` 2026-10-09T05:30:24Z; `signer`: route
    `anchor`, the signer root's SHA-256 above, depth 2, list null, both
    stretch ends null; `tsa`: route `anchor`, the TSA root's SHA-256 above,
    depth 2, list null, both ends null.

- **AC2 — the list that made it count**
  - Given the same file, with its two roots given as PEM lists through
    `TrustSettings::fromTrustLists()`
  - Then `signer->list` and `tsa->list` are the identities
    `trustLists()` reports for the signer and the TSA list (SPEC-081),
    the same objects' values.

- **AC3 — the stretch of a dated list**
  - Given the same file, with its roots in LoTE lists whose signer service
    is untrusted from a time after 2026-10-09T05:30:24Z (as SPEC-079's
    tests build them)
  - Then `signer->trustedFrom` is null and `signer->trustedUntil` is that
    time; with an untrusted stretch that ended before the token's time,
    `trustedFrom` is the stretch's end and `trustedUntil` null.

- **AC4 — the time when no time-stamp gave one**
  - Given `timestamp/tsa-matrix/no-timestamp.png` with its settings
  - When verified with a validation time, and without
  - Then `timeSource` is `given` with `judgedAt` that time, and `clock`
    with `judgedAt` equal to `$validationTime` of the report; `tsa` is
    null in both.

- **AC5 — the allowed list**
  - Given a file whose leaf is on the allowed list, with
    `trust/allowed-only.settings.json` (SPEC-014)
  - Then `signer` has route `allowedList`, the leaf's SHA-256, depth 0.

- **AC6 — an ingredient manifest has its own**
  - Given a file in the corpus with a trusted ingredient manifest
    (SPEC-021) and the settings that trust it
  - Then `evidence()` holds the ingredient manifest under its label, after
    the active one, with its own `judgedAt`, `timeSource` and anchors.

- **AC7 — the evidence agrees with the statuses**
  - Given every corpus file under no settings, under
    `trust/full.settings.json`, and under the programme's lists
  - Then for every manifest in `evidence()`, `signer` is non-null exactly
    when that manifest's signature has `signingCredential.trusted`, and
    `tsa` exactly when it has `timeStamp.trusted` and `timeSource` is
    `timestamp`; and every manifest whose signature has a status appears.

- **AC8 — nothing to show** *(required: error / malformed input)*
  - Given a file without a manifest, a store that cannot be read, an
    `x5chain` that cannot be read, an untrusted chain, and settings with
    `verify_trust` false
  - Then `evidence()` is empty for the first two; for the others the
    manifest appears with `signer` null, and nothing throws.

- **AC9 — the verdict does not move**
  - Given every corpus file under the settings of AC7
  - Then `toJson()` and `toCrJson()` are byte-identical before and after
    this spec.

- **AC10 — the contract**
  - The two classes and `evidence()` are in `public-surface.txt`; the
    surface test fails without them.

## References

- Specification: C2PA 2.4 §14.4.1–§14.4.3 (the lists, the allowed list),
  §15.7 (the chain to an anchor), §15.8.2 (the time a signer is
  judged at); ETSI TS 119 602 (a LoTE list's statuses, read by SPEC-079).
- Measured: the anchors and depths of `control.png` (2026-10-27, the
  verifier at `e01b800`, the status explanations); the token's time
  (SPEC-079).
- Oracle: none for the evidence itself, which `c2patool` does not hand
  out; AC7 holds it to the statuses, which are measured against
  `c2patool` elsewhere, and AC9 to this verifier before the change.
- Reasoned: that an archive needs it.

## API sketch

```php
namespace Provemark\C2paVerifier\Verifier;

final readonly class ManifestEvidence
{
    public const TIMESTAMP = 'timestamp';
    public const GIVEN = 'given';
    public const CLOCK = 'clock';

    public function __construct(
        public string $label,
        public int $judgedAt,             // epoch seconds
        public string $timeSource,        // one of the three above
        public ?AnchorEvidence $signer,
        public ?AnchorEvidence $tsa,
    ) {}
}

// namespace Provemark\C2paVerifier\Trust (amendment 1)
final readonly class AnchorEvidence
{
    public const ANCHOR = 'anchor';
    public const ALLOWED_LIST = 'allowedList';

    public function __construct(
        public string $route,
        public string $sha256,            // lowercase hex, of the anchor's (or listed leaf's) DER
        public int $depth,
        public ?TrustListIdentity $list,
        public ?int $trustedFrom,         // null: open, or no dates
        public ?int $trustedUntil,
    ) {}
}

// VerificationReport
/** @return array<string, ManifestEvidence> by manifest label */
public function evidence(): array;
```

## Open questions

1. **Several lists holding one anchor.** An anchor can sit in the legacy
   `trust_anchors` and in one or more entries. Proposal: name the one that
   made it count, in the order the check consults them (the legacy list
   first, as `ChainCheck::listedFault()` does), so the evidence says what
   the verdict used. Non-blocker.
   *Status 2026-10-27 (step 458):* decided by Maurice as proposed.
2. **Where the evidence is computed.** Re-running the walk afterwards
   would be a second implementation that could drift; the walk itself
   should hand it out. Proposal: `checkCertificates()` returns the anchor
   beside its status, internally, and the checks pass it up; AC7 is the
   drift alarm either way. Non-blocker.
   *Status 2026-10-27 (step 458):* decided by Maurice as proposed.

## Amendments

1. **2026-10-27, step 459, approved by Maurice van Loon ("akkoord").** Two
   things the build found.
   - **`AnchorEvidence` lives in `Trust`, not in `Verifier`.** The walk that
     makes it is `Trust\ChainCheck`, and the layer rules (`deptrac.yaml`)
     let `Trust` depend on nothing in `Verifier`. So the class is
     `Trust\AnchorEvidence`, beside `TrustListIdentity`; `ManifestEvidence`
     stays in `Verifier`. *Scope* and the API sketch read accordingly.
   - **AC7 for an ingredient manifest whose success lines were dropped.**
     When a writer recorded an ingredient manifest's success lines in its
     ingredient assertion, the report drops them, as `c2pa-rs` does
     (SPEC-021 amendment 1); the walk still trusted the signer and the
     evidence says so. Measured over the corpus: 8 such manifests, all in
     update-manifest files under `trust/full.settings.json`
     (`c2pa-rs/update_manifest.jpg` and seven under `update-manifest/`). AC7
     becomes: every manifest whose signature has a status in the report
     appears in `evidence()`, and for each manifest in `evidence()` whose
     signature has a status in the report, `signer` and `tsa` follow
     `signingCredential.trusted` and `timeStamp.trusted` as before; a
     manifest in `evidence()` whose signature has no status in the report is
     such an ingredient manifest, and keeps its evidence. Pinned on
     `c2pa-rs/update_manifest.jpg`.

## Traceability

All in `tests/Unit/Verifier/EvidenceTest.php`, group `SPEC-082`, unless
named otherwise. Seen red before the implementation (13 tests, all on the
missing `evidence()`; `ApiSurfaceTest` AC1 on the nineteen new symbols).

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | AC1: a trusted signer and a trusted time-stamp, each with its anchor | src/Trust/ChainCheck.php :: walk(), reached(); src/Timestamp/TimestampCheck.php :: judge(); src/Verifier/Verifier.php :: check() |
| AC2                  | AC2: the list that made each anchor count is the one trustLists() names | src/Trust/ChainCheck.php :: reached(); src/Timestamp/TimestampCheck.php :: tsaSettings() |
| AC3                  | AC3: the stretch of a dated list that held the time judged at | src/Trust/ChainCheck.php :: reached() |
| AC4                  | AC4: the time when no time-stamp gave one: given, or the clock | src/Verifier/IngredientManifestCheck.php :: evidenceOf() |
| AC5                  | AC5: a leaf on the allowed list | src/Trust/ChainCheck.php :: walk(), allowingList() |
| AC6                  | AC6: an ingredient manifest has its own evidence, after the active one | src/Verifier/IngredientManifestCheck.php :: check(), manifest() |
| AC7 (amendment 1)    | AC7: the evidence agrees with the statuses (5 datasets); AC7 (amendment 1): an ingredient manifest whose recorded success lines were dropped keeps its evidence; measured over the corpus (step 459) | src/Trust/ChainCheck.php :: checkWithAnchor(), timeStampingSigner() |
| AC8                  | AC8: nothing to show, and nothing thrown | src/Verifier/Verifier.php :: inspect(); src/Trust/ChainCheck.php :: checkWithAnchor() |
| AC9                  | AC9: the reports are the same with the evidence as without; measured over the corpus (step 459) | src/Verifier/VerificationReport.php :: evidence() |
| AC10                 | tests/Unit/ApiSurfaceTest.php :: AC1: the recorded surface is exactly what the contract classes expose today | src/Trust/AnchorEvidence.php; src/Verifier/ManifestEvidence.php; bin/api-check.php :: apiContract() |
