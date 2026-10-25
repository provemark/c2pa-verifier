# ECDSA algorithms under keys on another curve (SPEC-009 amendment 5)

Built by `bin/make-ecdsa-pairing-variants.php <scratch> tests/Fixtures/ecdsa-pairing
<c2patool-0.28.2> <c2patool-0.28.3>` on 2026-10-25 (step 422). A throw-away P-256 root
signs one signer certificate per probe, on the probe's curve, and the claim of
`../fixture-signed.png` is re-signed with the probe's algorithm; `x5chain` = signer +
root, the signature R‖S at the curve's size. `openssl dgst -verify` accepts all six.
The keys lived in a scratch directory while the script ran and were deleted. No private
key is here.

| probe | algorithm | key | 0.27.22, 0.28.1–0.28.3, `c2pa-web` 0.15.3 | here |
|---|---|---|---|---|
| `es256-p256` | ES256 | P-256 | `Trusted` | `Trusted` |
| `es384-p384` | ES384 | P-384 | `Trusted` | `Trusted` |
| `es512-p521` | ES512 | P-521 | `Trusted` | `Trusted` |
| `es256-p384` | ES256 | P-384 | `Invalid` | `Invalid` |
| `es384-p256` | ES384 | P-256 | `Invalid` | `Invalid` |
| `es512-p384` | ES512 | P-384 | `Invalid` | `Invalid` |

`<probe>.settings.json` holds the root as the trust anchor with `../trust/store.cfg`.
Without settings every `Trusted` above is `Valid`; every `Invalid` stays `Invalid`. The
answers of 0.28.2 and 0.28.3 are under
`../c2patool/ecdsa-pairing/<probe>--<version>[--no-settings].json`. A rerun makes new
keys: the PNGs change byte for byte, the answers should not.

SHA-256:

```
0092677857ef8c419b9b373f961201145901bc0e6c1977bea7ea2ed03a74fb09  es256-p256.png
e9ef4e935d28a41c13be49ec1b0e84163f100b057fe827672948bdb693ca1ddb  es256-p384.png
5e4bfe8d23bf7f03844fdb5453d6834bde7e30b43825ecf040f280084a002217  es384-p256.png
e1a9ba1aed70264d64fca42b6f94b271cf71e9ddfd149b3c2f5fd1c9f70847fc  es384-p384.png
38994a7257dd77b388921b9c41474c695346e1b77e7d17b5a771c1e9234d09a8  es512-p384.png
3c7bd983b16ef1a5a4155ad8e0a1088f8514dc740183e458c6a682f85bf6b381  es512-p521.png
```
