# Revocation-issuer probes (SPEC-086 amendment 2, SPEC-087 amendment 1, SPEC-066 amendment 3)

Built by `bin/make-revocation-issuer-variants.php <scratch> <c2patool-0.28.3>`
on 2026-10-27 (step 483), after the review of step 482. A throw-away P-256
hierarchy:

- `throw-away-root.pem` (R), the anchor;
- `signer-l.pem` (L), issued by R itself;
- `unrelated-b.pem` (B), self-signed, unrelated;
- `name-twin-n.pem` (N), self-signed with R's subject and its own key;
- `key-twin-k.pem` (K), self-signed with R's key and another subject;
- `intermediate-i.pem` (I), issued by R;
- `signer-t.pem` (T), issued by I, whose only EKU is timeStamping (critical).

Each PNG is `fixture-signed.png`'s manifest re-signed (ES256) with a new
protected header carrying the `x5chain` below, and its unprotected `pad`
resized so that the store keeps its length. `leaf-revoked.der` and
`int-revoked.der` are OCSP responses from `openssl ocsp`, signed by R,
without certificates, revoking L and I (keyCompromise). Keys lived in a
scratch directory and were deleted.

`root.settings.json` holds R as the legacy anchor; `tsa-split.settings.json`
holds I as the legacy anchor and R as a `"manifest"` entry, so that T
reaches I only through the legacy anchors and is walked a second time
without them (SPEC-031, step 159).

| probe | x5chain | settings | 0.28.3 | here, no source | here, a source answering L or I revoked |
|---|---|---|---|---|---|
| `leaf-alone` | [L] | `root` | `Trusted` | `Trusted` | `Invalid` (asked about L with R) |
| `leaf-bogus` | [L, B] | `root` | `Trusted` | `Trusted` | `Invalid` (asked about L with R) |
| `leaf-name-twin` | [L, N] | `root` | `Trusted` | `Trusted` | `Invalid` (asked about L with R) |
| `leaf-key-twin` | [L, K] | `root` | `Trusted` | `Trusted` | — |
| `leaf-root` | [L, R] | `root` | `Trusted` | `Trusted` | `Invalid` (asked about L with R) |
| `tsa-signer` | [T, I] | `tsa-split` | `Trusted` | `Trusted` | `Valid` (`signingCredential.untrusted`, I revoked) |
| `tsa-signer-staple` | [T, I], I's revocation stapled | `tsa-split` | `Trusted` | `Valid` (`signingCredential.untrusted`) | — |

With L on the allowed list, or L itself the anchor, L is asked about only
when `x5chain` is [L, R]: N fails the signature check, K the name check, B
both. The answers are under `../c2patool/revocation-issuer/<probe>--0.28.3.json`.
