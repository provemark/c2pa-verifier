# SPEC-046: Name constraints and critical extensions in the chain

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

The chain walk of SPEC-014 checks, for every certificate that issues
another, `CA:TRUE`, `keyCertSign`, `pathLen` and validity (SPEC-014
amendment 4). It does not look at two things RFC 5280 requires of a path:

- **name constraints** (§4.2.1.10, §6.1.3 (b) and (c), §6.1.4 (g)): a CA may
  limit the names that certificates below it may carry;
- **critical extensions** (§4.2): *"A certificate-using system MUST reject
  the certificate if it encounters a critical extension it does not
  recognize or a critical extension that contains information that it
  cannot process."*

Step 164 measured both on a throw-away hierarchy, against both `c2patool`
versions and `openssl verify`:

| file | `openssl verify` | c2patool 0.27.22 and 0.28.0 | this verifier (v0.2.4) |
|---|---|---|---|
| `nc-outside`: a leaf `O=Other Org` under an intermediate that permits `O=Permitted Org` | error 47 | `Valid`, `signingCredential.untrusted` | **`Trusted`** |
| `critical-leaf`: an unknown critical extension in the leaf | error 34 | `Invalid`, `signingCredential.invalid` and `.untrusted` | **`Trusted`** |
| `critical-intermediate`: the same in the intermediate | error 34 at depth 1 | `Valid`, `signingCredential.untrusted` | **`Trusted`** |
| `nc-inside`, `plain`: the guards | OK | `Trusted` | `Trusted` |

Each of the three is a wrong `Trusted`. The first one lets a subordinate
CA that may only issue for one organisation have any organisation name
shown as the signer.

Read in `c2pa` (`main`, 2026-09-27), which explains the two layers of
`c2patool`'s answer:

- `sdk/src/crypto/cose/certificate_trust/openssl.rs` validates the chain
  with OpenSSL's `X509StoreContext`, flags `X509_STRICT` and
  `PARTIAL_CHAIN`, with no policy check. That gives the `untrusted`.
- `sdk/src/crypto/cose/certificate_profile.rs` checks the **leaf** against
  a list of extensions it handles. Any other extension marked critical
  makes the profile fail. That gives the leaf's `invalid`.

## Scope

**In scope**

1. **The extensions this verifier understands**, one list, by OID:
   - basicConstraints, keyUsage, extKeyUsage;
   - subjectKeyIdentifier, authorityKeyIdentifier;
   - subjectAltName, issuerAltName;
   - certificatePolicies, policyMappings, policyConstraints,
     inhibitAnyPolicy;
   - nameConstraints;
   - cRLDistributionPoints, freshestCRL;
   - authorityInfoAccess, subjectInfoAccess;
   - the OCSP no-check extension (RFC 6960 §4.2.2.2.1).

   These are the RFC 5280 §4.2 extensions, which OpenSSL recognises. The
   list is read from each certificate's DER (`tbsCertificate.extensions`,
   the `critical` flag included), because `openssl_x509_parse()` does not
   report criticality.
2. **A critical extension outside the list:**
   - in the leaf, it is a profile fault, `signingCredential.invalid`
     (SPEC-015), naming the OID, as `c2pa-rs`'s profile check gives it;
   - in the leaf or any certificate the walk passes through (an x5chain
     intermediate, or an anchor that issues), it is
     `signingCredential.untrusted`, as OpenSSL's path validation gives it.
   - The policy extensions are understood, but, as in both `c2patool`
     versions, not enforced (open question 2).
3. **Name constraints, for `directoryName` and `rfc822Name`.** When a
   certificate in the walked path carries nameConstraints, every
   certificate below it must satisfy them (RFC 5280 §6.1.3 (b), (c)):
   - `directoryName`: the subject must lie inside a permitted subtree and
     outside every excluded one, compared RDN by RDN (§7.1);
   - `rfc822Name`: every e-mail address in the subject alternative name,
     and the subject's `emailAddress` attribute, must satisfy the
     constraint (§4.2.1.10).

   A violation is `signingCredential.untrusted`, naming the constraint and
   the name.
4. **Fail closed for the other name forms.** A nameConstraints extension
   that holds a `dNSName`, `uniformResourceIdentifier`, `iPAddress` or any
   other form is `signingCredential.untrusted`, with an explanation that
   this verifier does not evaluate that form. That is stricter than
   OpenSSL, which evaluates them. No C2PA signing chain measured so far
   carries name constraints at all (open question 1).
5. **The same rules for a time-stamping authority's chain** (SPEC-017),
   which uses the same walk: `timeStamp.untrusted` there.

**Out of scope**

- Enforcing certificate policies (`-policy_check`); open question 2.
- The other checks OpenSSL's `X509_STRICT` adds.
- The profile check of intermediates beyond items 2 and 3.

## Behavior

The fixtures are step 164's, made by `bin/make-chain-constraint-variants.php`
(`tests/Fixtures/chain-constraints/`, with `root.settings.json`), and
their answers in `tests/Fixtures/c2patool/chain-constraints/`.

- **AC1 — a leaf outside a name constraint is untrusted** *(required: error path)*
  - Given `nc-outside.png`
  - When it is verified with `root.settings.json`
  - Then the result is `Valid` with `signingCredential.untrusted`, whose
    explanation names the constraint and the subject. That is the verdict
    and failure code both `c2patool` versions give.

- **AC2 — a leaf inside it is trusted**
  - Given `nc-inside.png`
  - Then the result is `Trusted`, as today and as both `c2patool`
    versions say.

- **AC3 — an unknown critical extension in the leaf** *(required: error path)*
  - Given `critical-leaf.png`
  - Then the result is `Invalid` with `signingCredential.invalid` naming
    the OID `1.3.6.1.4.1.99999.7`, and `signingCredential.untrusted`. That
    is the verdict and codes both `c2patool` versions give.

- **AC4 — an unknown critical extension in an intermediate** *(required: error path)*
  - Given `critical-intermediate.png`
  - Then the result is `Valid` with `signingCredential.untrusted` naming
    the intermediate and the OID, as both `c2patool` versions give.

- **AC5 — name forms that are not evaluated fail closed** *(required: error path)*
  - Given a hierarchy whose intermediate permits only `dNSName:example.com`
    (a new variant of the same script)
  - Then the result is `untrusted`, with an explanation that names the
    form. This is stricter than OpenSSL, which accepts a leaf without a DNS
    name; the difference is named in `docs/comparison.md`.

- **AC6 — nothing else moves**
  - Given every media fixture under no settings and every readable
    settings file, before and after
  - Then no verdict and no status list changes outside the new fixtures.
    The real chains (Adobe, Google, OpenAI, Microsoft, Truepic,
    the test roots) carry no name constraints and no unknown critical
    extension; the before/after run is the measurement of that.

## References

- Specification: RFC 5280 §4.2 (critical extensions), §4.2.1.10 (name
  constraints), §6.1.3 and §6.1.4 (path processing), §7.1 (name
  comparison); C2PA 2.4 §14.5 (the signing certificate profile).
- Oracle: `c2patool` 0.27.22 and 0.28.0 with `--settings
  tests/Fixtures/chain-constraints/root.settings.json`, and `openssl verify
  -purpose any`, on step 164's files.
- Read: `c2pa` `certificate_trust/openssl.rs` and `certificate_profile.rs`
  (`main`, 2026-09-27).
- Reasoned: that the list in scope item 1 is what OpenSSL recognises. It is
  checked against step 164's probes only.

## API sketch

```php
// Provemark\C2paVerifier\Trust\Certificate (@internal)
/** @var list<array{oid: string, critical: bool, value: string}> from tbsCertificate.extensions */
public array $extensions;
public function unknownCriticalExtensions(): array {}   // list<string> OIDs
public ?NameConstraints $nameConstraints;              // permitted / excluded, by form

// Provemark\C2paVerifier\Trust\NameConstraints (@internal, final readonly)
public function allows(Certificate $issued): ?string {} // null, or the reason it does not
```

No public API changes.

## Open questions

1. **The other name forms** *(non-blocking; proposal: fail closed, as
   scope item 4).* Evaluating `dNSName`, `URI` and `iPAddress` would match
   OpenSSL exactly. Refusing them is simpler, and it prevents a wrong
   `Trusted` too. Nothing in the corpus carries them, which
   the AC6 run will show.
2. **Certificate policies** *(non-blocking; proposal: not now).* Both
   `c2patool` versions and OpenSSL without `-policy_check` accept
   `policy-required.png`. Enforcing policies would be stricter than every
   oracle, and it prevents nothing that a trust anchor does not already
   decide.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Trust/ChainConstraintsTest.php :: AC1: a leaf outside a name constraint is untrusted / SPEC-046 | src/Trust/ChainCheck.php :: pathFault(); src/Trust/NameConstraints.php :: violation() |
| AC2 | tests/Unit/Trust/ChainConstraintsTest.php :: AC2: a leaf inside it is trusted / SPEC-046 | src/Trust/NameConstraints.php :: violation() (directoryName, within a subtree) |
| AC3 | tests/Unit/Trust/ChainConstraintsTest.php :: AC3: an unknown critical extension in the leaf / SPEC-046 | src/Trust/CertificateProfileCheck.php :: checkLeaf() (rule 9); src/Trust/ChainCheck.php :: pathFault(); src/Trust/CertificateExtensions.php :: UNDERSTOOD, unknownCritical() |
| AC4 | tests/Unit/Trust/ChainConstraintsTest.php :: AC4: an unknown critical extension in an intermediate / SPEC-046 | src/Trust/ChainCheck.php :: pathFault() |
| AC5 | tests/Unit/Trust/ChainConstraintsTest.php :: AC5: name forms that are not evaluated fail closed / SPEC-046 | src/Trust/NameConstraints.php :: fromDer() ($unevaluated), violation() |
| AC6 | tests/Unit/Trust/ChainConstraintsTest.php :: AC6: nothing else moves / SPEC-046; the before/after run of step 166 | src/Trust/Certificate.php :: $x509; src/Trust/CertificateExtensions.php :: fromDer() |
