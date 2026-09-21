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
