# SPEC-084: Evidence in the report — the time-stamps found

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

SPEC-082 says which time a manifest was judged at, and the anchor that made
its time-stamp authority trusted, but not the time-stamp itself. A manifest
can be offered time-stamps in two places: the claim signature's `sigTst` or
`sigTst2` header (C2PA 2.4 §10.3.2.5, §15.8.1), and a `c2pa.time-stamp`
assertion, of the same or of another manifest (§18.18). The verifier tries
them in §15.8.1's order and the first token that validates and is trusted
gives the time (SPEC-017, SPEC-064). The others are rejected or never tried,
and only the statuses say so.

An archive that wants to show later which token gave the time, or that a
second token was there and why it did not count, would have to read JUMBF,
CBOR, COSE and CMS again. A token rejected because its authority was not on
the list at the time cannot be judged again if it is not kept.

This is the third of four specs that hand out the evidence behind a verdict
(candidate C1 of the revocation-and-evidence proposal, split by Maurice's
decision of 2026-10-27).

Measured on 2026-10-27 (the verifier at `ebecb10`, over every file under
`tests/Fixtures/`, 755 manifests in `evidence()` under no settings):

- 125 manifests have a header token (78 `sigTst`, 47 `sigTst2`); 13
  manifests are offered 14 tokens by assertions; one manifest has both
  (`timestamp/assertion/header-wins.png`), and its assertion token is never
  tried.
- Two probes carry two tokens in one header
  (`timestamp/tsa-matrix/two-tokens.png`,
  `expired-signer-two-tokens.png`): malformed, none is used (§15.8.1.1).
- Three tokens cannot be read: `c2pa-rs/CA_ct.jpg` (a genTime with minute
  63), `timestamp/tsa-matrix/imprint-sha1.png` and
  `expired-signer-imprint-sha1.png` (a SHA-1 imprint).
- One header cannot be read at all: `timestamp/tsa-matrix/header-both.png`
  carries both `sigTst` and `sigTst2`.
- Every token that can be read (138) carries one to three certificates.

## Scope

**In scope**

- `ManifestEvidence::$timestamps`: a `list<TimestampEvidence>`, one entry
  per token offered for that manifest, for the same manifests as
  `evidence()` (SPEC-082): the header's tokens in header order first, then
  the assertions' tokens in the order SPEC-064 tries them.
- A new contract class `Timestamp\TimestampEvidence` (in `Timestamp`, as
  `AnchorEvidence` is in `Trust`: the check that knows it makes it), with:
  - `source`: `sigTst`, `sigTst2` or `assertion`;
  - `url`: the claim signature's url for a header token, the assertion's
    url for an assertion token;
  - `token`: the token's DER, exactly as the file carries it;
  - `genTime`: the token's time in seconds since the epoch, null when the
    token cannot be read;
  - `certificates`: the DER of the certificates in the token's SignedData,
    in the token's order (RFC 5652 §5.1), empty when the token cannot be
    read;
  - `outcome`: `used` (it gave the time judged at), `rejected` (it was
    judged and did not), or `notTried` (an earlier token already gave the
    time);
  - `codes`: the `timeStamp.*` codes the check gave this token, in order,
    as `StatusCode` values, before the report scopes or drops any line
    (SPEC-021 amendment 1); empty for `notTried`.
- A header with more than one token: each token is listed, `rejected`, with
  `timeStamp.malformed`.
- A token judged over more than one form of the countersigned bytes
  (§18.18.3; SPEC-064 amendment 1) is one entry, with the codes of the last
  form tried.
- A new last constructor parameter of `ManifestEvidence`, optional, so the
  contract (SPEC-025) grows by one class and one property, in the same
  minor as SPEC-081 to SPEC-083.

**Out of scope** (each needs its own spec before it may be built)

- A header that cannot be read (both names, not a map, no `tstTokens`, an
  entry without a byte string, more than eight tokens): no entry; its
  `timeStamp.malformed` status already says why.
- Which form of the countersigned bytes matched.
- Judging a token again against other lists.
- The OCSP responses (the fourth spec of C1).
- The time-stamps in `toArray()`, `toJson()`, `toCrJson()` or the CLI.

## Behavior

- **AC1 — the token that gave the time**
  - Given `timestamp/tsa-matrix/control.png` with its settings
  - Then the active manifest has one entry: `sigTst2`, the signature's url,
    a token of 1,635 bytes with SHA-256
    `16eca41812a7299990c6840b7be210939b6b1eb8369f090da32227039a942a29`,
    `genTime` 2026-10-09T05:30:24Z (equal to `judgedAt`), two certificates
    with SHA-256 `cca52486…` (554 bytes) and `d09e9495…` (532 bytes), and
    outcome `used` with codes `timeStamp.validated`, `timeStamp.trusted`.

- **AC2 — the same token, rejected**
  - Given `timestamp/tsa-matrix/control.png` without settings
  - Then the same token, time and certificates, outcome `rejected`, codes
    `timeStamp.validated`, `timeStamp.untrusted`.

- **AC3 — an assertion's token**
  - Given `timestamp/assertion/raw.png` with its settings
  - Then the ingredient manifest (`urn:c2pa:c7cb9f11-…`) has one entry:
    `assertion`, the url of the active manifest's `c2pa.time-stamp`, a token
    with SHA-256 `0201f3b5…`, `genTime` 2026-10-09T13:38:13Z, outcome
    `used`. With `timestamp/assertion/untrusted.png` and its settings the
    same token is `rejected` with `timeStamp.untrusted`.

- **AC4 — not tried**
  - Given `timestamp/assertion/header-wins.png` with its settings
  - Then the ingredient manifest (`urn:c2pa:488bf983-…`) has two entries:
    its `sigTst2` token (SHA-256 `ed24d4ef…`) `used`, and the assertion's
    token (SHA-256 `af27feaa…`) `notTried` with no codes, but with its
    `genTime` (2026-10-09T13:38:13Z) and its certificate.

- **AC5 — two tokens in one header**
  - Given `timestamp/tsa-matrix/two-tokens.png` with its settings
  - Then two entries (SHA-256 `c04b0795…` and `44401016…`), both
    `rejected` with `timeStamp.malformed`, and the time source is not
    `timestamp`.

- **AC6 — a token that cannot be read** *(required: error / malformed input)*
  - Given `c2pa-rs/CA_ct.jpg`, and `timestamp/tsa-matrix/imprint-sha1.png`
    with its settings
  - Then one entry each, with the token's bytes, `genTime` null, no
    certificates, `rejected` with `timeStamp.malformed`; nothing throws.

- **AC7 — nothing to list** *(required: error / malformed input)*
  - Given `timestamp/tsa-matrix/header-both.png` with its settings, and a
    manifest without any time-stamp (`fixture-signed.png`)
  - Then the active manifest's `timestamps` is empty.

- **AC8 — the evidence agrees with the verdict and the statuses**
  - Given every corpus file under no settings, `trust/full.settings.json`
    and the programme's lists
  - Then for every manifest in `evidence()`: exactly one entry is `used`
    when the time source is `timestamp`, and then its `genTime` is
    `judgedAt`; none is `used` otherwise; no entry follows a `used` one
    except as `notTried`; and for the active manifest every `timeStamp.*`
    status at an entry's url is among that entry's codes, and every entry's
    code is among the statuses at its url.

- **AC9 — the verdict does not move**
  - Given the same runs as AC8
  - Then `toJson()` and `toCrJson()` are byte-identical before and after
    this spec.

- **AC10 — the contract**
  - `Timestamp\TimestampEvidence` and `ManifestEvidence :: property
    timestamps` are in `public-surface.txt`; the surface test fails without
    them.

## References

- Specification: C2PA 2.4 §10.3.2.5 (`sigTst2`), §15.8.1 and §15.8.1.1
  (the order, one token per header), §15.8.2 (the time judged at), §18.18
  (the time-stamp assertion); RFC 3161 §2.4.2 (the token); RFC 5652 §5.1
  (the certificates in SignedData).
- Measured: the counts and hashes above, with `TimestampHeader`,
  `TimestampAssertions` and `TimeStampToken` over the verifier at `ebecb10`
  (2026-10-27).
- Oracle: none for the evidence itself; AC8 holds it to the verdict and the
  statuses, AC9 to this verifier before the change.

## API sketch

```php
namespace Provemark\C2paVerifier\Timestamp;

use Provemark\C2paVerifier\Report\StatusCode;

final readonly class TimestampEvidence
{
    public const SIG_TST = 'sigTst';
    public const SIG_TST2 = 'sigTst2';
    public const ASSERTION = 'assertion';

    public const USED = 'used';
    public const REJECTED = 'rejected';
    public const NOT_TRIED = 'notTried';

    /**
     * @param  list<string>  $certificates  DER, the token's order
     * @param  list<StatusCode>  $codes
     */
    public function __construct(
        public string $source,
        public string $url,
        public string $token,
        public ?int $genTime,
        public array $certificates,
        public string $outcome,
        public array $codes,
    ) {}
}

// Verifier\ManifestEvidence gains, after $certificates:
//     /** @var list<TimestampEvidence> */
//     public array $timestamps = [],
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
