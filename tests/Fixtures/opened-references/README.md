# Ingredient references of a `c2pa.opened` (SPEC-033 amendment 3)

Built by `bin/make-opened-reference-variants.php <scratch> <c2patool-0.28.3>
<c2patool-0.28.1>` on 2026-10-25 (step 429). `c2patool` 0.28.3 signed
`fixture-unsigned.jpg` with a throw-away P-256 hierarchy; the keys lived in
a scratch directory and were deleted. `throw-away-root.pem` is the public
root, and `throw-away-root.settings.json` holds it as the legacy
`trust_anchors` string with `store.cfg`. The builder writes the references
as given; their hashes are not those of their targets, which neither tool
compares.

| file | the action's references | 0.28.3 and 0.28.1 | here |
|---|---|---|---|
| `opened-good.jpg` | `c2pa.opened`: the `parentOf` ingredient | `Trusted` | `Trusted` |
| `opened-good-unresolvable.jpg` | that, then `c2pa.ingredient.v3__9` (not there) | `Invalid` | `Invalid` |
| `opened-unresolvable-good.jpg` | the same two, the other way round | `Trusted` | `Invalid` |
| `opened-good-twice.jpg` | the `parentOf` ingredient twice | `Trusted` | `Invalid` |
| `opened-good-component.jpg` | the `parentOf` ingredient, then a `componentOf` one | `Invalid` | `Invalid` |
| `placed-good-unresolvable.jpg` | `c2pa.created`, then `c2pa.placed`: the `componentOf` ingredient, then one not there | `Trusted` | `Trusted` |
| `placed-unresolvable-good.jpg` | the same two, the other way round | `Trusted` | `Trusted` |

The answers are under `../c2patool/opened-references/<file>--<version>.json`.
Certificates are valid for ten years from the build.
