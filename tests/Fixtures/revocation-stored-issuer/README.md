# Stored-issuer probes (SPEC-030 amendment 5)

Built by `bin/make-revocation-stored-issuer-variants.php <scratch> <c2patool-0.28.3>`
on 2026-10-28 (step 490), after the review of steps 483–489. A throw-away
P-256 root R (`throw-away-root.pem`), signers L and M issued by it
(`signer-l.pem`, `signer-m.pem`), N (`name-twin-n.pem`: self-signed, R's
subject, its own key) and B (`unrelated-b.pem`: self-signed, unrelated).
OCSP responses from `openssl ocsp`, without certificates; keys lived in a
scratch directory and were deleted.

- `leaf-good.der`, `leaf-revoked.der`: from R, L good and L revoked (keyCompromise);
- `twin-forged-good.der`: L good with N as the issuer (CertID from N, signed by N's key);
- `bogus-forged-good.der`: L good with B as the issuer (from B, by B).

Each leaf probe is `fixture-signed.png`'s manifest re-signed (ES256) by L
with the `x5chain` below, one response stapled in `rVals` and the `pad`
resized so that the store keeps its length. `child-twin-forged.png` is
`fixture-unsigned.png` signed by M (`x5chain` [M, R]) with `c2patool`, its
parentOf ingredient `leaf-twin-forged.png`. `root.settings.json` holds R as
the legacy anchor; `allowed.settings.json` puts L on an allowed list (a
`"manifest"` entry whose anchor B issues nothing).

| probe | x5chain | stapled | `root`, no source | `root`, a source answering L revoked | `allowed` | 0.28.3 (`root`) |
|---|---|---|---|---|---|---|
| `leaf-twin-forged` | [L, N] | N's forged good | `Trusted`, `skipped` (was `notRevoked`) | `Invalid` (was `Trusted`, not asked) | `Trusted`, `skipped`, `notTried` | `Trusted`, no OCSP code |
| `leaf-bogus-forged` | [L, B] | B's forged good | `Trusted`, `skipped` | `Invalid` | `Trusted`, `skipped`, `notTried` | `Trusted`, no OCSP code |
| `leaf-alone-revoked` | [L] | R's revoked | `Invalid` (was `Trusted`) | `Invalid` | `Trusted`, `skipped` | `Trusted`, no OCSP code |
| `leaf-root-good` | [L, R] | R's good | `Trusted`, `notRevoked` | `Trusted`, not asked | `Trusted`, `notRevoked` | `Trusted`, no OCSP code |
| `child-twin-forged` | [M, R]; ingredient [L, N] | (ingredient) N's forged good | `Trusted` | `Invalid`, the ingredient `revoked` (was `Trusted`) | — | `Trusted` |

The answers are under `../c2patool/revocation-stored-issuer/<probe>--0.28.3.json`.
