# SPEC-049: An RSA signing certificate needs a real public exponent

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-28                      |
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
4. **In the path** (open question 1, decided B). A certificate the walk
   verifies, the anchor aside, whose RSA key fails item 1's test makes the
   path `signingCredential.untrusted`, naming the certificate and the
   exponent. The timestamp authority's chain (SPEC-017) uses the same
   walk, so this holds there too, as `timeStamp.untrusted`.

**Out of scope**

- The trust anchor's own key: it is trusted because the settings name it.
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

- **AC6 — an intermediate with a bad exponent is untrusted** *(required: error path)*
  - Given a chain whose intermediate has an RSA key with `e = 1` or an even
    `e`, and whose leaf is otherwise sound (synthetic certificates in a
    unit test of the path check; the intermediate's own link need not
    verify, because the rule reads the exponent alone)
  - Then the path is `signingCredential.untrusted`, naming the
    intermediate and its exponent
  - And an intermediate with `e = 65537` in the same position is not
    refused on its exponent.

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

1. **Intermediates too?** *Decided 2026-09-28 by Maurice van Loon: B,
   leaf and path.* Any certificate the walk verifies, the anchor aside,
   whose RSA key has `e < 3` or an even `e` makes the path
   `signingCredential.untrusted`, naming the certificate and the exponent,
   as SPEC-048 does for weak hashes. The evidence is step 174: the walk
   accepted an `e = 1` intermediate's link (`signingCredential.trusted`
   passed). The rule refuses on the exponent alone, whatever the link's
   signature, so no forged chain is needed to test it: AC6 uses an
   intermediate whose only fault is its exponent. Stricter than every
   oracle (c2pa-rs 0.91.1 checks the end-entity profile only), named in
   `docs/comparison.md`. The net verdict of a fully forged intermediate
   chain was not measured and is not pursued.
   *Status 2026-10-08 (step 279):* decided by Maurice van Loon (2026-09-28), stricter than every oracle.
2. **A negative exponent** *(non-blocking).* DER INTEGER is signed; a
   negative `e` should never parse as an RSA key. If OpenSSL reports it as
   unsigned bytes, AC1/AC2 already catch the resulting value; otherwise
   AC3 refuses it. Proposal: no separate criterion unless a probe shows a
   negative exponent reaching the profile.
   *Status 2026-10-08 (step 279):* about a verdict, fail closed.

## Amendments

1. **2026-09-28, proposed before the tests.** AC1 as approved rests on a
   file whose signature is made without a private key. That file is not
   built for the repository. AC1 instead uses a leaf whose only fault is
   `e = 1` and whose signature does not verify: the rule refuses on the
   exponent alone, so the criterion is the presence of
   `signingCredential.invalid` naming the exponent. It is absent today
   (red) and present after the change (green). Step 174's measurement
   remains the evidence that such a leaf can otherwise reach `Trusted`.

   **Weight B:** a criterion's input changes; what it asserts does not.

   Confirmed by Maurice van Loon, 2026-09-28.

2. **2026-09-28, proposed before the tests.** No RSA certificate with a bad
   exponent is built for the repository, so AC1, AC2, AC3 and AC6 rest on
   the rule itself, as c2pa-rs 0.91.1's own test does (PR #2712,
   `rsa_public_exponent_profile`: 1, 2, 4 and −3 refused; 3 and 65537
   accepted; no certificate involved, read through `gh`).
   - The rule is one function over the exponent's bytes as
     `openssl_pkey_get_details()` gives them. AC1: `01` refused. AC2: `02`
     and `04` refused. AC3: an empty or absent exponent refused. AC6's
     values are the same, and the path uses the same function. `03` and
     `010001` are accepted. A negative exponent cannot be written in those
     unsigned bytes; open question 2 stands.
   - A second test per call site shows the function is consulted: the
     leaf profile and the chain walk, each on RSA certificates already in
     the corpus (`e = 65537`), which keep their verdicts (AC4), and each
     naming the exponent in its explanation only when the function
     refuses.
   - What no test covers: a real certificate with `e = 1` refused end to
     end. That rests on reading the call sites and on step 174's
     measurement, as upstream's rests on its code.

   **Weight B:** the criteria's inputs change; what they assert does not.

   Confirmed by Maurice van Loon, 2026-09-28.

3. **2026-09-28, found by the tests before the code.** Scope item 1 reads
   the exponent from `openssl_pkey_get_details()`'s `['rsa']['e']`. For a
   key whose algorithm is `id-RSASSA-PSS` (1.2.840.113549.1.1.10), PHP
   reports no `['rsa']` details at all: measured on PHP 8.5.8 with
   OpenSSL 3.6.3, `type` −1 and only `bits`, `key` and `type`, as
   `Certificate::keyFacts()` already notes. Of the corpus's JPEG, PNG and
   WebP files whose active leaf could be read, 45 have such a key
   (`c2pa-rs/CA_ct.jpg`, `c2pa-rs/CIE-sig-CA.jpg`,
   `c2pa-rs/update_manifest.jpg`, `matrix/ps256.jpg`, …) and 7 an
   `rsaEncryption` key. Under item 3 as approved, all 45 would become
   `signingCredential.invalid`, and the exponent rule would never see the
   key type it most needs to see.
   - Item 1 changes: the exponent is read from the certificate's
     subjectPublicKeyInfo with this verifier's own DER reader (SPEC-016).
     For both `rsaEncryption` and `id-RSASSA-PSS` the BIT STRING holds
     `RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }`
     (RFC 8017 appendix A.1.1; RFC 4055 §1.2). The value is the INTEGER's
     content octets, so a negative exponent is read as such and refused
     (open question 2 answered).
   - Item 3 now means a subjectPublicKeyInfo that does not hold that
     structure, which is a malformed key, not a key type PHP does not
     describe.
   - The leaf call-site tests alter that structure's exponent in the key
     the details report (`['key']`), instead of `['rsa']['e']`; the real
     key still verifies every signature. AC6 and the rule's own tests are
     unchanged.
   - AC4 gains `c2pa-rs/CA_ct.jpg`, an `id-RSASSA-PSS` leaf, keeping its
     verdict.

   **Weight A:** where a value is read from changes, and so does which keys
   a criterion covers.

   Confirmed by Maurice van Loon, 2026-09-28.

4. **2026-10-09, step 327, follows SPEC-014 amendment 8** *(confirmed by Maurice van Loon, 2026-10-09)* —
   AC4 and AC6 used `chain-constraints/rsa1024-intermediate.png` as the
   control that stays `Trusted` with its own exponent. SPEC-014 amendment 8
   refuses that intermediate for its 1024 bits. The controls now assert
   what this spec is about: no exponent fault is named. The file is
   `Valid`, its untrusted status naming the size. The rule of this spec is
   unchanged.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Trust/RsaExponentTest.php :: AC1: an exponent of 1 is refused; AC1: the leaf profile consults the rule / SPEC-049 | src/Trust/RsaExponent.php :: fault(); src/Trust/CertificateProfileCheck.php :: keyFaults() |
| AC2 | tests/Unit/Trust/RsaExponentTest.php :: AC2: an even exponent is refused / SPEC-049 | src/Trust/RsaExponent.php :: fault() |
| AC3 | tests/Unit/Trust/RsaExponentTest.php :: AC3: an exponent that cannot be read is refused; AC3: the leaf profile refuses an RSA key that holds no exponent / SPEC-049 | src/Trust/RsaExponent.php :: fromSubjectPublicKeyInfo(), fault(); src/Trust/Certificate.php :: rsaExponent() |
| AC4 | tests/Unit/Trust/RsaExponentTest.php :: AC4: real exponents are accepted; AC4: real RSA files keep their verdicts / SPEC-049 | src/Trust/RsaExponent.php :: fault() |
| AC5 | the before/after run of step 175 (23,352 runs, no line moved) | the whole verification path |
| AC6 | tests/Unit/Trust/RsaExponentTest.php :: AC6: an intermediate with a bad exponent is untrusted; AC6: the same intermediate with its own exponent is trusted / SPEC-049 | src/Trust/ChainCheck.php :: pathFault() |
