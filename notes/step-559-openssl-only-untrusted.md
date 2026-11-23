# Step 559 — Three `untrusted` codes that come from one backend

*2026-11-23. Documentation only.* This is the last status-code move of the
`c2patool` 0.29 round. Maurice approved not following it ("ja akkoord").

> **Corrected in step 563.** `X509_STRICT` is the reason for
> `leaf-ku-cert-sign` and `int-no-ski` only. OpenSSL refuses
> `leaf-spki-params` without it too, because it cannot decode the key. What
> the native check would do was read, not run. See
> [step 563](step-563-corrections-559-560.md).

## What is left

Under their own settings, three probes still differ from `c2patool` 0.29.0
in one code. 0.29.0 reports `signingCredential.untrusted` beside
`signingCredential.invalid`; this verifier reports `invalid` only. All
three are `Invalid` on both sides.

| probe | what is wrong with the leaf |
|---|---|
| `trust/chain-matrix/leaf-ku-cert-sign` | keyCertSign without being a CA |
| `trust/chain-matrix/int-no-ski` | no AuthorityKeyIdentifier (its issuer has no Subject Key Identifier) |
| `profile-ed25519/leaf-spki-params` | Ed25519 key with parameters (RFC 8410 §3: absent) |

## Why `c2patool` says `untrusted` there

The answer is read in the `c2pa` 0.92.0 source. Before it checks the
certificate profile, `verify_signature` (`crypto/cose/verifier.rs`) runs
the chain check. Any error from that check is logged as
`signingCredential.untrusted`. There are two chain checks:

- **`certificate_trust/openssl.rs`** builds an OpenSSL store with
  `X509_STRICT` and `PARTIAL_CHAIN`. The `c2patool` 0.29.0 binary measured
  against carries OpenSSL. Its strict mode refuses all three leaves. The
  OpenSSL column of `tests/Fixtures/trust/chain-matrix/README.md`
  (`openssl verify -x509_strict -partial_chain`) shows the same refusals:
  "Key usage keyCertSign invalid for non-CA cert" and "Missing Authority
  Key Identifier".
- **`certificate_trust/rust_native.rs`** is used where OpenSSL is not, such
  as in WebAssembly. At the leaf it checks only the EKU and the validity,
  and for the issuers the signatures and CA authority. It would refuse none
  of the three.

So these codes belong to one backend, not to `c2pa`. Step 558 was
different: there both backends refuse an expired leaf.

## Decision

The codes are not followed. Here the leaf belongs to the certificate
profile (SPEC-015), and the profile reports all three faults as
`signingCredential.invalid`. Rebuilding OpenSSL's strict list in the
chain walk would bind this verifier to one backend's internals, and no
verdict would change.

What changed:

- **SPEC-061 amendment 8** records the reason. `leaf-ku-cert-sign` and
  `int-no-ski` stay in `SPEC061_CODES_DIFFER`, and the comment there says
  why.
- **`tests/Fixtures/profile-ed25519/README.md`** notes the same for
  `leaf-spki-params`. It also notes `claimSignature.mismatch`, which
  follows in `c2pa` from the unusable key.
- **`docs/comparison.md`** has one paragraph.

## The rest of the comparison

The other runs where only 0.29.0 reports `untrusted` are files that this
verifier refuses for their structure before it judges a signature: AVI
variants, duplicate and numeric labels, and the `x5chain` shapes. Those
differences are named in `docs/comparison.md` already.

**Checks.** `composer check` exit 0. `bin/spec-check.php` reports OK. The
only source change is a comment in `TrustMatrixTest.php`.
