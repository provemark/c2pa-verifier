# SPEC-077: The two trust lists as input

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

The C2PA Conformance Program's test harness takes four inputs: an asset,
a (test) C2PA Trust List, a (test) C2PA TSA Trust List and a validation
time, and answers in crJSON (*Additional Conformance Requirements* v0.2,
"Support of crJSON format …"; step 367). This verifier has the time
(SPEC-073) and crJSON (SPEC-074 to SPEC-076). The two lists it takes only
inside a settings document in `c2patool`'s JSON shape, which the caller
has to build (`docs/trust-settings.md`).

The programme publishes its lists as PEM bundles
(`c2pa-org/conformance-public`, `trust-list/C2PA-TRUST-LIST.pem` and
`C2PA-TSA-TRUST-LIST.pem`, CC BY 4.0), and a harness should take them as
they come. Building the JSON by hand is where a mistake hides: both lists
in the single `trust.trust_anchors` field let a time-stamp authority's
anchor vouch for a signer and the reverse, which C2PA 2.4 §14.4.1 and
§14.4.2 keep apart (SPEC-031; its AC9 names the one difference that
remains).

Measured on 2026-10-23, `conformance-public` `da71a72`:

- The signer list holds 32 certificates, the TSA list 23; **16 are in
  both**. Swapping the two official lists therefore leaves the two
  fixtures that are `Trusted` under them `Trusted`
  (`writers/google-20250919-pixel10-npld-picnic-table.jpg`,
  `writers/openai-20260826-c2pa_2x.png`). The official lists cannot show
  the separation.
- `timestamp/tsa-matrix/control.png` has a throw-away signer root and a
  separate TSA root. With its settings it is `Trusted`
  (`signingCredential.trusted`, `timeStamp.trusted`); with the two
  entries' kinds swapped it is `Valid` (`signingCredential.untrusted`,
  `timeStamp.untrusted`). Without its `trust_config` it stays `Trusted`:
  the built-in EKUs (SPEC-015) suffice, so the lists need no EKU file.

## Scope

**In scope**

- `bin/c2pa-verify --trust-list <pem>` and `--tsa-trust-list <pem>`, each
  a file of PEM certificates, built into one `"manifest"` and one `"tsa"`
  entry (SPEC-031) with `verify_trust` on.
- `TrustSettings::fromTrustLists(string $signers, ?string $tsas = null)`,
  the same for a library caller, since the entry class is internal.
- `docs/conformance-harness.md`: the harness call, in one place.
- The official lists at `da71a72` as fixtures, with their licence.

**Out of scope** (each needs its own spec before it may be built)

- An allowed list or an EKU file beside the lists (`--settings` still
  takes both).
- Fetching or refreshing a list. The programme says validators "SHALL
  regularly refresh the C2PA Trust List"; that is the operator's, and the
  verifier makes no network request (ADR-0001).
- The test mode with the Conformance Test Root: the next step.

## Behavior

- **AC1 — the lists are the two entries, and nothing else**
  - Given the official lists, and the Pixel, OpenAI and
    `fixture-signed.png` files
  - When verified with `--trust-list` and `--tsa-trust-list`, and with
    `--settings` holding `{"verify": {"verify_trust": true}, "trust":
    {"anchors": [{signer list, "manifest"}, {TSA list, "tsa"}]}}` built
    from the same two files
  - Then standard output, with and without `--crjson`, is the same byte
    for byte, and so is the exit status; the Pixel and OpenAI files are
    `Trusted`.

- **AC2 — the lists stay apart**
  - Given `timestamp/tsa-matrix/control.png`, and its two anchors (from
    its settings file) as two PEM files
  - When verified with them in place, and swapped
  - Then in place it is `Trusted` with `signingCredential.trusted` and
    `timeStamp.trusted`; swapped it is `Valid` with
    `signingCredential.untrusted` and `timeStamp.untrusted`.

- **AC3 — one list alone**
  - Given `control.png` with only `--trust-list` (its signer root)
  - When verified
  - Then it is `Trusted`, the signer `signingCredential.trusted` and the
    time-stamp `timeStamp.untrusted`: no TSA is anchored. With only
    `--tsa-trust-list` it is `Valid`, the signer
    `signingCredential.untrusted` and the time-stamp `timeStamp.trusted`.
    (Measured with settings holding one entry each, 2026-10-23.)

- **AC4 — the harness call**
  - Given `--crjson --trust-list … --tsa-trust-list … --at
    2026-10-09T05:31:00Z` on a fixture
  - When run
  - Then standard output is `toCrJson()` of `verify()` with
    `TrustSettings::fromTrustLists()` and that time, and every
    `validationTime` is `2026-10-09T05:31:00Z`.

- **AC5 — what is refused** *(required: error path)*
  - Given `--settings` together with either list option; a list option
    given twice or last without a path; a list file that does not exist
    or is a directory; a file without one `BEGIN CERTIFICATE` block; a
    PEM block that is not a certificate
  - When run
  - Then no report is printed, standard error names the option and the
    fault in one line plus the usage, and the exit status is 2. The
    library call throws `TrustException` for the last two.

## References

- Specification: C2PA 2.4 §14.4.1, §14.4.2 (signer and TSA trust lists,
  kept apart); C2PA Conformance Program v0.2, *Additional Conformance
  Requirements*, "Support of crJSON format" and "Machine-Readable List
  Operation".
- Oracle: this verifier's own `--settings` path (SPEC-031), which the
  corpora test against `c2patool`; the lists add no check, only a way in.
- Measured: the overlap of the two official lists; `control.png` in
  place, swapped and without `trust_config`.
- Reasoned: that a list without a certificate is a mistake to refuse,
  not an empty list to accept, since it would make every signer
  untrusted without a word.

## API sketch

```php
// src/Trust/TrustSettings.php — the contract grows by one symbol (0.8.0)
public static function fromTrustLists(string $signers, ?string $tsas = null, int $maxCertificates = self::DEFAULT_MAX_CERTIFICATES): self;
//   new self([], [], [], true, [new TrustAnchorSet('manifest', …), new TrustAnchorSet('tsa', …)])
//   each list through certificatesFromPem(); none found is a TrustException

// src/Cli/Command.php: --trust-list, --tsa-trust-list; with --settings a usage fault
```

## Open questions

- None blocking. The five choices were made by Maurice on 2026-10-23:
  options on the existing command; exclusive with `--settings`; an empty
  list refused; one list alone allowed; byte-equality with `--settings`
  as the test.

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
