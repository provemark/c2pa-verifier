# Step 347 — Two kept divergences from SPEC-011, now named in the comparison

*2026-10-13.*

`docs/comparison.md` promises every place where this verifier and
`c2patool` answer differently. Two divergences that SPEC-011 decided on
2026-09-21 were missing from it. The drift alarm (`VerifierTest.php`,
SPEC-013 AC10) already knew about both: it maps `algorithm.unsupported`
to `c2patool`'s code and leaves `assertion.undeclared` out. So the tests
passed, and the public list was incomplete. No code changes, and no
verdict changes.

## How they surfaced

On request of a WordPress/ai maintainer, who prefers the CAI's own
implementations, `@contentauth/c2pa-web` 0.15.3 (c2pa-rs `=0.91.0`, in
WASM) was run in headless Chrome over the five drift-alarm corpora. That
is 92 distinct files: `adobe-20220124-C.jpg` sits in two lists. It ran
twice, without trust settings and with `full-plus-digicert-g4` (the
matrix with its `test-roots`). Remote-manifest fetch and OCSP were off,
and the page made no outside request. Next to it ran `c2patool` 0.28.1
(c2pa 0.91.1) and this verifier's command.

Measured:

- **`c2pa-web` 0.15.3 against `c2patool` 0.28.1:** the same
  `validation_state`, the same failure codes and the same set of all codes
  on all 92 files, in both runs.
- **Against this verifier:** 8 state differences without settings and 4
  with them. In every one this verifier says `Invalid`. Each is a named
  exception in `tests/Pest.php`: TSA not configured, CAWG, remote
  manifest, or `c2pa-rs-cawg_ica`. The remaining code-only differences are
  in `SPEC013_SUBSET_ONLY` and the TSA lists, apart from the two below.

The script stays outside the repository for now. A later step decides
whether it gets a place under `tools/`.

## The two rows

1. **`binding/claim-alg-sha1.png`:** a claim `alg` of `sha1` on the
   active manifest. This verifier reports `algorithm.unsupported` per
   hashed URI (SPEC-011 AC7). `c2patool` 0.27.22, 0.28.1 and `c2pa-web`
   report `assertion.hashedURI.mismatch` with *"hash does not match
   assertion data"*. The ingredient case already had a row (SPEC-052
   amendment 1); the active-manifest case did not.
2. **`binding/hard-bindings-two.png`:** `c2pa.hash.data` duplicated under
   the same label. All three report `assertion.multipleHardBindings`. This
   verifier also reports `assertion.undeclared` for the second box, which
   no entry resolves to (SPEC-011 decision 2, AC5's duplicate-label
   case). None of the c2pa-rs builds names it.

Both stay `Invalid` everywhere.

## Checked

`composer check` and `php bin/spec-check.php` after the edit; see the
AI log for the counts.
