# Step 416 — C2PA 2.5 and the key that fits the algorithm (SPEC-009 amendment 4)

*2026-10-25.* C2PA 2.5 appeared on spec.c2pa.org on 2026-10-25. Maurice
asked whether this verifier has to meet it. The answer was: not now. The
verdict follows `c2patool`, and `c2pa-rs` (0.91.6, 2026-10-24) has no 2.5
work yet. But 2.5's *clarifications* may expose a gap that already holds
for 2.4 files, so three of them are read first, one per step. This is the
first: *"clarified algorithm and key matching"* (Appendix F.1).

## Source

<https://spec.c2pa.org/specifications/specifications/2.5/specs/C2PA_Specification.html>,
fetched 2026-10-25, SHA-256 `f8540a638691a160d2324cbdcaad97503149138373cbf7d4667931cfafe12d11`.
The rules that 2.4 had in §13.2.1 are §12.3.1 in 2.5.

## 2.5 §12.3.1 against `SignatureVerifier`

`SignatureVerifier::requireFit()` refuses a key that does not fit the
algorithm before any arithmetic, as both versions require (*"Implementations
shall refuse to generate or verify signatures with keys that are not
correct for the algorithm choice"*).

| 2.5 §12.3.1 | here | how known |
|---|---|---|
| ECDSA keys on P-256, P-384 or P-521 | `CURVES`; secp256k1 refused | measured: `SignatureVerifierTest` AC5 (`es256-p256k1`) |
| Ed25519 keys for EdDSA | `PublicKey::KIND_ED25519` only | measured: AC5 (`eddsa-rsa`) |
| RSASSA-PSS: modulus at least 2048 bits; above 16,384 may be refused | 2,048 to 16,384 | measured: AC5 (`ps256-rsa1024`); the upper bound read |
| RSASSA-PSS as RFC 8230 (salt the hash length, never PKCS#1 v1.5) | `RsaPss::verify()` under both key types | measured: AC7, AC12 |
| ESP256/384/512 (RFC 9864) and ML-DSA-44/65/87 (RFC 9964), new | not supported: `algorithm.unsupported`, the code 2.5 §14 names | measured: AC9 (any alg outside the seven) |
| *"P-256 keys shall be used with ES256 or ESP256 … Validators may reject other pairings"* | any of the three curves under any ECDSA alg | measured: AC6 (`es256-p384` is `true`) |

## The one change

2.4 §13.2.1 said *"Implementations shall accept keys on any of these curves
for all ECDSA algorithm choices."* Step 247 kept AC6 because of that
sentence. 2.5 drops it: measured, it occurs 0 times in the 2.5 text. In
its place a validator *may* reject a crossed pairing.

AC6 stays (SPEC-009 amendment 4):

- it meets both versions: required under 2.4, allowed under 2.5;
- the files in circulation are signed under 2.4 or earlier;
- reasoned: ES256 under a P-384 key is still an ECDSA signature made with
  the holder's key. Only the security level is lower (SHA-256 instead of
  SHA-384). Nothing becomes forgeable, so no verdict is wrongly `Valid`.

Not measured: what `c2patool` does with a *signed file* that carries such
a pairing. Only a signature vector exists (`tests/Fixtures/signatures/`),
not a file. If `c2patool` refuses one, the amendment is revisited.

## What changed

- `specs/SPEC-009-signature-verification.md`: amendment 4. No criterion
  changed.
- `docs/reading-c2pa-2.4.md`: the intro says 2.5 exists and is being read
  against this document; the curve row names the 2.5 permission.
- No code and no tests changed.

## Next

The second clarification: action and ingredient reference validation
(2.5 Appendix F.1, *"clarified action/ingredient reference validation"*).
The third: validity intervals for TSA trust anchors. The 2.5 additions
(new actions, `&cbor=` in JUMBF URIs, ESP and ML-DSA, new formats) wait
until `c2patool` supports them.
