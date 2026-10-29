# An assertion label of digits only (step 511)

Made by `bin/make-numeric-label-variants.php`: `c2patool` 0.28.3 signs
`fixture-unsigned.png` under a throw-away hierarchy (root ← intermediate ←
signer, P-256), whose keys were deleted when the script ended. Each file
holds `c2pa.actions.v2` and one more assertion of no type `c2patool`
knows:

| file | the other assertion's label | `c2patool` 0.28.3 |
|---|---|---|
| `numeric-label-control.png` | `probe.note` | Trusted |
| `numeric-label-5.png` | `5` | Trusted |
| `numeric-label-0.png` | `0` | Trusted |

`throw-away-root.pem` is the root, `throw-away-root.settings.json` the
trust settings both tools read. `c2patool`'s answers are under
`../c2patool/numeric-label/`. PHP turns an array key of digits only into
an integer; SPEC-007 AC15 asks that these files read as `c2patool` reads
them and that every document names the label as a string.
