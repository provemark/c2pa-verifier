# An ingredient's own stapled OCSP response (SPEC-066 amendment 2)

Built by `bin/make-ingredient-staple-variants.php <scratch> <c2patool-0.28.3>
<c2patool-0.28.1>` on 2026-10-25 (step 430). A throw-away root ←
intermediate ← signers P and U (P-256); the keys lived in a scratch
directory and were deleted. `throw-away-root.pem` is the public root, and
`throw-away-root.settings.json` holds it as the legacy `trust_anchors`
string with `store.cfg`. Each response comes from `openssl ocsp`, signed by
the intermediate, and replaces the COSE `pad` of the parent's signature
(the unprotected header is not signed).

| file | what it is | 0.28.3 and 0.28.1 | here |
|---|---|---|---|
| `parent.png` | `fixture-unsigned.png` signed by P | `Trusted` | `Trusted` |
| `parent-staple-revoked.png` | the same, with P's own response: revoked | `Trusted` | `Invalid` (SPEC-030) |
| `parent-staple-good.png` | the same, with P's own response: good | `Trusted` | `Trusted` |
| `child-control.png` | signed by U, `parent.png` as its parentOf ingredient | `Trusted` | `Trusted` |
| `child-staple-revoked.png` | signed by U, `parent-staple-revoked.png` as ingredient | `Trusted` | `Invalid` |
| `child-staple-good.png` | signed by U, `parent-staple-good.png` as ingredient | `Trusted` | `Trusted` |

The answers are under `../c2patool/ingredient-staple/<file>--<version>.json`.
Certificates are valid for ten years from the build; the responses too.

Step 557 (SPEC-014 amendment 11) recorded `c2patool` 0.29.0's answer for
`child-staple-revoked`: the ingredient delta holds `signingCredential.ocsp.revoked`
and neither `signingCredential.trusted` nor `claimSignature.validated`. This
verifier withholds both since steps 556 and 557.
