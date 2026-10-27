# Conformance: 111 named obligations, one by one

*Measured 2026-09-22 (step 90); `PRED-CRYP-006` closed by SPEC-079 (step 385); `PRED-STRU-002` set aside by design, `PRED-ASSE-021` and `PRED-CRYP-024` parked (step 387); `PRED-ASSE-024` set aside by design (step 388); updated the same day after SPEC-030 closed
five of its gaps (step 92); corrected 2026-09-24 (step 108), when `PRED-IMG-004`
turned out to be a gap that step 90 had marked enforced, and closed the same
day (step 109); four rows brought up to date with SPEC-035 amendment 5 and
SPEC-046 to SPEC-049 on 2026-09-28 (step 176); eight rows brought up to date
with SPEC-064, SPEC-065, SPEC-017 amendment 9 and the closing of issue #10 on
2026-10-23 (step 382).* This table puts every predicate of
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
| **yes** | 78 |
| partial | 9 |
| closed | 4 |
| by design | 18 |
| **gap** | 2 |
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
| `PRED-TIME-001` | shall | Skip or follow timestamp header lookup | **yes** — since 2026-10-09 (SPEC-064): the manifest's own `sigTst`/`sigTst2` header first, and where it gives no trusted time, each token a `c2pa.time-stamp` assertion offers for the manifest (§15.8.1.2) |
| `PRED-TIME-002` | shall | Manifest identifier lookup in timestamp mapping | **yes** — since 2026-10-09 (SPEC-064): a `c2pa.time-stamp` assertion's map is read by manifest label, and only the tokens for this manifest are tried |
| `PRED-TIME-003` | shall | c2pa.time-stamp assertion structural well-formedness | **yes** — since 2026-10-09 (SPEC-064): a shape §15.10.3.2.6 refuses is `assertion.timestamp.malformed` |
| `PRED-TIME-004` | shall | Ignore time-stamp manifests inside ingredients | **yes** — each manifest's timestamp comes from its own COSE header; an ingredient's can never reach the active manifest |

## Ingredients (8)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-INGR-001` | shall | Display attribution warning for invalid manifest data | **yes** — `VerificationReport` carries every failure and `Cli\Command` prints them; nothing is attributed to a signer whose manifest failed |
| `PRED-INGR-002` | shall | Gather and validate redacted assertions for each ingredient manifest | **yes** — since 2026-09-24 (SPEC-035): the absolute `redacted_assertions` of the active manifest and of the manifests the ingredient graph reaches are gathered, never those of an unreached manifest (SPEC-035 amendment 5, 2026-09-27); a redacted assertion that is gone is skipped, one still present with content is `assertion.notRedacted`, and a v2 ingredient manifest with redactions is bound by its claim signature (`ingredient.claimSignature.*`) |
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
| `PRED-STRU-002` | shall | Custom status code namespace syntax conformance | by design — this verifier emits no custom codes; it reads them only in an ingredient's recorded `validationResults`, where a recorded code only acknowledges a status raised here when code, type and url match (SPEC-070), and every code raised here is a C2PA 2.4 §15 name. A custom code, well formed or not, can therefore change nothing in the report, and the rule asks for no status of its own (decided 2026-10-23, step 387; issue #12) |
| `PRED-STRU-003` | shall | PDF update section: single manifest store required | by design — PDF is not a container this verifier reads |
| `PRED-STRU-004` | should | Stop manifest search after first located store | **yes** — one store per file; a second C2PA box is an error naming both offsets (SPEC-026 AC4), never a second candidate |
| `PRED-STRU-005` | shall | Remote manifest inaccessibility reporting | by design — remote manifests are not fetched (no network); `manifest.inaccessible` is a code this verifier cannot reach |
| `PRED-STRU-006` | should | HTTP Link header manifest discovery and childlabel exclusion | by design — HTTP Link header discovery needs the network |
| `PRED-STRU-007` | may | Optional manifest-asset association verification | by design — optional manifest-asset association check; not done |
| `PRED-STRU-008` | shall | Claim required fields and claim_generator_info name presence | **yes** — `Claim::fromMap()` requires every required field and a `name` in `claim_generator_info`, both `claim.malformed` (SPEC-007) |
| `PRED-STRU-009` | shall | Generator-info icon field structural validation | **yes** — since 2026-09-24 (SPEC-034): an `icon` that is a hashed URI must name an assertion the claim lists, with the hash the claim records, else `assertion.hashedURI.mismatch` / `assertion.missing` (§10.2.3.2, §15.10.3.3); the same for actions icons in v2 claims; an external url or a data box is `assertion.missing` |
| `PRED-STRU-010` | should | CA certificate revocation via AIA OCSP | **yes**, opt-in — each CA of the path is asked of the caller's revocation source with its AIA OCSP urls; a revoked CA makes the signer untrusted (ADR-0007) — SPEC-087 AC1, AC5 |
| `PRED-STRU-011` | shall | Signer certificate revocation validation process | **yes** — `Trust\OcspCheck` reads `rVals.ocspVals` and applies `certStatus` (SPEC-030); a certificate with no revocation information is treated as not revoked, by saying `skipped` |
| `PRED-STRU-012` | shall | Multiple OCSP responses: try each until one passes | **yes** — `Trust\OcspCheck::check()` tries each stapled response and stops at the first that proves something, a `revoked` winning over a `good` |
| `PRED-STRU-013` | should | Online OCSP fallback when no revocation info in manifest | **yes**, opt-in — a revocation source the caller injects is asked for a trusted signer whose stored responses prove nothing; this package opens no connection (ADR-0007) — SPEC-086 AC1, AC5 |
| `PRED-STRU-014` | shall | OCSP revoked certStatus disambiguation and rejection | **yes** — `Trust\OcspCheck::statusOf()` separates `removeFromCRL` from a real revocation (RFC 6960 §4.2.1) and rejects only the latter |
| `PRED-STRU-015` | shall | OCSP skipped and OCSP inaccessible informational codes | **yes** — every file now carries one `signingCredential.ocsp.*` line, and a skipped check says so — SPEC-030 AC5; a source that gives nothing usable is `signingCredential.ocsp.inaccessible` — SPEC-086 AC3, AC4 |
| `PRED-STRU-016` | shall | Online OCSP certStatus unknown/revoked outcome handling | **yes**, opt-in — a fetched response is judged exactly as a stapled one (`revoked`, `unknown`, a revocation after the stamped time) — SPEC-086 AC2 |
| `PRED-STRU-017` | should | Forward-compatible c2pa.metadata assertion field tolerance | **yes** — `c2pa.metadata` is not validated at all, so unknown fields cannot be rejected |
| `PRED-STRU-018` | shall | Hashed URI field presence and destination reachability | partial — an absent or unresolvable hashed URI is caught, and reported as `assertion.missing`: this verifier has no `hashedURI.missing` code |
| `PRED-STRU-019` | shall | JPEG APP11 exclusion range total length matches manifest store length | partial — the store is extracted from the APP11 segments themselves, so a tampered segment length changes it and the hash fails; the declared exclusion length is checked to *cover* the store (SPEC-012 amendment 5), not to equal the summed segment length |

## Signatures, certificates, algorithms (25)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-CRYP-001` | may | Optional validation result metadata fields | by design — optional `specVersion`/`trustListURI` fields are not emitted |
| `PRED-CRYP-002` | shall | Claim signature URI resolution | **yes** — `Manifest::signatureBytes()` resolves the claim's signature URI within the manifest; absent is `claimSignature.missing` |
| `PRED-CRYP-003` | shall | Signing credential validation | **yes** — `CertificateProfileCheck` (SPEC-015), every fault `signingCredential.invalid`; since 2026-09-27 also an unknown critical extension (SPEC-046) and a PSS leaf over SHA-1 (SPEC-048), and since 2026-09-28 an RSA public exponent below 3 or even (SPEC-049) |
| `PRED-CRYP-004` | shall | Signature algorithm allowed list check | **yes** — `SignatureVerifier` allows ES256/384/512, PS256/384/512, EdDSA; anything else is `algorithm.unsupported` |
| `PRED-CRYP-005` | shall | Timestamp presence check before trust chain verification | **yes** — the timestamp check runs before the chain and hands it the attested time (SPEC-013 amendment 8) |
| `PRED-CRYP-006` | shall | Trust anchor temporal validity gating | **yes** — since 2026-10-23 (SPEC-079 with amendment 1): the programme's JSON lists (ETSI TS 119 602 LoTE) are read, and an anchor the list has `untrusted` does not count inside that stretch, at the trusted time-stamp's time or else the validation time (§14.4.1's `notAfter`, §15.7). A `trusted` status's date is not read as a `notBefore`: the schema calls it the date the certificate was added, and reading it so would be this project's own interpretation (issue #9, parked 2026-09-24). A PEM bundle or a `--settings` document carries no dates |
| `PRED-CRYP-007` | shall | Certificate chain of trust to trust anchor | **yes** — `ChainCheck` builds to an anchor; `signingCredential.trusted` / `.untrusted` (SPEC-014). Every issuer must be a CA with `keyCertSign` within its `pathlen` (SPEC-014 amendment 4); name constraints and unknown critical extensions stop the path (SPEC-046), as do MD5 or SHA-1 signatures (SPEC-048) and an RSA key with a bad exponent (SPEC-049) between the anchor and the leaf |
| `PRED-CRYP-008` | shall | COSE claim signature cryptographic verification | **yes** — `CoseSign1` + `SignatureVerifier` over the Sig_structure; `claimSignature.validated` / `.mismatch` (SPEC-009) |
| `PRED-CRYP-009` | may | COSE header bucket placement permissiveness | **yes** for a claim v1 — `CoseSign1::findChain()` looks as `c2pa-rs` does: the protected header (label 33 or `"x5chain"`), else the unprotected `"x5chain"`. Label 33 in the unprotected header is no chain, and a chain in both headers is refused; in a claim v2 or later an unprotected chain is `signingCredential.invalid`, stricter by design (SPEC-047, 2026-09-27) |
| `PRED-CRYP-010` | shall | Multiple tstToken entries rejected as malformed | **yes** — a second `sigTst` key cannot survive CBOR decoding, and since SPEC-017 amendment 9 a header whose `tstTokens` holds more than one token is `timeStamp.malformed` and gives no time (§15.8.1.1) |
| `PRED-CRYP-011` | shall | sigTst time-stamp response PKI status validation | **yes** — `TimeStampToken` requires PKIStatusInfo 0 or 1 (SPEC-016) |
| `PRED-CRYP-012` | shall | sigTst2 TimeStampToken retrieval | **yes** — `sigTst2` is read as a bare TimeStampToken (SPEC-016, SPEC-017) |
| `PRED-CRYP-013` | shall | TimeStampToken signature and messageImprint validation | **yes** — signature, messageImprint and its algorithm are all checked: `timeStamp.malformed` / `.mismatch` (SPEC-017) |
| `PRED-CRYP-014` | shall | TimeStampToken TSA certificate chain validation | **yes** — the TSA chain is built to a configured anchor; `timeStamp.untrusted` otherwise — the one divergence from c2patool by design (ADR-0004) |
| `PRED-CRYP-015` | may | TSA certificate temporal validity and credentialInvalid informational code | partial — `timeStamp.outsideValidity` is implemented; the optional `timeStamp.credentialInvalid` informational code is not |
| `PRED-CRYP-016` | shall | Time-stamp and signing certificate validity window enforcement | partial — the rule is enforced — the signer is judged at the attested time — but reported as `signingCredential.expired`, not `claimSignature.outsideValidity` |
| `PRED-CRYP-017` | shall | Use attested time for certificate validity when trusted timestamp present | **yes** — `CertificateProfileCheck::check($at)` takes the trusted timestamp's time; `now` only without one, and the explanation says which (SPEC-017) |
| `PRED-CRYP-018` | may | Claimed time of signing via iat header validation | by design — the `iat` claimed time of signing is not read; a `may` |
| `PRED-CRYP-019` | shall | Claimed time of signing inside/outside validity informational code | by design — follows from CRYP-018: no `timeOfSigning.*` codes exist here; issue #10 closed as not planned (the maintainer's decision) |
| `PRED-CRYP-020` | shall | CA certificate revocation check at signing time | **yes** — from stapled responses and certificate-status assertions (SPEC-066), and, opt-in, from the caller's revocation source, judged at the trusted time-stamp's time or the validation time — SPEC-087 AC1, AC8 |
| `PRED-CRYP-021` | shall | OCSP response from manifest store revocation check | **yes** — the same stapled-OCSP path; `notRevoked` is recorded with the caveat that the header carrying it is unsigned |
| `PRED-CRYP-022` | may | Online OCSP query fallback | **yes**, opt-in — through the caller's revocation source, with the signer's AIA OCSP urls (ADR-0007) — SPEC-086 AC7 |
| `PRED-CRYP-023` | shall | Online OCSP response acceptance and not-revoked determination | **yes**, opt-in — a fetched response passes the stapled one's tests (about the signer, an authorised responder, current) or is `inaccessible` — SPEC-086 AC1, AC3 |
| `PRED-CRYP-024` | shall | c2pa.session-keys signerBinding signature verification | **gap** — `c2pa.session-keys` is not recognised, so its `signerBinding` signature is not verified Parked 2026-10-23 (step 387): no measured writer emits `c2pa.session-keys`, `c2pa-rs` 0.91.1 does not recognise it, and a fixture would have to be built from the specification alone (issue #8) |
| `PRED-CRYP-025` | shall | No hash mismatch codes for activeManifest field in claim signature hash validation | **yes** — the claim-signature hash method and `c2pa.ingredient.v3`'s `activeManifest` field are not implemented, so no mismatch can be recorded for it |

## Assertions (27)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-ASSE-001` | shall | Ingredient validation and results recording | by design — an obligation on the claim *generator*, not on a verifier |
| `PRED-ASSE-002` | shall | Multiple embedded manifest stores invalidation | **yes** — a second manifest store in one asset is `claim.multiple` (SPEC-005/013); in ISOBMFF a second C2PA box is a container error |
| `PRED-ASSE-003` | shall | Redacted assertion label check | **yes** — since 2026-09-24 (SPEC-035): a redacted actions assertion is `assertion.action.redacted`; a redacted hard binding is `assertion.hardBinding.redacted` (SPEC-036) |
| `PRED-ASSE-004` | shall | Assertion URI must reference same manifest | **yes** — since 2026-09-24 (SPEC-040): a claim entry naming another manifest is `assertion.outsideManifest`, on the entry as written |
| `PRED-ASSE-005` | shall | Assertion URI must be resolvable | **yes** — `assertion.missing` when a claim's hashed URI names no assertion (SPEC-011) |
| `PRED-ASSE-006` | shall | Assertion hashed-URI integrity check | **yes** — `HashedUriCheck`: `assertion.hashedURI.match` / `.mismatch` over every entry |
| `PRED-ASSE-007` | shall | Standard assertion encoding validity | **yes** — JSON that does not decode is `assertion.json.invalid`, CBOR that does not decode `assertion.cbor.invalid` (SPEC-088; `general.error` until then) — SPEC-088 AC1 |
| `PRED-ASSE-008` | shall | Undeclared assertion detection | **yes** — `assertion.undeclared` for a box in the store that no claim list references (SPEC-011) |
| `PRED-ASSE-009` | shall | Self-redaction prohibition | **yes** — since 2026-09-24 (SPEC-035): an entry naming the claim's own manifest is `assertion.selfRedacted` |
| `PRED-ASSE-010` | shall | Assertion metadata field not validated | **yes** — assertion `metadata` fields are never validated |
| `PRED-ASSE-011` | shall | Specific assertion type dispatch | **yes** — dispatch by label: actions, ingredient, the hard bindings (`Verifier`) |
| `PRED-ASSE-012` | shall | Hashed-URI and hashed-ext-URI field validation in assertions | partial — hashed URIs inside ingredient assertions are validated (SPEC-020/021); hashed URIs inside other assertion types are not walked |
| `PRED-ASSE-013` | shall | Alternative-content-representation structural validity | **yes** — since 2026-10-09 (SPEC-065): the assertion's count and shape (§15.10.3.2.7), else `assertion.alternativeContentRepresentation.malformed` |
| `PRED-ASSE-014` | shall | Alternative-content-representation embedded preservation image hash | **yes** — since 2026-10-09 (SPEC-065): the embedded image's hash, `assertion.alternativeContentRepresentation.match` or `.hashMismatch` |
| `PRED-ASSE-015` | shall | Hashed-URI reference integrity | **yes** — same mechanism as ASSE-006 |
| `PRED-ASSE-016` | shall | External reference validation procedure | by design — external data is never retrieved (no network) |
| `PRED-ASSE-017` | shall | Ingredient assertion relationship field validity | **yes** — `IngredientAssertion`: `relationship` required and one of the three, else `assertion.ingredient.malformed` |
| `PRED-ASSE-018` | shall | Ingredient assertion activeManifest and digitalSourceType mutual exclusivity | **yes** — `IngredientAssertion` refuses `activeManifest` and `digitalSourceType` together — §18.16.12.3, a rule c2pa-rs does not have (docs/comparison.md) |
| `PRED-ASSE-019` | shall | Exclusion range C2PA store content integrity | **yes** — the store exclusion must cover the store and only padding beyond it (SPEC-012 AC3, amendment 5) |
| `PRED-ASSE-020` | shall | Hard binding assertion required for validation | **yes** — same as CROSS-002 |
| `PRED-ASSE-021` | shall | Multi-asset hash fallback after standard hard binding failure | **gap** — no `c2pa.hash.multi-asset` fallback; a failed hard binding stays failed — stricter than the rule, not laxer Parked 2026-10-23 (step 387): the multi-asset hash covers assets the API is not given, no measured writer emits `c2pa.hash.multi-asset`, and `c2pa-rs` 0.91.1 does not read it, so there is neither a fixture nor an oracle (issue #7) |
| `PRED-ASSE-022` | shall | Content validation failure signalling | **yes** — every failure is reported and an ingredient never masks one in the active manifest (SPEC-021, CAI-12751) |
| `PRED-ASSE-023` | shall | Inception action position validation | partial — `ActionsCheck` requires the first action to be `c2pa.created` or `c2pa.opened`, and since SPEC-033 refuses a second opening anywhere in the claim (a later `c2pa.created` included); it does not require `created_assertions`' first actions reference to resolve to `c2pa.actions.v2` |
| `PRED-ASSE-024` | shall | reviewRatings absent when dataSource is human entry | by design — a rule on what a manifest may say (C2PA 2.4 §18.3.5), not on validation: chapter 15 does not name it and gives it no status code, and §18.3.1 says "A Manifest Consumer is not required to read any portion of assertion metadata". Its types do not match the CDDL (`humanEntry.credentialed` is not defined; `humanEntry.anonymous` and `.identified` are deprecated since 2.0). The programme checks it itself, in three asset rubrics, on the crJSON this verifier writes faithfully (SPEC-074, SPEC-075). `c2pa-rs` 0.91.1 does not enforce it (decided 2026-10-23, step 388; issue #1) |
| `PRED-ASSE-025` | shall | Mandatory digitalSourceType for editorial and created actions | partial — since 2026-09-24 (SPEC-032): a `c2pa.created` in a v2 claim without `digitalSourceType` is `assertion.action.malformed`, as both `c2patool` versions; other actions are not required to carry one, which no oracle enforces and §15 does not ask |
| `PRED-ASSE-026` | shall | Alternative content representation choice exclusivity | **yes** — since 2026-10-09 (SPEC-065): exactly one of the two fields, else malformed |
| `PRED-ASSE-027` | shall | Forbidden labels in external references | **yes** — since 2026-09-24 (SPEC-032): `ExternalReferenceCheck` refuses the forbidden labels, a `location` without `url`, and `alg` or `hash` alone, with `assertion.external-reference.malformed` (§15.10.3.2.2); nothing is fetched |

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
| `PRED-IMG-004` | shall | Exclusion range content restrictions | **yes** — since 2026-09-24 (SPEC-012 amendment 7): an exclusion holding any part of the store must hold nothing else, else `assertion.dataHash.mismatch`; separate extra ranges are `assertion.dataHash.additionalExclusionsPresent`. Step 90 had marked this **yes** while the code only checked *cover*; it was a gap from then until step 109 (see section 0 below) |

## ISOBMFF hash (4)

| predicate | sev | rule | this verifier |
|---|---|---|---|
| `PRED-BMFF-001` | shall | BMFF exclusions field presence | **yes** — since 2026-09-24 (SPEC-038): an absent or empty `exclusions` list is `assertion.bmffHash.malformed`, before any hashing |
| `PRED-BMFF-002` | shall | BMFF subset range ordering and non-negativity | **yes** — since 2026-09-24 (SPEC-038): unsorted or overlapping `subset` ranges are `assertion.bmffHash.malformed`, before any hashing |
| `PRED-BMFF-003` | shall | BMFF Merkle tree validation | partial — the fragmented Merkle tree is validated in full (SPEC-028); `fixedBlockSize`/`variableBlockSizes` are not implemented |
| `PRED-BMFF-004` | shall | BMFF additional exclusions informational | **yes** — since 2026-09-24 (SPEC-038): `assertion.bmffHash.additionalExclusionsPresent` is reported, informational, as `c2patool` 0.28.0 reports it |

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

## What the 23 gaps mean, sorted by what they could cost

Twenty-three gaps were recorded. Sixteen have since been closed (section 0, section 1, `PRED-ASSE-027` by SPEC-032, `PRED-STRU-009` by SPEC-034, `PRED-BMFF-001`, `-002` and `-004` by SPEC-038, `PRED-TIME-002` and `-003` by SPEC-064, `PRED-ASSE-013`, `-014` and `-026` by SPEC-065), one narrowed to partial (`PRED-ASSE-025`), and one set aside by decision (`PRED-CRYP-019`, issue #10). `PRED-CRYP-006` (#9) closed on 2026-10-23 by SPEC-079, and `PRED-STRU-002` (#12) set aside the same day as changing nothing in a report. `PRED-ASSE-024` (#1) was set aside the same day as a rule on the manifest's content that the programme checks itself. Two remain, #7 and #8, both parked for want of a writer and an oracle.

A gap is only interesting through its consequence. The question this
project asks of everything is the same one: **can it make this verifier say
`Valid` about something that is not?** Sorted by that answer.

### 0. Closed 2026-09-24, and it did let changed bytes through: `PRED-IMG-004`

Step 90 marked this rule **yes**, and it was not. C2PA 2.4 (VAL-ASSE-0043/0044)
says the exclusion range that contains the manifest store may hold **only**
the store and padding; anything else in it is `assertion.dataHash.mismatch`.
This verifier checks that the exclusion *covers* the store (SPEC-012
amendment 5, 2026-09-21) and nothing about what else it holds.

Measured on 2026-09-24 (`notes/step-108-exclusion-wider-than-store.md`): the
three official `truepic-20230212-*` test files exclude the SOI marker and the
whole EXIF segment together with the store. A copy with its EXIF capture
date changed from 2023 to 2019 is still **`Trusted`** here, with the Truepic
root as the anchor, and in `c2patool` 0.27.22. `c2patool` 0.28.0 (`c2pa`
0.91.0) rejects both the original and the copy. Over the corpus, an
exact-equality rule changes the verdict of these three files and no others.

SPEC-012 amendment 7 reversed amendment 5 on the same day (step 109): an
exclusion that holds any part of the store must hold nothing else. The
three Truepic files and the changed copy are now `Invalid` with
`assertion.dataHash.mismatch`, which is `c2patool` 0.28.0's verdict too.
Over 864 runs (every corpus file, three settings files), no other verdict
changed.

### 1. Closed since this table was written: stapled OCSP

`PRED-STRU-011`, `-012`, `-014`, `-015` and `PRED-CRYP-021` were one gap
with one cause — **OCSP responses stapled into the manifest were not read**
— and it was the only entry here that could produce a wrong `Trusted`.
SPEC-030 closed it on 2026-09-22 (steps 91–92).

The reasoning is kept because it is what shaped the fix. Revocation as a
whole is out of scope and has been since SPEC-014, for a stated reason: an
OCSP query is a network call, and there is no network in the verification
path. That covered the *online* predicates (`STRU-010`, `013`, `016`,
`CRYP-020`, `022`, `023`). It never covered a response the signer had
already placed **inside the file**. Since SPEC-086 (ADR-0007) the signer's
online query is possible through a source the caller injects, so `013`,
`016`, `022` and `023` are *yes*, opt-in; since SPEC-087 the CAs of the
path are asked too (`STRU-010`, `CRYP-020`).

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

- `PRED-ASSE-025` (partial since SPEC-032) — a `digitalSourceType` on actions other than `c2pa.created` (issue #2 closed as not planned)
- `PRED-ASSE-023` (partial) — `created_assertions`' first actions reference resolving to `c2pa.actions.v2`
- `PRED-CRYP-024` — `c2pa.session-keys`: its `signerBinding` is not verified

Each would make a strict validator reject a file this one accepts. None of
them lets changed bytes through: they are conformance rules about a
manifest's own consistency, and the signer vouched for every one of those
bytes.

### 3. It is stricter than the rule, or reports under another name

These cost nothing in safety and are listed so that a divergence from
c2patool is never a surprise.

- `PRED-ASSE-021`, `PRED-ABMF-001` — no `c2pa.hash.multi-asset` fallback: a
  failed hard binding stays failed, where the rule says to try another
- `PRED-STRU-018`, `PRED-CRYP-016` — the check happens, the status code
  differs (`assertion.missing` for `hashedURI.missing`,
  `signingCredential.expired` for `claimSignature.outsideValidity`; both
  as `c2pa-rs`). `PRED-ASSE-007` stood here until SPEC-088 gave
  `assertion.cbor.invalid` its own code.
- (`PRED-STRU-002` stood here, a syntax check on custom codes an ingredient
  recorded; set aside by design on 2026-10-23. `PRED-BMFF-002` stood here
  too, as changing no verdict. Step 133 measured that it did, and SPEC-038
  closed it.)

## Outside the catalogue

The catalogue names 150 predicates, and none of them mentions EKUs or most
of §15.10.3.2.3's actions rules. The actions rules are enforced since
SPEC-033: ingredient references of the right relationship,
`c2pa.translated`'s languages, `relatedAssertions`, and a watermark's soft
binding. Since SPEC-034, icons in `softwareAgents`, `templates` and an
action's `softwareAgent` are checked too, and since SPEC-037 a
`c2pa.redacted` action's reference must resolve (as `c2patool` reads the
rule: only an action with `parameters`). Two
obligations of C2PA 2.4 that this verifier does not meet have come up anyway,
and are listed here so that a table built from the catalogue does not hide
them.

### §15.10.3.2.3 for version 1 claims — not applied, by decision

> If the action’s parameters field contains no ingredients field (or
> ingredient field for c2pa.actions), the claim shall be rejected with a
> failure code of assertion.action.ingredientMismatch.

The actions rules of §15.10.3.2.3 apply to every claim; the text names
`c2pa.actions`, the version 1 assertion, and its `ingredient` field
explicitly. This verifier applies SPEC-033's rules to version 2 claims only,
as SPEC-018 and SPEC-033 decided after `c2pa-rs`, which skips them for
version 1 claims unless `verify.strict_v1_validation` is set
(`verify_actions` in `claim.rs`, read at 0.91.1). Where it does apply them,
it accepts `ingredients` or the version 1 `ingredient` in either actions
assertion, as `c2pa-rs` does; the letter of the rule names one field per
version.

Measured 2026-10-07 on
`tests/Fixtures/writers/adobe-20260425-lightroom-classic-church.jpg`
(Lightroom Classic 15.3: a version 1 claim whose `c2pa.actions` opens with
`c2pa.opened` carrying `parameters.ingredients`, one hashed URI to a
`parentOf` `c2pa.ingredient.v2`, and no `parameters.ingredient`): `Valid`
here and in `c2patool` 0.27.22 and 0.28.1; with `strict_v1_validation`
both `c2patool` versions say `Invalid`, but with
`assertion.action.malformed` ("first action must be created or opened"),
not `ingredientMismatch`. A reader that follows the letter fails it: Dawn
Technology's fork of `c2pa-ts` (0.17.2, `653f2bf`) reports
`assertion.action.ingredientMismatch`.

### §14.5.1.2: trust anchors tied to EKUs — gap, by decision

> the validator shall use only the trust anchors it associates with EKUs
> present in the certificate

§14.4.1 builds the signer trust model as a list of anchors *per EKU*. The
2.4 change list restricts the C2PA Trust List to certificates carrying
`c2pa-kp-claimSigning`. This verifier keeps one list of accepted EKUs and
one set of anchors, and checks them independently. So does `c2patool`,
0.27.22 and 0.28.0. A certificate without the claim-signing EKU that
chains to a C2PA Trust List anchor would be `Trusted` here, where strict
2.4 would not trust it through that list.

Measured on 2026-09-24 (`notes/step-113-anchors-and-ekus.md`): under the
official list, two corpus files reach an anchor (Pixel 10, OpenAI). Both
carry the claim-signing EKU on the leaf and on the issuing CA, as the C2PA
Certificate Policy requires of every CA on the list. The case therefore
needs a listed CA to issue outside its own policy first, and no file is
known to show it.

Measured on 2026-10-23 (SPEC-078, `tests/Fixtures/conformance-test/`):
the conformance programme's own public test certificate is such a case.
"C2PA Conformance Test Signing" (`contentauth/c2pa-conformance-tool`
`6d10ee0`) carries only id-kp-documentSigning, and the programme's tool
documents files signed with it as trusted in test mode. With its root as
`--trust-list`, this verifier says `Trusted`; `c2patool` 0.28.2 says
`Invalid` (`signingCredential.invalid`, "certificate missing required
EKU") unless `store.cfg` is given as `trust_config`, and then `Trusted`.
Strict §14.5.1.2 would say untrusted. Pinned as it is, by the
maintainer's decision of 2026-10-23.

**Named, not built** (the maintainer's decision, 2026-09-24). The settings
format this verifier shares with `c2patool` has no way to say which
anchors belong to which EKU. `trust.anchors[].trust_config` means *widen*
in `c2pa` 0.91.0 (SPEC-031 AC8), and the official list's JSON carries no
EKU. Enforcing the rule would take a setting of this project's own, or a
bundled list, and the design rules out both. It will be revisited when
the shared format can express the association.

## What this does not tell you

The table is a reading of 111 rules against this code, made by the same
hands that wrote the code. It is **reasoned, not measured**, except where an
entry names a test: the suite was not run as a judge, because step 71
measured that its own verdicts are unreliable on files three other
implementations accept.

What *is* measured, and lives elsewhere, is the comparison against c2patool
0.27.22 on every fixture in this repository (`docs/comparison.md`, and the
recorded oracles under `tests/Fixtures/c2patool/`), the same corpus
compared with 0.28.0 on 2026-09-24 (steps 107–118), and the Go verifier as
a second independent reading.
