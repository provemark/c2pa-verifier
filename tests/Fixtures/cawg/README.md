# CAWG identity assertions where the refusal did not look (step 502)

Made by `bin/make-cawg-variants.php`: `fixture-unsigned.png` signed by
`c2patool` 0.28.3 with a throw-away hierarchy. `c2patool` numbers an
`__n` label itself, so each probe was signed with a label of the same
length that is no CAWG label and then renamed: the assertion's hash
recomputed in the claim, the claim signed again with the same key (for an
ingredient, the parent's digests and claim too), the caBX CRC recomputed.
The keys were deleted when the script ended; only the root is here. The
"credential" in each assertion is a placeholder no one could validate.

| file | assertion | `c2patool` 0.28.3 |
|---|---|---|
| `cawg-instance-control.png` | `cawg.identitx-i1` in the active manifest | `Trusted` |
| `cawg-instance.png` | the same renamed `cawg.identity__1` | `Invalid` (`assertion.required.missing`) |
| `cawg-ingredient-control.png` | `cawg.identitx` in the ingredient manifest | `Trusted` |
| `cawg-ingredient.png` | the same renamed `cawg.identity` | `Invalid` (`assertion.required.missing`) |
| `cawg-ingredient-instance.png` | `cawg.identity__2` in the ingredient manifest | `Invalid` (`assertion.required.missing`) |

Before step 502 this verifier called the three renamed files `Trusted`
(SPEC-013 amendment 24, SPEC-021 amendment 8). The answers are in
`../c2patool/cawg/`.
