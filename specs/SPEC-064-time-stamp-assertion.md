# SPEC-064: The time-stamp assertion — a later trusted time for an earlier manifest

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-09                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A manifest signed offline has no timestamp in its COSE header, and its
signer's certificate will expire. C2PA 2.4 §18.18 lets a later manifest
carry a `c2pa.time-stamp` assertion: a map from a manifest's label to an
RFC 3161 token over that manifest's COSE `signature` field. It is a proof
of existence obtained while the certificate was still valid. §15.8.1.2
tells the validator what to do with it:

- skip it when the manifest's own `sigTst`/`sigTst2` token passed
  validation (§15.8.2);
- otherwise look the manifest's label up among the tokens of every
  time-stamp assertion, and try each until one passes §15.8.2. Its time is
  then the manifest's trusted time.

§15.10.3.2.6 adds the shape rule. The assertion is one CBOR map with at
least one pair, else `assertion.timestamp.malformed` and the claim is
rejected. §18.18.2–3 add that each key is a manifest label, each value a
byte string, and that a manifest holds at most one.

This verifier reads none of it (issue #6, candidates P05-2, P06-2, P09-2,
P09-4). That costs two things:

- **Stricter:** a manifest that a later manifest time-stamped while its
  certificate was valid is judged at *now* here, so it is `expired`.
- **Laxer:** a malformed time-stamp assertion passes here. `c2pa-rs`
  stops with `assertion.timestamp.malformed`.

`c2pa-rs` 0.91.1 implements it (`store.rs` collects the tokens of every
manifest's time-stamp assertions into `svi.timestamps`; `claim.rs` passes
them to `verify_cose`). It differs from §15.8.1.2 in one place: a token
from an assertion *replaces* the header's token, even one that passed.

## Scope

**In scope**

1. **Collect.** Every `c2pa.time-stamp` assertion (any instance) listed by
   the claim of any manifest in the store, as `c2pa-rs` does. Its shape:
   a CBOR map with at least one pair, each key text in the form of a
   manifest label, each value a byte string. Anything else is
   `assertion.timestamp.malformed`, a failure of the manifest that holds
   it. A second time-stamp assertion in one manifest is also malformed
   (§18.18.3: *"at most one"*; open question 1). An assertion whose hashed
   URI did not match is left unread.
2. **Use, in §15.8.1.2's order.** For each manifest this verifier
   validates (the active one and every ingredient manifest it reaches):
   - when its header token passed (`timeStamp.validated` and
     `timeStamp.trusted`), that token's time stands and the assertions
     are not consulted;
   - otherwise each token keyed by its label is checked by
     `TimestampCheck` as a header token is, against the manifest's own
     COSE `signature` field (the v2 payload, §10.3.2.5). The first that
     passes gives the manifest its trusted time, used for the signer's
     validity (SPEC-017) and OCSP (SPEC-030).
3. **Report.** The token used is reported with the `timeStamp.*` codes a
   header token gets. Their url names the time-stamp assertion that held
   it, so a reader can tell the two sources apart. Tokens tried and
   failed are reported as informational, as a failed header token is.
4. **Trust.** The TSA must chain to a `trust_anchors` entry with the
   timeStamping EKU, as for a header token (SPEC-017, SPEC-031). No
   exception for version 1 manifests. `c2pa-rs` skips the trust check
   there; this spec does not.
5. **Probes.** A builder makes the fixtures: tokens from a throw-away TSA
   through `openssl ts`, as `bin/make-tsa-matrix.php` does, set as byte
   strings into a time-stamp assertion of an update manifest whose parent
   carries no header token. Both `c2patool` versions judge each.

**Out of scope** (each needs its own spec before it may be built)

- Fetching a token, or adding one: this verifier never writes.
- `c2pa-rs`'s precedence (an assertion's token replacing a passed header
  token). §15.8.1.2 says the opposite, and this spec follows the
  specification; the difference is named in AC6.
- A time-stamp assertion keyed by a label that no manifest in the store
  carries: it is collected and never used.

## Behavior

- **AC1 — a later token keeps an earlier manifest alive**
  - Given an update manifest whose `c2pa.time-stamp` holds a trusted token
    for its parent, taken while the parent's signer was valid; the
    parent has no header token, and its signer has since expired
  - When verified with the throw-away TSA's anchor in the settings
  - Then the parent is judged at the token's time: no
    `signingCredential.expired` for it, and `timeStamp.validated` and
    `timeStamp.trusted` whose url is the assertion

- **AC2 — without the assertion, the same parent is expired** *(control)*
  - Given the same files with the assertion's token for another label
  - Then the parent is `signingCredential.expired`, as today

- **AC3 — an untrusted or mismatched token does not help** *(error path)*
  - Given the assertion with a token from a TSA not in the settings, and
    one over other bytes
  - Then `timeStamp.untrusted` or `timeStamp.mismatch` (informational),
    and the parent is judged at now: `signingCredential.expired`

- **AC4 — a malformed assertion is a failure** *(error path)*
  - Given a time-stamp assertion that is a CBOR array, an empty map, a map
    whose value is text, and a second time-stamp assertion in one manifest
  - Then `assertion.timestamp.malformed` for the manifest that holds it,
    and the state is `Invalid`

- **AC5 — a header token that passed is not replaced**
  - Given a manifest whose header token passed, and an assertion holding
    another valid token for it, at a time outside the signer's validity
  - Then the header token's time stands. The manifest is not expired, and
    no status names the assertion as its timestamp

- **AC6 — the oracles, and the named difference**
  - Each probe's state as both `c2patool` versions give it, never more
    lenient than 0.28.1. AC5's probe is the named difference: `c2pa-rs`
    takes the assertion's token and calls the signer expired.

- **AC7 — the vocabulary grows by one code, verbatim**
  - `assertion.timestamp.malformed` in `StatusCode`, a failure.

## References

- Specification: C2PA 2.4 §15.8.1.2, §15.8.2, §15.10.3.2.6, §18.18
  (§18.18.2 schema, §18.18.3 requirements), §10.3.2.5 (the payload).
- Oracle: `c2patool` 0.28.1 and 0.27.22 on the builder's fixtures, with
  their settings.
- Read: `c2pa-rs` 0.91.1 `store.rs` (the collection into
  `svi.timestamps`), `claim.rs` (passed to `verify_cose`),
  `cose_validator.rs` (`tst_info` overrides the header),
  `assertions/timestamp.rs`.
- Reasoned: that the source manifest of a token need not be trusted
  itself. A token is self-authenticating: the TSA's signature over the
  named manifest's signature. That is why `c2pa-rs` and this spec take
  tokens from any manifest in the store.

## API sketch

```php
namespace Provemark\C2paVerifier\Timestamp;

/** @internal SPEC-025 */
final readonly class TimestampAssertions
{
    /** label => list of [token DER, the assertion's url], from every manifest; and the malformed statuses */
    public static function collect(ManifestStore $store): self;

    /** @return list<array{0: string, 1: string}> */
    public function tokensFor(string $manifestLabel): array;

    /** @return list<ValidationStatus> */
    public function faults(): array;
}
```

`TimestampCheck` gains a path that takes a token and an url instead of
the header.

## Open questions

- 1. **A second time-stamp assertion in one manifest.** §18.18.3 says *"at
  most one"*; `c2pa-rs` reads every instance and refuses none. Proposal:
  malformed, fail closed. That is stricter than `c2patool`, and named.
  *Status 2026-10-09 (step 332):* decided by Maurice van Loon with the approval: as proposed.
- 2. **The codes for a token from an assertion.** `c2pa-rs` reports none
  (the assertion's tokens are checked into a scratch log that is
  dropped). Proposal: report them, with the assertion's url, as §15.8.2
  says a validator *"shall issue"* them.
  *Status 2026-10-09 (step 332):* decided by Maurice van Loon with the approval: as proposed.

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
