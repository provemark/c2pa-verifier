# Stored-issuer probes (SPEC-030 amendment 5)

Built by `bin/make-revocation-stored-issuer-variants.php <scratch> <c2patool-0.28.3>`
on 2026-10-28 (step 490; regenerated in step 492 with two more probes and
new throw-away keys), after the review of steps 483–489. A throw-away
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
parentOf ingredient `leaf-twin-forged.png`; `child-root-good.png` likewise
with `leaf-root-good.png` (step 492). `root.settings.json` holds R as
the legacy anchor; `allowed.settings.json` puts L on an allowed list (a
`"manifest"` entry whose anchor B issues nothing).

| probe | x5chain | stapled | `root`, no source | `root`, a source answering L revoked | `allowed` | 0.28.3 (`root`) |
|---|---|---|---|---|---|---|
| `leaf-twin-forged` | [L, N] | N's forged good | `Trusted`, `skipped` (was `notRevoked`) | `Invalid` (was `Trusted`, not asked) | `Trusted`, `skipped`, `notTried` | `Trusted`, no OCSP code |
| `leaf-bogus-forged` | [L, B] | B's forged good | `Trusted`, `skipped` | `Invalid` | `Trusted`, `skipped`, `notTried` | `Trusted`, no OCSP code |
| `leaf-alone-revoked` | [L] | R's revoked | `Invalid` (was `Trusted`) | `Invalid` | `Trusted`, `skipped` | `Trusted`, no OCSP code |
| `leaf-root-good` | [L, R] | R's good | `Trusted`, `notRevoked` | `Trusted`, not asked | `Trusted`, `skipped`, `notChosen` (amendment 6; was `notRevoked`) | `Trusted`, no OCSP code |
| `leaf-root-revoked` | [L, R] | R's revoked | `Invalid` | `Invalid`, not asked | `Invalid` | `Trusted`, no OCSP code |
| `child-twin-forged` | [M, R]; ingredient [L, N] | (ingredient) N's forged good | `Trusted` | `Invalid`, the ingredient `revoked` (was `Trusted`) | — | `Trusted` |
| `child-root-good` | [M, R]; ingredient [L, R] | (ingredient) R's good | — | — | `Valid` (M untrusted), the ingredient's good `notChosen`; with a source the ingredient `revoked`, `Invalid` | `Trusted` |

The answers are under `../c2patool/revocation-stored-issuer/<probe>--0.28.3.json`.

*SPEC-030 amendment 6 (step 492):* under `allowed.settings.json`, or with
L itself as the anchor (built in the test from `root.settings.json`), R in
`x5chain[1]` is proven only by its signature on L. A `good` from it is
`notChosen` and the status `skipped`; a `revoked` counts.
