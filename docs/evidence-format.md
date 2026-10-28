# The evidence document, format version 1

`VerificationReport::evidenceToArray()` and `evidenceToJson()` write down
what a verdict rested on, as a document to keep next to the file it is
about (SPEC-089). It holds the verdict it is the evidence of, the trust
lists the file was judged against, and for each judged manifest the time
it was judged at, the anchors its signer and its time-stamp authority
reached, and the certificates, time-stamp tokens and OCSP responses
offered for it — the bytes as the file carried them, or as the caller's
revocation source returned them.

The bytes are what make it worth keeping: anyone can check them later
without this verifier, with `openssl x509 -inform DER`, `openssl ts -reply
-in … -token_in` and `openssl ocsp -respin`.

The document is not signed and holds no hash of the file; keeping it
unaltered, and saying which file it belongs to, is the archive's task.
`toJson()` and `toCrJson()` do not contain it.

## Conventions

- **Bytes** (`certificates`, `token`, `response`) are DER in standard
  base64 (RFC 4648 §4, with `+`, `/` and padding).
- **Times** (`judged_at`, `gen_time`, `trusted_from`, `trusted_until`,
  `produced_at`, `this_update`, `next_update`) are integers, seconds since
  the epoch, UTC.
- **Status codes** are their C2PA strings (C2PA 2.4 §15), such as
  `timeStamp.trusted` or `signingCredential.ocsp.notRevoked`.
- **Null, not absent.** Every key is always present; one that does not
  apply is `null`, an empty list `[]`, and an empty `manifests` `{}`.
- **Text** comes from the file (a manifest label, a url built from it) or
  from a trust list. A sequence that is not UTF-8 is written as U+FFFD;
  `evidenceToJson()` escapes control characters and writes every
  character outside ASCII as `\uXXXX`.
- **Deterministic.** The same file, settings and validation time give the
  same text.

## How the format changes

Within `version` 1 keys may only be added; a reader must ignore keys it
does not know. Removing a key, renaming it, or changing what it means
raises `version`. `verifier` names the release that wrote the document.

## The document

| key | type | meaning |
|---|---|---|
| `format` | string | always `"c2pa-verifier/evidence"` |
| `version` | integer | `1` |
| `verifier` | string | the verifier's version, `Verifier::VERSION` |
| `validation_state` | string or null | the verdict, as `toArray()` gives it: `Invalid`, `Valid` or `Trusted` |
| `active_manifest` | string or null | the active manifest's label; null when the file has no store that could be read |
| `trust_lists` | list of *list* | each list given to `verify()` through `TrustSettings::fromTrustLists()`, in order; `[]` for a settings document or none |
| `manifests` | object, label → *manifest* | each manifest whose signature was judged, the active one first, then each ingredient manifest in the order checked |

### *list*

| key | type | meaning |
|---|---|---|
| `kind` | string | `"manifest"` (signers) or `"tsa"` (time-stamp authorities) |
| `form` | string | `"pem"` or `"lote"` (the programme's JSON) |
| `sha256` | string | lowercase hex, of the list as given |
| `sequence_number` | integer or null | a JSON list's, when it is an integer of at least 1 |
| `issue_date_time` | string or null | a JSON list's, verbatim, when it is an RFC 3339 date-time |

### *manifest*

| key | type | meaning |
|---|---|---|
| `judged_at` | integer | the time the signer was judged at (C2PA 2.4 §15.8.2) |
| `time_source` | string | `"timestamp"` (a trusted time-stamp's genTime), `"given"` (the caller's validation time) or `"clock"` |
| `signer` | *anchor* or null | what made the signer trusted; null unless the signature has `signingCredential.trusted` |
| `tsa` | *anchor* or null | what made the time-stamp authority trusted; null unless the time-stamp was trusted and gave the time |
| `certificates` | list of bytes | the signer's `x5chain` as the file carries it, the leaf first; `[]` when the signature could not be read |
| `timestamps` | list of *timestamp* | every time-stamp token offered, the header's then the assertions', in the order tried |
| `ocsp` | list of *response* | every OCSP response the revocation check walked: stapled, then from certificate-status assertions, then the caller's source |

### *anchor*

| key | type | meaning |
|---|---|---|
| `route` | string | `"anchor"` (the chain reached a trust anchor) or `"allowedList"` (the leaf is on the allowed list) |
| `sha256` | string | lowercase hex, of the anchor's DER, or of the listed leaf's |
| `depth` | integer | links from the leaf to the anchor: 0 when the leaf is itself an anchor or on the allowed list |
| `list` | *list* or null | the list that made the anchor count; null for the legacy `trust_anchors` and for a settings document |
| `trusted_from` | integer or null | the start of the stretch of the list's dates that held the time judged at; null for an open start or a list without dates |
| `trusted_until` | integer or null | its end; null for an open end or a list without dates |

### *timestamp*

| key | type | meaning |
|---|---|---|
| `source` | string | `"sigTst"`, `"sigTst2"` (the claim signature's header) or `"assertion"` (a `c2pa.time-stamp` assertion) |
| `url` | string | the claim signature's url for a header token, the assertion's for an assertion token |
| `token` | bytes | the token as the file carries it |
| `gen_time` | integer or null | its time; null when it cannot be read |
| `certificates` | list of bytes | the certificates inside it, in its order; `[]` when it cannot be read |
| `outcome` | string | `"used"` (it gave the time), `"rejected"` (judged, and did not) or `"notTried"` |
| `codes` | list of strings | the `timeStamp.*` codes the check gave it, in order; `[]` when not tried |

### *response*

| key | type | meaning |
|---|---|---|
| `source` | string | `"rVals"` (stapled), `"assertion"` (a certificate-status assertion) or `"fetched"` (the caller's revocation source) |
| `url` | string | the claim signature's url for a stapled or fetched response, the assertion's for an assertion's |
| `response` | bytes | the response as the file carries it or the source returned it |
| `outcome` | string | `"chosen"`, `"notChosen"`, `"unusable"` or `"notTried"` |
| `code` | string or null | the `signingCredential.ocsp.*` code the chosen response gave; null for every other |
| `cert_status` | string or null | `"good"`, `"revoked"` or `"unknown"`, for a chosen or not-chosen response |
| `produced_at` | integer or null | for a chosen or not-chosen response |
| `this_update` | integer or null | for a chosen or not-chosen response |
| `next_update` | integer or null | null also when the response has none |
