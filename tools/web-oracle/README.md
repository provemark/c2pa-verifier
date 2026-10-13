# The CAI's browser build as an oracle: `c2pa-web`

`c2patool` is the oracle this project measures itself against. Some
people who weigh this verifier would rather rely on the Content
Authenticity Initiative's own code, and in a browser that means
[`@contentauth/c2pa-web`](https://github.com/contentauth/c2pa-js): c2pa-rs
compiled to WebAssembly. This directory runs that package, unchanged, in
headless Chrome over the five drift-alarm corpora. It puts the answers
next to `c2patool`'s and this verifier's, so the comparison can be
repeated by anyone with Node, PHP and Chrome.

It is **tooling**. It needs Node, npm and a local Google Chrome, which
the verifier itself never does, and it is not part of `composer check`.
The page it runs is served locally by Playwright. Every other request is
aborted and counted, remote-manifest fetching and OCSP are switched off
in both c2pa-rs builds, and so a run uses no network.

## What it runs

- `list.php` reads the corpus lists from `tests/Pest.php` (the four lists
  the drift alarms use) and adds `tests/Fixtures/matrix/`. That is 92
  distinct files: `adobe-20220124-C.jpg` sits in two lists.
- `run-cli.mjs` runs `c2patool` (`$C2PATOOL`, else the one on `PATH`) and
  `bin/c2pa-verify`.
- `run-web.mjs` runs `c2pa-web` through `entry.js`, bundled by esbuild
  with the inline WASM build.
- `compare.mjs` compares `validation_state`, the failure codes and all
  codes, active manifest and ingredient deltas together.

Each engine runs twice: without trust settings, and with
`tests/Fixtures/trust/full-plus-digicert-g4.settings.json` (the matrix
with its own `test-roots.settings.json`). c2pa-web takes the same files'
contents, renamed to its camelCase keys.

## Running it

```sh
cd tools/web-oracle
npm ci
C2PATOOL=/path/to/c2patool npm run all
```

The results land in `out/` (ignored).
`notes/step-348-web-oracle.md` records what it found on 2026-10-13, and
`docs/comparison.md` names every difference with this verifier.
