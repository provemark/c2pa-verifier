# SPEC-086: Revocation data from a source the caller injects — the signer

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

The verifier judges a signer's revocation only by the OCSP responses a file
carries: stapled in the claim signature's `rVals` (SPEC-030) or held in
`c2pa.certificate-status` assertions (SPEC-066). Most files carry none, and
then the answer is `signingCredential.ocsp.skipped`, always. A certificate
revoked after signing, or a signer whose key was compromised, stays
`Trusted`.

C2PA 2.4 §15.9.2 lets a validator ask the responder online when no stored
response proves anything, and defines `signingCredential.ocsp.inaccessible`
for a query that gets no answer. ADR-0007 (accepted 2026-10-27) allows this
here through a source the caller injects: the package itself opens no
connection, and with the default source nothing changes.

This is the first spec under ADR-0007: the signer's certificate. The CAs of
the path through their AIA responders may follow in a second.

Measured on 2026-10-27 (the verifier at `c0eaf42`, `c2patool` 0.28.3; see
ADR-0007 and `notes/step-471-adr-0007-proposed.md`):

- `c2patool` asks only with `verify.ocsp_fetch` on (default off). With it
  on, `writers/google-20250919-pixel10-npld-picnic-table.jpg` gets a live
  `signingCredential.ocsp.notRevoked`; `writers/microsoft-20260609-bing-fast-heartbeat.jpg`
  gets `signingCredential.ocsp.inaccessible`.
- Here, with the programme's trust lists, the Google file is `Trusted` and
  `signingCredential.ocsp.skipped`; its signer's certificate names one OCSP
  responder in its AIA extension, `http://c2pa-ocsp.pki.goog/`.
- `revocation/cs-parent.png` under `revocation/throw-away-root.settings.json`
  is `Trusted`, carries no response, and is `skipped` for its signer P.
  Responses about P already exist in the corpus: `good` (SHA-256
  `427b01dc…`) in `revocation/cs-good.png`, `revoked` (`d39d48fa…`) in
  `revocation/cs-revoked.png`, both current from 2026-10-09T14:26:59Z to
  2036-10-06T14:26:59Z; and one about signer U, `revoked` (`3490ee03…`), in
  `revocation/cs-own.png`.
- In `revocation/cs-good.png` the ingredient (P) has a stored response that
  proves `notRevoked`; the active manifest (U) has only a response about
  another certificate, so nothing stored proves anything for U. In
  `revocation/cs-own.png` it is the other way round.

## Scope

**In scope**

- A new contract interface `Trust\RevocationSource` with one method,
  `ocspResponse(string $subject, string $issuer, array $urls): ?string`:
  the DER of the signer's certificate and of its issuer, and the OCSP
  responder urls of the signer's certificate; it returns the DER of an OCSP
  response, or null.
- A new contract class `Trust\NoRevocationSource`, which returns null and
  is never called by the verifier: with it (the default) no status, report
  or evidence changes.
- A new last parameter of the `Verifier` constructor,
  `?RevocationSource $revocationSource = null`, null meaning the default.
- **When the source is asked:** for each judged manifest (active and
  ingredient alike, as `OcspCheck` runs for both), exactly once, only when
  - its signer's chain reaches an anchor (the manifest's
    `signingCredential.trusted`), and
  - the check over the stored responses gave `signingCredential.ocsp.skipped`
    (no stored response proves anything, C2PA 2.4 §15.9.2), and
  - the signer's issuer is in `x5chain`, so a response can be matched.
- **The urls:** the `id-ad-ocsp` access locations (RFC 5280 §4.2.2.1) of
  the signer's certificate's AIA extension, in order, kept only when they
  are `http` or `https` URIs of at most 2,048 bytes, at most four. An AIA
  that cannot be read gives an empty list; the source is still asked.
- **What becomes of the answer:**
  - a response that passes the same reading as a stored one (`OcspCheck`:
    about the signer, verified under a responder tied to its issuer, at
    most the size limit) is judged exactly as a stored one at the same
    time — `notRevoked`, `revoked`, `unknown`, or `skipped` when it is not
    current — and the status's explanation says the response was fetched
    from the caller's source;
  - null, an exception or error thrown by the source, or a response that
    does not pass the reading: `signingCredential.ocsp.inaccessible`
    (informational), with the reason, in place of `skipped`.
- A new `StatusCode::SigningCredentialOcspInaccessible`
  (`signingCredential.ocsp.inaccessible`), informational.
- The evidence (SPEC-085): a third source, `OcspEvidence::FETCHED`
  (`fetched`), with the signature's url and the bytes the source returned,
  appended after the stored responses, outcome `chosen` when it was judged
  and `unusable` when it did not pass the reading. Null or a throw adds no
  entry.
- The contract (SPEC-025) grows by one interface, one class, one enum
  case, one constant and one constructor parameter, in the same minor as
  SPEC-081 to SPEC-085.
- With the build, the lines of SPEC-014's and SPEC-030's *Out of scope*
  that rule out an online query get an amendment each, and the README's
  design rule and `docs/comparison.md` say what ADR-0007 decided.

**Out of scope** (each needs its own spec before it may be built)

- The CAs of the path through their own AIA responders (`c2pa-rs` asks
  those first; a revoked CA makes the signer untrusted): a second spec.
- An issuer completed from the trust anchors when `x5chain` holds the
  signer alone (`c2pa-rs` does): the source is not asked.
- CRLs, AIA `caIssuers`, any HTTP code, caching, retries, time-outs: the
  source's business.
- A source for an untrusted signer. `c2pa-rs` with fetching on asks there
  too (the Bing file: `inaccessible`); here it stays `skipped`, a
  difference in the report only, recorded in `docs/comparison.md`.
- `c2pa-rs`'s second time-stamp validation on its fetch path (ADR-0007,
  decided question 1): not copied.
- The source in `toArray()`, `toJson()`, `toCrJson()` or the CLI.

## Behavior

The test source records each call and returns a fixed answer.

- **AC1 — a fetched `good`**
  - Given `revocation/cs-parent.png` with
    `revocation/throw-away-root.settings.json` and a source returning the
    `good` response about P (`427b01dc…`, taken from `revocation/cs-good.png`)
  - Then the source is called once, with P's certificate, its issuer's and
    no urls; the signature's status is `signingCredential.ocsp.notRevoked`
    and its explanation says fetched; the verdict is `Trusted`; the
    evidence holds one entry, `fetched`, `chosen`, `good`.

- **AC2 — a fetched `revoked`**
  - Given the same file and settings and a source returning the `revoked`
    response about P (`d39d48fa…`, from `revocation/cs-revoked.png`)
  - Then `signingCredential.ocsp.revoked` and the verdict `Invalid`; the
    evidence entry is `fetched`, `chosen`, `revoked`.

- **AC3 — an answer about another certificate** *(required: error / malformed input)*
  - Given the same file and settings and a source returning the response
    about U (`3490ee03…`, from `revocation/cs-own.png`)
  - Then `signingCredential.ocsp.inaccessible` with the reason, no
    `skipped`; the verdict `Trusted`; the evidence entry `fetched`,
    `unusable`.

- **AC4 — no answer** *(required: error / malformed input)*
  - Given the same file and settings, and a source that returns null; one
    that throws an exception; one that throws an `Error`; one that returns
    bytes that are not DER
  - Then each time `signingCredential.ocsp.inaccessible` with the reason,
    the verdict `Trusted`, and nothing escapes `verify()`; the evidence is
    empty for null and the throws, and one `fetched` `unusable` entry for
    the bytes.

- **AC5 — not asked where a stored response proves something**
  - Given `revocation/cs-good.png` with the throw-away settings and a source
    returning null
  - Then the source is called once, for U (the active manifest), not for P,
    whose stored `notRevoked` stands.
  - And given `revocation/cs-own.png`: called once, for P (the ingredient),
    not for U, whose stored `revoked` stands.

- **AC6 — not asked for an untrusted signer**
  - Given `revocation/cs-parent.png` with no settings, and
    `writers/microsoft-20260609-bing-fast-heartbeat.jpg` with the
    programme's lists, each with a source returning `good` about P
  - Then the source is never called, and the statuses equal those without a
    source.

- **AC7 — the urls from the AIA**
  - Given `writers/google-20250919-pixel10-npld-picnic-table.jpg` with the
    programme's lists and a source returning null
  - Then the source is called once, with the urls
    `['http://c2pa-ocsp.pki.goog/']`, and the status is
    `signingCredential.ocsp.inaccessible`.

- **AC8 — urls that are not kept** *(required: error / malformed input)*
  - Given signer certificates (public test certificates, keys deleted at
    build time) whose AIA holds an `ldap` URI, a `caIssuers` http URI, five
    OCSP http URIs, an OCSP URI over 2,048 bytes, and an AIA that is not
    valid DER
  - Then the urls passed are only the OCSP `http`/`https` ones, at most
    four, and an empty list for the broken AIA.

- **AC9 — the default changes nothing**
  - Given every corpus file under no settings, `trust/full.settings.json`
    and the programme's lists, with no source and with
    `new NoRevocationSource`
  - Then `toJson()` and `toCrJson()` are byte-identical to this verifier
    before this spec, in every run.

- **AC10 — asked exactly where allowed**
  - Given the same runs with a source that records its calls and returns
    null
  - Then each call belongs to a judged manifest whose signer is trusted and
    whose stored responses gave `skipped`; each such manifest with its
    issuer in `x5chain` is called exactly once; and every
    `signingCredential.ocsp.inaccessible` status belongs to such a call.

- **AC11 — the contract**
  - `Trust\RevocationSource`, `Trust\NoRevocationSource`,
    `StatusCode::SigningCredentialOcspInaccessible`,
    `OcspEvidence::FETCHED` and the new constructor parameter are in
    `public-surface.txt`; the surface test fails without them.

## References

- Specification: C2PA 2.4 §15.9.2 (an online query, its rules,
  `signingCredential.ocsp.inaccessible`); RFC 6960 §3.2 (accepting a
  response), RFC 5280 §4.2.2.1 (authority information access).
- Decision: ADR-0007 (accepted 2026-10-27), ADR-0005 (and the exception
  ADR-0007 makes beside it).
- Measured: the statuses, hashes, times and the Google AIA url above, over
  the verifier at `c0eaf42`; `c2patool` 0.28.3 with `verify.ocsp_fetch`
  (step 471).
- Read: `c2pa` 0.91.1 `crypto/cose/ocsp.rs` (`check_ocsp_status`,
  `fetch_and_check_ocsp_response`) for the order and the
  `inaccessible` code.
- Oracle: `c2patool` with `verify.ocsp_fetch` for the codes, not for the
  verdict where ADR-0007 departs from it; recorded responses for the tests.

## API sketch

```php
namespace Provemark\C2paVerifier\Trust;

interface RevocationSource
{
    /**
     * An OCSP response about $subject, or null.
     *
     * @param  string  $subject  DER of the signer's certificate
     * @param  string  $issuer  DER of its issuer
     * @param  list<string>  $urls  the OCSP responder urls of $subject's AIA (http/https, at most four)
     * @return string|null  DER of an OCSP response; untrusted, read like any stored one
     */
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string;
}

final readonly class NoRevocationSource implements RevocationSource
{
    public function ocspResponse(string $subject, string $issuer, array $urls): ?string
    {
        return null;
    }
}

// OcspEvidence gains: public const FETCHED = 'fetched';
// StatusCode gains:   case SigningCredentialOcspInaccessible = 'signingCredential.ocsp.inaccessible';
// Verifier::__construct gains, last: private ?RevocationSource $revocationSource = null
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
| AC11                 |                             |                      |
