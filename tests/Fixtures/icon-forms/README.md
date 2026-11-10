# Icons that are not a hashed URI (SPEC-034 amendments 3 and 4)

Built by `bin/make-icon-form-variants.php <c2patool-0.28.6> <c2patool-0.28.3>`
on 2026-11-10 (step 522). `c2patool` 0.28.6 signed the three controls with a
throw-away P-256 hierarchy; the keys lived in a temporary directory and were
overwritten and deleted. `probe-root.pem` is the public root, and
`probe-root.settings.json` holds it with `store.cfg`.

Each probe is a control with one icon replaced by CBOR of exactly the same
length (a resource reference's `identifier` padded to fit; for the map with
both forms, the generator's `name` shortened by as many bytes as the map
grew), its claim signed again with the same leaf. No box changes length.

| file | what it is | 0.28.6 | 0.28.3 | here |
|---|---|---|---|---|
| `generator-icon.png` | control: `claim_generator_info` icon, a hashed URI to `c2pa.icon` | Trusted | Trusted | Trusted |
| `templates-icon.png` | control: a `templates` icon | Trusted | Trusted | Trusted |
| `action-agent-icon.png` | control: an action's `softwareAgent` icon | Trusted | Trusted | Trusted |
| `generator-icon-resource.png` | the `claim_generator_info` icon as `{format, identifier}` | Invalid, `hashedURI.missing` on `…/c2pa.claim.v2` | Trusted | the same as 0.28.6 |
| `templates-icon-resource.png` | the `templates` icon as `{format, identifier}` | Invalid, `hashedURI.missing` on `…/c2pa.assertions/c2pa.actions.v2` | Trusted | the same as 0.28.6 |
| `action-agent-icon-resource.png` | the `softwareAgent` icon as `{format, identifier}` | Invalid, `hashedURI.missing` on `…/c2pa.assertions/c2pa.actions.v2` | Trusted | the same as 0.28.6 |
| `generator-icon-both.png` | the hashed URI with `format` and `identifier` added | Trusted | Trusted | Trusted |
| `generator-icon-text.png` | the `claim_generator_info` icon as a text | no report: "claim_generator_info is missing or invalid" | the same | Invalid, `hashedURI.missing` on `…/c2pa.claim.v2` (amendment 4) |

The answers are under `../c2patool/icon-forms/<file>--<version>.json` (or
`.error.txt`), with `probe-root.settings.json`. Certificates are valid for
ten years from the build.
