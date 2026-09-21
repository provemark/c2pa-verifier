# Certificate-profile variants for SPEC-015 (step 33)

Made by `bin/make-profile-variants.php` on 2026-09-21 with a **throw-away
hierarchy**: a P-256 root generated for the run and, per variant, a leaf
signed by it that departs from the C2PA 2.4 §14.5 profile in exactly one
way. The PNG fixture's manifest was re-signed with each leaf — new
protected header (`alg`, `x5chain` = leaf + root), new signature over the
Sig_structure, the unprotected `pad` resized so the store keeps its 46,025
bytes and nothing but the signature box changes. The keys existed in a
directory outside the repository for the run and were deleted by the
script; **no private key is here** (`grep -l PRIVATE tests/Fixtures/profile/*`
is empty). Decided by Maurice van Loon on 2026-09-21: tooling may sign with
throw-away keys; the product never signs.

Re-running the script makes a *new* hierarchy and new bytes; the files
here are the ones measured, with the SHA-256s below.

| variant | the one departure | c2patool 0.27.22 (root as anchor) |
|---|---|---|
| `good` | none: v3, CA:FALSE, KU digitalSignature+nonRepudiation, EKU emailProtection | `Trusted` — the control: the re-signing is right |
| `no-digital-signature` | KU nonRepudiation only | **`Trusted`** — c2pa-rs accepts nonRepudiation or keyCertSign in place of digitalSignature |
| `expired` | notAfter 2025-01-01 (notBefore 2024-01-01) | `Invalid`; `signingCredential.expired` (and `signingCredential.trusted` still logged) |
| `ca-as-leaf` | basicConstraints CA:TRUE | `Invalid`; `signingCredential.invalid` |
| `eku-outside-list` | EKU codeSigning only | `Invalid`; `signingCredential.invalid` |
| `eku-any` | EKU anyExtendedKeyUsage | `Invalid`; `signingCredential.invalid` |
| `eku-mixed` | EKU timeStamping + emailProtection | `Invalid`; `signingCredential.invalid` (an invalid set) |
| `eku-c2pa` | EKU 1.3.6.1.4.1.62558.2.1 (C2PA Signing) only | `Trusted` — on the built-in list |
| `no-eku` | no EKU extension | `Invalid`; `signingCredential.invalid` |
| `v1` | no extensions at all (an X.509 v1 certificate) | `Invalid`; `signingCredential.invalid` |
| `rsa-1024` | RSA 1024, PS256 | `Invalid`; `signingCredential.invalid` — this verifier's `SignatureVerifier` already refuses the key (SPEC-009) |
| `curve-secp256k1` | EC secp256k1, ES256 | `Invalid`; `signingCredential.invalid` — likewise refused at SPEC-009 |

Per variant: `<name>.leaf.pem` (public), `<name>.bin` (the store),
`<name>.png` (the carrier). `throw-away-root.pem` and
`throw-away-root.settings.json` (anchors = that root, `trust_config` =
`store.cfg`) are what c2patool was given; c2patool's JSON is under
`../c2patool/profile/`.

```
f97aa6625da5221c807c1c99eb7d878a03da5353cf718487e28b908eb13ab0c8  good.bin
f8cfe5250edbf9e41abad750d1d784e042f04c87eeca358c8a0f883e53abe805  no-digital-signature.bin
b8eb79118731b28f3c8cd47d71a77992f79fbb8f04fca04407479db2f88c79a0  expired.bin
c0dcbab242267296838afc06f11d09ddd6cdf0533843c170f8145ed47af82662  ca-as-leaf.bin
365d9da0df185c367f0f01585ee30bee054c02696da79ab7874385c511a066a8  eku-outside-list.bin
00feba26321e87833c036f077570bccbb615f5ce993761321da7696397a53190  eku-any.bin
b13bee244cd765849fd31b9ece9dc8fb813771302a35e250cb21805e2fdb410a  eku-mixed.bin
ac0895d414e3b1c1f26778ac916869825c9824ddebd6372475ba91f18e3b7a43  eku-c2pa.bin
66f2d23128aad658855d9328279ec3ba80950ef507eb1424fb1520648f14f37b  no-eku.bin
7c5c1386166024a1d14df011cb02f359c1a9d4e477bcc468706245aa8d8c30b0  v1.bin
9734e15f3f638fbf471247b031669c11b3ee190bd79573a48898f815536ac114  rsa-1024.bin
05d265d5e59292dfd4cc9d3fc08e2c5cd65bc4f192b3aa6011ee50d994f35907  curve-secp256k1.bin
```
