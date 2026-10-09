# Manifest probes from the reading of C2PA 2.4 (SPEC-007 amendment 7, SPEC-012 amendment 10, SPEC-063)

Built by `bin/make-manifest-probe-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22>` on 2026-10-09 (step 318; rebuilt with the two data-hash
probes in step 319 and the cloud-data probes in step 321 the unlisted ones in step 322 and `label-urn-uuid` in
step 330, with new keys each time). `c2patool` 0.28.1 signed
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
| `label-urn-uuid.png` | the label `urn:c2pa:…` made the deprecated `urn:uuid:…`, in the claim's signature reference too (SPEC-007 amendment 8) | `Trusted` | `Trusted` | `Trusted` |
| `type-c2md.png` | the manifest box's type `c2ma` made `c2md` | `Trusted` | `Trusted` | `Trusted` |
| `datahash-no-pad.png` | the data hash's `pad` key renamed `paX` (SPEC-012 amendment 10) | error: cannot decode | error | `Trusted` |
| `datahash-pad-text.png` | the data hash's `pad` a text string | `Trusted` | `Trusted` | `Trusted` |
| `parent.png` | signed by `c2patool` with `control.png` as its parent: `[X, Y]` | `Trusted` | `Trusted` | `Trusted` |
| `duplicate-label-last.png` | a copy of `X` appended: `[X, Y, X']` | `Invalid` | `Invalid` | `Invalid` |
| `duplicate-label-middle.png` | a copy of `X` after it: `[X, X', Y]` | `Trusted` | `Trusted` | `Invalid` |
| `cloud-ok.png` | a `c2pa.cloud-data` assertion as `c2patool` writes it (SPEC-063), its `location.hash` text | `Trusted` | `Trusted` | `Trusted` |
| `cloud-hash-bytes.png` | the same, `location.hash` a byte string | `Trusted` | `Trusted` | `Trusted` |
| `cloud-hash-data.png` | its `label` `c2pa.hash.data` | `Invalid` | `Trusted` | `Invalid` |
| `cloud-size-zero.png` | its `size` 0 | `Invalid` | `Trusted` | `Invalid` |
| `cloud-actions.png` | its `label` `c2pa.actions.v2` | `Invalid` | `Trusted` | `Invalid` |
| `cloud-no-location.png` | its `location` key renamed | `Invalid` | `Trusted` | `Invalid` |
| `cloud-in-ingredient.png` | signed by 0.28.1 with `cloud-hash-data.png` as its parent; the ingredient records the failure | `Trusted` | `Trusted` | `Trusted` |
| `cloud-in-ingredient-unrecorded.png` | the same signed by 0.27.22, which records none | `Invalid` | `Trusted` | `Invalid` |
| `unlisted-control.png` | well-formed actions (`when` "123"), metadata, certificate status and soft binding (SPEC-063 amendment 2) | `Trusted` | `Invalid` (`@context`) | `Trusted` |
| `unlisted-metadata-no-context.png` | its metadata without `@context` | error: cannot decode | error | `Trusted` |
| `unlisted-certificate-status-no-ocspvals.png` | its certificate status without `ocspVals` | error: cannot decode | error | `Trusted` |
| `unlisted-soft-binding-no-blocks.png` | its soft binding without `blocks` | `Invalid` | `Invalid` | `Trusted` |
| `unlisted-action-when-integer.png` | its action's `when` the integer 123 | error: cannot decode | error | `Trusted` |
| `x5chain-unprotected-too.png` | the signer's chain under label 33 in the unprotected header as well (candidate C1, §14.5; SPEC-047 amendment 2) | `Trusted` | `Trusted` | `Invalid` |

The answers are under `../c2patool/manifest-probes/<file>--<version>.json`.
Certificates are valid for ten years from the build.
