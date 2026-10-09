# Step 338 — Revocation beyond the signer's own staple (SPEC-066)

*2026-10-09.*

SPEC-066 was approved with its two proposals: the anchor is excluded, and
a response is matched by its CertID, whichever manifest carries it. This
step built the probes and both parts.

## The probes

`bin/make-revocation-variants.php` (new) makes a throw-away hierarchy root
← intermediate ← signers P and U, and OCSP responses from `openssl ocsp`.
Each response is signed by the issuer of the certificate it is about, with
no certificates, so it is small.

- **A.** `c2patool` 0.28.1 signs with U and the intermediate. The
  1000-byte `pad` of the COSE unprotected header is replaced by an `rVals`
  with one response and a shorter pad of the same total length. That
  header is not signed and the store keeps its length, so nothing else
  changes.
- **B.** A parent signed by P. Children signed by U carry a
  `c2pa.certificate-status` whose placeholders become the responses, and
  are signed again.

Both `c2patool` versions call all eleven probes `Trusted`.

## What changed

- **`OcspCheck::revokedCa()`** offers each stapled response to every CA
  below the anchor, with the certificate above it as issuer. A verified
  `revoked` (not `removeFromCRL`) at the judged time is a reason.
  `ChainCheck::checkCertificates()` asks for it after `pathFault()`, so a
  revoked CA leaves the path untrusted (`signingCredential.untrusted`
  naming it).
- **`OcspCheck::assertionResponses()`** collects the `ocspVals` of every
  certificate-status assertion in the store. **`OcspCheck::check()`** takes
  them as a second part of its pool, after the stapled ones. A
  `notRevoked` from one names the assertion. `Verifier` passes them for
  the active manifest. `IngredientManifestCheck` runs the check for an
  ingredient manifest only when the store has such responses (amendment
  1). Running it for every ingredient with an `rVals` gave
  `c2pa-rs/ocsp.jpg` an extra `notRevoked` delta that `c2patool` does not
  report, which AC7 does not allow.

## Measured

| probe | `c2patool` 0.28.1 | here before | here after |
|---|---|---|---|
| `ca-revoked` | `Trusted` | `Trusted` | `Valid` (untrusted: the intermediate revoked) |
| `ca-good`, `ca-removed`, `ca-broken`, `ca-other`, `ca-control` | `Trusted` | `Trusted` | `Trusted` |
| `cs-good` | `Trusted` | `Trusted` | `Trusted`, P `notRevoked` |
| `cs-revoked`, `cs-two`, `cs-own` | `Trusted` | `Trusted` | `Invalid` (`ocsp.revoked`) |

- **Tests first.** `tests/Unit/Trust/RevocationBeyondStapleTest.php`: 5
  failed (AC2 and AC6 held already), then 11 passed.
- **The corpus.** 881 runs moved. Verdicts changed only for the probes. One
  real file changed codes: `c2pa-rs/ocsp_with_assertion.jpg`, the only one
  carrying a certificate-status assertion. Its responses expired in August
  2025, so they are `skipped` (stale) for its signer and its ingredients,
  and its verdict stays.
- **The fuzzer.** 0 faults. With `--trust`, 534 and 1,765 suspects, each
  judged by `c2patool` 0.28.1, none more lenient here.
- `composer check`: 1008 passed.

`docs/reading-c2pa-2.4.md`: the three §15.9 candidates covered. Eight
remain, all ISOBMFF details (group 2).
