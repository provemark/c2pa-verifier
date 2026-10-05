# Step 239 — SPEC-058 drafted: AVI

*2026-10-05.*

Step 209 measured AVI and left one question: what follows the first RIFF
chunk. SPEC-003 amendments 3 and 4 have answered it for every RIFF form
since. To see whether anything is left for AVI, the existing
`RiffManifestStoreExtractor` was run with the form `AVI ` over every AVI
fixture (a probe, nothing changed in `src/`):

| file | probe |
|---|---|
| `fixture-signed.avi` | the store, 13,463 bytes, range `[11700, 13471]` |
| `fixture-unsigned.avi`, `unsigned-avix`, `c2pa-in-movi`, `c2pa-only-in-avix` | `null` |
| `signed-avix` and its six variants (`avix-*`, `second-form-avi`) | the store of the first RIFF chunk |
| `c2pa-before-idx1`, `trailing-bytes` | the store |
| `lbox-differs`, `length-differs`, `pad-nonzero`, `riff-size-plus-one`, `truncated-in-c2pa`, `two-c2pa` | a `ContainerException`, `storeReached` true |

Every answer is `c2patool`'s, or the named stricter one SPEC-003 and
SPEC-055 already give. One wording slip: the two-C2PA message read "a
AVI"; the build takes the article from the name.

SPEC-058 is therefore a form, not a rule, like SPEC-055: five criteria
over step 209's files.
