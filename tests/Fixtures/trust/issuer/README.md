# Issuer probes for SPEC-014 amendment 4

Built by `bin/make-issuer-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`
on 2026-09-25 (step 148). Each JPEG is `../../fixture-unsigned.jpg` signed
by c2patool 0.27.22 with a throwaway P-256 leaf. Its chain is carried in
x5chain; the probe root is left out. The keys lived in a temporary
directory while the script ran and were overwritten and deleted. No
private key is here.

| file | chain (leaf first) | 0.27.22 | 0.28.0 | this verifier |
|---|---|---|---|---|
| `good-chain.jpg` | leaf ← intermediate (`CA:TRUE`, `keyCertSign`, `pathlen:0`) | `Trusted` | `Trusted` | `Trusted` |
| `ee-as-issuer.jpg` | leaf ← an end-entity certificate (`CA:FALSE`) the root issued | `Valid` | `Valid` | `Valid` |
| `ca-without-keycertsign.jpg` | leaf ← `CA:TRUE` whose keyUsage lacks `keyCertSign` | `Valid` | `Valid` | `Valid` |
| `pathlen-exceeded.jpg` | leaf ← intermediate ← intermediate with `pathlen:0` | `Valid` | `Valid` | `Valid` |
| `expired-intermediate.jpg` | leaf ← intermediate valid only in 2020 | `Trusted` | `Valid` | `Valid` |

All under `probe-root.settings.json`, which holds the probe root as the
legacy `trust.trust_anchors` string, because both versions read that
shape. `honest-as-anchor.settings.json` makes the end-entity issuer
itself the anchor: `ee-as-issuer.jpg` is `Valid` under it in both
versions and here. Every `Valid` above carries
`signingCredential.untrusted`. Before step 148 this verifier said
`Trusted` for all six cases.
