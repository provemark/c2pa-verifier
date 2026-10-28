# SPEC-085: Evidence in the report — the OCSP responses offered

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
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

- 18 manifests in 16 files staple one response each in `rVals`
  (amendment 1). The 7 from real writers (OpenAI, Adobe, Amazon,
  `c2pa-rs`) are each readable, about the signer and verified under its
  issuer's responder, and each is `signingCredential.ocsp.skipped` today:
  each was current for a week, and the judged time is now. 7 probes staple
  a response about a CA of the path, unusable for the signer (SPEC-066); 4
  `ingredient-staple/` probes give `notRevoked` or `revoked`.
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
    responder tied to the signer's issuer), or `notTried` (not read: after
    a revoked answer the check stops, or the signer's issuer is not in
    `x5chain`, so none can be matched; amendment 2);
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

## Amendments

1. **2026-10-27, step 470, approved by Maurice van Loon ("akkoord").** A
   measurement in *Problem* that the build showed to be wrong.
   - **Not every stapled response is from a real writer, or usable.**
     *Problem* says the 18 manifests with a stapled response are all from
     real writers, each readable, about the signer, verified and `skipped`
     today. The build's corpus run (every manifest with `rVals`, its
     evidence and its status) shows three groups:
     - 7 manifests from real writers (`writers/openai-20260826-c2pa_2x.png`,
       `writers/adobe-20260425-lightroom-classic-church.jpg`,
       `writers/amazon-20240925-titan-g1.png`, `c2pa-rs/ocsp.jpg` twice,
       `c2pa-rs/ocsp_with_assertion.jpg` twice): usable, chosen, and
       `skipped` today because their week has passed — what *Problem*
       claims, for these;
     - 7 probes whose stapled response is about a CA in the path, not the
       signer (`revocation/ca-*.png`, `anchor-in-chain/*-ca-*.png`):
       `unusable` for the signer, as SPEC-066 made them;
     - 4 `ingredient-staple/` probes: usable, chosen, `notRevoked` or
       `revoked`.
     The claim was generalised from five of the files. Decided: *Problem*
     reads as above. No acceptance criterion changes.

2. **2026-10-28, step 486, found by the review of step 482; measured** *(approved by Maurice van Loon, 2026-10-28)* —
   When the signer's issuer is not in `x5chain`, no stored response can
   be matched to the signer, so none is read. *Design* nevertheless
   called every such response `unusable`, which means read and refused:
   a response that is fine, but could not be checked, looked the same as
   one that is broken or about another certificate. A reader keeping the
   evidence (the reason for this spec) drew the wrong conclusion.

   Such responses are now **`notTried`**. *Design*'s `outcome` reads:
   `unusable` (it cannot be read, is not successful, is about another
   certificate, or does not verify under a responder tied to the signer's
   issuer), or `notTried` (not read: after a revoked answer the check
   stops, or the signer's issuer is not in `x5chain`). AC6's third case,
   `good.der` stapled to a chain of one certificate, gives one entry
   `notTried`, with the response's bytes, no code, no status and no
   times. AC8's rule on `notTried` reads: a manifest whose `x5chain` holds
   fewer than two certificates has every stored entry `notTried`;
   otherwise a `notTried` entry follows only a `chosen` one whose status is
   `revoked`. A `fetched` entry (SPEC-086) is not a stored one: since
   SPEC-086 amendment 2 the source may still be asked, with the issuer the
   walk verified, and its answer chosen.

   No verdict and no status changes; only the outcome in the evidence.
   No corpus file reached the case before. Measured with
   `bin/make-revocation-notried-variants.php`: signer L issued by the
   throw-away root R, `x5chain` [L], L's `good` stapled. `c2patool` 0.28.3
   calls it `Trusted`; here it is `Trusted` with
   `signingCredential.ocsp.skipped` and the staple `notTried`, and with a
   source answering L revoked `Invalid`, the staple `notTried` and the
   fetched response `chosen`. Tests: `EvidenceOcspTest.php` (AC6, AC8)
   and `tests/Unit/Verifier/RevocationNotTriedTest.php`, group `SPEC-085`.

   **Weight C: the evidence says what happened; no verdict changes.**

   *Since SPEC-030 amendment 5 (step 490):* a trusted signer's stored
   responses are read against the issuer the chain walk verified, so
   AC8's rule on fewer than two certificates applies to a signer that is
   not trusted; a trusted signer without a verified issuer has every
   stored entry `notTried` too.

## Traceability

All in `tests/Unit/Verifier/EvidenceOcspTest.php`, group `SPEC-085`,
unless named otherwise. Seen red before the implementation (all 14 tests,
on the missing class and method). One test for a *Scope* line no corpus
file shows was added after the build, when mutation M8 survived; it now
fails under that mutation.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | AC1: a current response, chosen | src/Trust/OcspCheck.php :: checkWithEvidence(), evidence(); src/Verifier/Verifier.php :: check() |
| AC2                  | AC2: the same response, no longer current, keeps its bytes and times | src/Trust/OcspCheck.php :: checkWithEvidence() |
| AC3                  | AC3: chosen, not chosen and unusable in one manifest | src/Trust/OcspCheck.php :: checkWithEvidence(), evidence() |
| AC4                  | AC4: a revocation in an assertion, for the ingredient and not for the active signer | src/Verifier/IngredientManifestCheck.php :: manifest(), evidenceOf() |
| AC5                  | AC5: a revoked answer stops the check, and wins over an earlier good one | src/Trust/OcspCheck.php :: checkWithEvidence(), evidence() |
| AC6                  | AC6: nothing usable, and nothing thrown (3 datasets) | src/Trust/OcspCheck.php :: checkWithEvidence() |
| AC7                  | AC7: nothing to list | src/Trust/OcspCheck.php :: checkWithEvidence() |
| AC8                  | AC8: over the corpus, the responses agree with the verdict and the statuses (3 datasets) | src/Trust/OcspCheck.php :: checkWithEvidence() |
| AC9                  | AC9: the reports carry no response bytes; measured over the corpus (step 470) | src/Verifier/ManifestEvidence.php |
| AC10                 | AC10: OcspEvidence and ocsp are in the contract; tests/Unit/ApiSurfaceTest.php :: AC1: the recorded surface is exactly what the contract classes expose today | src/Trust/OcspEvidence.php; bin/api-check.php :: apiContract() |
| Scope (an assertion response over the size limit) | scope: an assertion response over the size limit is listed, unusable | src/Trust/OcspCheck.php :: checkWithEvidence() |
| Amendment 1          | measured over the corpus (step 470) | — |
| Amendment 2          | AC6: nothing usable, and nothing thrown (no issuer in the chain); AC8; tests/Unit/Verifier/RevocationNotTriedTest.php | src/Trust/OcspCheck.php :: stored() |
