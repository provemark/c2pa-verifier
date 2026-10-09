# SPEC-066: Revocation beyond the signer's own staple — CA certificates and certificate-status assertions

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

SPEC-030 reads the OCSP responses a signer staples in the COSE `rVals`
header, about the signer's own certificate. C2PA 2.4 §15.9 asks for more,
and two parts of it need no network:

- **A. CA certificates.** *"Discovery of the revocation status of the
  signer's certificate and all CA certificates that are part of the trust
  chain is important."* The validator *should* use OCSP responses in the
  manifest about a CA. §14.5.2 says a claim generator *should* staple
  them for the intermediates it includes. *"If the validator determines
  that a CA certificate was revoked at the time indicated in a trusted
  time-stamp, or at the current time if no trusted time-stamp is present,
  then the claim signature shall be rejected with a failure status of
  `signingCredential.untrusted`."*
- **B. Certificate-status assertions.** *"If subsequent claim generators
  added certificate status assertions in other C2PA Manifests in the C2PA
  Manifest Store, the validator shall use those OCSP response(s)... If
  more than one OCSP response for the certificate is found, the validator
  shall try each one until one successfully passes validation."*
  (§18.19: `c2pa.certificate-status`, `{ "ocspVals": [1* bstr] }`.)

This verifier does neither (candidates P05-5, P05-6). `c2pa-rs` 0.91.1
matches stapled responses to the end-entity certificate only
(`cert_id_matches_signer`). It collects certificate-status assertions
(`store.rs`), but uses them only when `builder.certificate_status_should_override`
is set, and that setting defaults to off. So `c2patool` ignores both:
adopting them is stricter than `c2patool`, by the specification's rule.

## Scope

**In scope**

1. **A — a CA in the path.** For each CA certificate of the path the trust
   check built from the leaf towards the anchor, the anchor itself
   excluded (it is trusted by configuration), every stapled `rVals`
   response is offered with that CA as the subject and the next
   certificate of the path as its issuer. `OcspCheck`'s rules hold
   unchanged: the CertID must match, the response must verify under a
   responder tied to that issuer (RFC 6960 §4.2.2.2), the time window must
   hold. Then:
   - a verified `revoked` (not `removeFromCRL`) at the judged time makes
     the path reach no anchor: `signingCredential.untrusted` naming the CA;
   - anything else about a CA adds no status. §15.9 defines no success
     code for a CA, and an unverifiable response may never fail a file
     (SPEC-030 rule 2).
2. **B — certificate-status assertions.** The OCSP responses of every
   `c2pa.certificate-status` assertion the claim of any manifest in the
   store lists join the pool `OcspCheck` reads for a signer, after that
   signer's own `rVals`. A response applies to a certificate only when its
   CertID names it. The CertID is self-identifying, so it does not matter
   which manifest carries the response. As SPEC-030 already does, a
   verified `revoked` wins; otherwise the first verified `good` gives
   `signingCredential.ocsp.notRevoked`, with an explanation that names the
   assertion it came from. This holds for the active manifest's signer and
   for every ingredient manifest's signer this verifier validates. An
   assertion that does not decode adds nothing (step 322: its shape is not
   judged).
3. The same limits as SPEC-030 (`DEFAULT_MAX_RESPONSES`,
   `DEFAULT_MAX_RESPONSE_BYTES`), counted over the pool.
4. Probes from `bin/make-ocsp-variants.php`, grown: responses about an
   intermediate (good, revoked), and files carrying a certificate-status
   assertion, judged by both `c2patool` versions.

**Out of scope** (each needs its own spec before it may be built)

- Online OCSP, AIA lookups and CRLs (§15.9.2): no network in the
  verification path, by rule.
- The trust anchor's own revocation.
- The five `notRevoked`/`skipped` timing differences of candidate P05-7.

## Behavior

- **AC1 — a revoked intermediate leaves the path untrusted** *(error path)*
  - Given a chain leaf ← intermediate ← anchor, with a stapled response
    from the anchor's responder that the intermediate is revoked (reason
    keyCompromise) before the judged time
  - When verified with the anchor in the settings
  - Then `signingCredential.untrusted` naming the intermediate and OCSP,
    and the state is not `Trusted`

- **AC2 — a good, removed or unverifiable answer about a CA changes nothing**
  - Given the same chain with an intermediate `good`, `removeFromCRL`, a
    response with a broken signature, and one about another certificate
  - Then the state is as without the response, and no status names the CA

- **AC3 — a certificate-status assertion's `revoked` is a failure** *(error path)*
  - Given a later manifest whose `c2pa.certificate-status` holds a
    verified response that an earlier manifest's signer is revoked
  - Then `signingCredential.ocsp.revoked` for that manifest, `Invalid`

- **AC4 — a certificate-status assertion's `good` is notRevoked**
  - Given the same with a `good` response, and no `rVals` on the signer
  - Then `signingCredential.ocsp.notRevoked` whose explanation names the
    assertion, where SPEC-030 gave `signingCredential.ocsp.skipped`, and
    the state is unchanged

- **AC5 — several responses: each is tried**
  - Given an assertion with a response about another certificate, then
    one about the signer
  - Then the second is used

- **AC6 — the oracles, and never more lenient**
  - Both `c2patool` versions judge every probe. AC1 and AC3 are stricter
    than `c2patool`, named. No probe is more lenient than 0.28.1.

- **AC7 — nothing that passed stops passing**
  - The corpus, before and after: a file without such responses keeps its
    verdict and its codes.

## References

- Specification: C2PA 2.4 §15.9, §15.9.1, §14.5.2, §18.19; RFC 6960
  §2.2, §4.2.1, §4.2.2.2, §3.2.
- Oracle: `c2patool` 0.28.1 and 0.27.22 on the probes.
- Read: `c2pa-rs` 0.91.1 `crypto/ocsp/mod.rs` (`cert_id_matches_signer`),
  `store.rs` (the collection into `svi.certificate_statuses`),
  `settings/builder.rs` (`certificate_status_should_override`, off).

## API sketch

```php
namespace Provemark\C2paVerifier\Trust;

/** OcspCheck grows two seams; internal (SPEC-025). */
final readonly class OcspCheck
{
    /** @param list<string> $extra DER responses from certificate-status assertions, with their source urls */
    public function check(array $unprotected, array $chain, ?int $at, string $url, array $extra = []): array;

    /** @param list<Certificate> $path anchor first, leaf last @return ?string why a CA is revoked, or null */
    public function revokedCa(array $unprotected, array $path, ?int $at): ?string;
}
```

## Open questions

- 1. **The anchor.** Excluded: an anchor is trusted by configuration, and
  its revocation is the operator's to manage. Proposal: as written.
- 2. **A response in a certificate-status assertion about the manifest's
  own signer.** §15.9 speaks of *"other C2PA Manifests"*. `c2pa-rs` binds
  the assertion to its own manifest's signer. Matching by CertID covers
  both readings. Proposal: as written.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
