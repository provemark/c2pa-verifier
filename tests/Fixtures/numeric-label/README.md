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

## A manifest label of digits only (step 512)

The same script relabels a manifest after signing, recomputing every
enclosing box length, every CBOR text length it touches and the caBX CRC.
The source of the claim v1 files is `../databox/c2pasign-logo.png` (signed
by `c2patool` 0.9.12), its active manifest
`:urn:uuid:f8274d66-382e-4eda-b93e-596e5273fed5`:

| file | relabelled | `c2patool` 0.28.3 |
|---|---|---|
| `numeric-manifest-v1-5.png` | claim v1, to `5`, description box only | Invalid, `claimSignature.missing` |
| `numeric-manifest-v1-0.png` | claim v1, to `0`, description box only | Invalid, `claimSignature.missing` |
| `numeric-manifest-v1-claim-5.png` | claim v1, to `5`, the claim's URIs too | Invalid, `claimSignature.mismatch` |
| `numeric-manifest-v1-claim-0.png` | claim v1, to `0`, the claim's URIs too | Invalid, `claimSignature.mismatch` |
| `numeric-manifest-v2-5.png` | `numeric-label-control.png` (claim v2), to `5` | Invalid, `claim.malformed` |
| `numeric-manifest-v2-0.png` | `numeric-label-control.png` (claim v2), to `0` | Invalid, `claim.malformed` |

SPEC-007 AC16 asks that none of them makes the verifier or a writer throw,
and that the documents keep their shape.
