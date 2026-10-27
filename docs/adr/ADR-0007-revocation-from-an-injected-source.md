# ADR-0007: Revocation data from a source the caller injects

| Field    | Value                          |
|----------|--------------------------------|
| Status   | proposed                       |
| Date     | 2026-10-27                     |
| Decided  | —                              |

## Context

One of this project's fixed design rules is **no network in the
verification path** (the README's design decisions; SPEC-014: revocation
and the trust list are "never in the verification path"). The verifier
judges what the file carries and what the caller hands it, and nothing
else. For revocation that means: the OCSP responses stapled in `rVals`
(SPEC-030) and those in `c2pa.certificate-status` assertions (SPEC-066).
A file that carries none is `signingCredential.ocsp.skipped`, always.

That leaves a gap. A certificate that was revoked after signing, or a
signer whose CA was compromised, stays `Trusted` here as long as the file
staples nothing, and most files staple nothing. C2PA 2.4 §15.9.2 lets a
validator query the responder online when no stored response proves
anything, and defines `signingCredential.ocsp.inaccessible` for a query
that gets no answer (`docs/reading-c2pa-2.4.md`, rows 887–890: today *by
design* and *n/a*, because no query is ever made). An independent
analysis names optional revocation checking as a weak spot of C2PA
verifiers (Krawetz, arXiv 2604.24890).

What `c2patool` does, measured on 2026-10-27 with `c2patool` 0.28.3
(`c2pa` 0.91.6) on the writer files under `tests/Fixtures/writers/`, with
the programme's trust lists as `trust_anchors`, and read in the `c2pa`
0.91.1 source:

- **Default off.** The setting is `verify.ocsp_fetch`, `false` by default
  (source: `settings/mod.rs`). Without the key, and with it `false`, the
  results are identical: `signingCredential.ocsp.skipped`, "OCSP fetching
  skipped", and no network request.
- **Order.** A stapled response that proves `notRevoked` or `revoked`
  ends the check; otherwise, with fetching on, `c2pa-rs` queries each CA
  of the chain against its own AIA responder (a revoked CA makes the
  signer `signingCredential.untrusted`), then the signer
  (`crypto/cose/ocsp.rs`, `fetch_and_check_ocsp_response`).
- **With fetching on:** `google-20250919-pixel10-npld-picnic-table.jpg`
  goes from `ocsp.skipped` to `signingCredential.ocsp.notRevoked` (a live
  answer, about 3 seconds); `microsoft-20260609-bing-fast-heartbeat.jpg`
  goes to `signingCredential.ocsp.inaccessible`; the OpenAI file keeps its
  stapled `notRevoked`; the Adobe and Amazon files, whose roots are not on
  those lists, get no `ocsp.*` code either way.
- **A side effect.** With fetching on, `c2pa-rs` validates the time-stamp
  a second time on the fetch path and logs what it finds:
  `trustnxt-20260113-icon-signed-timestamp.jpg` gains a second
  `timeStamp.validated`, a `timeStamp.untrusted` and a failure
  `signingCredential.invalid` ("certificate missing mandatory EKU"), and
  its verdict falls from `Valid` to `Invalid` — while no response was
  fetched at all (`ocsp.inaccessible`). The Bing file gains the same two
  time-stamp lines.

## Decision (proposed)

**The verifier accepts revocation data from a source the caller injects.
The package itself still opens no connection.**

1. **An interface, default off.** `Trust` gains an interface — for
   example `RevocationSource::ocspResponse(Certificate $subject,
   Certificate $issuer): ?string`, the DER of an OCSP response or null —
   and one implementation, which returns null and is the default. With
   the default, every verdict, status and report is byte-identical to
   today's; a corpus run before and after is the proof.
2. **No network code in this package.** No HTTP client, no socket, no
   URL is followed. A caller that wants online checks writes or installs
   the source; a separate package can provide one and keep the bytes it
   fetched.
3. **The report says so.** A response from the source is marked as
   fetched, in the evidence of SPEC-085 as a third source beside `rVals`
   and `assertion`, and in the status line's explanation. A verdict that
   rests on a fetched response shows it.
4. **The same tests as a stapled response.** A fetched response must be
   about the certificate, verify under a responder tied to its issuer,
   and be current at the judged time (SPEC-030, SPEC-066, RFC 6960 §3.2).
   The source is asked only where §15.9.2 allows it — when no stored
   response proves anything — in `c2pa-rs`'s order. A source that returns
   nothing gives `signingCredential.ocsp.inaccessible`. Bytes from the
   source are untrusted input, bounded like any other.
5. **Deterministic tests.** Tests use a fake source that returns recorded
   responses. No test touches the network.
6. **Online lookups touch revocation only.** Asking the source may change
   the `signingCredential.ocsp.*` status, and a revoked CA may make the
   signer `signingCredential.untrusted`; it changes nothing else. The
   side effect measured above — a second time-stamp validation that adds
   a `signingCredential.invalid` — is not copied (open question 1).

The first spec under this ADR covers the signer's certificate; the CAs of
the path through their AIA responders may follow as a second.

## Open questions

1. **Not copying `c2patool`'s side effect.** Point 6 makes the verdict on
   the opt-in path differ from `c2patool`'s where `c2pa-rs` logs extra
   time-stamp and profile lines when fetching is on (the TrustNXT file:
   `Invalid` there, `Valid` here, with nothing fetched). ADR-0005 allows a
   difference only where strictness protects a verdict; this one is the
   other way round — more lenient than `c2patool`, on a path where the
   extra failure has nothing to do with revocation. Proposal: accept the
   difference for this opt-in path, record it in `docs/comparison.md`,
   and report the side effect upstream only if Maurice wants that.
2. **Where the source is passed.** On the `Verifier` constructor (once
   per verifier) or as an argument of `verify()` (per call). Proposal: the
   constructor, beside the other collaborators, so `verify()`'s signature
   stays as it is; the spec decides.

## Consequences

- The fixed rule reads, after acceptance: *no network in this package;
  revocation data from outside only through a source the caller injects,
  default off.* The README and SPEC-014's out-of-scope line are updated in
  the spec that builds it.
- Verdicts with a source depend on when they are made and on what the
  responder answers. That is the nature of online revocation, and the
  report shows it.
- Privacy: an OCSP query tells the CA which certificate, and so roughly
  which file, is being checked. That is the caller's choice, and the
  reason it stays off by default.
- The WebAssembly demo cannot use a source (most responders send no CORS
  headers); it keeps the default.
- Remote manifests and external data (cloud data, external references)
  stay out: their URLs are chosen by the file's author, with the risks
  C2PA itself lists (server-side request forgery, redirects, DNS
  rebinding).
