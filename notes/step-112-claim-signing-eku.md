# Step 112 — `eku-c2pa.png`: not a wrong `Valid` here, a change in the oracle

*2026-09-24. Measurement only: no specification, test or code changed.*

## The question

Step 107 found `profile/eku-c2pa.png` going from `Valid` to `Invalid` with
`signingCredential.invalid` (*"certificate missing required EKU"*) between
`c2patool` 0.27.22 and 0.28.0. This verifier says `Trusted` under the
file's own root. The file is one of SPEC-015's own variants: a throwaway
P-256 leaf whose only EKU is the C2PA claim-signing OID
`1.3.6.1.4.1.62558.2.1`. Step 61 had already seen the Go verifier refuse
it. After step 108, every such divergence has to be settled before
anything else: is this a wrong `Valid`?

## Measured

**The settings are not the cause.** `eku-c2pa.png` under its root with
five different `trust_config`s (none, `store.cfg`, `store.cfg` plus the
C2PA OID, emailProtection only, empty): 0.27.22 says `Trusted` all five
times, and 0.28.0 says `Invalid` all five times. That includes the case
that names the OID. `good.png` (an emailProtection leaf) is `Trusted`
everywhere. The case "`store.cfg` plus the C2PA OID" turned out to be an
artefact: `store.cfg` ends without a newline, so the concatenation welded
two OIDs into one line (`1.3.6.1.5.5.7.3.91.3.6.1.4.1.62558.2.1`). This
verifier's parser does the same.

**A probe settled it.** Three leaves were made under step 110's throwaway
root and intermediate (scratchpad only), each signed onto
`fixture-unsigned.jpg` with c2patool 0.28.0:

| leaf EKU | `trust_config` | 0.27.22 | 0.28.0 | this verifier |
|---|---|---|---|---|
| C2PA only | none | `Trusted` | **`Invalid`** | `Trusted` |
| C2PA only | `store.cfg` | `Trusted` | **`Invalid`** | `Trusted` |
| C2PA only | the C2PA OID alone | `Trusted` | `Trusted` | `Trusted` |
| C2PA only | `store.cfg` + newline + the C2PA OID | — | `Trusted` | `Trusted` |
| documentSigning only | none | `Trusted` | **`Invalid`** | `Trusted` |
| documentSigning only | `store.cfg` (holds it) | `Trusted` | `Trusted` | `Trusted` |
| documentSigning only | the C2PA OID alone | `Trusted` | **`Invalid`** | `Trusted` |
| C2PA + emailProtection | any of the above | `Trusted` | `Trusted` | `Trusted` |

Without any settings, 0.28.0 calls both the C2PA-only and the
documentSigning-only leaf `Invalid` (*"missing required EKU"*), where
0.27.22 said `Valid` (untrusted).

**What it means.** In 0.28.0 the accepted EKUs are emailProtection,
timeStamping and OCSPSigning, which `has_allowed_eku()` hard-codes, plus
whatever `trust_config` lists. The rest of `valid_eku_oids.cfg`
(documentSigning, the Microsoft C2PA OID, and the C2PA claim-signing OID)
is no longer applied by default, although the file still holds all
three. The code path that changed was not pinned down in the source. The
table is the evidence.

**Why nobody saw it.** Every real signer in the corpus carries
emailProtection. Across 47 signed JPEG/PNG/WebP files, the leaf EKU sets
are `emailProtection` (39), `+clientAuth` (2), `+Adobe` (3), and three
files that add the C2PA OID *next to* emailProtection: Pixel 10,
OpenAI, and Lightroom Classic. So the change shows only on a synthetic
file.

## Read: what C2PA 2.4 says

- §14.4.1: *"For the c2pa-kp-claimSigning (1.3.6.1.4.1.62558.2.1) EKU, the
  list of trust anchor configurations shall include … the C2PA Trust
  List."* Emailprotection and documentSigning are named as EKUs a user
  *may* add. Signers are advised to include one of them *"together with
  c2pa-kp-claimSigning"* only for **older** validators.
- The 2.4 change list: *"Added support for new c2pa-kp-claimSigning EKU"*
  and *"Restricted use of the C2PA Trust List to certificates with the
  c2pa-kp-claimSigning EKU"*.

A 2.4-conformant signer that carries only the claim-signing EKU is the
case the specification is built around. Accepting it by default is right.
`c2patool` 0.28.0 refuses it unless it is configured by hand.

## Conclusion

- **Not a wrong `Valid` in this verifier.** SPEC-015's built-in list
  (emailProtection, documentSigning, timeStamping, OCSPSigning, the
  Microsoft C2PA OID, the C2PA OID) stays. 0.28.0's behaviour looks like
  a regression against 0.27.22 and against 2.4. That is reasoned from the
  table and the text; upstream has not been asked.
- Named in `docs/comparison.md` as a place where this verifier
  deliberately does not follow the newest oracle.

## Found on the way, for later

- **§14.5.1 ties anchors to EKUs:** *"the validator shall use only the
  trust anchors it associates with EKUs present in the certificate"*.
  Together with the 2.4 change list, a certificate chaining to a C2PA
  Trust List anchor should carry the claim-signing EKU. This verifier,
  like `c2patool` 0.27.22 and 0.28.0, associates anchors with no EKU.
  SPEC-031's per-entry `trust_config` widens EKUs per entry but never
  narrows them. The conformance catalogue has no predicate for this
  (none mentions EKUs), so `docs/conformance.md` does not list it. It
  could make a file `Trusted` that strict 2.4 would not trust (an
  emailProtection-only certificate under a C2PA Trust List CA), so it is
  worth its own step. Recorded here, not decided.
- **A `trust_config` without a final newline** reads the same here as in
  `c2pa-rs`, so it is not a divergence. It is a trap for anyone who
  concatenates configs.
