# SPEC-076: The signature in crJSON

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-23                      |
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
  *Status 2026-10-23 (step 377):* settled; approved without changes.

## Amendments

1. **2026-10-23, step 377, decided by Maurice van Loon** *(confirmed by Maurice van Loon, 2026-10-23: "akkoord")* — building AC2 to AC4 against the 92 manifests showed three differences from `c2pa-rs` the draft did not count. The output follows the draft; the comparisons name what they measured.
   - AC2 and AC3: where a DN holds an e-mail address, this verifier writes it as `E`, which the schema names, and `c2pa-rs` leaves it out (11 names in the 59 files). The comparison removes `E` from this verifier's side where `c2patool`'s lacks it.
   - AC4: `timeStampInfo` is on the same manifests as `c2patool`'s in 74 cases, not 75, and two manifests differ by name. `c2pa-rs/CA_ct.jpg`'s header token cannot be read here (`c2patool` itself reports `timeStamp.malformed`; `c2pa-rs` writes the TSA's certificate without a time, which the schema refuses), so this verifier writes no `timeStampInfo`. `c2pa-rs/update_manifest.jpg`'s manifest has no header token but a `c2pa.time-stamp` assertion's (SPEC-064), which this verifier reads and `c2pa-rs` does not use for crJSON. Of the 74, the time is equal on the 69 where `c2patool` writes one, and this verifier adds it on the other 5.
   - AC5: the fixtures are `cose/chain-empty.png`, `cose/leaf-der-broken.png` and `cose/protected-not-map.png`; there is no `x5chain/` directory.

2. **2026-10-23, step 400, found by the review of 2026-10-23 (the low items, "doe 1 nog", Maurice van Loon).** `timeStampInfo` showed the header's token whenever it could be read, also where the verdict took its time from a `c2pa.time-stamp` assertion's token (SPEC-064) because the header's TSA was not trusted; a reader could not tell which time counted. Where the manifest's own results hold `timeStamp.trusted` at an assertion's url, that assertion's token is now shown; otherwise as before (AC4).
   - **AC7 — timeStampInfo shows the token the verdict used**: `timestamp/assertion/header-wins.png`, judged at 2026-10-23T12:00:00Z: under its own settings the parent's `timeStamp.trusted` is the header's and `timeStampInfo` names the TSA Matrix TSA; with that TSA left out of the settings, `timeStamp.trusted` is the assertion's and `timeStampInfo` names the throw-away TSA.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC1: the algorithm by its C2PA name, equal to c2patool's once upper-cased / SPEC-076 | src/Verifier/CrJsonDocument.php :: signature(), ALGORITHMS |
| AC2 | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC2: certificateInfo of the leaf, equal to c2patool's with times as instants / SPEC-076 | src/Verifier/CrJsonDocument.php :: certificateInfo(); src/Trust/Certificate.php :: $serialHex |
| AC3 | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC3: a DN holds the schema's eight keys only, in the certificate's order, a repeat's last value / SPEC-076 | src/Verifier/CrJsonDocument.php :: distinguishedName(), DN_KEYS |
| AC4 | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC4: timeStampInfo wherever the token can be read, its time whether or not it validated; AC4: a validated, an untrusted and a mismatched token each give their time and their TSA (3 datasets); AC4: no token, no timeStampInfo / SPEC-076 | src/Timestamp/TimestampCheck.php :: readToken(); src/Verifier/CrJsonDocument.php :: signature() |
| AC5 | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC5: a signature that cannot be read is {} (3 datasets) / SPEC-076 | src/Verifier/CrJsonDocument.php :: signature() |
| AC6 | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC6: every document passes the whole schema but for the named exceptions; tests/Unit/Verifier/CrJsonDocumentTest.php :: AC7: every crJSON written is schema-valid but for the accepted v1 claims / SPEC-076, SPEC-075 | src/Verifier/CrJsonDocument.php |
| AC7 (amendment 1) | tests/Unit/Verifier/CrJsonSignatureTest.php :: AC7: timeStampInfo shows the token whose time the verdict used / SPEC-076 | src/Timestamp/TimestampCheck.php :: readToken(); src/Verifier/CrJsonDocument.php :: signature() |

The tests were run red first: 8 of 12 failed. AC5 and AC6 passed
before the build, since every signature was then `{}`; AC6 was tightened
to name the three unreadable fixtures rather than infer them from the
output, and both were then watched failing under the mutation "every
signature `{}`". Five mutations in all, each failing: lower-case names
(AC1); a repeat's first value (AC2, AC3: one corpus certificate repeats
an attribute); no token read (AC4); every signature `{}` (AC1, AC2, AC4,
AC6); assertion tokens ignored (AC4). The ordinary report over the
corpus before and after: 0 of 1,842 runs moved. `composer check`: 1,194
passed, 1 skipped.

Amendment 1 (step 400): the test red on the first build at the right
assertion (the verdict's url was the assertion's, the issuer the
header's TSA), green after. Mutations, each failing: the judged token
not put first, the results not passed to `signature()`. Without trust
settings no token is trusted, so no crJSON document of the fixtures can
move.
