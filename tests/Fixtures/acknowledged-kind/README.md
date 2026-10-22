# The acknowledged-kind variants (step 363, SPEC-070 amendment 2)

Three signed variants that differ only in the list where the active
manifest's v3 ingredient assertion files one status.
`bin/make-acknowledged-kind-variants.php <scratch>` builds them from
`../ingredient-manifest/ingredient-signature-broken.jpg`, whose ingredient
manifest has a broken claim signature that nothing records. Each variant
adds that `claimSignature.mismatch`, with the same url, to
`validationResults.activeManifest` under one list. The recorded successes
lose their explanation text to make room, so the assertion, the store and
the data hash keep their length. The active claim is re-signed with a
throw-away P-256 hierarchy (keys outside the repository, deleted at the end
of the run). The public root is in `throw-away-root.pem`, and, together with
the C2PA test anchors, in `throw-away-root.settings.json`. Regenerating
changes the root and every signature, so the c2patool JSON under
`../c2patool/acknowledged-kind/` is regenerated with them.

| variant | recorded under | c2patool 0.27.22 and 0.28.2 (with the settings) | this verifier |
|---|---|---|---|
| `recorded-failure` | `failure` | `Trusted`, the mismatch not reported | `Trusted`, the mismatch informational and acknowledged (SPEC-070) |
| `recorded-success` | `success` | `Trusted`, the mismatch not reported | `Invalid`, `claimSignature.mismatch` (amendment 2) |
| `recorded-informational` | `informational` | `Trusted`, the mismatch not reported | `Invalid`, `claimSignature.mismatch` (amendment 2) |

Without settings the first reads `Valid` here and the other two `Invalid`.
