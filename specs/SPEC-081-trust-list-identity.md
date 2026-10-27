# SPEC-081: Which trust lists a verdict was judged against

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A `Trusted` verdict means something only together with the lists it was
judged against. This verifier takes the conformance programme's two lists
as input, as PEM bundles (SPEC-077) or in ETSI TS 119 602's JSON form, LoTE
(SPEC-079), and then forgets them: the report says `signingCredential.trusted`
but not which list made it so. An archive that keeps a verdict cannot later
show what the verdict rested on, and a harness that runs the same asset
twice cannot tell whether the list changed in between.

The obvious identifier does not identify. Measured on 2026-10-26 over the
full history of `c2pa-org/conformance-public`: the signer list in JSON
exists in six versions with different content between 5 August and
17 October 2026 (commits `0af418f`, `64625cc`, `70ec46e`, `c3f64cf`,
`99927ca`, `47009ee`), the TSA list in five (`722b639`, `4cb1f31`,
`1829df5`, `99927ca`, `47009ee`), and **every one of them carries
`LoTESequenceNumber` 1 and the same `ListIssueDateTime`**. C2PA 2.5
§14.2.2 names the sequence number as the identifier of an ETSI list, so
that identifier cannot tell these versions apart. Only the bytes can.

C2PA 2.5 adds a place for this in crJSON: `trustListUris`, entries with
`uri`, `type` (`c2pa.trustList`, `c2pa.tsaTrustList`) and `identifier`
(§14.2.2, §16.5.13.4.3). C2PA 2.4 has no such field. Measured on
2026-10-27: `c2patool` 0.28.1, 0.28.2 and 0.28.3 write no trust-list field
in `--crjson` (`out/signed.png` of the sister repository, with its trust
settings); their binaries name `trustListUri` and `timestampTrustListUri`,
singular, as fields of `validationResults`, and not `trustListUris`. The
crJSON field therefore has no oracle yet and waits, as every 2.5 addition
does (`docs/milestones.md`). What this spec adds is the record itself, in
this verifier's own report, which changes no verdict and needs none.

## Scope

**In scope**

- For each list given to `TrustSettings::fromTrustLists()`: the SHA-256
  of the exact string passed (before a byte order mark is taken off), the
  form read (`pem` or `lote`), its kind (`manifest` or `tsa`), and for a
  LoTE list `LoTESequenceNumber` and `ListIssueDateTime` from
  `ListAndSchemeInformation`.
- A `readonly` value object `Trust\TrustListIdentity` holding that, on the
  `TrustAnchorSet` the list became (`identity`, null for a set built
  otherwise).
- `VerificationReport::trustLists()`: the identities of the lists given to
  `verify()`, in the order given, or an empty list.
- The contract (SPEC-025) grows by these symbols, with
  `tests/Fixtures/api/public-surface.txt`. A contract change: it waits
  for the next minor, not a patch.

**Out of scope** (each needs its own spec before it may be built)

- `trustListUris` in `toCrJson()`: no oracle yet (see *Problem*).
- The identity in `toArray()`, `toJson()` or the CLI's output: the report
  shape stays `c2patool`'s.
- A settings document in `c2patool`'s shape (`TrustSettings::fromJson()`,
  `fromArray()`): see open question 1.
- The evidence behind a verdict, anchor reached and the rest (candidate C1
  of the revocation-and-evidence proposal): a later spec, which may build
  on this one.

## Behavior

- **AC1 — a PEM list is named by its bytes**
  - Given `tests/Fixtures/trust/conformance-public/C2PA-TRUST-LIST.pem`
    as the signer list and `C2PA-TSA-TRUST-LIST.pem` as the TSA list
  - When an asset is verified with `TrustSettings::fromTrustLists()` of
    the two
  - Then `trustLists()` holds two identities, in that order: kind
    `manifest`, form `pem`, SHA-256
    `b3949271a07b007e92ef1d18050b55388f5e0d2686a16fd864176c48421af8ae`;
    kind `tsa`, form `pem`, SHA-256
    `7182cb227c02701be0eebd67ab635fca7f85431c32796fb53b267c657cd2c675`;
    sequence number and issue time null for both.

- **AC2 — a LoTE list also carries its number and date**
  - Given the two `.json` lists of the same directory
  - When verified as in AC1
  - Then the identities hold form `lote`, SHA-256
    `f023f31dc3eb534d0afaab97b95cc8744ee2d88da6daebe5abf08770c3841d8c`
    and `7ac0b1c96e4b5ad346c7f40794c54ce8510776e4a772d62c6ec33556a011a503`,
    sequence number 1 for both, issue time `2026-08-05T00:11:39Z` and
    `2026-08-05T00:11:47Z`, verbatim.

- **AC3 — one byte apart, two identities**
  - Given the signer list of AC2 and a copy with one byte of a
    certificate's base64 changed for another valid base64 character, both
    still read (same sequence number, same issue time)
  - When each is given alone
  - Then the two identities differ in their SHA-256 and in nothing else.
    This is the case measured in `conformance-public`.

- **AC4 — the bytes as given**
  - Given the signer list of AC1 with a byte order mark in front
  - When given alone
  - Then the SHA-256 is that of the string with the mark, not of the list
    after it was taken off.

- **AC5 — no lists, nothing named**
  - Given `verify()` without settings, and `verify()` with settings from
    `TrustSettings::fromJson()`
  - Then `trustLists()` is an empty list in both.

- **AC6 — the verdict does not move**
  - Given every corpus fixture verified under the lists of AC1 and of AC2
  - Then `toArray()` and `toCrJson()` are byte-identical before and after
    this spec.

- **AC7 — a number or date that is not one** *(required: malformed input)*
  - Given LoTE lists whose `LoTESequenceNumber` is absent, a string, `0`,
    negative, or a float, and whose `ListIssueDateTime` is absent, a
    number, or a string that is not an RFC 3339 date-time (`2026-08-05`,
    one with a control character)
  - When given
  - Then the list is read as before this spec (its anchors and dates
    unchanged), and the identity holds null for that field: never a value
    that was not checked, never a refusal that did not exist before
    (open question 2).

- **AC8 — the contract**
  - The new class, the `identity` property and `trustLists()` are in
    `public-surface.txt`; the surface test fails without them.

## References

- Specification: C2PA 2.5 §14.2.2 (an ETSI list's identifier, the
  sequence number), §16.5.13.4.3 (`trustListUris` in crJSON); C2PA 2.4
  §14.4.1, §14.4.2 (the two lists, kept apart); ETSI TS 119 602
  (`ListAndSchemeInformation`, `LoTESequenceNumber`, `ListIssueDateTime`).
- Measured: the `conformance-public` history (2026-10-26, commits as
  listed in *Problem*); `c2patool` 0.28.1–0.28.3 `--crjson` and the
  strings in their binaries (2026-10-27); the SHA-256 values in AC1 and
  AC2 with `shasum -a 256` over the fixtures (2026-10-27).
- Oracle: none for the record itself; it states bytes this verifier was
  given. AC6 is measured against this verifier before the change.
- Reasoned: that an archive needs the record; that the sequence number
  alone is not enough follows from the measurement.

## API sketch

```php
namespace Provemark\C2paVerifier\Trust;

final readonly class TrustListIdentity
{
    public const PEM = 'pem';
    public const LOTE = 'lote';

    public function __construct(
        public string $kind,             // TrustAnchorSet::MANIFEST or ::TSA
        public string $form,             // self::PEM or self::LOTE
        public string $sha256,           // lowercase hex, of the string as given
        public ?int $sequenceNumber = null,
        public ?string $issueDateTime = null,   // RFC 3339, verbatim
    ) {}
}

// TrustAnchorSet: public ?TrustListIdentity $identity = null (last parameter)

namespace Provemark\C2paVerifier\Verifier;

// VerificationReport
/** @return list<TrustListIdentity> */
public function trustLists(): array;
```

## Open questions

1. **A settings document.** A caller with `c2patool`'s JSON shape
   (`fromJson()`) could be named too: a digest of the whole document, or
   one per `trust.anchors` entry. Proposal: not now. That shape is built
   by the caller, who already holds its bytes; the programme's lists are
   what an archive takes from outside. Non-blocker.
   *Status 2026-10-27 (step 455):* decided by Maurice as proposed: not
   now; `trustLists()` is empty for such settings (AC5).
2. **A malformed number or date.** Refusing the list would add a refusal
   that a list read today does not meet, and a verdict could move with it.
   Proposal: null, as in AC7, since the digest identifies the list either
   way. Non-blocker.
   *Status 2026-10-27 (step 455):* decided by Maurice as proposed: null,
   no new refusal (AC7).

## Traceability

Filled when status becomes `implemented`.

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
