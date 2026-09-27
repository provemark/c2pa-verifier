# Step 168 — The algorithms of intermediates, measured

*2026-09-27. Measurement only: no spec, test or code in `src/` changed.
The last unmeasured chain finding of step 157 that could give a wrong
`Trusted`.*

## What was built

`bin/make-chain-constraint-variants.php` (step 164) gained two
intermediates under the throw-away root, each issuing an ordinary P-256
leaf that re-signs the PNG fixture:

- `sha1-intermediate`: the intermediate is signed by the root with
  `ecdsa-with-SHA1`;
- `rsa1024-intermediate`: the intermediate has an RSA key of 1024 bits
  and signs the leaf with `sha256WithRSAEncryption`.

The script now builds the COSE Sig_structure itself (RFC 9052 §4.4)
instead of borrowing it from the parser, which since SPEC-047 refuses
some of the headers the script writes on purpose. All files were rebuilt
with new keys. Every earlier answer came out the same, and SPEC-046's and
SPEC-047's tests pass on the rebuilt files.

## Measured

| file | `openssl verify` (OpenSSL 3.6.3) | c2patool 0.27.22 and 0.28.0 | this verifier |
|---|---|---|---|
| `sha1-intermediate` | OK | `Trusted` | `Trusted` |
| `rsa1024-intermediate` | OK | `Trusted` | `Trusted` |

## What it means

There is no wrong verdict relative to the oracles. The chain walk
verifies every link with `openssl_x509_verify()`, and OpenSSL at its
default security level accepts both. So does `c2pa-rs`'s path check
(`X509_STRICT` does not refuse them).

The leaf is different. SPEC-015 already refuses a leaf with a SHA-1
signature or an RSA key under 2048 bits (C2PA 2.4 §14.5), as `c2pa-rs`'s
profile does.

Whether to be stricter than every oracle is the maintainer's decision.
The case for refusing SHA-1 (and MD5) signatures anywhere in the path is
that chosen-prefix collisions on SHA-1 are practical. A CA that still
signs certificates with SHA-1 could have one forged that it never
issued. That is unchecked trust in ADR-0005's sense (reasoned; no forged
certificate was made). For RSA-1024 no public factorisation exists, so
the case is weaker. No certificate in the corpus's chains uses either;
that is to be measured by the before/after run of any spec that follows.

## Disclosure

Nothing here is a vulnerability by SECURITY.md's definition, since the
reference tool gives the same verdict. It rides with the unreleased steps
164–167 all the same.
