# SPEC-078: Files signed with the conformance programme's test certificate

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-23                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The conformance programme hands an applicant test inputs, among them "a
(test) C2PA Trust List" (step 367). Which test lists it uses is not
public. The one public part of a test set-up is in
`contentauth/c2pa-conformance-tool` (`6d10ee0`, Apache-2.0): a
"C2PA Conformance Test Root" embedded in `src/lib/CertificateManager.svelte`,
and `public/test-certs/test-signing-bundle.zip`, which holds a leaf
certificate, "C2PA Conformance Test Signing", and its private key,
published on purpose. Its `TEST_CERTIFICATES.md` says files signed with
it "will validate as **trusted**" in the tool's test mode. That was read,
not measured.

Measured on 2026-10-23 with a prototype outside the repository:

- The leaf is EC P-256, valid 2026-02-08 to 2036-02-06, issued directly
  by the root (`openssl verify` OK), `CA:FALSE`, its only EKU
  id-kp-documentSigning (1.3.6.1.5.5.7.3.36). The root is self-signed,
  `CA:TRUE`, the same validity. The bundle holds no time-stamp
  authority.
- `fixture-unsigned.jpg`, `.png` and `.webp`, signed with it by
  `c2patool` 0.28.2: this verifier says `Trusted` with the root as
  `--trust-list`, and `Valid` (`signingCredential.untrusted`) without;
  under the official lists of `trust/conformance-public/` also `Valid`,
  `signingCredential.untrusted` the only failure.
- One byte changed in the image data after signing (JPEG and PNG: 40
  bytes before the end; WebP: offset 40, inside `VP8L`, since the `C2PA`
  chunk comes last): `Invalid`, `assertion.dataHash.mismatch`, here and in
  `c2patool` (root and `store.cfg`). The first WebP try changed a byte 40
  before the end, inside the store, and gave `claimSignature.mismatch`.
- `c2patool` 0.28.2 and 0.28.1, with the root as `trust_anchors` and no
  `trust_config`, say `Invalid`: `signingCredential.invalid`,
  "certificate missing required EKU". With the sister repository's
  `store.cfg` as `trust_config` they say `Trusted`. `c2pa-rs` 0.91.1 has
  documentSigning in its built-in `valid_eku_oids.cfg`, but settings
  without a `trust_config` leave it only emailProtection, timeStamping
  and OCSPSigning (`certificate_trust_policy.rs`).
- C2PA 2.4 §14.5.1.2: "the validator shall use only the trust anchors it
  associates with EKUs present in the certificate", and §14.4.1 ties the
  C2PA Trust List to `c2pa-kp-claimSigning`. Read strictly, a
  documentSigning-only certificate under that list is untrusted. Neither
  this verifier nor `c2patool` ties anchors to EKUs; `docs/conformance.md`
  already names that gap.

Maurice chose (2026-10-23) to pin the behaviour as it is, name both
differences, and leave anchor-to-EKU association for a spec of its own.
He also allowed the generator to use the public test key, kept outside
the repository.

## Scope

**In scope**

- `bin/make-conformance-test-variants.php <scratch-dir> <c2patool-0.28.2>
  <test-signing-bundle.pem>`: signs `fixture-unsigned.{jpg,png,webp}`
  with the bundle's leaf and key, makes one variant of each with one
  byte of the image data changed after signing (outside the manifest
  store, at the offsets measured), and records `c2patool`'s answers.
  The key is copied into the scratch directory only.
- `tests/Fixtures/conformance-test/`: the six files, the root and leaf
  certificates (public), and a README naming their source, licence and
  SHA-256s. `tests/Fixtures/c2patool/conformance-test/`: `c2patool`'s
  answers.
- The two differences named in `docs/comparison.md` and
  `docs/conformance.md`.

**Out of scope** (each needs its own spec before it may be built)

- Tying trust anchors to EKUs (§14.5.1.2).
- Running the conformance tool itself as an oracle.
- Other media types; a time-stamped variant (the bundle has no TSA).

## Behavior

- **AC1 — trusted under the test root, through the harness**
  - Given the three signed files, the root as `--trust-list`, and
    `--crjson --at 2026-10-23T12:00:00Z`
  - When run
  - Then each exits 0, the report says `Trusted` with
    `signingCredential.trusted` and no failure, and the crJSON passes the
    whole schema with no exception.

- **AC2 — untrusted without it, and under the official lists**
  - Given the same files without any list, and with the official lists
    of `trust/conformance-public/` (SPEC-077)
  - When verified
  - Then each is `Valid` with `signingCredential.untrusted` as its only
    failure: the test root is not on the production list.

- **AC3 — a changed byte is caught** *(required: error path)*
  - Given the three variants with one image byte changed after signing
  - When verified with the root as `--trust-list`
  - Then each is `Invalid` with `assertion.dataHash.mismatch`, and
    `c2patool` (root and `store.cfg`) says the same.

- **AC4 — against `c2patool`, both ways it can be run**
  - Given `c2patool`'s recorded answers
  - When compared
  - Then with the root and `store.cfg` the state and the failure codes
    equal this verifier's (with the same settings) for all six files; with
    the root alone `c2patool` says `Invalid`
    (`signingCredential.invalid`) on the three signed files and this
    verifier `Trusted`: the named difference, kept.

- **AC5 — no key in the repository**
  - Given `tests/Fixtures/conformance-test/` and the generator
  - When searched
  - Then no file holds a `PRIVATE KEY` block, and the generator refuses
    a scratch directory inside the repository.

## References

- Specification: C2PA 2.4 §14.4.1, §14.5.1.1, §14.5.1.2.
- Oracle: `c2patool` 0.28.2, with the root as `trust_anchors`, with and
  without `store.cfg` as `trust_config`.
- Source of the certificates: `contentauth/c2pa-conformance-tool`
  `6d10ee0` (Apache-2.0).
- Measured: the prototype results in Problem.
- Reasoned: that the programme's tool calls these files trusted (read in
  its documentation).

## API sketch

No library change. A generator script, fixtures, two documentation
entries and one test file.

## Open questions

- None blocking. Anchor-to-EKU association (§14.5.1.2) stays open, named
  in `docs/conformance.md`.
  *Status 2026-10-23 (step 381):* settled; approved without changes.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/ConformanceTestRootTest.php :: AC1: trusted under the test root, through the harness, schema-valid (3 types) / SPEC-078 | tests/Fixtures/conformance-test/signed.*; bin/make-conformance-test-variants.php |
| AC2 | tests/Unit/Verifier/ConformanceTestRootTest.php :: AC2: untrusted without a list, and under the official lists (3 types) / SPEC-078 | tests/Fixtures/conformance-test/signed.* |
| AC3 | tests/Unit/Verifier/ConformanceTestRootTest.php :: AC3: one changed byte of the image data is a data-hash mismatch, here and in c2patool (3 types) / SPEC-078 | tests/Fixtures/conformance-test/edited.*; bin/make-conformance-test-variants.php :: ctStoreRanges() |
| AC4 | tests/Unit/Verifier/ConformanceTestRootTest.php :: AC4: c2patool with the root and store.cfg agrees; with the root alone it says invalid, the named difference (3 types) / SPEC-078 | tests/Fixtures/c2patool/conformance-test/ (12 answers); docs/comparison.md; docs/conformance.md |
| AC5 | tests/Unit/Verifier/ConformanceTestRootTest.php :: AC5: no private key in the fixtures, and the generator refuses a scratch directory inside the repository / SPEC-078 | bin/make-conformance-test-variants.php (the scratch check comes first) |

The generator takes a fourth argument, the root's PEM, which the Scope
did not list: the bundle holds the leaf and its key but not the root,
which the tool embeds in `src/lib/CertificateManager.svelte`. It signs
with `--settings root-store-cfg.settings.json`, so that `c2patool`'s own
check after signing passes (the prototype, without settings, exited 1
on the missing EKU). The WebP store check needed `WebpManifestStoreExtractor`;
the first run named the abstract RIFF class and stopped before writing
WebP, and its key directory was removed by the shutdown handler all the
same.

The tests were run red first: 13 of 13 failed before the fixtures
existed. This spec adds no library code, so there is no mutation to
watch; AC4 holds both of `c2patool`'s answers, and AC2 the official
lists, as the checks that the verdicts are not the fixtures' own.
`composer check`: 1,222 passed, 1 skipped.
