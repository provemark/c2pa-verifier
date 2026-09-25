# c2patool's JSON on the update-manifest variants (step 57)

`c2patool ../../update-manifest/<name>.<ext> --settings
../../update-manifest/throw-away-root.settings.json`, c2patool 0.27.22,
recorded 2026-09-22, unchanged. Three variants make c2patool exit 1 with
no JSON; their standard error is in `<name>.stderr.txt`. See
`../../update-manifest/README.md` for what each variant is.

`standard-no-binding` and `standard-borrows-with-update` (step 150) were
recorded 2026-09-25 the same way, with c2patool 0.27.22 (`<name>.json`)
and 0.28.0 (`<name>--0.28.0.json`).
