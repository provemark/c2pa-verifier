# Step 380 — SPEC-078 drafted: files signed with the programme's test certificate

*2026-10-23.* The test-mode step of the plan in step 367. Maurice
allowed the generator to use the conformance tool's public test key,
kept outside the repository ("het mag"), and, after the finding below,
chose to pin the behaviour as it is ("optie 1").
`specs/SPEC-078-conformance-test-root.md` is the draft.

## The finding

`contentauth/c2pa-conformance-tool` `6d10ee0` (Apache-2.0) publishes a
"C2PA Conformance Test Root" and a test signing certificate with its
key. The leaf's only EKU is id-kp-documentSigning. Files signed with it
by `c2patool` 0.28.2 (a prototype outside the repository):

| | this verifier | `c2patool` 0.28.2 |
|---|---|---|
| root as trust list | `Trusted` | `Invalid`, `signingCredential.invalid` (missing EKU) |
| root and `store.cfg` | `Trusted` | `Trusted` |
| no list | `Valid` | — |
| the official lists | `Valid` | — |
| one image byte changed | `Invalid`, `dataHash.mismatch` | the same |

`c2pa-rs` accepts documentSigning only with an explicit `trust_config`;
this verifier always (SPEC-015's built-in list). C2PA 2.4 §14.5.1.2,
read strictly, ties the C2PA Trust List to `c2pa-kp-claimSigning` and
would make the file untrusted; neither implementation ties anchors to
EKUs (`docs/conformance.md`). The tool's documentation says such files
are trusted in its test mode (read, not measured).

## Measured and reasoned

- Measured: the certificates (`openssl x509`, `openssl verify`); the
  prototype signatures and verdicts above; `c2pa-rs` 0.91.1's EKU
  handling; C2PA 2.4 §14.4.1 and §14.5.1.2.
- Reasoned: that the programme's tool calls these files trusted.
