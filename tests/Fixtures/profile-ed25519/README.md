# Ed25519 certificates with algorithm parameters (SPEC-015 amendment 11)

Built by `bin/make-ed25519-params-variants.php <scratch> tests/Fixtures/profile-ed25519
<c2patool-0.28.2> <c2patool-0.28.3>` on 2026-10-25 (step 421). A throw-away Ed25519
hierarchy (root ← intermediate ← signer) re-signs the claim of `../fixture-signed.png`
with EdDSA, `x5chain` = signer + intermediate. RFC 8410 §3: for `id-Ed25519` *"the
parameters MUST be absent"*. OpenSSL writes no such certificate, so the DER was edited
and the issuer signed the new tbsCertificate again. The keys lived in a scratch
directory while the script ran and were deleted. No private key is here.

| probe | what differs | 0.28.2 | 0.28.3 | here |
|---|---|---|---|---|
| `control` | nothing | `Trusted` | `Trusted` | `Trusted` |
| `leaf-spki-params` | the signer's key AlgorithmIdentifier carries NULL | `Invalid` | `Invalid` | `Invalid` |
| `leaf-signature-params` | the intermediate signs the signer with NULL in both AlgorithmIdentifiers | `Trusted` | `Invalid` | `Invalid` |
| `intermediate-signature-params` | the root signs the intermediate with NULL in both | `Trusted` | `Trusted` | `Trusted` |

`<probe>.settings.json` holds the root as the trust anchor (legacy
`trust.trust_anchors`) with `../trust/store.cfg`. Without settings every
`Trusted` above is `Valid`. The answers are under
`../c2patool/profile-ed25519/<probe>--<version>[--no-settings].json`. A rerun
makes new keys: the PNGs change byte for byte, the answers should not.

SHA-256:

```
5843a06fd2492cea15d40c919810b1f9c4a87b9c6540f4e1f44e4fcf58695d1c  control.png
0a16ada59b965344e4eb46cb5f94732221dfa284890891e4ffe2c873f846ddb9  intermediate-signature-params.png
13c00777c0a8d22976a7cee5720fd0e860a3f957e289c7891aa417dfd7edd3f0  leaf-signature-params.png
50dc1db8f99ab089e4e1c42eaa788d97c849850e82fe7835f086e0eee37f379b  leaf-spki-params.png
```
