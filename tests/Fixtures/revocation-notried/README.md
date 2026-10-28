# Revocation-not-tried probe (SPEC-085 amendment 2)

Built by `bin/make-revocation-notried-variants.php <scratch> <c2patool-0.28.3>`
on 2026-10-28 (step 486), after the review of step 482. A throw-away P-256
root R (`throw-away-root.pem`) and a signer L issued by it (`signer-l.pem`).
The OCSP responses come from `openssl ocsp`, signed by R, without
certificates; keys lived in a scratch directory and were deleted.

- `leaf-good.der`: L good (stapled);
- `leaf-revoked.der`: L revoked (keyCompromise), what a source may answer.

`leaf-alone-stapled.png` is `fixture-signed.png`'s manifest re-signed
(ES256) by L, `x5chain` [L] — the chain walk finds R in the trust list —
with `leaf-good.der` stapled in the unprotected `rVals` and the `pad`
resized so that the store keeps its length. `root.settings.json` holds R as
the legacy anchor.

| source answers | here | the staple in the evidence | `c2patool` 0.28.3 |
|---|---|---|---|
| no source | `Trusted`, `signingCredential.ocsp.skipped` | `notTried` (was `unusable`) | `Trusted`, no OCSP code |
| `leaf-revoked.der` | `Invalid`, `signingCredential.ocsp.revoked` | `notTried`; the fetched one `chosen` | — |
| nothing | `Trusted`, `signingCredential.ocsp.inaccessible` | `notTried` | — |

The answer is under `../c2patool/revocation-notried/leaf-alone-stapled--0.28.3.json`.
