# Revocation-unknown probe (SPEC-086 amendment 3)

Built by `bin/make-revocation-unknown-variants.php <scratch> <c2patool-0.28.3>`
on 2026-10-27 (step 484), after the review of step 482. A throw-away P-256
root R (`throw-away-root.pem`) and a signer L issued by it (`signer-l.pem`).
The OCSP responses come from `openssl ocsp`, signed by R, without
certificates; keys lived in a scratch directory and were deleted.

- `leaf-unknown.der`: L `unknown` (its serial absent from the responder's index);
- `leaf-revoked.der`: L revoked (keyCompromise);
- `leaf-good.der`: L good.

`leaf-unknown.png` is `fixture-signed.png`'s manifest re-signed (ES256) by
L, `x5chain` [L, R], with `leaf-unknown.der` stapled in the unprotected
`rVals` and the `pad` resized so that the store keeps its length.
`root.settings.json` holds R as the legacy anchor.

| source answers | here | `c2patool` 0.28.3 |
|---|---|---|
| no source | `Trusted`, `signingCredential.ocsp.unknown` | `Trusted`, no OCSP code |
| `leaf-revoked.der` | `Invalid`, `signingCredential.ocsp.revoked` | — |
| `leaf-good.der` | `Trusted`, `signingCredential.ocsp.notRevoked` | — |
| nothing, an exception, not DER, `leaf-unknown.der` | `Trusted`, `signingCredential.ocsp.unknown`, saying what the source gave | — |

The answer is under `../c2patool/revocation-unknown/leaf-unknown--0.28.3.json`.
