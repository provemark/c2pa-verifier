# Step 423 — The release commit for 0.8.2

*2026-10-25.* Maurice: "akkoord, bereid 0.8.2 voor".

0.8.2 carries steps 414–422: the graph fuzzer's update manifests and the
rule it led to (SPEC-022 amendment 8; steps 414–415), the reading of C2PA
2.5 (steps 416–418, documentation), the corpus against `c2patool` 0.28.3
(step 419) and three probes for what that corpus did not cover (steps
420–422), two of which changed a verdict (SPEC-015 amendment 11, SPEC-009
amendment 5).

What the release commit does:

- The CHANGELOG gains **0.8.2 — 2026-10-25** (Changed, Documentation).
  The README names the new tag. `Verifier::VERSION` is `0.8.2`.
- `SECURITY.md` names the two signatures that were `Valid` or `Trusted`
  here and `Invalid` in `c2patool`, and says why they are not counted
  among the wrong verdicts: the holder's key made each signature, and the
  change follows `c2patool`'s rule rather than repairs a broken one. The
  same reasoning kept 0.8.1's cycle rule out of the count. Maurice may
  decide otherwise.
- **A patch release:** `bin/api-check.php` reports 142 contract symbols,
  as in 0.8.1.

Measured:

- `v0.8.1` (a worktree with a copied `vendor/`) on the new probes:
  `es256-p384`, `es384-p256`, `es512-p384` and `leaf-signature-params`
  are `Trusted` there with their own settings; `Invalid` in this tree.
- The corpus against `v0.8.1`, with the current `tests/Fixtures/` copied
  into both trees: 12 of 1,906 runs moved, all in fixtures new since
  0.8.1 (the three crossed ECDSA probes, the Ed25519 signer probe, the
  two unreached manifests of `graph-fuzz-update/`, each with and without
  settings). No older fixture moved.
- `bin/package-check.php`: 562 files, 5.4 MB in the dist, 1.9 MB as a
  zip; every top-level path classified.
- `composer check`: 1,345 passed, 1 skipped; `bin/spec-check.php`: 79 specs.

Reasoned: that the crossed ECDSA pairing was accepted from `0.1.0` on.
SPEC-009 AC6 accepted it from the start, and `v0.1.0`'s
`SignatureVerifier` holds the same untied `CURVES`; it was confirmed by
running only on `v0.8.1`.

Not done here: the push, the tag, Packagist, and the plugin and demo
updates wait for Maurice's word.
