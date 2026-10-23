# SPEC-076: The signature in crJSON

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | SPEC-075's `"signature": {}` placeholder          |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-075 writes each manifest's `signature` as `{}`. The crJSON schema
requires `algorithm` and `certificateInfo` there, so every document this
verifier writes fails it (SPEC-075 AC7 excepts that path). C2PA 2.4
crJSON §3.7 says what the object holds:

- `algorithm`: "one of the strings defined in the C2PA specification,
  such as "ES256" or "Ed25519", or "Unknown" if it is not one of the
  defined values". C2PA 2.4 §13.2.1 allows ES256, ES384, ES512, PS256,
  PS384, PS512 and EdDSA, Ed25519 only.
- `certificateInfo`: the leaf of the claim signature's `x5chain`, with
  "(if available)" `serialNumber`, `issuer` and `subject` as DN maps, and
  `validity.notBefore`/`notAfter`, times as ISO 8601. Further certificates
  of the chain "should not be extracted".
- `timeStampInfo`, when the signature carries a time-stamp: `timestamp`
  and the time-stamp authority's leaf as `certificateInfo`.
- "When signature information is unavailable, signature shall be
  present as an empty object `{}`" (the schema disagrees; step 370).

The schema's `distinguishedName` allows exactly `C`, `ST`, `L`, `O`,
`OU`, `CN`, `E` and `2.5.4.97`, each a string, nothing else.

Measured on 2026-10-23 against `c2patool` 0.28.2 `--crjson`, 92 manifests
of the 59 files under `tests/Fixtures/c2patool/crjson/`:

- `algorithm` in lower case: `ps256` 70, `es256` 21, `es384` 1.
- `serialNumber` as lower-case hex without leading zeros; validity and
  timestamps as `+00:00`.
- `timeStampInfo` on 75 manifests; on 6 of them without `timestamp` (the
  token failed: `timeStamp.mismatch` 5, `timeStamp.malformed` 1), which
  the schema refuses.
- `c2pa-rs` 0.91.1 (`src/crjson.rs`, `extract_dn_components()`) keeps
  `C`, `ST`, `L`, `O`, `OU`, `CN` in the certificate's order, skips any
  other attribute, and lets a repeated attribute's last value win.

The certificates this verifier meets: 193 distinct leaf certificates in
the fixtures carry `organizationName`, `commonName`, `countryName`,
`organizationalUnitName`, `stateOrProvinceName`, `localityName` and
`emailAddress`, nothing else; one has an attribute twice.

## Scope

**In scope**

- `algorithm`, `certificateInfo` and `timeStampInfo` for every manifest
  of the document, the active one and each ingredient manifest.
- The time-stamp token read again for crJSON: the one SPEC-017 and
  SPEC-064 judge (the header's `sigTst2`/`sigTst`, else a
  `c2pa.time-stamp` assertion's), its `genTime` and its signer's
  certificate, whether or not it validated.
- The whole-document schema criterion that SPEC-075 left here.

**Out of scope** (each needs its own spec before it may be built)

- Intermediate certificates (§3.7: should not be extracted).
- Trust, revocation, OCSP: those are statuses, already in
  `validationResults`.
- CAWG identity signatures (`cawg.identity` stays as SPEC-074 maps it).

## Behavior

- **AC1 — the algorithm by its C2PA name**
  - Given the 59 files, and the COSE `alg` of each claim signature
  - When written
  - Then `algorithm` is `ES256`, `ES384`, `ES512`, `PS256`, `PS384`,
    `PS512` or `Ed25519` for COSE -7, -35, -36, -37, -38, -39 and -8, and
    `Unknown` for anything else. Against `c2patool`: equal on all 92
    manifests after upper-casing `c2patool`'s.

- **AC2 — the leaf certificate**
  - Given the same files
  - When written
  - Then `certificateInfo` holds `serialNumber` (lower-case hex, no
    leading zeros), `issuer`, `subject` and `validity` (`notBefore`,
    `notAfter` as `YYYY-MM-DDTHH:MM:SSZ`), from the first certificate of
    the `x5chain`. Against `c2patool`: equal on all 92, the times compared
    as instants.

- **AC3 — the distinguished names**
  - Given the certificates of the 59 files, and a unit vector with an
    attribute outside the eight, and one with `OU` twice
  - When written
  - Then each DN holds the schema's eight keys only, as strings, in the
    certificate's order; an attribute outside them is left out; a
    repeated one is written once, its last value. Against `c2patool`:
    equal on all 92 (where `c2pa-rs` drops `E`, this verifier keeps it;
    named if a file shows it).

- **AC4 — the time-stamp**
  - Given the same files, and `timestamp/tsa-matrix/` probes with a
    validated, an untrusted and a mismatched token
  - When written
  - Then `timeStampInfo` is present wherever the manifest's token can be
    read, with `timestamp` (the token's `genTime`, whole seconds, `Z`) and
    the time-stamp authority's leaf as `certificateInfo`, whether or not
    the token validated; it is absent where there is no token or none can
    be read. Against `c2patool`: present on the same 75 manifests; the
    time equal on the 69 where `c2patool` writes one; on the 6 where it
    writes none, this verifier writes the token's time.

- **AC5 — a signature that cannot be read** *(required: error path)*
  - Given a manifest whose `x5chain` is missing or holds no certificate,
    and one whose COSE structure does not decode (from the existing
    `cose/` and `x5chain/` fixtures)
  - When written
  - Then `signature` is `{}`, as §3.7 says, and no exception escapes.

- **AC6 — the whole document against the schema**
  - Given every crJSON written for the 59 files, the `tsa-matrix` probes
    and the fixtures of SPEC-075 AC6 and AC8
  - When validated with `opis/json-schema` (ADR-0006)
  - Then there is no error, except, by name: an error inside a v1 claim
    (SPEC-075 amendment 1), and `signature` `{}` where AC5 applies.
    SPEC-075 AC7's exception for `/manifests/*/signature` is removed.

## References

- Specification: C2PA 2.4 crJSON §3.7 and §5 (schema `signature`,
  `certificateInfo`, `distinguishedName`); C2PA 2.4 §13.2.1; RFC 5280
  §4.1.2.2, §4.1.2.4–§4.1.2.6; RFC 3161 §2.4.2 (`genTime`).
- Oracle: `c2patool` 0.28.2 `--crjson` under
  `tests/Fixtures/c2patool/crjson/`, with the named differences
  (upper-case names, `Z`, the 6 time-stamps it leaves out).
- Measured: the counts in Problem.
- Reasoned: that a token's time is worth writing when it failed, since
  `validationResults` says it failed and the schema requires the time.

## API sketch

```php
// src/Verifier/CrJsonDocument.php (SPEC-075), one more private part:
private static function signature(Manifest $manifest): array|\stdClass;

// src/Timestamp/ — read the judged token without judging it:
// its genTime and its signer's Certificate, or null where none can be read.
```

The contract does not change: the signature is inside the crJSON string.

## Open questions

- None blocking. The six choices were made by Maurice on 2026-10-23: the
  crJSON page's names, `c2pa-rs`'s serial form, the schema's eight DN
  keys with the last of a repeat winning, `Z` throughout, a failed but
  readable token written, `{}` for an unreadable signature.

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
