# Step 31 — Three trust variants through c2patool, before the SPEC-014 tests

*2026-09-21.* SPEC-014 is approved. Its AC3, AC4 and AC9 need material
that did not exist: a store whose `x5chain` carries the leaf alone, and
two settings files. This step makes them and asks c2patool first. No
verifier code.

## `x5chain-leaf-only` — the first variant that edits the COSE_Sign1

The PNG's signature box holds `d2 84 | protected (1,288 bytes: alg −7,
x5chain of two certificates, 654 + 625) | unprotected {pad: 10,932 zero
bytes} | nil | signature`. Removing the intermediate would shorten the
protected bstr by 625 bytes and, with it, every enclosing box, the
store, the data hash's exclusion and the hashed URI of the assertion
that holds it — a variant that breaks five things to test one.

The `pad` c2pa-rs writes is the way out: it exists so that a signer can
reserve space and fill it later. `bin/make-trust-variants.php` removes
the 625 bytes from the protected header and adds 625 zero bytes to the
pad. The store keeps its 46,025 bytes, every LBox its value, the
exclusion and the hashed URIs theirs. A parse probe on M2/M3's classes
confirmed it: one certificate in the chain, the pad 11,557 bytes,
`claimSignature.mismatch` (the Sig_structure covers the protected
header), three `assertion.hashedURI.match`.

## Measured: c2patool 0.27.22

| what | c2patool | SPEC-014 |
|---|---|---|
| `x5chain-leaf-only.png`, full settings | `Invalid`; `signingCredential.untrusted` + `claimSignature.mismatch`, nothing else | AC4 ✔ — exactly the two codes the criterion names |
| the same, no settings | the same two | — |
| PNG, `intermediate-anchor` (the EC intermediate as the only anchor) | `Trusted`, "found in System trust anchors" | AC4 ✔ — `PARTIAL_CHAIN`: an intermediate on the anchor list ends the walk |
| PNG, `allowed-plus-wrong-root` (allowed list + the RSA root as the only anchor) | `Trusted`, "found in EndEntity trust anchors" | AC3 ✔ — the allowed list is tried before the anchors |
| `pixel-changed.png`, full settings | `Invalid`; success `signingCredential.trusted`, failure `assertion.dataHash.mismatch` | AC9 ✔ — trust does not rescue a tampered file, and the state rule holds |

No criterion contradicted. The four JSONs are under
`tests/Fixtures/c2patool/trusted/`, the two settings under
`tests/Fixtures/trust/`.

## Next

The SPEC-014 tests (step 31b), red on the missing `Trust\` classes, the
two enum cases and `ValidationState::Trusted`.
