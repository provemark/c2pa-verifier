# Step 110 — the trust lists in the 2.4 text, and an EKU no list knows

*2026-09-24. Measurement and specification text only. The maintainer asked
for two things to be settled before SPEC-031 is approved: the section
number the draft quoted from 2.3, and the per-entry `trust_config`, which
no existing fixture could separate.*

## The 2.4 text

Source: the rendered 2.4 specification in `c2pa-org/specifications` at
`4eb2c67` (2026-09-16), `build/site/specifications/2.4/specs/C2PA_Specification.html`,
converted to text.

- **§14.4.1 *C2PA Signers*.** The number is confirmed. The text says more
  than the draft assumed: *"For each accepted EKU value, a list of 'trust
  anchor configurations'"*. Trust is per anchor and per EKU. The
  `notBefore`/`notAfter` of issue #9 are defined here too, not only in
  §15.7.
- **§14.4.2 *Time Stamping Authorities*.** The TSA anchors *"shall be
  separate from the lists for C2PA signers"*. That is a *shall*. It
  supports the maintainer's answer to SPEC-031's open question 1. It also
  means the legacy single `trust.trust_anchors`, which serves both here
  and in `c2patool`, is a departure kept for compatibility (SPEC-031 open
  question 5).
- **§14.4.3 *Private Credential Storage*** (what the settings call
  `allowed_list`). It *"shall only apply to validating signed C2PA
  manifests, and shall not apply to validating time-stamps"*. Two
  consequences:
  - the draft's `$tsaAllowedList` is gone from SPEC-031, and a `"tsa"`
    entry with an `allowed_list` is refused;
  - today's `TimestampCheck::tsaSettings()` hands the loose allowed list
    to the TSA check (`src/Timestamp/TimestampCheck.php:223`). That is
    read from the code, not measured, and recorded as SPEC-031 open
    question 6.
- **§15.12.1.1 and §15.12.1.2** confirm SPEC-012 amendment 7 by number.
  For JPEG the text is explicit: *"a validator shall match the total
  length of the exclusion range with that of the total length of all
  APP11 segments representing the C2PA Manifest"*. The amendment and the
  code now cite them.

## An EKU no list knows

This needs a signer whose only EKU is in neither the built-in list nor
`store.cfg`. None exists in the corpus, so one was made in the session
scratchpad; nothing here is in the repository yet:
- `openssl`, P-256: a root, an intermediate (`pathlen:0`), and a leaf with
  `keyUsage=digitalSignature` and `extendedKeyUsage=1.3.6.1.4.1.99999.1`;
- `fixture-unsigned.jpg` signed with `c2patool` 0.28.0.

`c2patool` refuses to sign with that leaf (*"the certificate is invalid"*)
unless its settings list the EKU in `trust.trust_config`.

Verified under nine settings. The `c2patool` column is 0.28.0; "this
verifier" is `bin/c2pa-verify`, which reads only the legacy shape today.

| | settings | `c2patool` 0.28.0 | this verifier |
|---|---|---|---|
| E1 | legacy root, no `trust_config` | `Invalid`, *missing required EKU* | `Invalid`, the same reason |
| E2 | legacy root, top-level `trust_config` = the EKU | `Trusted` | `Trusted` |
| E2b | legacy root, top-level = `store.cfg` | `Invalid` | `Invalid` |
| E3 | one entry, no `trust_config` | `Invalid` | — |
| E4 | one entry with the EKU, no top level | `Trusted` | — |
| **E5** | **entry A = the root without config, entry B = the test roots *with* the EKU** | **`Invalid`** | — |
| E6 | entry with the EKU, top level = `store.cfg` | `Trusted` | — |
| E7 | entry = emailProtection, top level = the EKU | `Trusted` | — |
| E8 | entry without config, top level = the EKU | `Trusted` | — |
| E9 | no anchors, top level = the EKU | `Valid`, untrusted | `Valid`, untrusted |

What the table says:
- An entry accepts the built-in EKUs, plus the top level, plus **its own**,
  and one entry's list never widens another's (E5). That is §14.4.1's
  model.
- The draft's proposal, a union across all entries, would have said
  `Trusted` for E5. That is laxer than both the oracle and the
  specification. It became SPEC-031 AC8 instead.
- On the legacy shape this verifier already agrees with 0.28.0, case for
  case.

## Found on the way: a one-certificate `x5chain` is refused

The first probe was signed with the leaf alone, directly under the root.
`c2pa-rs` then writes `x5chain` as a single CBOR byte string, not an array.
Both are allowed: C2PA 2.4 quotes RFC 9360, *"If a single certificate is
conveyed, it is placed in a CBOR byte string."* This verifier refuses it:
`CoseSign1` throws *"x5chain is not an array but a byte string"* and the
file is `Invalid` with `signingCredential.invalid`. Under the same settings
(E2), `c2patool` 0.28.0 says `Trusted`.

This refusal is stricter than the specification. It fails closed, so it
never produces a wrong `Valid`, but it would refuse a real signer that is
issued directly by a root. No corpus file has a one-certificate chain,
which is why nothing noticed. It belongs to SPEC-008 and is a separate
step. The probe was rebuilt with an intermediate so that the EKU question
could be measured.

## Not done here

- No fixture was committed. The probe keys live in the scratchpad. The
  tests-first step of SPEC-031 builds the probe with a `bin/make-*` script
  that shreds its keys, as `bin/make-ocsp-variants.php` does.
- SPEC-031 is still `draft`.
