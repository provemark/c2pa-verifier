# Step 348 — `c2pa-web` as an oracle: `tools/web-oracle/`

*2026-10-13.*

Step 347 ran the CAI's browser build, `@contentauth/c2pa-web` 0.15.3,
from a scratch directory. This step moves that script into the
repository, so the comparison can be repeated and linked to.
`tools/web-oracle/` sits next to `tools/go-oracle/`. Like that tool, it
is outside `composer check` and outside the Composer package
(`/tools export-ignore`).

## What is in it

- `package.json` with exact versions: `@contentauth/c2pa-web` 0.15.3,
  `playwright-core` 1.56.1 (it drives the local Google Chrome, so there is
  no browser download), `esbuild` 0.25.10. Plus `package-lock.json`.
- `list.php` reads the four corpus lists from `tests/Pest.php` and adds
  `matrix/`. That is 92 distinct files.
- `run-cli.mjs` (`c2patool`, `bin/c2pa-verify`), `run-web.mjs`
  (`c2pa-web` via `entry.js`), and `compare.mjs`.
- `.gitignore` for `node_modules/` and `out/`. The root `.gitignore`
  lets `tools/web-oracle/` through, as it does `tools/go-oracle/`.

## Measured (re-run from the repository, 2026-10-13)

`npm run all` with `c2patool` 0.28.1 (c2pa 0.91.1), no network (0
blocked requests):

| | without trust settings | with trust settings |
|---|---|---|
| `c2pa-web` vs `c2patool`: state / failure codes / any code | 0 / 0 / 0 | 0 / 0 / 0 |
| `c2pa-web` vs this verifier: state / failure codes / any code | 8 / 17 / 49 | 4 / 12 / 21 |

The scratch run of step 347 gave the same numbers, file for file. Every
state difference is `Invalid` here: TSA not configured (Truepic, `ocsp`,
`ocsp_with_assertion`, Amazon, Lightroom), CAWG (`C_with_CAWG_data`,
`c2pa-rs-cawg_ica`), and the two remote manifests, which `c2pa-web` cannot
read with fetching off. Every difference is named in `docs/comparison.md`,
which now says so in its opening section.

## Not measured

- Other `c2pa-web` versions, and the `fromBlobFragment` path.
- Files outside the corpus. The point of agreement says nothing about them.
