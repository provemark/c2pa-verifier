# An external reference in an ingredient manifest (step 433)

Made by `bin/make-ingredient-reference-variants.php`: `fixture-unsigned.jpg`
signed by `c2patool` 0.28.3 with a throw-away hierarchy, a SPEC-032 probe
from `../assertion-rules/` as its parent ingredient. The keys were deleted
when the script ended; only the root is here.

| file | parent | what the ingredient assertion records |
|---|---|---|
| `ingredient-reference-control.jpg` | `reference-hashed.jpg` | no fault |
| `ingredient-reference-empty-url.jpg` | `reference-empty-url.jpg` | `assertion.external-reference.malformed`, as `c2patool` found it |
| `ingredient-reference-unrecorded.jpg` | the same | the recorded code changed by one letter (`…malformeX`), the hashes over it recomputed, the claim signed again with the same key: the fault is no longer recorded |

`throw-away-root.settings.json` trusts this root and the SPEC-032 probe
root. The answers of `c2patool` 0.28.1 and 0.28.3 are in
`../c2patool/ingredient-reference/`: `Trusted`, `Trusted`, `Invalid`, as
here.
