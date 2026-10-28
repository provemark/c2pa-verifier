# SPEC-089: The evidence as a document to keep

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-28                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-081 to SPEC-085 made a report show what its verdict rests on:
`VerificationReport::trustLists()` and `evidence()`, one
`ManifestEvidence` per judged manifest with its time, its anchors, its
`x5chain`, the time-stamp tokens and the OCSP responses offered. They are
PHP objects only. `toJson()` and `toCrJson()` leave them out on purpose
(SPEC-082), and nothing turns them into something that can be stored.

A caller that keeps a record of a verdict — an archive kept next to an
uploaded file, to show later what a `Trusted` rested on on the day it was
given — has to write its own serialisation. Each caller then invents a
format, chooses its own encoding for the DER bytes and its own names, and
two archives of the same verdict cannot be compared. That is a second truth
about what the verifier found, which this project does not allow
(the brief's rule on one vocabulary; ADR-0005).

What makes an archive worth keeping is that it can be checked without this
verifier: the certificates, the time-stamp tokens and the OCSP responses as
the file carried them (or the caller's source returned them), byte for
byte, with the anchor's SHA-256 and the list it came from. C2PA 2.4 §15.8
(the time a signer is judged at) and §15.9 (revocation) say what those
pieces mean; this spec only writes them down.

The first consumer is planned, not built: the Drupal module, by way of the
library, storing the evidence of a verdict in an archive. Nobody has asked
for it in public.

## Scope

**In scope**

- `VerificationReport::evidenceToArray(): array` and `evidenceToJson():
  string`: one document holding the verdict's identity, the trust lists, and
  every `ManifestEvidence` with what it holds.
- A documented, versioned format (`docs/evidence-format.md`): every key,
  its type, when it is null, and the rule for changing it.
- DER bytes as standard base64 (RFC 4648 §4); times as integers, seconds
  since the epoch, as the objects hold them.
- Deterministic output: the same file, settings and validation time give
  byte-identical JSON.

**Out of scope** (each needs its own spec before it may be built)

- Reading a document back into objects (`fromArray()`), or re-verifying from
  it. An auditor re-checks with the bytes and any tool; this verifier keeps
  verifying files, not archives.
- Signing, timestamping or otherwise sealing the document. Keeping it
  unaltered is the archive's task.
- A hash of the asset. The verifier does not always read the whole asset,
  and the caller has the file; the caller adds the hash.
- The evidence in `toJson()` or `toCrJson()`: both stay as they are.
- Exposing the document through the library or the Drupal module (their own
  specs, after this one).

## Behavior

- **AC1 — the document for a trusted, time-stamped signer**
  - Given a fixture signed by the service (time-stamped, `x5chain` of two
    certificates) verified under `trust/full.settings.json` at a fixed
    validation time
  - When `evidenceToArray()` is called
  - Then it holds `format` `"c2pa-verifier/evidence"`, `version` `1`,
    `verifier` (`Verifier::VERSION`), `validation_state` (as `toArray()`
    has it), `active_manifest` (the label), `trust_lists` (one entry per
    `trustLists()` identity, in order), and `manifests` keyed by label in
    the order `evidence()` gives; the active manifest's entry has
    `judged_at`, `time_source` `"timestamp"`, `signer` and `tsa` with
    `route`, `sha256`, `depth`, `list`, `trusted_from`, `trusted_until`,
    and `certificates`, `timestamps` and `ocsp` as lists.

- **AC2 — the bytes are the bytes**
  - Given the report of AC1, and one whose signer stapled OCSP responses
  - When each `certificates` entry, each token's `token` and
    `certificates`, and each response's `response` is base64-decoded
  - Then each equals, byte for byte, the DER in the matching
    `ManifestEvidence` field, in the same order.

- **AC3 — what was not trusted is null, not absent**
  - Given a fixture verified without settings, and one with no manifest
  - When `evidenceToArray()` is called
  - Then the first has `trust_lists` `[]` and `signer` and `tsa` `null`,
    every key of AC1 present; the second has `active_manifest` `null` and
    `manifests` `{}` (an empty object in JSON, not `[]`).

- **AC4 — codes by name**
  - Given a report whose time-stamps and chosen OCSP response carry status
    codes
  - When the document is written
  - Then each code is its C2PA string (`timeStamp.trusted`,
    `signingCredential.ocsp.notRevoked`, …), never an enum name or number,
    and a code the objects hold as null is `null`.

- **AC5 — deterministic**
  - Given one fixture, one settings file and one validation time
  - When it is verified twice and `evidenceToJson()` is called on each
  - Then the two strings are identical.

- **AC6 — the file's own text cannot break the document** *(required:
  malformed input)*
  - Given variants whose manifest labels and assertion urls hold control
    characters, quotes, a backslash and bytes that are not UTF-8
  - When `evidenceToJson()` is called
  - Then it returns valid JSON (no exception), the hostile characters are
    escaped, and text that is not UTF-8 — if any reaches the evidence; the
    first test establishes whether the JUMBF reader already refuses such a
    label — is written with U+FFFD, as `ValidationStatus` does for an
    explanation (`mb_scrub`); `json_decode` of it equals
    `evidenceToArray()`.

- **AC7 — every report the verifier gives can be written** *(required:
  malformed input)*
  - Given every file of the corpus under no settings,
    `trust/full.settings.json` and the programme's lists
  - When `evidenceToJson()` is called
  - Then no call throws, each result decodes, and each matches the shape of
    `docs/evidence-format.md` (checked by the test, key by key and type by
    type).

- **AC8 — the other outputs do not move**
  - Given the corpus at the commit before this spec and after it
  - When `toJson()` and `toCrJson()` are compared
  - Then they are identical.

## References

- Specification: C2PA 2.4 §14.5 (`x5chain`), §15.8 (the time a signer is
  judged at), §15.9 (revocation), §10.3.2.5 and §18.18 (time-stamps),
  §18.19 (certificate status); RFC 4648 §4 (base64); RFC 8259 (JSON).
- Oracle: none outside this verifier — the document writes down this
  verifier's evidence. AC2 is measured against `evidence()` itself, and the
  bytes can be checked with `openssl x509`, `openssl ts -reply` and
  `openssl ocsp -respin`.
- Reasoned: that an archive needs the bytes rather than a summary of them;
  that integers keep the times without a second format.

## API sketch

```php
namespace Provemark\C2paVerifier\Verifier;

final readonly class VerificationReport
{
    /** @return array<string, mixed>  the evidence document, format version 1 (SPEC-089) */
    public function evidenceToArray(): array;

    /** evidenceToArray() as JSON: pretty-printed, unescaped slashes, deterministic */
    public function evidenceToJson(): string;
}
```

```json
{
  "format": "c2pa-verifier/evidence",
  "version": 1,
  "verifier": "0.10.0",
  "validation_state": "Trusted",
  "active_manifest": "urn:c2pa:…",
  "trust_lists": [
    {"kind": "manifest", "form": "pem", "sha256": "…", "sequence_number": null, "issue_date_time": null}
  ],
  "manifests": {
    "urn:c2pa:…": {
      "judged_at": 1793001234,
      "time_source": "timestamp",
      "signer": {"route": "anchor", "sha256": "…", "depth": 1, "list": null, "trusted_from": null, "trusted_until": null},
      "tsa": {"route": "anchor", "sha256": "…", "depth": 1, "list": null, "trusted_from": null, "trusted_until": null},
      "certificates": ["MIIC…", "MIIB…"],
      "timestamps": [
        {"source": "sigTst2", "url": "self#jumbf=…", "token": "MIIG…", "gen_time": 1793001234, "certificates": ["MIIC…"], "outcome": "used", "codes": ["timeStamp.validated", "timeStamp.trusted"]}
      ],
      "ocsp": [
        {"source": "rVals", "url": "self#jumbf=…", "response": "MIIB…", "outcome": "chosen", "code": "signingCredential.ocsp.notRevoked", "cert_status": "good", "produced_at": 1793000000, "this_update": 1793000000, "next_update": 1793604800}
      ]
    }
  }
}
```

## Open questions

1. **Times as integers or as RFC 3339 text?** Integers are what the objects
   hold and what a program compares; text is what a person reading an
   archive sees. Proposal: integers only (one form, no rounding question);
   `docs/evidence-format.md` says they are UTC seconds. *Non-blocker.*
   *Status 2026-10-28: decided by Maurice van Loon, as proposed.*
2. **How the format may change.** Proposal: within `version` 1 only keys may
   be added, and a reader must ignore keys it does not know; removing,
   renaming or changing the meaning of a key raises `version`. *Blocker:
   it is the promise the archive leans on.*
   *Status 2026-10-28: decided by Maurice van Loon, as proposed.*
3. **The release.** Proposal: the minor that also carries group F and the
   C2PA 2.5 additions (0.10), so that the library and the Drupal module
   follow once. *Non-blocker.*
   *Status 2026-10-28: decided by Maurice van Loon, as proposed.*
4. **Whether `validation_state` belongs in it.** Proposal: yes, with the
   active manifest's label, so that a document says which verdict it is the
   evidence of; the statuses themselves stay in `toJson()`, which a caller
   keeps next to it. *Non-blocker.*
   *Status 2026-10-28: decided by Maurice van Loon, as proposed.*

## Amendments

1. **2026-10-28, while writing the tests; measured** *(confirmed by Maurice van Loon, 2026-10-28)* —
   AC1 names "a fixture signed by the service (time-stamped, `x5chain` of
   two certificates) verified under `trust/full.settings.json`". No such
   fixture exists: `fixture-signed.png` and `.jpg` are `Trusted` under that
   file but carry no time-stamp (`time_source` `given`, no `tsa`). AC1 is
   held by `timestamp/tsa-matrix/control.png` under its own
   `control.settings.json` instead — `Trusted`, time-stamped, two
   certificates, the fixture SPEC-082 AC1 uses — and its `trust_lists`
   entries by the same file under `TrustSettings::fromTrustLists()` with
   the probe's two roots. Nothing else in AC1 changes.

   *Also measured while writing AC6:* a label reaches the evidence only as
   a C2PA URN or the deprecated `urn:uuid` form (`Manifest::C2PA_URN`,
   `UUID_URN`; C2PA 2.4 §8.1), whose free part is printable ASCII without a
   colon; control characters and text that is not UTF-8 are refused by the
   JUMBF reader before that. Of AC6's characters only the quote and the
   backslash can reach the document from a file, and only in a label longer
   than a same-length variant can carry. AC6 is therefore held by two
   variants that are refused before any evidence (`jumbf/label-control.png`,
   and `fixture-signed.png` with a quote, a backslash and U+202E in its
   label: `claim.malformed`), and by a report built directly with every
   hostile character, which the writer escapes.

2. **2026-10-28, while writing the tests; measured** *(confirmed by Maurice van Loon, 2026-10-28)* —
   AC6 says text that is not UTF-8 is written with U+FFFD "as
   `ValidationStatus` does for an explanation (`mb_scrub`)". `mb_scrub()`
   writes `mb_substitute_character()`, which is `?` (0x3F) by default, not
   U+FFFD (`php -r 'echo bin2hex(mb_scrub("a\xffb","UTF-8"));'` prints
   `613f62`). The document keeps the promise of U+FFFD — written through
   `JSON_INVALID_UTF8_SUBSTITUTE` — and drops the comparison with
   `ValidationStatus`, which writes `?` as SPEC-043 AC13 asks. The command
   line, which SPEC-019 amendment 4 had promised U+FFFD and which wrote `?`,
   was corrected in step 496.

## Traceability

All in `tests/Unit/Verifier/EvidenceDocumentTest.php`, group `SPEC-089`. Before the implementation 16 of 17
tests failed (`evidenceToArray()` undefined); the guard on the evidence classes' constants passed. The test
for the order of two manifests was added when mutation M9 survived. Thirteen further mutations of
`EvidenceDocument` were caught. 3,132 corpus runs before (`0581010`) and after: `toJson()`, `toCrJson()` and
the evidence outcomes identical.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | "AC1: the document for a trusted, time-stamped signer"; "AC1 (amendment 1): the trust lists, each with its identity, and the anchor's list"; "AC1: the manifests in the order evidence() gives, the active one first" | `src/Verifier/EvidenceDocument.php` :: `of()`, `manifest()`, `anchor()`, `identity()`; `src/Verifier/VerificationReport.php` :: `evidenceToArray()` |
| AC2 | "AC2: the bytes are the bytes" (certificates and a token; a stapled response) | `EvidenceDocument` :: `manifest()`, `timestamp()`, `ocsp()` |
| AC3 | "AC3: what was not trusted is null, not absent" | `EvidenceDocument` :: `anchor()`, `json()` |
| AC4 | "AC4: codes by name" | `EvidenceDocument` :: `timestamp()`, `ocsp()` |
| AC5 | "AC5: deterministic" (three cases) | `EvidenceDocument` :: `json()`; `VerificationReport` :: `evidenceToJson()` |
| AC6 | "AC6: a label the JUMBF reader refuses never reaches the document"; "AC6 (amendment 1): a label with a bidi override, a quote and a backslash is refused before any evidence"; "AC6: text the objects hold that is not UTF-8 or carries controls is written safely" (amendment 2) | `EvidenceDocument` :: `text()`, `json()` |
| AC7 | "AC7: every report the verifier gives can be written" (none, full, lists) | `EvidenceDocument`; `docs/evidence-format.md` |
| AC8 | "AC8: evidenceToArray and evidenceToJson are in the contract, and the other outputs carry no document"; the corpus comparison above | `tests/Fixtures/api/public-surface.txt` |
| guard | "the anchor and evidence classes keep the names the document reads" | `AnchorEvidence`, `ManifestEvidence`, `TimestampEvidence`, `OcspEvidence` constants |
