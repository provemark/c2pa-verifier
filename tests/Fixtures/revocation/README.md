# Revocation probes (SPEC-066)

Built by `bin/make-revocation-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22>` on 2026-10-09 (step 338). The hierarchy is
`throw-away-root.pem` ← an intermediate ← signers P and U, all P-256. The
OCSP responses come from `openssl ocsp`, each signed by the issuer of the
certificate it is about, without certificates (`-resp_no_certs`). Keys
lived in a scratch directory and were deleted. `throw-away-root.settings.json`
holds the root and `store.cfg`.

**A — a CA of the path.** `ca-control.png` is `fixture-unsigned.png` signed
by `c2patool` 0.28.1 with U and the intermediate in its x5chain. Each
other `ca-*` probe is that file with the 1000-byte `pad` of the COSE
unprotected header replaced by an `rVals` holding one response, and a
shorter pad of the same total length. The unprotected header is not signed
and the store keeps its length.

**B — certificate-status assertions.** `cs-parent.png` is signed by P.
Each `cs-*` child is signed by U with `cs-parent.png` as its parent and a
`c2pa.certificate-status` assertion. Its placeholders were made byte
strings holding the responses, and the claim was re-hashed and signed
again.

| probe | response(s) | 0.28.1 | 0.27.22 | here |
|---|---|---|---|---|
| `ca-control` | none | `Trusted` | `Trusted` | `Trusted` |
| `ca-revoked` | the intermediate revoked (keyCompromise) | `Trusted` | `Trusted` | `Valid` (`signingCredential.untrusted`) |
| `ca-good` | the intermediate good | `Trusted` | `Trusted` | `Trusted` |
| `ca-removed` | the intermediate removeFromCRL | `Trusted` | `Trusted` | `Trusted` |
| `ca-broken` | `ca-revoked`'s, one signature byte changed | `Trusted` | `Trusted` | `Trusted` |
| `ca-other` | good, about another certificate | `Trusted` | `Trusted` | `Trusted` |
| `cs-good` | P good | `Trusted` | `Trusted` | `Trusted` (P `notRevoked`) |
| `cs-revoked` | P revoked | `Trusted` | `Trusted` | `Invalid` (P `ocsp.revoked`) |
| `cs-two` | another certificate good, then P revoked | `Trusted` | `Trusted` | `Invalid` |
| `cs-own` | U revoked, in U's own assertion | `Trusted` | `Trusted` | `Invalid` |

The answers are under `../c2patool/revocation/<probe>--<version>.json`.
