# Time Stamping written twice (step 503)

Made by `bin/make-tsa-signer-twice-variants.php`, as `../tsa-signer/` was
made in step 159: a throw-away P-256 root and two leaves under it, the PNG
fixture's manifest re-signed with each. The keys were deleted when the
script ended; only public certificates are here.

| file | the leaf's EKU | under `legacy.settings.json` (the root in `trust_anchors`) | `c2patool` 0.28.3 |
|---|---|---|---|
| `tsa-once.png` | Time Stamping (critical) | `Valid`, `signingCredential.untrusted` | `Trusted` |
| `tsa-twice.png` | Time Stamping, Time Stamping (critical) | `Valid`, `signingCredential.untrusted` (before step 503: `Trusted`) | `Trusted` |

`c2patool` lets the legacy list anchor a time-stamping certificate as a
signer; this verifier does not (SPEC-031 amendment 3, ADR-0005). The
answers are in `../c2patool/tsa-signer-twice/`.
