# Step 113 — anchors tied to EKUs (§14.5.1): measured, and nobody does it

*2026-09-24. Measurement and reading only: no specification, test or code
changed.*

## The rule

C2PA 2.4 §14.5.1.2, read in the 2.4 HTML of `c2pa-org/specifications` at
`4eb2c67`:

> When validating a certificate used to sign a C2PA Claim, the signing
> certificate shall have at least one of the EKUs for which the validator
> has an associated list of trust anchors (see Section 14.4.1), and the
> validator shall use only the trust anchors it associates with EKUs
> present in the certificate.

§14.4.1 frames the whole signer trust model that way: *"For each accepted
EKU value, a list of 'trust anchor configurations'"*, and for
`c2pa-kp-claimSigning` that list includes the C2PA Trust List. The 2.4
change list adds *"Restricted use of the C2PA Trust List to certificates
with the c2pa-kp-claimSigning EKU."*

## What the issuing side promises

The C2PA Certificate Policy (conformance program v0.2,
`c2pa-org/conformance-public/docs/v0.2/`): a claim-signing leaf and its
issuing CA *"MUST contain c2pa-kp-claimSigning in addition to at least
one of id-kp-emailProtection, id-kp-documentSigning"*. A conformant CA on
the list therefore never issues a C2PA signing certificate without the
claim-signing EKU. The rule in §14.5.1 is what catches a certificate that
did not follow that policy.

## Measured

- **The official list as anchors.** `C2PA-TRUST-LIST.pem` (30) and
  `C2PA-TSA-TRUST-LIST.pem` (22), as one legacy `trust_anchors` string, run
  over every signed corpus file with `bin/c2pa-verify`. **Two files reach
  an official anchor**: `google-20250919-pixel10-npld-picnic-table.jpg`
  (Google C2PA Root CA G3) and `openai-20260826-c2pa_2x.png` (SSL.com C2PA
  RSA Root CA 2025). Both leaves *and* both issuing CAs carry the
  claim-signing EKU next to emailProtection (OpenAI also documentSigning).
  Both are `Trusted` here and in `c2patool` 0.28.0.
- **No implementation ties anchors to EKUs.** `good.png` (an
  emailProtection-only leaf) is `Trusted` under its root in 0.27.22,
  0.28.0 and here. In `c2pa` 0.91.0, `has_allowed_eku()` checks the leaf
  against one global list, and `check_certificate_trust()` walks every
  anchor set without looking at the EKU (step 110). In this verifier,
  `CertificateProfileCheck` accepts the EKU and `ChainCheck` walks,
  independently. SPEC-031's per-entry `trust_config` widens an entry's
  EKUs, as `c2pa` 0.91.0 does; it never narrows them.
- **The catalogue does not name it.** No predicate in
  `encypherai/c2pa-conformance-suite` mentions EKUs, so
  `docs/conformance.md`, which is built from that catalogue, has no row
  for it.

## What it could cost

The case the rule is there for: a certificate **without** the
claim-signing EKU (emailProtection only, for example) that chains to a
C2PA Trust List anchor. Strict 2.4 does not trust it through that list.
This verifier and `c2patool` both say `Trusted`. It takes a CA on the list
issuing outside its own Certificate Policy. The corpus has no such file,
and no public source is known to have one. That is reasoned, not measured.

## Why it cannot simply be switched on

The settings format, shared with `c2patool` by design, has no way to say
"this anchor is for claim-signing". The legacy string is one pool.
`trust.anchors` entries have a `trust_kind`, but no EKU association:
their `trust_config` means *widen*, and SPEC-031 AC8 pins that to
`c2patool` 0.28.0's behaviour. The official list's own JSON (ETSI
TS 119 602) carries no EKU either (step 107). A validator that enforced
§14.5.1 would have to learn, from somewhere, which anchors are
claim-signing anchors. Every source of that knowledge would be this
project's own invention, or a bundled list, which the design rules out.

## Found on the way

§14.5.1.2 also says: *"Except for certificates accepted through the
private credential store …, a validator shall verify a certificate's
compliance with the Certificate Profile."* A certificate on the allowed
list is exempt from the profile. This verifier runs the profile on it
anyway (SPEC-015), which is stricter, never laxer. Recorded; nothing to
fix for safety.

## Decided

The maintainer chose route 1 of three: **name it and stop**. The
alternatives were a setting of this project's own, which would break the
shared-file rule, and asking upstream first. It is recorded in
`docs/conformance.md` under *Outside the catalogue* and in
`docs/comparison.md`. It will be revisited when the shared settings
format can say which anchors belong to which EKU.
