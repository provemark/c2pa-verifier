# SPEC-087: Revocation data from a source the caller injects — the CAs of the path

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

SPEC-086 lets a caller inject a revocation source, and the verifier asks it
about the signer's certificate. A signer is also untrusted when a CA between
it and the anchor was revoked — after a key compromise, for instance — and
C2PA 2.4 §15.9 says so: a revoked issuing CA makes the credential untrusted.
This verifier already reads that from a stapled response about a CA
(SPEC-066, `OcspCheck::revokedCa()`), but most files staple none. With a
source in hand, a file under a revoked CA still comes out `Trusted`.

`c2pa-rs` with `verify.ocsp_fetch` on asks each CA of the chain against its
own AIA responder before the signer, and a revoked CA makes the signer
`signingCredential.untrusted` ("issuing CA revoked"); any other answer adds
nothing (read in `c2pa` 0.91.1 `crypto/cose/ocsp.rs`,
`fetch_and_check_ocsp_response`). It does so only when the signer's own
stapled response proved nothing.

This is the second spec under ADR-0007, and it completes candidate C3.

Measured on 2026-10-27 (the verifier at `bc3aef5`):

- `revocation/ca-control.png` under `revocation/throw-away-root.settings.json`
  is `Trusted`: Signer U ← Throw-away Intermediate ← Throw-away Root (the
  anchor, not in `x5chain`), no response anywhere, no AIA.
- The probes beside it staple a response about the intermediate:
  `ca-revoked.png` revoked, keyCompromise, at 2026-09-09T14:26:58Z
  (SHA-256 `c457d65b…`), `ca-good.png` good (`7e48060b…`), `ca-removed.png`
  revoked with removeFromCRL (`26fd05fb…`); each current from 2026-10-09 to
  2036-10-06. SPEC-066 already makes `ca-revoked.png` `Valid`
  (`signingCredential.untrusted`).
- `anchor-in-chain/anchor-in-chain.png` under its own
  `throw-away-root.settings.json` carries the root in `x5chain` and is
  `Trusted` at depth 2.
- `writers/openai-20260826-c2pa_2x.png` under the programme's lists, at the
  validation time 2026-08-26T12:00:00Z, is `Trusted` with the signer's
  stapled response giving `signingCredential.ocsp.notRevoked`. Its CA,
  SSL.com C2PA ICA R1 2025, names `http://ocsp-c2pa.ssl.com` in its AIA.
  `writers/google-20250919-pixel10-npld-picnic-table.jpg`'s CA names
  `http://c2pa-ocsp.pki.goog/`.

## Scope

**In scope**

- **The same source, a wider question.** `RevocationSource::ocspResponse()`
  keeps its signature; `$subject` becomes "the certificate asked about: the
  signer or a CA of its path", `$issuer` the certificate above it, `$urls`
  the subject's AIA OCSP urls (SPEC-086's rule). No new contract symbol;
  the docblock and the README say what changed. SPEC-086 is not yet
  released, so no released caller's assumption breaks.
- **Which CAs.** Every certificate strictly between the anchor and the
  signer on the path the chain check finds — the anchor's issuer is not
  known, so the anchor is never asked about. Each once per judged manifest,
  from the one below the anchor down to the one above the signer, before
  the signer is asked (SPEC-086).
- **When.** Whenever a source is given and the path reaches an anchor —
  also when the signer's stored responses prove something. This is
  stricter than `c2pa-rs`: a current `good` about the signer says nothing
  about its issuer, and asking prevents a wrong `Trusted` (ADR-0005). Not
  when a stored response already shows that CA revoked (SPEC-066 then
  decides).
- **What becomes of the answer.** A response read as SPEC-066 reads a
  stapled one about a CA (about that CA, verified under a responder tied to
  its issuer, at most the size limit) that says revoked at the judged time,
  not with removeFromCRL: the path reaches no anchor,
  `signingCredential.untrusted`, the explanation saying the response was
  fetched from the caller's source. The signer is then not asked (it is no
  longer trusted). Any other answer — good, unknown, a revocation after the
  judged time, removeFromCRL, null, a throw, a response that cannot be used
  — adds no status and changes nothing: §15.9 has no code for a CA's
  check, and an unanswered question may not lower a verdict.
- The source reaches the chain check: `ChainCheck::checkWithAnchor()` takes
  it, from `Verifier` and `IngredientManifestCheck` alike; the default asks
  nothing.
- A row in `docs/comparison.md` for the difference with `c2pa-rs` above.

**Out of scope** (each needs its own spec before it may be built)

- A fetched response about a CA in `ManifestEvidence`: SPEC-085's list is
  the signer's pool. Only a revoked answer changes the verdict, and its
  explanation names it.
- An issuer completed from the trust anchors where `x5chain` lacks one
  (SPEC-086 left it out for the signer too).
- The time-stamp authority's chain; CRLs.

## Behavior

The test source records each call and answers per subject.

- **AC1 — a revoked CA**
  - Given `revocation/ca-control.png` with its settings and a source that
    answers the intermediate with the revoked response from
    `revocation/ca-revoked.png` (`c457d65b…`)
  - Then the source is called once, with the intermediate, the root and no
    urls; the signature's status is `signingCredential.untrusted`, its
    explanation names the intermediate and says fetched; the verdict is
    `Valid`; the signer is not asked.

- **AC2 — a good CA, then the signer**
  - Given the same file with a source answering the intermediate with the
    good response from `revocation/ca-good.png` (`7e48060b…`) and the signer
    with null
  - Then two calls, the intermediate first, then Signer U; the verdict is
    `Trusted`; the signer's status is `signingCredential.ocsp.inaccessible`
    (SPEC-086).

- **AC3 — answers that change nothing** *(required: error / malformed input)*
  - Given the same file with the intermediate answered by the removeFromCRL
    response (`26fd05fb…`), by null, by an exception, by bytes that are not
    DER, and by the response about Signer P from `revocation/cs-good.png`
    (about another certificate)
  - Then each time the verdict is `Trusted`, `signingCredential.trusted`
    stands, and nothing escapes `verify()`.

- **AC4 — the anchor is not asked about**
  - Given `anchor-in-chain/anchor-in-chain.png` with its settings and a
    source answering null
  - Then the calls are the intermediate (with the root from `x5chain` as
    its issuer) and then the signer; never the root.
  - And given `revocation/ca-control.png` with the intermediate itself as
    the only anchor: one call, about the signer; none about a CA.

- **AC5 — asked although the signer's staple proves something**
  - Given `writers/openai-20260826-c2pa_2x.png` with the programme's lists
    at the validation time 2026-08-26T12:00:00Z and a source answering null
  - Then one call, about SSL.com C2PA ICA R1 2025, with the urls
    `['http://ocsp-c2pa.ssl.com']`; the signer is not asked (its staple
    gives `notRevoked`); the verdict stays `Trusted`.

- **AC6 — not asked where a stored response already shows the CA revoked**
  - Given `revocation/ca-revoked.png` with its settings and a recording
    source
  - Then no call; the status is SPEC-066's `signingCredential.untrusted`,
    unchanged.

- **AC7 — not asked without an anchor**
  - Given `revocation/ca-control.png` with no settings, and
    `writers/microsoft-20260609-bing-fast-heartbeat.jpg` with the
    programme's lists
  - Then no call.

- **AC8 — an ingredient's CA**
  - Given `revocation/cs-good.png` with its settings and a source answering
    the intermediate with the revoked response (`c457d65b…`)
  - Then both manifests (U active, P the ingredient, each under the same
    intermediate) are `signingCredential.untrusted` and the verdict is not
    `Trusted`.

- **AC9 — the default changes nothing**
  - Given every corpus file under no settings, `trust/full.settings.json`
    and the programme's lists, with no source and with
    `NoRevocationSource`
  - Then `toJson()` and `toCrJson()` are byte-identical to this verifier
    before this spec.

- **AC10 — asked exactly where allowed**
  - Given the same runs with a source that records and answers null
  - Then, for every judged manifest whose path reaches an anchor and whose
    stored responses show no CA revoked, each CA strictly between the
    anchor and the signer is asked exactly once, before the signer; no
    other certificate is asked about but the signers SPEC-086 allows; and
    every verdict equals the run without a source (null changes nothing a
    CA's answer could).

## References

- Specification: C2PA 2.4 §15.9 (a revoked issuing CA), §15.9.2 (an online
  query); RFC 6960 §3.2; RFC 5280 §4.2.2.1.
- Decision: ADR-0007, ADR-0005.
- Measured: the chains, responses and urls above, over the verifier at
  `bc3aef5`.
- Read: `c2pa` 0.91.1 `crypto/cose/ocsp.rs` (`check_ocsp_status`,
  `fetch_and_check_ocsp_response`).
- Oracle: `c2patool` with `verify.ocsp_fetch` for the code a revoked CA
  gives; recorded responses for the tests.

## API sketch

```php
// Trust\RevocationSource, unchanged signature, wider docblock:
//     @param string $subject DER of the certificate asked about: the signer, or a CA of its path
//     @param string $issuer  DER of the certificate above it
//     @param list<string> $urls the OCSP responder urls of $subject's AIA

// Trust\ChainCheck::checkWithAnchor() gains, last: ?RevocationSource $source = null
```

## Open questions

None.

## Amendments

None yet.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  |                             |                      |
| AC2                  |                             |                      |
| AC3                  |                             |                      |
| AC4                  |                             |                      |
| AC5                  |                             |                      |
| AC6                  |                             |                      |
| AC7                  |                             |                      |
| AC8                  |                             |                      |
| AC9                  |                             |                      |
| AC10                 |                             |                      |
