# Signature-level variants for M3

Every `.bin` here is the manifest store of `../fixture-signed.png` (46,025
bytes, extracted with the SPEC-002 extractor) with **one byte** changed —
the same length, so nothing else moves — by `bin/make-cose-variants.php`.
Next to every `.bin` is a `.png`: the fixture with that store in its `caBX`
chunk (CRC recomputed), which is what c2patool was given. Regenerate with
`php bin/make-cose-variants.php`; the script prints each `.bin`'s SHA-256,
and these are the values committed on 2026-09-21. Measured with c2patool
0.27.22 the same day (`notes/step-16-cose-signature.md`). The M3 column is
filled when the M3 specs are approved.

| file | what is wrong | c2patool 0.27.22 on the `.png` | M3 |
|---|---|---|---|
| `claim-title-changed` | the first byte of the claim's `dc:title` (`fixture-signed.png` → `gixture-signed.png`) | `Invalid`, `claimSignature.mismatch` | — |
| `signature-changed` | one bit of the 64-byte ES256 signature | `Invalid`, `claimSignature.mismatch` | — |
| `alg-eddsa-with-ec-key` | the protected header's `alg` −7 (ES256) → −8 (EdDSA), the key still P-256 | `Invalid`, `claimSignature.mismatch` — no separate code for a key that does not fit the algorithm | — |

SHA-256 of the `.bin` files (as printed by the script):

```
9a42f90f72a775e3178c13001a7633f4822646e5bdec088324629274dc8dede7  claim-title-changed.bin
000fa25e38647fa27743d7eb70ff42d35119a087488e6afaec041e534b13cfe0  signature-changed.bin
23ece603dde721e6d4e32b07aa8020bb1b11d0d64a12248054248e07ca484cc9  alg-eddsa-with-ec-key.bin
```
