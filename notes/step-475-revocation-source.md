# Step 475 — A revocation source the caller injects, for the signer

*2026-10-27.* SPEC-086 built, under ADR-0007. With the default nothing
changes; with a source, a trusted signer whose file proves nothing about
revocation can now be checked online, without this package opening a
connection. The public contract grows (208 symbols, eighteen classes), so
this waits for the next minor release, with SPEC-081 to SPEC-085.

## Why

Most files carry no OCSP response, and then the answer here was always
`signingCredential.ocsp.skipped`: a certificate revoked after signing
stayed `Trusted`. C2PA 2.4 §15.9.2 allows a validator to ask the responder
online when no stored response proves anything. ADR-0007 allows it here
only through a source the caller hands in.

## What changed

- `Trust\RevocationSource`: one method, `ocspResponse($subject, $issuer,
  $urls)`, the DER of the signer's certificate and its issuer and the
  OCSP urls of the certificate's AIA extension; it returns an OCSP
  response or null. `Trust\NoRevocationSource` is the default and is never
  asked. The `Verifier` takes the source as its last constructor
  parameter, `revocationSource`.
- **When it is asked.** Once per judged manifest, active and ingredient
  alike, only when the signer is trusted (`signingCredential.trusted`),
  the stored responses gave `skipped`, and the issuer is in `x5chain`.
- **What becomes of the answer.** `OcspCheck::checkWithEvidence()` now
  first runs the stored check (`stored()`, the old body), then asks the
  source (`fetched()`). The answer goes through the same `usable()` and
  `statusOf()` as a stapled response; the explanation says it was fetched.
  Null, a source that throws, or an answer that cannot be used gives the
  new informational `signingCredential.ocsp.inaccessible`, with the reason
  (the exception's class is not named: an anonymous class's name holds a
  NUL byte, and its message is the caller's text). A fetched response is a
  third evidence source, `OcspEvidence::FETCHED`, after the stored ones.
- **The urls.** `CertificateExtensions::ocspUrls()` reads the AIA's
  `id-ad-ocsp` locations: `http`/`https` only, at most 2,048 bytes each,
  at most four; an AIA that is not DER gives none.
- **An ingredient's report.** An ingredient in a store without
  certificate-status assertions still reports only a `revoked` (SPEC-066
  amendment 2). Its source is asked like the active manifest's, but a
  fetched `notRevoked` or an `inaccessible` stays out of its statuses; a
  fetched `revoked` is reported.
- The README, `docs/comparison.md` (two new rows: the side effect of
  `c2patool`'s fetch path not copied; an untrusted signer not looked up),
  `docs/conformance.md` (`PRED-STRU-013`, `-016`, `PRED-CRYP-022`, `-023`
  from *by design* to *yes*, opt-in: 75 yes, 20 by design) and
  `docs/reading-c2pa-2.4.md` (the three §15.9.2 rows covered).
- SPEC-014 amendment 9 and SPEC-030 amendment 4, approved by Maurice: their *Out of
  scope* lines that rule out an online query are read as ADR-0007 says.

## Measured

- **Red first.** All 21 tests of the eleven criteria failed — the missing
  interface, the unknown constructor parameter, the missing `ocspUrls()` —
  and `ApiSurfaceTest` on the four new symbols. Then green: `composer
  check` and `vendor/bin/pest --parallel`, both exit 0, 1,510 passed, 1
  skipped.
- **Two tests had to follow, as planned.** The SPEC-015 status-code count
  (69 → 70) and the README contract check (the two new classes).
- **AC9.** 3,066 runs (1,022 files, the two new AIA certificates included,
  under no settings, `trust/full.settings.json` and the programme's lists)
  in a worktree at `738e1dd` with its own copy of `vendor/` (checked to
  load its own `src/`) and after the build, with no source and with
  `NoRevocationSource`: the SHA-256 of `toJson()` and `toCrJson()` is the
  same in every run.
- **AC10.** With a source that records and answers null: under no
  settings 0 calls; under `full` 222 calls in 187 files and 176
  `inaccessible` statuses (the difference is the ingredient rule above and
  ingredient statuses the store recorded, SPEC-021); under the lists 2
  calls, both with an AIA url. Every call belongs to a manifest that may be
  asked, and every such manifest is asked once. Where the report holds no
  trust status for a manifest (68 recorded ingredients), the test reads
  trust from the evidence's anchor; over 2,197 manifests with both, the
  two agree in every case.
- **Mutations.** Nineteen changes. Fifteen were caught at once. Three
  survived at first and are now caught by tests written after the build:
  asking without the issuer in `x5chain` (M5), a fetched response not
  appended after stored ones (M8; the first version of this mutation was
  too weak, since `cs-parent.png` has no stored response), and no size
  limit on a fetched response (M16). One is equivalent: a broken AIA that
  keeps the urls of an earlier AIA extension (M19) differs only for a
  certificate with two AIA extensions, which RFC 5280 §4.2 forbids, and
  the reader reads the whole value before it keeps a url.

## Not measured

No live responder was asked by the tests; they use recorded responses
from the `revocation/` probes. The live behaviour of `c2patool` with
`verify.ocsp_fetch` was measured in step 471.

## For the next release

The CHANGELOG entry of the next minor names `Trust\RevocationSource`,
`Trust\NoRevocationSource`, `StatusCode::SigningCredentialOcspInaccessible`,
`OcspEvidence::FETCHED` and the `Verifier`'s `revocationSource`
parameter, beside the symbols of SPEC-081 to SPEC-085. A second spec may
follow for the CAs of the path through their AIA responders.
