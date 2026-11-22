# c2patool's JSON on the coverage matrix (step 59)

Two per file: `<name>.json` (no settings — every file is `Valid` with
`signingCredential.untrusted`) and `<name>.trusted.json`
(`--settings ../../matrix/test-roots.settings.json` — every file is
`Trusted`). c2patool 0.27.22, recorded 2026-09-22 with the files
themselves; regenerating the matrix regenerates these. See
`../../matrix/README.md`.

Since step 554 the drift alarm (SPEC-013 AC17) reads `c2patool` 0.29.0's
answers, recorded the same two ways on 2026-11-22, under
`../0.29.0/matrix/`. They equal these in state and failure codes.
