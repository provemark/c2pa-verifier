# SPEC-048: No SHA-1 or MD5 signature in the certificate path

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The chain walk (SPEC-014) verifies each link with `openssl_x509_verify()`,
and accepts whatever signature algorithm OpenSSL accepts. SPEC-015 checks
the algorithm of the **leaf** only, against C2PA 2.4 §14.5's list. For
RSASSA-PSS it looks at the name `rsassaPss` and not at the hash inside.

Step 168 measured an intermediate that the root signed with
`ecdsa-with-SHA1`. It is `Trusted` in this verifier, in both `c2patool`
versions and in `openssl verify` (OpenSSL 3.6.3, default security level).
So there is no wrong verdict relative to the oracles.

Maurice van Loon decided on 2026-09-27 to be stricter here (ADR-0005:
it prevents unchecked trust). Chosen-prefix collisions on SHA-1 have been
shown in practice (reasoned from the published attack, not reproduced).
A CA that still signs certificates with SHA-1 could have a certificate
forged that it never issued, and the chain walk would trust it. MD5 is
broken the same way, and worse. An RSA key of 1024 bits is left as the
oracles judge it (decided the same day).

## Scope

**In scope**

1. **A weak hash** is one of these, read from the certificate's own DER
   (`Certificate.signatureAlgorithm`, the outer AlgorithmIdentifier):
   - `md2WithRSAEncryption`, `md4WithRSAEncryption`, `md5WithRSAEncryption`;
   - `sha1WithRSAEncryption` and the older `sha1WithRSA` (1.3.14.3.2.29);
   - `ecdsa-with-SHA1`, `dsa-with-sha1`;
   - `RSASSA-PSS` whose `hashAlgorithm` is SHA-1 or MD5, **or absent**:
     the default is SHA-1 (RFC 4055 §3.1).
2. **In the path.** A certificate the walk verifies, apart from the anchor,
   whose signature uses a weak hash makes the path
   `signingCredential.untrusted`, naming the certificate and the
   algorithm. That covers an x5chain intermediate and the certificate an
   anchor issued. The anchor's own signature binds nothing: it is trusted
   because the settings name it.
3. **The leaf.** SPEC-015's rule keeps the leaf's algorithm. It is extended
   so that an `rsassaPss` leaf whose hash is weak by item 1 is
   `signingCredential.invalid`, as any other leaf algorithm outside §14.5's
   list is. The leaf is not also made `untrusted`, so its status list
   stays what the profile gives (open question 1).
4. **The timestamp authority's chain** (SPEC-017) uses the same walk, so
   item 2 holds there too, as `timeStamp.untrusted`.

**Out of scope**

- The algorithm of an OCSP response's signature, and of the timestamp
  token's own CMS signature (SPEC-016 already limits the latter).
- RSA keys under 2048 bits in intermediates (decided: as the oracles).

## Behavior

The fixtures come from `bin/make-chain-constraint-variants.php`
(`tests/Fixtures/chain-constraints/`). Step 169a adds:

- `md5-intermediate`: an intermediate that the RSA-1024 intermediate signs
  with `md5WithRSAEncryption`, issuing an ordinary leaf;
- `pss-sha1-intermediate`: the same, signed with RSASSA-PSS with no hash
  parameter (so SHA-1);
- `pss-sha1-leaf`: a leaf that the RSA-1024 intermediate signs that way.

Both `c2patool` versions and `openssl verify` judge each file. If OpenSSL
will not create one of them, the criterion rests on a synthetic
certificate in a unit test instead, and the note says so.

- **AC1 — a SHA-1 intermediate is untrusted** *(required: error path)*
  - Given `sha1-intermediate.png` (step 168)
  - When it is verified with `root.settings.json`
  - Then the result is `Valid` with `signingCredential.untrusted`, whose
    explanation names the intermediate and `ecdsa-with-SHA1`. Both
    `c2patool` versions call it `Trusted`; the difference is named in
    `docs/comparison.md`.

- **AC2 — an MD5 intermediate is untrusted** *(required: error path)*
  - Given `md5-intermediate.png`
  - Then `Valid` with `signingCredential.untrusted` naming
    `md5WithRSAEncryption`.

- **AC3 — RSASSA-PSS with its default hash is weak** *(required: error path)*
  - Given `pss-sha1-intermediate.png` and `pss-sha1-leaf.png`
  - Then the first is `Valid` with `signingCredential.untrusted`, and the
    second `Invalid` with `signingCredential.invalid`, each naming SHA-1.

- **AC4 — what stays as it was**
  - Given `rsa1024-intermediate.png`, `plain.png`, and a PSS leaf with
    SHA-256 if one exists in the corpus
  - Then each keeps today's verdict.

- **AC5 — nothing else moves**
  - Given every media fixture under no settings and every readable settings
    file, before and after
  - Then only the new fixtures move. No chain in the corpus uses a weak
    hash; the before/after run is the measurement of that.

## References

- Specification: C2PA 2.4 §14.5 (the signing certificate's algorithms);
  RFC 4055 §3.1 (RSASSA-PSS parameters and their SHA-1 default); RFC 5280
  §4.1.1.2 (signatureAlgorithm).
- Oracle: `c2patool` 0.27.22 and 0.28.0 and `openssl verify` on the files
  above, with `root.settings.json`. They are the reference for AC4 and for
  what AC1–AC3 depart from.
- Reasoned: the practicality of SHA-1 chosen-prefix collisions, from the
  published work, not reproduced here.

## API sketch

```php
// Provemark\C2paVerifier\Trust\CertificateExtensions (@internal), from the DER
public string $signatureOid;          // the outer AlgorithmIdentifier
public ?string $pssHashOid;           // RSASSA-PSS only; SHA-1 when the parameter is absent
public function weakHash(): ?string {} // the algorithm's name when it rests on SHA-1 or MD5, else null
```

No public API changes.

## Open questions

1. **A SHA-1 leaf: `untrusted` too?** *(non-blocking; proposal: no).* The
   profile already makes it `Invalid`. Adding `untrusted` would change the
   status list of a leaf that `c2patool` also refuses, and it would decide
   nothing.
   *Status 2026-10-08 (step 279):* about the report; the leaf is already `Invalid`.
2. **A trust anchor with a weak self-signature** *(non-blocking; proposal:
   allowed).* The anchor is trusted by configuration, not by its
   signature; RFC 5280 §6.1 does not verify it either.
   *Status 2026-10-08 (step 279):* about a verdict, measured 2026-10-08 (step 279): a root self-signed over SHA-1 as the anchor, intermediate and leaf over SHA-256, is `Trusted` in `c2patool` 0.27.22 and 0.28.1 and here; `openssl verify -x509_strict` says OK. Equal to every oracle.

## Amendments

1. **2026-09-27, step 170a, measured before the tests.** The three new
   probes were made as the Behavior section describes. OpenSSL signs MD5
   and RSASSA-PSS over SHA-1 without complaint, and `openssl verify` and
   both `c2patool` versions call all three `Trusted`. That includes
   `pss-sha1-leaf`, so `c2pa-rs`'s profile does not look at a PSS leaf's
   hash either. AC3's leaf refusal is therefore stricter than `c2patool`
   too, not only the path rule. The `docs/comparison.md` row names both.
   No criterion changed.

   **Weight C:** the evidence, and one more named difference.

   Confirmed by Maurice van Loon, 2026-09-27 (step 171).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Trust/WeakHashTest.php :: AC1: a SHA-1 intermediate is untrusted / SPEC-048 | src/Trust/ChainCheck.php :: pathFault(); src/Trust/CertificateExtensions.php :: weakHash(), WEAK_SIGNATURES |
| AC2 | tests/Unit/Trust/WeakHashTest.php :: AC2: an MD5 intermediate is untrusted / SPEC-048 | src/Trust/CertificateExtensions.php :: WEAK_SIGNATURES |
| AC3 | tests/Unit/Trust/WeakHashTest.php :: AC3: RSASSA-PSS with its default hash is weak / SPEC-048 | src/Trust/CertificateExtensions.php :: fromDer() ($pssHashOid), weakHash(); src/Trust/CertificateProfileCheck.php :: checkLeaf() (rule 4) |
| AC4 | tests/Unit/Trust/WeakHashTest.php :: AC4: what stays as it was / SPEC-048 | src/Trust/ChainCheck.php :: pathFault() (the anchor and the leaf excluded) |
| AC5 | tests/Unit/Trust/WeakHashTest.php :: AC5: nothing else moves / SPEC-048; the before/after run of step 170 | the whole verification path |
