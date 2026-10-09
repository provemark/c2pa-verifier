# Step 310 — Unique IDs in a certificate, measured (candidate C5)

*2026-10-09. Measurement only; no change to `src/` or to the generators.*

C2PA 2.4 §14.5.1.1 says no certificate may carry the optional
`issuerUniqueID` or `subjectUniqueID` fields of the tbsCertificate (RFC 5280
§4.1.2.8). `c2pa-rs` 0.91.1 refuses them on the end-entity certificate;
this verifier does not look at them (step 309, candidate C5).

## Probes

OpenSSL cannot write these fields. So `bin/make-trust-matrix.php`, run in
its scratch mode, let OpenSSL issue the certificate, inserted the field
into its tbsCertificate before the extensions, and signed the tbsCertificate
again with the issuer's throw-away key. Three probes:

- a leaf with `issuerUniqueID`;
- a leaf with `subjectUniqueID`;
- an intermediate with `subjectUniqueID`.

`openssl x509 -text` shows "Issuer Unique ID" and "Subject Unique ID", and
`openssl verify` accepts the chains. The first build gave the field a
length of 9 for 10 bytes of content and was refused as unreadable; the
length is now computed. The change to the generator is not committed in
this step (SPEC-061 AC6); it comes with the fix.

| probe | `c2patool` 0.27.22 | 0.28.1 | `openssl verify` | this verifier |
|---|---|---|---|---|
| control | `Trusted` | `Trusted` | OK | `Trusted` |
| leaf with `issuerUniqueID` | `Invalid` | `Invalid` | OK | **`Trusted`** |
| leaf with `subjectUniqueID` | `Invalid` | `Invalid` | OK | **`Trusted`** |
| intermediate with `subjectUniqueID` | `Trusted` | `Trusted` | OK | `Trusted` |

0.28.1's explanation: `signingCredential.invalid`, "certificate
issuer/subject unique ids are not allowed".

**This is a wrong `Trusted` against `c2patool`.** A signer certificate with
a unique ID is trusted here and refused there. It needs a certificate
authority that issues such certificates: the fields are obsolete, but RFC
5280 still allows them in a v3 certificate. The intermediate case agrees
with `c2patool` and belongs to candidate C4.

## Checked

No code changed. `composer check`: 953 passed.
