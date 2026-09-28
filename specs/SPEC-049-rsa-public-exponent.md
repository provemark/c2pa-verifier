# SPEC-049: An RSA signing certificate needs a real public exponent

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-015 checks the signing certificate's RSA key by its **modulus** only:
at least 2048 bits (C2PA 2.4 §14.5.1.1, verbatim: "the modulus field of
the parameters field shall have a length of at least 2048 bits"). Nothing
checks the **public exponent** `e`.

With `e = 1`, `s^e mod n = s`: raising a signature to the public exponent
is the identity. The EMSA-PSS encoding of any message is then its own valid
signature, so a PS256 claim verifies **without the private key**. Step 174
measured it on a throw-away hierarchy: a leaf with `e = 1` whose signature
was computed with no key at all is `Trusted` in this verifier, in
`c2patool` 0.27.22 (c2pa-rs 0.90) and in `c2patool` 0.28.0 (c2pa-rs
0.91.0). It is a wrong `Trusted`.

c2pa-rs closed it in 0.91.1 (PR #2712, CAI-13156): its certificate
profile now refuses `e < 3`, an even `e`, and a negative `e`. The C2PA
specification states no exponent bound, so this is stricter than the
letter, as the fixed engine is, to close a keyless forgery (ADR-0005:
it prevents a wrong `Valid`/`Trusted`). RFC 8017 §3.1 defines a valid RSA
public exponent as an integer between 3 and `n − 1` coprime to λ(n), which
an even exponent never is (λ(n) is even).

## Scope

**In scope**

1. **The exponent, read from the key.** `openssl_pkey_get_details()`
   reports it as `['rsa']['e']`, a big-endian unsigned byte string
   (measured in step 174: `01`, `03`, `010001`). It is read without an
   arbitrary-precision extension: the value is below 3 when the string,
   leading zero bytes removed, is empty or a single byte `01` or `02`; it
   is even when the last byte's low bit is clear.
2. **The leaf.** A signing certificate whose RSA key (`rsaEncryption` or
   `id-RSASSA-PSS`) has `e < 3` or an even `e` is
   `signingCredential.invalid`, with an explanation naming the exponent,
   as SPEC-015's other key rules are. It is refused whatever the
   signature says, so a keyless `e = 1` forgery no longer reaches
   `claimSignature.validated` as a trusted result.
3. **An exponent that cannot be read** (no `['rsa']['e']` for an RSA key)
   is refused the same way: fail closed, not assumed valid.

**Out of scope**

- Intermediates and the trust anchor: see open question 1.
- An exponent larger than the modulus, and exponents coprime tests beyond
  evenness: OpenSSL already refuses `e ≥ n` on use, and a full coprimality
  check needs λ(n), which a verifier does not have.

## Behavior

The fixtures come from a new `bin/make-rsa-exponent-variants.php` (a
throw-away P-256 root as the one anchor, RSA leaves re-signing the PNG
fixture's claim with PS256, `x5chain` protected), following step 174's
probe. Keys stay outside the repository and are deleted at the end of the
run. Both `c2patool` versions judge each file and are recorded.

- **AC1 — `e = 1` is refused** *(required: error path)*
  - Given `rsa-exponent/e1.png`, whose signature is the PSS encoding of the
    Sig_structure with no private key
  - When it is verified with the root settings
  - Then the result is `Invalid` with `signingCredential.invalid`, whose
    explanation names the exponent. Both released `c2patool` versions call
    it `Trusted`; c2pa-rs 0.91.1 refuses it. The difference from the
    released tools is named in `docs/comparison.md`.

- **AC2 — an even exponent is refused** *(required: error path)*
  - Given a leaf with an even exponent (a synthetic certificate in a unit
    test if OpenSSL will not lay out the file; the note says which)
  - Then `signingCredential.invalid` naming the exponent.

- **AC3 — an unreadable exponent is refused** *(required: error path)*
  - Given an RSA leaf for which the key details carry no exponent
    (synthetic, in a unit test)
  - Then `signingCredential.invalid`, not a silent pass.

- **AC4 — real exponents stay as they were**
  - Given `rsa-exponent/e3.png` and `rsa-exponent/e65537.png`, each signed
    with its real key
  - Then each keeps today's verdict (`Trusted` with the root settings).

- **AC5 — nothing else moves**
  - Given every media fixture under no settings and every readable settings
    file, before and after
  - Then only the new fixtures move. No certificate in the corpus has an
    exponent below 3 or an even one; the before/after run is the
    measurement of that.

## References

- Specification: C2PA 2.4 §14.5.1.1 (the RSA modulus bound) and §13.2.1
  ("implementations shall refuse to generate or verify signatures with
  keys that are not correct for the algorithm choice"); RFC 8017 §3.1 (the
  RSA public exponent) and §9.1 (EMSA-PSS).
- Oracle: c2pa-rs 0.91.1 PR #2712 (read, no binary yet); `c2patool` 0.27.22
  and 0.28.0 on the files above, the reference for AC4 and for what AC1
  departs from.
- Measured: step 174 (`notes/step-174-engine-0.91.1-measured.md`).

## API sketch

```php
// Provemark\C2paVerifier\Trust\Certificate (@internal), from openssl_pkey_get_details()
public ?string $rsaExponent;   // big-endian unsigned bytes for an RSA key, null otherwise or unreadable
```

`CertificateProfileCheck`'s key rule for `RSA` gains the exponent test
beside the modulus test. No public API changes.

## Open questions

1. **Intermediates too?** *(blocking; the maintainer's decision).* Step 174
   showed the chain walk accepts an `e = 1` intermediate's forged link
   (`signingCredential.trusted` succeeded), but a clean probe of the net
   verdict did not resolve, and c2pa-rs 0.91.1's fix is scoped to the
   end-entity profile. Two options:
   - **A — leaf only**, exactly as c2pa-rs 0.91.1. Matches the reference
     tool; leaves an `e = 1` intermediate to the path check as today.
   - **B — leaf and path**: any certificate the walk verifies, the anchor
     aside, with `e < 3` or an even `e` makes the path
     `signingCredential.untrusted`, as SPEC-048 does for weak hashes. An
     `e = 1` intermediate would let anyone issue leaves under it without
     its key; this closes that. Stricter than every oracle, named in
     `docs/comparison.md`. Needs the clean intermediate probe first.
   Proposal: B, because the walk measurably accepts the forged link and
   fail closed is the project's first rule — but only after that probe
   gives the net verdict.
2. **A negative exponent** *(non-blocking).* DER INTEGER is signed; a
   negative `e` should never parse as an RSA key. If OpenSSL reports it as
   unsigned bytes, AC1/AC2 already catch the resulting value; otherwise
   AC3 refuses it. Proposal: no separate criterion unless a probe shows a
   negative exponent reaching the profile.

## Amendments

None.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | | |
| AC2 | | |
| AC3 | | |
| AC4 | | |
| AC5 | | |
