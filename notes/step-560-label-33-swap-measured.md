# Step 560 — How far the label 33 swap reaches

*2026-11-23. Measurement and documentation only.* Step 549 found that
`c2patool` 0.29.0 reads a signer's chain under label 33 in the unprotected
header, so a certificate there can be swapped after signing
(`chain-constraints/x5chain-swapped.png`: `Trusted`, issuer "Adobe Inc").
Before deciding whether to tell upstream, Maurice asked whether the problem
really exists ("ga goed na of het label 33 probleem er echt is"). This step
records the answer.

> **Corrected in step 563.** The counts in section 3 and the claim that the
> 37 come from real writers are replaced: 30 are third-party files, 7 are
> this repository's probes (two of them claim v2), and 666 files have the
> chain protected. "No writer" holds for the 51 readable third-party files
> of this corpus only. See [step 563](step-563-corrections-559-560.md).

## 1. The probe still shows it

With `c2patool` 0.29.0 and `--settings
tests/Fixtures/chain-constraints/root.settings.json`:

| file | 0.29.0 |
|---|---|
| `plain.png` (chain protected, label 33) | `Trusted`, issuer "C2PA Verifier throw-away hierarchy" |
| `x5chain-unprotected.png` (label 33, unprotected only) | `Trusted`, the same issuer |
| `x5chain-swapped.png` (that chain swapped for a certificate of the same key) | `Trusted`, issuer "Adobe Inc" |

## 2. A file signed the usual way cannot be swapped

Three scratch probes, made from `plain.png` with its protected header and
signature untouched, and a chain *added* to the unprotected header (the
signer is `plain.pem`, the added one `swapped.pem`). They were built
outside the repository and not kept as fixtures. SHA-256:

| probe | what was added | 0.29.0 | here |
|---|---|---|---|
| `prot33-control` (`826e2d3c…`) | nothing (rebuilt, the control) | `Trusted`, real issuer | `Trusted` |
| `prot33-unprot33-swapped` (`1013233f…`) | label 33 with `swapped.pem` | no report (`Error: COSE verifier failure`) | `Invalid` (`signingCredential.invalid`: a chain in both headers) |
| `prot33-unprottext-swapped` (`b0fc3671…`) | `"x5chain"` with `swapped.pem` | `Trusted`, real issuer (the protected chain wins) | `Invalid` (the same) |

So 0.29.0 shows no swapped name on these. The swap works only on a file
whose signer put the chain in the unprotected header alone.

## 3. Who puts the chain there

A scan of the 932 media files of the corpus looked at each COSE_Sign1 for
where its chain is. It is a byte scan, not the verifier:

| where the chain is | files |
|---|---|
| protected header | 788 |
| unprotected only, under `"x5chain"` | 37 |
| unprotected only, under label 33 | 2 (`x5chain-unprotected`, `x5chain-swapped`: this repository's probes) |
| no chain found | 7 (deliberate probes, and files whose COSE the scan could not read) |

The 37 come from real writers of the claim v1 era: Bing images, the 2022
Adobe files, older `c2pa-rs` test files and `public-testfiles`. No writer
in the corpus puts the chain unprotected under label 33.

## 4. What a swap needs

The signature is checked against the key in the certificate shown, so a
swapped certificate must carry the same public key and chain to a trusted
anchor. In the probe this repository is its own CA. A real CA asks for
proof of possession of the key before it issues a certificate. So in
practice a swap can only exchange certificates of the key holder itself,
such as a renewed certificate on the same key, not put an arbitrary name
on a file. That is reasoned, not measured.

## Conclusion

- **What 0.29.0 adds** (label 33 unprotected) reaches no file a writer
  makes today.
- **The older form** (`"x5chain"` unprotected, claim v1) is swappable in
  every `c2patool` measured since 0.27.22. That was known since step 164.
  `c2pa` supports it on purpose ("permitted in older versions of C2PA").
  This verifier keeps it for claim v1 too (SPEC-047, ADR-0005).
- **Not a security report.** If anything, an ordinary issue: in a claim
  v2, 2.5 §13.2 says the chain *shall* be in the protected header. Whether
  to file one is Maurice's call.

`docs/comparison.md` (the `x5chain` row) records this. No code, test or
spec changed.
