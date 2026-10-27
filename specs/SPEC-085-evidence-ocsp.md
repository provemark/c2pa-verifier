# SPEC-085: Evidence in the report — the OCSP responses offered

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

An OCSP response is the issuer's signed statement that a certificate was not
revoked at a given moment, or that it was, or that its status is unknown
(RFC 6960). A manifest can carry such responses in the claim signature's
unprotected `rVals` header (C2PA 2.4 §14; SPEC-030) and in
`c2pa.certificate-status` assertions (§15.9, §18.19; SPEC-066). The
verifier offers them all to the signer's chain. A response counts only when
it is about that certificate, signed by a responder tied to its issuer, and
current at the time judged at. A revoked answer wins; otherwise the first
usable one is chosen. One status follows: `signingCredential.ocsp.notRevoked`,
`.revoked`, `.unknown` or `.skipped`.

The report hands out that status, not the response behind it. An archive
that wants to show later that the certificate was not revoked when the file
arrived needs the signed response itself: an issuer only has to answer OCSP
until a year after the certificate expires (C2PA Certificate Policy), and
after that the response in the file is the only evidence left.

This is the fourth of four specs that hand out the evidence behind a verdict
(candidate C1 of the revocation-and-evidence proposal, split by Maurice's
decision of 2026-10-27).

Measured on 2026-10-27 (the verifier at `13839bf`, over every file under
`tests/Fixtures/`, 755 manifests in `evidence()` under no settings):

- 18 manifests in 16 files staple one response each in `rVals`, from real
  writers (OpenAI, Adobe, Amazon, `c2pa-rs`). Every one is readable, about
  the signer and verified under its issuer's responder, and every one is
  `signingCredential.ocsp.skipped` today: each was current for a week, and
  the judged time is now.
- With a validation time inside that week (SPEC-073), the same response is
  `signingCredential.ocsp.notRevoked`:
  `writers/openai-20260826-c2pa_2x.png` at 2026-08-26T12:00:00Z (the
  response valid from 2026-08-25T02:43:57Z to 2026-09-01T02:43:56Z), and
  `writers/adobe-20260425-lightroom-classic-church.jpg` at
  2026-04-25T12:00:00Z.
- 5 stores carry 7 responses in certificate-status assertions;
  `c2pa-rs/ocsp_with_assertion.jpg` offers its manifests a stapled response,
  an assertion response about another certificate, and a second usable
  assertion response that is not chosen.

## Scope

**In scope**

- `ManifestEvidence::$ocsp`: a `list<OcspEvidence>`, one entry per response
  in the pool the signer's revocation check walks for that manifest, in its
  order — the stapled responses, then those of the store's certificate-status
  assertions (at most four of each, as `OcspCheck` takes them) — for the same
  manifests as `evidence()` (SPEC-082).
- A new contract class `Trust\OcspEvidence` (in `Trust`, where `OcspCheck`
  is), with:
  - `source`: `rVals` or `assertion`;
  - `url`: the claim signature's url for a stapled response, the
    assertion's url for an assertion's;
  - `response`: the response's DER, exactly as the file carries it;
  - `outcome`: `chosen` (the response the signer was judged by), `notChosen`
    (usable, but another was chosen), `unusable` (it cannot be read, is not
    successful, is about another certificate, or does not verify under a
    responder tied to the signer's issuer; also every response when the
    signer's issuer is not in `x5chain`, so none can be matched), or
    `notTried` (after a revoked answer the check stops);
  - `code`: the `StatusCode` the chosen response gave — `notRevoked`,
    `revoked`, `unknown`, or `skipped` when it was not current or reported a
    revocation that does not count at the judged time — and null for every
    other entry;
  - `certStatus`: `good`, `revoked` or `unknown`; `producedAt`,
    `thisUpdate`, `nextUpdate` in seconds since the epoch (`nextUpdate`
    null when the response has none): set for `chosen` and `notChosen`,
    null otherwise.
- A new last constructor parameter of `ManifestEvidence`, optional, so the
  contract (SPEC-025) grows by one class and one property, in the same
  minor as SPEC-081 to SPEC-084.

**Out of scope** (each needs its own spec before it may be built)

- An `rVals` header that cannot be read (not a map, empty, no `ocspVals`,
  more than four responses, an entry that is not a byte string or is over
  the size limit): its responses are not listed; the `skipped` status says
  why. Assertion responses are still listed.
- How a stapled response served the check of a CA in the path (SPEC-066):
  its bytes are in the list; its use there is not.
- A reason text per response: explanations are sentences, not contract.
- Fetching responses over a network (candidate C3, ADR-0007 first); CRLs.
- The responses in `toArray()`, `toJson()`, `toCrJson()` or the CLI.

## Behavior

- **AC1 — a current response, chosen**
  - Given `writers/openai-20260826-c2pa_2x.png` at the validation time
    2026-08-26T12:00:00Z
  - Then the active manifest has one entry: `rVals`, the signature's url, a
    response of 2,007 bytes with SHA-256
    `f440effccf83491c4cbf3894e41609d213f8c09cad509cca4a8dd214bb5bd4e6`,
    `chosen`, code `signingCredential.ocsp.notRevoked`, `good`, produced and
    this update 2026-08-25T02:43:57Z, next update 2026-09-01T02:43:56Z.

- **AC2 — the same response, no longer current**
  - Given the same file with no validation time
  - Then the same entry, with code `signingCredential.ocsp.skipped`: the
    bytes and the times are what an archive keeps.

- **AC3 — chosen, not chosen, and unusable**
  - Given `c2pa-rs/ocsp_with_assertion.jpg`
  - Then the active manifest (`adobe:urn:uuid:8208d145-…`) has three
    entries: the stapled response (`d0a06525…`) `chosen` with code
    `signingCredential.ocsp.skipped`; the assertion response `ed5c7195…`
    `unusable`; the assertion response `29424eae…` `notChosen`, `good`,
    this update 2025-07-16T21:41:26Z. Both assertion entries carry the url
    of the certificate-status assertion of `urn:uuid:b82c36da-…`.

- **AC4 — a revocation in an assertion**
  - Given `revocation/cs-two.png` with
    `revocation/throw-away-root.settings.json`
  - Then the ingredient manifest (`urn:c2pa:f0970afc-…`) has two entries:
    `c9befada…` `unusable`, and `533def49…` `chosen` with code
    `signingCredential.ocsp.revoked`, `revoked`; the active manifest has the
    same two responses, both `unusable`, and no entry is `chosen`.

- **AC5 — a revoked answer stops the check**
  - Given the signer and issuer of `tests/Fixtures/ocsp/` and the stapled
    responses `revoked.der` then `good.der`; and `good.der` then
    `revoked.der`
  - Then the first gives `revoked.der` `chosen` (code `revoked`) and
    `good.der` `notTried` with no status or times; the second gives
    `good.der` `notChosen` and `revoked.der` `chosen`.

- **AC6 — nothing usable** *(required: error / malformed input)*
  - Given the signer and issuer of `tests/Fixtures/ocsp/` with
    `other-good.der` stapled, a stapled response that is not DER, and
    `good.der` stapled to a chain of one certificate
  - Then one entry each, `unusable`, with the response's bytes, no code, no
    status and no times; nothing throws.

- **AC7 — nothing to list** *(required: error / malformed input)*
  - Given `timestamp/tsa-matrix/control.png` (no response anywhere), and an
    `rVals` that is a list rather than a map with no assertion responses
  - Then `ocsp` is empty.

- **AC8 — the evidence agrees with the verdict and the statuses**
  - Given every corpus file under no settings, `trust/full.settings.json`
    and the programme's lists
  - Then for every manifest in `evidence()`: at most one entry is `chosen`,
    and one is exactly when an entry is `chosen` or `notChosen`; `notTried`
    entries follow only a `chosen` one whose status is `revoked`; status and
    times are set exactly for `chosen` and `notChosen`; and for the active
    manifest, the chosen entry's code is the report's
    `signingCredential.ocsp.*` status of its signature, and without a chosen
    entry that status is `skipped`.

- **AC9 — the verdict does not move**
  - Given the same runs as AC8
  - Then `toJson()` and `toCrJson()` are byte-identical before and after
    this spec.

- **AC10 — the contract**
  - `Trust\OcspEvidence` and `ManifestEvidence :: property ocsp` are in
    `public-surface.txt`; the surface test fails without them.

## References

- Specification: C2PA 2.4 §14 (the unprotected header), §15.9 (revocation
  and its status codes), §18.19 (the certificate-status assertion); RFC 6960
  §4.2 (the response and its times), RFC 5019 §3.2 (current); C2PA
  Certificate Policy (how long a responder must answer).
- Measured: the counts, hashes and times above, with `OcspCheck`'s pool and
  its reading of each response over the verifier at `13839bf` (2026-10-27).
- Oracle: none for the evidence itself; AC8 holds it to the verdict and the
  statuses, AC9 to this verifier before the change.

## API sketch

```php
namespace Provemark\C2paVerifier\Trust;

use Provemark\C2paVerifier\Report\StatusCode;

final readonly class OcspEvidence
{
    public const RVALS = 'rVals';
    public const ASSERTION = 'assertion';

    public const CHOSEN = 'chosen';
    public const NOT_CHOSEN = 'notChosen';
    public const UNUSABLE = 'unusable';
    public const NOT_TRIED = 'notTried';

    public function __construct(
        public string $source,
        public string $url,
        public string $response,
        public string $outcome,
        public ?StatusCode $code,
        public ?string $certStatus,
        public ?int $producedAt,
        public ?int $thisUpdate,
        public ?int $nextUpdate,
    ) {}
}

// Verifier\ManifestEvidence gains, after $timestamps:
//     /** @var list<OcspEvidence> */
//     public array $ocsp = [],
```

## Open questions

None.

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
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
