# Conformance: 111 named obligations, one by one

*Measured 2026-09-22 (step 90); updated the same day after SPEC-030 closed
five of its gaps (step 92).* This table puts every predicate of
[`encypherai/c2pa-conformance-suite`](https://github.com/encypherai/c2pa-conformance-suite)
that applies to the containers this verifier reads next to what this
verifier actually does. The suite's catalogue formalises **237 normative
rules of C2PA 2.4 as 150 predicates**; 111 of them apply here — 97
cross-cutting, 4 image-specific, 8 ISOBMFF and 2 streaming. The other 39
belong to containers this verifier does not read (PDF, WAV, fonts, JPEG XL,
ZIP collections, multi-asset, plain text, SVG).

Step 71 put the applicable count at 101. That was before M8: closing it
added the ISOBMFF and streaming families, so the number is 111 now.

The catalogue is used as a **checklist of named obligations**, not as an
oracle. Step 71 measured that the suite's own JPEG path disagrees with
c2patool, this verifier and the Go implementation on files all three
accept; its predicate list does not depend on its cryptography being right.

## What the verdicts mean

| verdict | meaning |
|---|---|
| **yes** | the rule is enforced, or satisfied by construction (e.g. a field the rule says to ignore is never read) |
| partial | part of the rule is enforced; the entry says which part is not |
| closed | the feature is refused wholesale, so the rule cannot be broken silently — such a file fails rather than passing |
| by design | deliberately not done and recorded in a spec: the network, remote manifests, revocation, containers not read, obligations on a player or a claim generator |
| **gap** | it applies, it is not done, and until this table it was not written down |

## The count

| verdict | predicates |
|---|---|
| **yes** | 54 |
| partial | 12 |
| closed | 7 |
| by design | 21 |
| **gap** | 17 |
| **total** | 111 |

## Cross-format (6)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-CROSS-001` | shall | Hash algorithm in allowed list | **yes** — `DataHashCheck`/`BmffHashCheck`/`HashedUriCheck` accept sha256/384/512 only; anything else is `algorithm.unsupported` (SPEC-012, SPEC-027) |
| `PRED-CROSS-002` | shall | Hard binding assertion presence | **yes** — `Verifier::verify()` requires a hard binding; absent is `claim.hardBindings.missing` (we name it differently from their `assertion.dataHash.mismatch`) |
| `PRED-CROSS-003` | shall | Embedded manifest store takes priority over remote | **yes** — only embedded stores are read, so a remote reference can never outrank one |
| `PRED-CROSS-004` | should | Remote manifest store discovery order | by design — no network in the verification path (SPEC-013, SPEC-014). A `should`, declined on purpose |
| `PRED-CROSS-005` | shall | Compressed manifest decompression | closed — `JumbfParser` refuses a `brob` box by name rather than skipping it |
| `PRED-CROSS-006` | shall | Ignore pad and pad2 fields | **yes** — `pad`/`pad2` are never read, which is what the rule asks |

## Trust (1)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-TRUS-001` | shall | Claim signature within TST validity window | **yes** — `TimestampCheck` judges the TSA certificate at `genTime`; outside it is `timeStamp.outsideValidity` (SPEC-017) |

## Timestamps (assertion-based) (4)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-TIME-001` | shall | Skip or follow timestamp header lookup | partial — the `sigTst`/`sigTst2` header path is implemented; the assertion-based fallback is not — see TIME-003 |
| `PRED-TIME-002` | shall | Manifest identifier lookup in timestamp mapping | **gap** — the manifest-identifier-to-token mapping of a `c2pa.time-stamp` assertion is not read |
| `PRED-TIME-003` | shall | c2pa.time-stamp assertion structural well-formedness | **gap** — the `c2pa.time-stamp` assertion (C2PA 2.4's assertion-based timestamps) is not read at all; no `assertion.timestamp.malformed` code exists here |
| `PRED-TIME-004` | shall | Ignore time-stamp manifests inside ingredients | **yes** — each manifest's timestamp comes from its own COSE header; an ingredient's can never reach the active manifest |

## Ingredients (8)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-INGR-001` | shall | Display attribution warning for invalid manifest data | **yes** — `VerificationReport` carries every failure and `Cli\Command` prints them; nothing is attributed to a signer whose manifest failed |
| `PRED-INGR-002` | shall | Gather and validate redacted assertions for each ingredient manifest | closed — `HashedUriCheck` refuses any claim that declares `redacted_assertions`; a redaction is never passed on trust |
| `PRED-INGR-003` | shall | Validate hashed_uri and hashed_ext_uri references in standard assertions | **yes** — `HashedUriCheck` resolves and hashes every hashed URI of the claim; external retrieval is optional in the rule and declined here |
| `PRED-INGR-004` | may | Ingredient nesting with associated manifests | **yes** — `ManifestGraph` walks nested ingredients and their manifests (SPEC-020) |
| `PRED-INGR-005` | shall | Execute recursive ingredient validation algorithm | **yes** — `IngredientManifestCheck` runs the recursive algorithm over the graph (SPEC-021) |
| `PRED-INGR-006` | should | Ignore unrecognised manifests not in ingredient list | **yes** — `ManifestGraph::$unreferenced` records manifests the walk never reaches and leaves them out (§15.11.3.3) |
| `PRED-INGR-007` | shall | Locate hard binding for asset content validation | **yes** — `UpdateManifestCheck::bindingManifest()` follows the `parentOf` chain; nothing found is `claim.hardBindings.missing` (SPEC-022) |
| `PRED-INGR-008` | shall | Validate BMFF hash excluding update manifest ContentProvenanceBox | closed — a C2PA box whose `purpose` this verifier does not read is an error naming it (SPEC-026 AC5), so an `update` box cannot silently shift a hash |

## Structure, claim fields, revocation (19)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-STRU-001` | may | Validation outcome classification (well-formed / valid / trusted) | **yes** — `ValidationState` is Valid / Invalid / Trusted; this verifier has no separate Well-Formed tier and says so in the README |
| `PRED-STRU-002` | shall | Custom status code namespace syntax conformance | **gap** — a custom status code read from an ingredient's recorded `validationResults` is not checked against the reverse-DNS syntax |
| `PRED-STRU-003` | shall | PDF update section: single manifest store required | by design — PDF is not a container this verifier reads |
| `PRED-STRU-004` | should | Stop manifest search after first located store | **yes** — one store per file; a second C2PA box is an error naming both offsets (SPEC-026 AC4), never a second candidate |
| `PRED-STRU-005` | shall | Remote manifest inaccessibility reporting | by design — remote manifests are not fetched (no network); `manifest.inaccessible` is a code this verifier cannot reach |
| `PRED-STRU-006` | should | HTTP Link header manifest discovery and childlabel exclusion | by design — HTTP Link header discovery needs the network |
| `PRED-STRU-007` | may | Optional manifest-asset association verification | by design — optional manifest-asset association check; not done |
| `PRED-STRU-008` | shall | Claim required fields and claim_generator_info name presence | **yes** — `Claim::fromMap()` requires every required field and a `name` in `claim_generator_info`, both `claim.malformed` (SPEC-007) |
| `PRED-STRU-009` | shall | Generator-info icon field structural validation | **gap** — an `icon` inside `claim_generator_info` is not validated |
| `PRED-STRU-010` | should | CA certificate revocation via AIA OCSP | by design — revocation via AIA OCSP needs the network (SPEC-014 puts revocation out of scope) |
| `PRED-STRU-011` | shall | Signer certificate revocation validation process | **yes** — `Trust\OcspCheck` reads `rVals.ocspVals` and applies `certStatus` (SPEC-030); a certificate with no revocation information is treated as not revoked, by saying `skipped` |
| `PRED-STRU-012` | shall | Multiple OCSP responses: try each until one passes | **yes** — `Trust\OcspCheck::check()` tries each stapled response and stops at the first that proves something, a `revoked` winning over a `good` |
| `PRED-STRU-013` | should | Online OCSP fallback when no revocation info in manifest | by design — online OCSP fallback needs the network |
| `PRED-STRU-014` | shall | OCSP revoked certStatus disambiguation and rejection | **yes** — `Trust\OcspCheck::statusOf()` separates `removeFromCRL` from a real revocation (RFC 6960 §4.2.1) and rejects only the latter |
| `PRED-STRU-015` | shall | OCSP skipped and OCSP inaccessible informational codes | **yes** — every file now carries one `signingCredential.ocsp.*` line, and a skipped check says so — SPEC-030 AC5 |
| `PRED-STRU-016` | shall | Online OCSP certStatus unknown/revoked outcome handling | by design — online OCSP outcomes need the network |
| `PRED-STRU-017` | should | Forward-compatible c2pa.metadata assertion field tolerance | **yes** — `c2pa.metadata` is not validated at all, so unknown fields cannot be rejected |
| `PRED-STRU-018` | shall | Hashed URI field presence and destination reachability | partial — an absent or unresolvable hashed URI is caught, and reported as `assertion.missing`: this verifier has no `hashedURI.missing` code |
| `PRED-STRU-019` | shall | JPEG APP11 exclusion range total length matches manifest store length | partial — the store is extracted from the APP11 segments themselves, so a tampered segment length changes it and the hash fails; the declared exclusion length is checked to *cover* the store (SPEC-012 amendment 5), not to equal the summed segment length |

## Signatures, certificates, algorithms (25)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-CRYP-001` | may | Optional validation result metadata fields | by design — optional `specVersion`/`trustListURI` fields are not emitted |
| `PRED-CRYP-002` | shall | Claim signature URI resolution | **yes** — `Manifest::signatureBytes()` resolves the claim's signature URI within the manifest; absent is `claimSignature.missing` |
| `PRED-CRYP-003` | shall | Signing credential validation | **yes** — `CertificateProfileCheck` (SPEC-015), every fault `signingCredential.invalid` |
| `PRED-CRYP-004` | shall | Signature algorithm allowed list check | **yes** — `SignatureVerifier` allows ES256/384/512, PS256/384/512, EdDSA; anything else is `algorithm.unsupported` |
| `PRED-CRYP-005` | shall | Timestamp presence check before trust chain verification | **yes** — the timestamp check runs before the chain and hands it the attested time (SPEC-013 amendment 8) |
| `PRED-CRYP-006` | shall | Trust anchor temporal validity gating | **gap** — a trust anchor with `notBefore`/`notAfter` gating is not supported: `TrustSettings` has no such field |
| `PRED-CRYP-007` | shall | Certificate chain of trust to trust anchor | **yes** — `ChainCheck` builds to an anchor; `signingCredential.trusted` / `.untrusted` (SPEC-014) |
| `PRED-CRYP-008` | shall | COSE claim signature cryptographic verification | **yes** — `CoseSign1` + `SignatureVerifier` over the Sig_structure; `claimSignature.validated` / `.mismatch` (SPEC-009) |
| `PRED-CRYP-009` | may | COSE header bucket placement permissiveness | **yes** — `CoseSign1::findChain()` reads both buckets, integer label 33 winning within one |
| `PRED-CRYP-010` | shall | Multiple tstToken entries rejected as malformed | partial — the header is a CBOR map, so a second `sigTst` key cannot survive decoding; a `tstToken` list inside one header is not counted |
| `PRED-CRYP-011` | shall | sigTst time-stamp response PKI status validation | **yes** — `TimeStampToken` requires PKIStatusInfo 0 or 1 (SPEC-016) |
| `PRED-CRYP-012` | shall | sigTst2 TimeStampToken retrieval | **yes** — `sigTst2` is read as a bare TimeStampToken (SPEC-016, SPEC-017) |
| `PRED-CRYP-013` | shall | TimeStampToken signature and messageImprint validation | **yes** — signature, messageImprint and its algorithm are all checked: `timeStamp.malformed` / `.mismatch` (SPEC-017) |
| `PRED-CRYP-014` | shall | TimeStampToken TSA certificate chain validation | **yes** — the TSA chain is built to a configured anchor; `timeStamp.untrusted` otherwise — the one divergence from c2patool by design (ADR-0004) |
| `PRED-CRYP-015` | may | TSA certificate temporal validity and credentialInvalid informational code | partial — `timeStamp.outsideValidity` is implemented; the optional `timeStamp.credentialInvalid` informational code is not |
| `PRED-CRYP-016` | shall | Time-stamp and signing certificate validity window enforcement | partial — the rule is enforced — the signer is judged at the attested time — but reported as `signingCredential.expired`, not `claimSignature.outsideValidity` |
| `PRED-CRYP-017` | shall | Use attested time for certificate validity when trusted timestamp present | **yes** — `CertificateProfileCheck::check($at)` takes the trusted timestamp's time; `now` only without one, and the explanation says which (SPEC-017) |
| `PRED-CRYP-018` | may | Claimed time of signing via iat header validation | by design — the `iat` claimed time of signing is not read; a `may` |
| `PRED-CRYP-019` | shall | Claimed time of signing inside/outside validity informational code | **gap** — follows from CRYP-018: no `timeOfSigning.*` codes exist here |
| `PRED-CRYP-020` | shall | CA certificate revocation check at signing time | by design — CA revocation at signing time needs revocation data (SPEC-014) |
| `PRED-CRYP-021` | shall | OCSP response from manifest store revocation check | **yes** — the same stapled-OCSP path; `notRevoked` is recorded with the caveat that the header carrying it is unsigned |
| `PRED-CRYP-022` | may | Online OCSP query fallback | by design — online OCSP query; no network |
| `PRED-CRYP-023` | shall | Online OCSP response acceptance and not-revoked determination | by design — online OCSP acceptance; no network |
| `PRED-CRYP-024` | shall | c2pa.session-keys signerBinding signature verification | **gap** — `c2pa.session-keys` is not recognised, so its `signerBinding` signature is not verified |
| `PRED-CRYP-025` | shall | No hash mismatch codes for activeManifest field in claim signature hash validation | **yes** — the claim-signature hash method and `c2pa.ingredient.v3`'s `activeManifest` field are not implemented, so no mismatch can be recorded for it |

## Assertions (27)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-ASSE-001` | shall | Ingredient validation and results recording | by design — an obligation on the claim *generator*, not on a verifier |
| `PRED-ASSE-002` | shall | Multiple embedded manifest stores invalidation | **yes** — a second manifest store in one asset is `claim.multiple` (SPEC-005/013); in ISOBMFF a second C2PA box is a container error |
| `PRED-ASSE-003` | shall | Redacted assertion label check | closed — redactions are refused wholesale, so a redacted actions assertion cannot be accepted |
| `PRED-ASSE-004` | shall | Assertion URI must reference same manifest | partial — URIs are resolved inside the manifest only; one pointing elsewhere fails as `assertion.missing`, not `assertion.outsideManifest` |
| `PRED-ASSE-005` | shall | Assertion URI must be resolvable | **yes** — `assertion.missing` when a claim's hashed URI names no assertion (SPEC-011) |
| `PRED-ASSE-006` | shall | Assertion hashed-URI integrity check | **yes** — `HashedUriCheck`: `assertion.hashedURI.match` / `.mismatch` over every entry |
| `PRED-ASSE-007` | shall | Standard assertion encoding validity | partial — JSON gets `assertion.json.invalid`; malformed **CBOR** is caught but reported as `general.error` — this verifier has no `assertion.cbor.invalid` code |
| `PRED-ASSE-008` | shall | Undeclared assertion detection | **yes** — `assertion.undeclared` for a box in the store that no claim list references (SPEC-011) |
| `PRED-ASSE-009` | shall | Self-redaction prohibition | closed — self-redaction cannot arise: any claim declaring redactions is refused |
| `PRED-ASSE-010` | shall | Assertion metadata field not validated | **yes** — assertion `metadata` fields are never validated |
| `PRED-ASSE-011` | shall | Specific assertion type dispatch | **yes** — dispatch by label: actions, ingredient, the hard bindings (`Verifier`) |
| `PRED-ASSE-012` | shall | Hashed-URI and hashed-ext-URI field validation in assertions | partial — hashed URIs inside ingredient assertions are validated (SPEC-020/021); hashed URIs inside other assertion types are not walked |
| `PRED-ASSE-013` | shall | Alternative-content-representation structural validity | **gap** — `c2pa.alternative-content-representation` is not implemented |
| `PRED-ASSE-014` | shall | Alternative-content-representation embedded preservation image hash | **gap** — the embedded preservation image hash is not checked |
| `PRED-ASSE-015` | shall | Hashed-URI reference integrity | **yes** — same mechanism as ASSE-006 |
| `PRED-ASSE-016` | shall | External reference validation procedure | by design — external data is never retrieved (no network) |
| `PRED-ASSE-017` | shall | Ingredient assertion relationship field validity | **yes** — `IngredientAssertion`: `relationship` required and one of the three, else `assertion.ingredient.malformed` |
| `PRED-ASSE-018` | shall | Ingredient assertion activeManifest and digitalSourceType mutual exclusivity | **yes** — `IngredientAssertion` refuses `activeManifest` and `digitalSourceType` together — §18.16.12.3, a rule c2pa-rs does not have (docs/comparison.md) |
| `PRED-ASSE-019` | shall | Exclusion range C2PA store content integrity | **yes** — the store exclusion must cover the store and only padding beyond it (SPEC-012 AC3, amendment 5) |
| `PRED-ASSE-020` | shall | Hard binding assertion required for validation | **yes** — same as CROSS-002 |
| `PRED-ASSE-021` | shall | Multi-asset hash fallback after standard hard binding failure | **gap** — no `c2pa.hash.multi-asset` fallback; a failed hard binding stays failed — stricter than the rule, not laxer |
| `PRED-ASSE-022` | shall | Content validation failure signalling | **yes** — every failure is reported and an ingredient never masks one in the active manifest (SPEC-021, CAI-12751) |
| `PRED-ASSE-023` | shall | Inception action position validation | partial — `ActionsCheck` requires the first action to be `c2pa.created` or `c2pa.opened`; it does not require a `c2pa.created` appearing later to be first, nor that `created_assertions`' first actions reference resolves to `c2pa.actions.v2` |
| `PRED-ASSE-024` | shall | reviewRatings absent when dataSource is human entry | **gap** — `reviewRatings` alongside a `humanEntry` `dataSource` is not rejected |
| `PRED-ASSE-025` | shall | Mandatory digitalSourceType for editorial and created actions | **gap** — a mandatory `digitalSourceType` per non-exempt action is not required |
| `PRED-ASSE-026` | shall | Alternative content representation choice exclusivity | **gap** — follows from ASSE-013 |
| `PRED-ASSE-027` | shall | Forbidden labels in external references | **gap** — `c2pa.external-reference` forbidden labels are not checked |

## Container and progressive content (7)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-CONT-001` | may | Multi-part asset independent validation | by design — multi-part assets are not read |
| `PRED-CONT-002` | may | BMFF Merkle leaf alignment to synchronisation points | by design — an obligation on a progressive player |
| `PRED-CONT-003` | shall | BMFF block fetch and track selection | by design — an obligation on a progressive player |
| `PRED-CONT-004` | may | Non-standard playback validation limitation signalling | by design — an obligation on a player rendering in a non-standard mode |
| `PRED-CONT-005` | shall | Pad and pad2 fields ignored in hash computation | **yes** — `pad`/`pad2` are never read in either hash path |
| `PRED-CONT-006` | shall | Box hash algorithm resolution and support check | closed — `c2pa.hash.boxes` is refused by name as a hard binding this verifier does not implement |
| `PRED-CONT-007` | shall | Hash processing follows specified procedure | **yes** — the standard procedure, measured against c2patool on every fixture |

## Image data hash (4)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-IMG-001` | shall | Exclusion range ordering and non-negativity | **yes** — `DataHashCheck` requires non-negative, sorted, non-overlapping ranges; overlap is `.malformed` where c2patool says `.mismatch` (recorded divergence) |
| `PRED-IMG-002` | shall | Exclusion range within asset bounds | **yes** — a range past the end of the asset is refused (SPEC-012) |
| `PRED-IMG-003` | shall | Data hash computation and match | **yes** — `assertion.dataHash.match` / `.mismatch` over the streamed bytes |
| `PRED-IMG-004` | shall | Exclusion range content restrictions | **yes** — only the store and padding inside the exclusion; extra ranges are `assertion.dataHash.additionalExclusionsPresent` |

## ISOBMFF hash (4)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-BMFF-001` | shall | BMFF exclusions field presence | **gap** — an absent or empty `exclusions` list is accepted rather than refused as malformed — in practice the C2PA box is then hashed and the file fails as a mismatch |
| `PRED-BMFF-002` | shall | BMFF subset range ordering and non-negativity | **gap** — `subset` ranges are applied in the order given; they are not checked for ordering or overlap |
| `PRED-BMFF-003` | shall | BMFF Merkle tree validation | partial — the fragmented Merkle tree is validated in full (SPEC-028); `fixedBlockSize`/`variableBlockSizes` are not implemented |
| `PRED-BMFF-004` | shall | BMFF additional exclusions informational | **gap** — no `assertion.bmffHash.additionalExclusionsPresent` informational code is emitted |

## ISOBMFF hash, advanced (4)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-ABMF-001` | shall | BMFF hash match and multi-asset fallback | partial — `assertion.bmffHash.match` / `.mismatch` are implemented; the multi-asset fallback is not (ASSE-021) |
| `PRED-ABMF-002` | should | BMFF Merkle last block boundary clamping | by design — block-size clamping belongs to the `fixedBlockSize` trees this verifier does not implement |
| `PRED-ABMF-003` | shall | Non-fragmented BMFF Merkle tree leaf hash validation | closed — a non-fragmented file carrying a `merkle` map finds no fragments and fails as `assertion.bmffHash.mismatch`; it is never accepted unchecked |
| `PRED-ABMF-004` | shall | Fragmented BMFF Merkle tree validation | **yes** — `FragmentedVerifier` + `BmffHashCheck::checkMerkle()`: init hash, every leaf, the root, and the count (SPEC-028) |

## Streaming (2)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-STREAM-001` | shall | Progressive content validation before render | by design — an obligation on the player: this verifier answers per stream, not per rendered frame |
| `PRED-STREAM-002` | shall | Streaming sequence validation | **yes** — the fragment count and each leaf's position are part of the verdict; a missing, repeated or foreign fragment is `Invalid` and named (SPEC-028 AC3–AC5) |

## What the 22 gaps mean, sorted by what they could cost

A gap is only interesting through its consequence. The question this
project asks of everything is the same one: **can it make this verifier say
`Valid` about something that is not?** Sorted by that answer.

### 1. Closed since this table was written: stapled OCSP

`PRED-STRU-011`, `-012`, `-014`, `-015` and `PRED-CRYP-021` were one gap
with one cause — **OCSP responses stapled into the manifest were not read**
— and it was the only entry here that could produce a wrong `Trusted`.
SPEC-030 closed it on 2026-09-22 (steps 91–92).

The reasoning is kept because it is what shaped the fix. Revocation as a
whole is out of scope and has been since SPEC-014, for a stated reason: an
OCSP query is a network call, and there is no network in the verification
path. That covers the *online* predicates (`STRU-010`, `013`, `016`,
`CRYP-020`, `022`, `023`), which are still marked *by design* above. It
never covered a response the signer had already placed **inside the file**.

What `ocsp.jpg` actually carries, measured:

```
rVals: ocspVals[0] = 2264 bytes of DER
$ openssl ocsp -respin ocsp.der -resp_text -noverify
  Cert Status:  good        This Update: Aug 11 21:51:18 2025 GMT
  Produced At:  Aug 11 21:51:18 2025 GMT   Next Update: Aug 18 21:51:18 2025 GMT
  Responder:    CN=Adobe Product Services G3 OCSP Responder 2025-07-15…
```

The header holding it is **unprotected** — not covered by the signature —
which is the measurement the whole of SPEC-030 is built on: a stapled
response may lower trust and never raise it, and may never fail a file it
cannot prove anything about. A `revoked` answer that verifies under a
responder tied to the signer's own issuer now makes the file `Invalid`;
anything unreadable, unverifiable or about another certificate is
`signingCredential.ocsp.skipped`, which costs no verdict.

And every file now says what was not checked. A skipped check that leaves
no trace was the shape of silence this project refuses everywhere else.

### 2. It could call a file `Valid` that C2PA 2.4 says is malformed

These are assertion rules that do not touch the bytes. The hash binding, the
signature and the chain all still hold; what fails is a rule about what a
manifest may say.

- `PRED-ASSE-024` — `reviewRatings` alongside a `humanEntry` `dataSource`
- `PRED-ASSE-025` — a mandatory `digitalSourceType` on every non-exempt action
- `PRED-ASSE-027` — forbidden labels in a `c2pa.external-reference`
- `PRED-ASSE-023` (partial) — a `c2pa.created` that is not the first action
- `PRED-ASSE-013`, `014`, `026` — `c2pa.alternative-content-representation`
- `PRED-CRYP-024` — `c2pa.session-keys`: its `signerBinding` is not verified
- `PRED-CRYP-006` — a trust anchor with `notBefore`/`notAfter` gating
- `PRED-STRU-009` — an `icon` in `claim_generator_info`

Each would make a strict validator reject a file this one accepts. None of
them lets changed bytes through: they are conformance rules about a
manifest's own consistency, and the signer vouched for every one of those
bytes.

### 3. It is stricter than the rule, or reports under another name

These cost nothing in safety and are listed so that a divergence from
c2patool is never a surprise.

- `PRED-ASSE-021`, `PRED-ABMF-001` — no `c2pa.hash.multi-asset` fallback: a
  failed hard binding stays failed, where the rule says to try another
- `PRED-BMFF-001` — an empty `exclusions` list is accepted rather than
  refused; the C2PA box is then hashed and the file fails as a mismatch
- `PRED-TIME-002`, `PRED-TIME-003` — the `c2pa.time-stamp` assertion is not
  read, so such a file is judged at *now*: an expired certificate is called
  expired rather than excused
- `PRED-ASSE-004`, `PRED-ASSE-007`, `PRED-STRU-018`, `PRED-CRYP-016`,
  `PRED-CRYP-019` — the check happens, the status code differs
  (`assertion.missing` for `assertion.outsideManifest`, `general.error` for
  `assertion.cbor.invalid`, `assertion.missing` for `hashedURI.missing`,
  `signingCredential.expired` for `claimSignature.outsideValidity`)
- `PRED-BMFF-002`, `PRED-BMFF-004`, `PRED-STRU-002` — ordering checks and
  informational codes that change no verdict

## What this does not tell you

The table is a reading of 111 rules against this code, made by the same
hands that wrote the code. It is **reasoned, not measured**, except where an
entry names a test: the suite was not run as a judge, because step 71
measured that its own verdicts are unreliable on files three other
implementations accept.

What *is* measured, and lives elsewhere, is the comparison against c2patool
0.27.22 on every fixture in this repository (`docs/comparison.md`, and the
recorded oracles under `tests/Fixtures/c2patool/`), plus the Go verifier as
a second independent reading.
