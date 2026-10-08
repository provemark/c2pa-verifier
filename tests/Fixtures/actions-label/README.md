# The version 1 actions label in a version 2 claim (SPEC-018 amendment 6)

Built by `bin/make-actions-label-variants.php <scratch> <c2patool-0.28.1>
<c2patool-0.27.22>` on 2026-10-08 (step 281). Each PNG is
`../fixture-signed.png` with its store edited and its claim re-signed by a
throw-away P-256 leaf under `throw-away-root.pem`. The keys lived in a
scratch directory while the script ran and were deleted. No private key is
here. Neither `c2patool` version can write such a claim: both rewrite the
label to `c2pa.actions.v2` when they sign.

| file | the actions assertion | 0.27.22 | 0.28.1 |
|---|---|---|---|
| `control-resigned.png` | unchanged, `c2pa.actions.v2` | `Trusted` | `Trusted` |
| `actions-v1-label-in-v2-claim.png` | labelled `c2pa.actions` | `Trusted` | `Trusted` |
| `actions-v1-label-first-edited.png` | labelled `c2pa.actions`, first action `c2pa.edited` | `Invalid`, `assertion.action.malformed` | `Invalid`, `assertion.action.malformed` |

All under `throw-away-root.settings.json`. The answers are under
`../c2patool/actions-label/<file>--<version>.json`.
