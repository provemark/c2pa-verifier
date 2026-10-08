# SPEC-047: Where the certificate chain may be

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

SPEC-008 reads the signer's `x5chain` from the first place it finds one, in
this order: protected label 33, protected `"x5chain"`, unprotected 33,
unprotected `"x5chain"`. It records whether the chain was protected
(`chainProtected`), but nothing uses that.

The unprotected header is not covered by the signature (RFC 9052 §3). A
chain there binds nothing: the signature is checked against the key in
whatever certificate the header holds. Step 164 measured four shapes, each
on the PNG fixture re-signed under a throw-away root:

| file | c2patool 0.27.22 and 0.28.0 | this verifier (v0.2.4) |
|---|---|---|
| `x5chain-unprotected`: the chain under label 33, unprotected | exit 1, *could not find signing certificate chain* | **`Trusted`** |
| `x5chain-both`: a chain protected (33) and another unprotected (`"x5chain"`) | exit 1, *COSE verifier failure* | **`Trusted`**, with the protected chain |
| `x5chain-text-unprotected`: the chain under `"x5chain"`, unprotected | `Trusted` | `Trusted` |
| `x5chain-text-swapped`: that chain replaced after signing by a second certificate for the same key, naming `O=Adobe Inc` | **`Trusted`, signer "Adobe Inc"** | **`Trusted`, signer "Adobe Inc"** |

Read in `c2pa` `sdk/src/crypto/cose/sign1.rs` (`cert_chain_from_sign1`,
`main`, 2026-09-27):

- the protected header is searched for label 33 or `"x5chain"`;
- only when neither is there, the unprotected header is searched, and only
  for `"x5chain"` (*"This was permitted in older versions of C2PA"*);
- a chain in both headers is `MultipleSigningCertificateChains`.

SPEC-006 recorded that real claim v1 files carry the chain that way (the
2022 Adobe fixture, `c2patool/adobe-20220124-C.json`).

Maurice van Loon decided on 2026-09-27 (option A):

- follow `c2pa-rs` for the first two shapes;
- go further than `c2pa-rs` for the third. In a claim v2 or later, a
  chain that is not protected is refused.

A claim v1 keeps the older form. ADR-0005 allows the stricter rule
because it prevents unchecked trust in who signed: it closes the swap for
every claim this verifier reads as current.

## Scope

**In scope**

1. **Never under label 33 unprotected.** An unprotected chain is looked for
   only under `"x5chain"`, as `c2pa-rs` does. A chain only under label 33
   unprotected is *"no x5chain"*: `signingCredential.invalid`.
2. **Never in both headers.** A chain in the protected header and another
   in the unprotected one is `signingCredential.invalid`, naming both, as
   `c2pa-rs`'s `MultipleSigningCertificateChains` refuses it.
3. **Protected in a claim v2 or later.** For a manifest whose claim is
   `c2pa.claim.v2` (or later), a chain found only in the unprotected header
   is `signingCredential.invalid`: *"the claim is v2, and its signer's
   certificate chain is not in the protected header, so the signature does
   not cover which certificate signed"*. A v1 claim may keep it
   unprotected under `"x5chain"`.
4. The same holds for every manifest the verifier validates (ingredients
   included), and the report's `signature_info` is then not filled from
   an unprotected chain in a v2 claim.

**Out of scope**

- The timestamp's certificates (the TSA's own chain is inside its token,
  which the TSA signed).
- `x5t`, `x5u` and `x5bag` (SPEC-008 already refuses them).

## Behavior

The fixtures are step 164's (`tests/Fixtures/chain-constraints/`), their
answers in `tests/Fixtures/c2patool/chain-constraints/`.

- **AC1 — label 33 in the unprotected header is not a chain** *(required: error path)*
  - Given `x5chain-unprotected.png`
  - Then the result is `Invalid` with `signingCredential.invalid`, whose
    explanation says no chain was found where one may be. Both `c2patool`
    versions refuse the file without a report.

- **AC2 — a chain in both headers is refused** *(required: error path)*
  - Given `x5chain-both.png`
  - Then the result is `Invalid` with `signingCredential.invalid` naming
    both headers. Both `c2patool` versions refuse the file.

- **AC3 — an unprotected chain in a v2 claim is refused** *(required: error path)*
  - Given `x5chain-text-unprotected.png` and `x5chain-text-swapped.png`
    (claim v2)
  - Then both are `Invalid` with `signingCredential.invalid`, and neither
    report names "Adobe Inc". Both `c2patool` versions call both `Trusted`;
    the difference is named in `docs/comparison.md`.

- **AC4 — a v1 claim keeps the older form**
  - Given `public-testfiles/adobe-20220124-C.jpg` (claim v1, chain
    unprotected under `"x5chain"`), and every other claim v1 file in
    `public-testfiles/`
  - Then their reports are unchanged, as `c2patool` gives them
    (`c2patool/adobe-20220124-C.json`).

- **AC5 — nothing else moves**
  - Given every media fixture under no settings and every readable
    settings file, before and after
  - Then only the new fixtures move. Every other v2 claim in the corpus
    carries its chain in the protected header; the before/after run is
    the measurement of that.

## References

- Specification: RFC 9052 §3 (the unprotected header is not signed);
  RFC 9360 (`x5chain`); C2PA 2.4 §14.5 as SPEC-008 cites it. The C2PA text
  on the chain's placement was not read in this draft (open question 1).
- Oracle: `c2patool` 0.27.22 and 0.28.0 with `--settings
  tests/Fixtures/chain-constraints/root.settings.json` on step 164's files.
- Read: `c2pa` `sdk/src/crypto/cose/sign1.rs` (`main`, 2026-09-27).

## API sketch

```php
// CoseSign1::fromBytes() keeps $chainProtected; the placement rules live where the
// claim version is known (the signature checks), not in the COSE parser, which does not know it
```

No public API changes.

## Open questions

1. **The C2PA text** *(non-blocking).* The published HTML of C2PA 2.2 was
   fetched and came back truncated before the signature sections. So the
   rule rests on `c2pa-rs`'s behaviour, its comment, and the maintainer's
   decision, not on a quoted sentence. It is to be quoted from C2PA 2.4
   before implementation, if the text can be reached.
   *Status 2026-10-08 (step 279):* a process question (quoting the text), no verdict.
2. **Where a v1 claim's unprotected chain is shown** *(non-blocking).* It
   is still unsigned. A line in the report, or in `docs/comparison.md`
   only? Proposal: only the comparison row, since `c2patool` says nothing.
   *Status 2026-10-08 (step 279):* about the report's display, no verdict.

## Amendments

1. **2026-09-27, step 167, while building.** `ClaimSignatureCheck` is left
   as it is: the signature verifies under the key in the header, and
   `claimSignature.validated` says only that. The refusal comes from the
   chain and profile checks, and from the empty `signature_info`, through
   `CoseSign1::ofManifest()`. The v2 case therefore shows
   `signingCredential.invalid` beside `claimSignature.validated`, as the
   missing-chain case of SPEC-008 AC10 does.

   **Weight C:** where the rule sits, not what it decides.

   Confirmed by Maurice van Loon, 2026-09-27 (step 167).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Cose/X5chainPlacementTest.php :: AC1: label 33 in the unprotected header is not a chain / SPEC-047 | src/Cose/CoseSign1.php :: findChain() |
| AC2 | tests/Unit/Cose/X5chainPlacementTest.php :: AC2: a chain in both headers is refused / SPEC-047 | src/Cose/CoseSign1.php :: findChain() |
| AC3 | tests/Unit/Cose/X5chainPlacementTest.php :: AC3: an unprotected chain in a v2 claim is refused / SPEC-047 | src/Cose/CoseSign1.php :: ofManifest(); its callers in src/Verifier/Verifier.php (signatureInfo(), unprotectedHeader(), chainOf()), src/Trust/ChainCheck.php :: check(), src/Trust/CertificateProfileCheck.php :: check(), src/Timestamp/TimestampCheck.php :: check() |
| AC4 | tests/Unit/Cose/X5chainPlacementTest.php :: AC4: a v1 claim keeps the older form / SPEC-047 | src/Cose/CoseSign1.php :: ofManifest() (claim version 1) |
| AC5 | tests/Unit/Cose/X5chainPlacementTest.php :: AC5: nothing else moves / SPEC-047; the before/after run of step 167 | the whole verification path |
