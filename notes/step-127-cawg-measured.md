# Step 127 — CAWG, measured before anything is specified

*2026-09-24. Measurement only: no specification, test or code changed.*

## Why

Since SPEC-013 amendment 7, a manifest carrying a `cawg.identity`
assertion is refused here with `general.error`. The reason given was that
the identity credential inside is not validated, *"refused rather than
trusted unseen"*. It is the largest remaining difference with `c2patool`
on files that exist, so it was measured before deciding what 0.3 or 0.4
should do with it.

## Measured

**Where CAWG occurs.** Every manifest in the corpus was scanned for
`cawg.*` labels. Two files carry them, both made by `c2pa-rs` itself:

| file | assertions | `sig_type` |
|---|---|---|
| `c2pa-rs/C_with_CAWG_data.jpg` | `cawg.identity`, `cawg.training-mining` | `cawg.x509.cose`: a COSE_Sign1 (1,230 bytes) with its own certificate chain |
| `writers/c2pa-rs-cawg_ica.jpg` | `cawg.identity` | `cawg.identity_claims_aggregation`: a verifiable credential (2,779 bytes) from an identity-claims aggregator |

Among the real writers in the corpus (OpenAI, Google Pixel, Adobe
Photoshop and Lightroom, Amazon, Truepic, Nikon, TrustNXT), none carries
CAWG. Nor does any of the 115 files in `c2pa-org/public-testfiles`
(`git/trees/main`, no path mentions `cawg` or `identity`).

**What the tools say**, without settings and with
`full-plus-digicert-g4`:

| file | `c2patool` 0.27.22 | `c2patool` 0.28.0 | here |
|---|---|---|---|
| `C_with_CAWG_data.jpg` | `Valid`, `cawg.identity.well-formed` | `Valid`, `cawg.x509.signature.validated`, `cawg.identity.well-formed`, and `cawg.x509.credential.untrusted` among the failures | `Invalid`, `general.error` |
| `c2pa-rs-cawg_ica.jpg` | `Valid` / `Trusted`, `cawg.ica.credential_valid` (twice) | `Valid` / `Trusted`, `cawg.ica.untrusted_issuer` | `Invalid`, `general.error` |

**In both `c2patool` versions, CAWG never changes `validation_state`.**
`C_with_CAWG_data.jpg` stays `Valid` in 0.28.0 while its own failure list
names `cawg.x509.credential.untrusted`. CAWG results travel next to the
C2PA verdict, not inside it. This verifier does the opposite, and its
verdict differs from both oracles on both files.

## Read and reasoned

- **The cause of the difference is a choice, not a bug.** Amendment 7
  refused because a consumer seeing `Trusted` next to a CAWG-named
  identity might read the identity as verified. That concern is real.
  The oracles answer it differently: the C2PA verdict covers the C2PA
  claim, and CAWG gets codes of its own.
- **A middle step would align the verdict** without validating anything
  new: stop refusing, and report every `cawg.identity` as present and
  *not validated here*, in words a consumer cannot mistake for a
  verification. The state would then equal both oracles' on both files.
  One question is open: which status code to use. The CAWG codes come from
  the CAWG specification, not C2PA §15, and none of them means *"not
  looked at"*.
- **Full validation comes in two sizes:**
  - **`cawg.x509.cose`** is a COSE_Sign1 over the `signer_payload`, with an
    `x5chain`: close to what M3 and M5 already do. The anchors would come
    from SPEC-031's `"cawg"` entries, which are parsed today and used for
    nothing.
  - **`cawg.identity_claims_aggregation`** is a W3C verifiable credential
    from an aggregator named by a DID. A `did:web` issuer cannot be
    resolved without the network, which this verifier never uses.
    `c2pa-rs` has an allow-list of issuer DIDs for this
    (`trusted_ica_issuers`, which SPEC-031 reads and ignores). Offline,
    that list is the only way in.
- **What is missing to judge the real-world weight:** a CAWG file from a
  real product. The corpus has none. Adobe's Content Authenticity web app
  is said to attach CAWG identities (for example via LinkedIn or
  Behance); that is unverified here, and a file made there would settle
  it.

## Summary for the maintainer

- CAWG costs this verifier its agreement with `c2patool` on 2 of 2 files
  that carry it, both test files from `c2pa-rs`. It costs nothing on any
  real file in the corpus, because none carries CAWG.
- The middle step (read, report *not validated*, stop refusing) would be
  one small specification, plus one question about the status code.
- Full validation is a milestone, and the x509 path is much smaller than
  the ICA path.
