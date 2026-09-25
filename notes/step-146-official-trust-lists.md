# Step 146 — Files from current writers, under the official C2PA trust lists

*2026-09-25. A measurement. No code or test changed. One sentence of
`docs/comparison.md` was corrected by what was read on the way.*

## Why

Step 141 measured the 78 files from current writers without settings. On
a real site this verifier would run with trust configured, and the
obvious configuration is the C2PA's own lists. Before deciding on box
hashes (Bing's hard binding), the question was how much of the difference
with `c2patool` is left once trust is configured as it would be in
practice.

## What was run

- The lists: `c2pa-org/conformance-public`, `trust-list/C2PA-TRUST-LIST.pem`
  (30 certificates) and `C2PA-TSA-TRUST-LIST.pem` (22), at `5a94626`
  (2026-09-22). These are the same counts as step 113.
- Two settings files, kept in the scratch directory:
  - *legacy*: both lists as one `trust.trust_anchors` string;
  - *split*: `trust.anchors` with the signer list as `"manifest"` and the
    TSA list as `"tsa"`.
- On step 141's 78 files: `bin/c2pa-verify --settings <file>` at `dce58fe`,
  `c2patool` 0.27.22 and 0.28.0 with `--settings <file>`.

## Measured

Both settings files gave the same result in all three tools:

| writer | files | this verifier | 0.27.22 | 0.28.0 |
|---|---|---|---|---|
| Gemini, ChatGPT, GPT Image 2/2.5 (SSL.com), Pixel 10 Pro/Pro XL | 32 | `Trusted` | `Trusted` | `Trusted` |
| Lightroom (Julesvernex2), Firefly retouching | 13 | `Valid` | `Valid` | `Valid` |
| GPT Image 1/1.5, Pixel 10 | 11 | `Invalid` | `Invalid` | `Invalid` |
| GPT Image 2/2.5 signed under Trufo | 6 | `Trusted` | `Trusted` | `Invalid` (EKU, step 112) |
| Adobe Firefly | 7 | `Invalid` | `Valid` | `Valid` |
| Bing Image Creator | 9 | `Invalid` | `Valid` | `Invalid` |

**56 of 78 agree in all three, and 32 of them are now `Trusted`.** The
official lists make most of the current AI writers and the Pixel camera
reach an anchor.

### Firefly: the timestamp, and why `c2patool` trusts it

All seven Firefly files carry a claim v1 and a signer (`firefly-prod`,
issued by Adobe Inc.) that expired in 2024. Their timestamp comes from
*"DigiCert Adobe AATL Timestamp Responder"*, whose chain ends at DigiCert
Trusted Root G4. That root is on neither official list. This verifier
therefore judges the signer at *now*: `signingCredential.expired`,
*"the timestamp's TSA is not trusted"*. `c2patool` 0.28.0 reports
`timeStamp.trusted` with the explanation *"**legacy** timestamp cert
trusted"*.

The reason is in `c2pa-rs`'s source (`sdk/src/claim.rs`, `ada3e4a`):

```rust
if claim.version() == 1 {
    adjusted_settings.verify.verify_timestamp_trust = false;
}
```

For a claim v1, `c2pa-rs` does not check whether the TSA is trusted at
all. Step 40 §5 found `c2patool`'s TSA trust *"not derivable from the
0.90.22 source"*. At `ada3e4a` it is derivable. Whether 0.90.22 already
had this line was not re-checked.

With DigiCert Trusted Root G4 added as a `"tsa"` anchor
(`tests/Fixtures/trust/digicert-trusted-root-g4.pem`), **all seven
Firefly files are `Valid` here**, as at `c2patool`.

Bing's timestamp is the same case: *"DigiCert SHA256 RSA4096 Timestamp
Responder 2025 1"*, reported by 0.28.0 as *"legacy timestamp cert
trusted"*. Its signer expires on 2026-10-01. From then on, its verdict
here depends on the same anchor, whatever becomes of box hashes.

## Reasoned

- **ADR-0004's rule stays, and ADR-0005 supports it.** Trusting an
  unanchored TSA lets any timestamp authority place an expired or stolen
  signer's signature inside its validity: a wrong `Valid`. `c2pa-rs`
  switches the check off for claim v1, which is compatibility with old
  files, not a protection. The `docs/comparison.md` row now names both
  the source line and the protection.
- **The practical cost falls on older writers.** Every claim v1 file whose
  signer has expired is `Invalid` here, unless its TSA's root is
  configured. For Adobe and Microsoft that root is DigiCert Trusted Root
  G4, which the official TSA list does not hold.
- **For box hashes this means:** SPEC-042 and a box hash would make the
  Bing files readable. After 2026-10-01 they would be `Valid` here only
  with the DigiCert root configured as a TSA anchor.
