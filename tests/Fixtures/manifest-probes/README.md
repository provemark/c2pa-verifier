# Manifest probes from the reading of C2PA 2.4 (SPEC-007 amendment 7)

Built by `bin/make-manifest-probe-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22>` on 2026-10-09 (step 318). `c2patool` 0.28.1 signed
`fixture-unsigned.png` with a throw-away P-256 hierarchy; the keys lived in
a scratch directory and were deleted. `throw-away-root.pem` is the public
root, and `throw-away-root.settings.json` holds it as the legacy
`trust_anchors` string with `store.cfg`. A probe `c2patool` will not write
was made by editing the store. The bytes are replaced and every enclosing
box resized. The data hash's exclusion is re-lengthened to the new chunk,
its hashed URI recomputed and the claim signed again with the same
throw-away leaf. `cgi-shorter.png` runs that edit on a change that keeps
the file valid.

| file | what it is | 0.28.1 | 0.27.22 | here |
|---|---|---|---|---|
| `control.png` | signed by `c2patool` | `Trusted` | `Trusted` | `Trusted` |
| `cgi-shorter.png` | `claim_generator_info` replaced by `{name: "p"}` | `Trusted` | `Trusted` | `Trusted` |
| `cgi-empty.png` | `claim_generator_info` replaced by `{}` | error | error | `Invalid` |
| `label-not-urn.png` | the label `urn:c2pa:…` made `urx:c2pa:…`, in the claim's signature reference too | `Invalid` | `Invalid` | `Invalid` |
| `type-c2md.png` | the manifest box's type `c2ma` made `c2md` | `Trusted` | `Trusted` | `Trusted` |
| `parent.png` | signed by `c2patool` with `control.png` as its parent: `[X, Y]` | `Trusted` | `Trusted` | `Trusted` |
| `duplicate-label-last.png` | a copy of `X` appended: `[X, Y, X']` | `Invalid` | `Invalid` | `Invalid` |
| `duplicate-label-middle.png` | a copy of `X` after it: `[X, X', Y]` | `Trusted` | `Trusted` | `Invalid` |
| `x5chain-unprotected-too.png` | the signer's chain under label 33 in the unprotected header as well (candidate C1, §14.5) | `Trusted` | `Trusted` | `Trusted` |

The answers are under `../c2patool/manifest-probes/<file>--<version>.json`.
Certificates are valid for ten years from the build.
