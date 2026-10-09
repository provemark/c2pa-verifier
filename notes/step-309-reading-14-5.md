# Step 309 — Reading C2PA 2.4 §14.5 (X.509 certificates) against the verifier

*2026-10-09. Reading only; no change to `src/`.*

The second step of the reading round (step 308 began it). §14.5 is about
2,200 words: `x5chain` as RFC 9360 defines it with C2PA's additions, the
certificate profile (§14.5.1.1), the trust chain (§14.5.1.2) and
revocation (§14.5.2). Because the profile is where `c2patool` and the
specification can differ, `c2pa-rs` 0.91.1's
`src/crypto/cose/certificate_profile.rs` was read beside it. It applies the
profile to the end-entity certificate only.

## Result

Twenty-four rules, in `docs/reading-c2pa-2.4.md`:

| verdict | rules |
|---|---|
| covered | 11 |
| covered for the leaf only | 5 |
| partial | 3 |
| candidate | 2 |
| by design | 2 |
| n/a | 1 |

§14.5 settles half of step 308's C1. When label 33 and `"x5chain"` are
both present, validators shall use 33, as the code does. What stays open is
the same label in both buckets.

New candidates:

- **C4 — the profile above the leaf.** §14.5.1.1 says "all certificates"
  for the algorithm list, the PSS parameters, the curves, the RSA size,
  version 3 and the AKI. This verifier and `c2pa-rs` apply them to the leaf
  only. Measured earlier: an RSA-1024 intermediate or anchor is `Trusted`
  in both `c2patool` versions, in OpenSSL and here.
- **C5 — unique IDs.** No certificate may carry `issuerUniqueID` or
  `subjectUniqueID`. `c2pa-rs` refuses them on the end-entity certificate
  ("certificate issuer/subject unique ids are not allowed"); this verifier
  checks neither. **Possibly more lenient than `c2patool`**, the only
  candidate so far that may be. It needs a hand-built probe, since OpenSSL
  cannot write these fields.
- **C6 — digitalSignature.** A manifest signer must assert it; this
  verifier, like `c2pa-rs`, also accepts Non Repudiation alone.
- **C7 — a CA's Subject Key Identifier.** Required by §14.5.1.1 and RFC 5280
  §4.2.1.2, and checked nowhere here.

C4, C6 and C7 would make this verifier stricter than `c2patool`; whether to
adopt them is Maurice's decision. C1 and C5 are measured first.

## Checked

No code changed. `composer check`: 953 passed.
