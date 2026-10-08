# Key-usage probes for SPEC-014 amendment 7

Built by `bin/make-trust-matrix.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22> key-usage control int-no-key-usage anchor-no-key-usage`
on 2026-10-08 (step 284). Each PNG is `../../fixture-signed.png` with its
claim re-signed by a throw-away P-256 leaf; x5chain holds the leaf and the
intermediate, the anchor is left out. The keys lived in a scratch
directory while the script ran and were deleted. No private key is here.
`<probe>.anchor.pem` is each probe's anchor, and `<probe>.settings.json`
holds it as the legacy `trust.trust_anchors` string with `store.cfg`.

| file | what differs | 0.27.22 | 0.28.1 | `openssl verify -x509_strict` |
|---|---|---|---|---|
| `control.png` | nothing | `Trusted` | `Trusted` | OK |
| `int-no-key-usage.png` | the intermediate has no keyUsage | `Valid` | `Valid` | refused: CA cert does not include key usage extension |
| `anchor-no-key-usage.png` | the anchor has no keyUsage | `Valid` | `Valid` | refused: the same |

Every `Valid` carries `signingCredential.untrusted`. The answers are under
`../../c2patool/key-usage/<probe>--<version>.json`.
