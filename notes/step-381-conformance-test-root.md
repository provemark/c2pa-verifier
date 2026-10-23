# Step 381 — SPEC-078: files signed with the programme's test certificate, built

*2026-10-23.* Approved unchanged. `bin/make-conformance-test-variants.php`
signed `fixture-unsigned.{jpg,png,webp}` with the conformance tool's
public test certificate, and made one variant of each with a byte of
the image data changed. The key stayed in a scratch directory and was
deleted; `grep -rl "PRIVATE KEY"` over the new fixtures is empty.

## Measured

| file | root as `--trust-list` | no list / official lists | `c2patool`, root alone | `c2patool`, root + `store.cfg` |
|---|---|---|---|---|
| `signed.jpg/png/webp` | `Trusted` | `Valid` (`untrusted`) | `Invalid` (`signingCredential.invalid`) | `Trusted` |
| `edited.jpg/png/webp` | `Invalid` (`dataHash.mismatch`) | — | — | `Invalid` (`dataHash.mismatch`) |

The crJSON of the signed files passes the whole schema. The difference
with `c2patool` without a `trust_config`, and with a strict reading of
C2PA 2.4 §14.5.1.2, is named in `docs/comparison.md` and
`docs/conformance.md` (the documentSigning-only leaf).

- Red: 13 of 13 before the fixtures existed; then green.
- `composer check`: 1,222 passed, 1 skipped.

## What the plan of step 367 has left

The open gaps of `docs/conformance.md`, and the decision whether and
with what to apply. Before that, a release (0.8.0) would carry SPEC-073
to SPEC-078.
