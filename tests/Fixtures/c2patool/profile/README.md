# c2patool's JSON for the certificate-profile variants (step 33, for SPEC-015)

`c2patool ../../profile/<name>.png --settings ../../profile/throw-away-root.settings.json`,
c2patool 0.27.22, recorded 2026-09-21, unchanged. The table is in
`../../profile/README.md`. In every file `signingCredential.trusted` is
under `success` (the chain reaches the throw-away root) and
`claimSignature.validated` too (the re-signing is right); the profile's
verdict is the failure, where there is one: `signingCredential.expired`
for `expired`, `signingCredential.invalid` for the eight others that
fail. `good`, `no-digital-signature` and `eku-c2pa` have no failure and
no `validation_status` key.

Added in step 34a (SPEC-015 amendment 1):

| file | what c2patool says |
|---|---|
| `expired-no-settings.json` | no settings: `Invalid`; `signingCredential.expired` **and** `signingCredential.untrusted` — the profile is checked without any trust configuration |
| `expired-verify-off.json` | `verify_trust: false`: `Invalid`; `signingCredential.expired` alone — the profile is checked even with trust verification off |
| `expired-wrong-anchor.json` | the EC test root as anchor: `Invalid`; `.expired` and `.untrusted` together — the two checks are independent |
| `no-eku-no-settings.json` | no settings: `Invalid`; `signingCredential.invalid` and `.untrusted` |
| `good-no-settings.json` | no settings: `Valid`; `.untrusted`; `signature_info` present and identical to the settings run — it does not depend on trust |
