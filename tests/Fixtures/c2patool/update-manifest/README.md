# c2patool's JSON on the update-manifest variants (step 57)

`c2patool ../../update-manifest/<name>.<ext> --settings
../../update-manifest/throw-away-root.settings.json`, c2patool 0.27.22,
recorded 2026-09-22, unchanged. Three variants make c2patool exit 1 with
no JSON; their standard error is in `<name>.stderr.txt`. See
`../../update-manifest/README.md` for what each variant is.

`standard-no-binding` and `standard-borrows-with-update` (step 150) were
recorded 2026-09-25 the same way, with c2patool 0.27.22 (`<name>.json`)
and 0.28.0 (`<name>--0.28.0.json`).

`two-parents--0.28.2.json` (step 346) was recorded 2026-10-12 the same
way with c2patool 0.28.2: `Trusted`, where 0.27.22 says `Invalid` with
`manifest.multipleParents` (SPEC-022 amendment 7).
