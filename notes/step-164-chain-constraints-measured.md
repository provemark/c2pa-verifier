# Step 164 — The lower chain findings of step 157, measured

*2026-09-27. Measurement only: no spec, test or code in `src/` changed.
Asked for by the plugin's notes (its open point 4: "to confirm against
v0.2.4's code and `c2patool` before a spec there").*

## What was built

`bin/make-chain-constraint-variants.php` makes a throw-away P-256 hierarchy
and re-signs `fixture-signed.png`'s manifest with each leaf, as step 159's
script does. The root is the only anchor, in the legacy `trust_anchors`
field (`tests/Fixtures/chain-constraints/root.settings.json`), so that
`c2patool` 0.27.22 reads it too. All three judges saw the same files: this
verifier (v0.2.4), both `c2patool` versions, and, as the reference for
RFC 5280 path validation, `openssl verify -CAfile root.pem -untrusted
<intermediate> -purpose any` (with and without `-policy_check`).

## Measured

| file | what it is | `openssl verify` | c2patool 0.27.22 and 0.28.0 | this verifier |
|---|---|---|---|---|
| `nc-inside` | an intermediate whose nameConstraints permit `O=Permitted Org`; the leaf is inside | OK | `Trusted` | `Trusted` |
| `nc-outside` | the same intermediate; the leaf's subject is `O=Other Org` | error 47, *permitted subtree violation* | `Valid`, `untrusted` | **`Trusted`**, signer "Other Org" |
| `critical-leaf` | the leaf carries a critical extension of an unknown OID | error 34, *unhandled critical extension* | `Invalid`, `invalid` + `untrusted` | **`Trusted`** |
| `critical-intermediate` | the intermediate carries it | error 34 at depth 1 | `Valid`, `untrusted` | **`Trusted`** |
| `policy-required` | a critical policyConstraints `requireExplicitPolicy:0`; the leaf has no policy | OK; with `-policy_check` error 43, *no explicit policy* | `Trusted` | `Trusted` |
| `plain` | the leaf under the root, `x5chain` protected: the guard | OK | `Trusted` | `Trusted` |
| `x5chain-unprotected` | `x5chain` under label 33 in the unprotected header | — | exit 1, *could not find signing certificate chain* | **`Trusted`** |
| `x5chain-both` | `x5chain` in the protected header and under `"x5chain"` in the unprotected one | — | exit 1, *COSE verifier failure* | **`Trusted`** (protected chain used) |
| `x5chain-text-unprotected` | `x5chain` under the text label `"x5chain"` in the unprotected header | — | `Trusted` | `Trusted` |
| `x5chain-text-swapped` | that file with the unprotected chain replaced, after signing, by a second certificate for the same key naming `O=Adobe Inc` | — | **`Trusted`, signer "Adobe Inc"** | **`Trusted`, signer "Adobe Inc"** |

`x5chain-swapped` (the swap under label 33) is refused by both `c2patool`
versions, as `x5chain-unprotected` is. The answers are in
`tests/Fixtures/c2patool/chain-constraints/`.

Read in `c2pa` `sdk/src/crypto/cose/sign1.rs` (`cert_chain_from_sign1`,
`main`, fetched 2026-09-27): the protected header is searched for label 33
or `"x5chain"`. Only when neither is there, the unprotected header is
searched, and only for the text label `"x5chain"` (*"This was permitted in
older versions of C2PA"*). A chain in both headers is
`MultipleSigningCertificateChains`. SPEC-006 recorded that the 2022 Adobe
fixture (claim v1) carries its chain unprotected under `"x5chain"`, so
real files use that form.

## What it means

1. **Four wrong `Trusted` that `c2patool` does not give** (a vulnerability
   by SECURITY.md's definition, present since the chain walk was built in
   M5):
   - name constraints are not enforced;
   - an unknown critical extension is ignored, in the leaf and in an
     intermediate;
   - `x5chain` is read from the unprotected header under label 33;
   - a chain in both headers is accepted.

   The fix follows `c2patool` and RFC 5280 §4.2 and §6.1.
2. **One weakness shared with `c2pa-rs`:** a chain under the unprotected
   `"x5chain"` label is not covered by the signature. Anyone can replace
   it with another certificate for the same key, and the signer shown
   changes. It needs a second certificate that an anchor issued for that
   key, so its reach is narrower than items 1 (reasoned). Whether to be
   stricter here (ADR-0005: it prevents unchecked trust in the signer's
   identity), for which claim versions, and whether to tell Adobe first,
   is the maintainer's decision.
3. **Policy constraints:** this verifier and both `c2patool` versions
   agree (`Trusted`). OpenSSL refuses only with `-policy_check`. No wrong
   verdict relative to the oracle. Whether to enforce it is a separate
   choice, for later.
4. **Not measured:** the stapled-OCSP binding and ESSCertID(v2). Both need
   a responder or a TSA that this step did not build. They stay reasoned
   and low: neither can turn a failure into a pass.

## Disclosure

Committed locally, not pushed: the repository is public, and SECURITY.md
keeps unfixed wrong verdicts out of view. Item 2 also concerns `c2pa-rs`.
