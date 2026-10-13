# Step 351 — SPEC-068: a data hash over nothing is a mismatch

*2026-10-13.*

Step 349 measured a wrong `Trusted`. A `c2pa.hash.data` that excludes
`[0, store start]`, the store, and `[store end, file end]` hashes zero
bytes, and a pixel can then change while this verifier, `c2patool` 0.28.1
and 0.28.2 all say `Trusted`. SPEC-068, approved by Maurice today, makes
that `assertion.dataHash.mismatch`. It is the floor. SPEC-069 (draft)
covers the case where some bytes are left.

## What changed

`src/Hash/DataHashCheck.php`: after the store-exclusion checks (SPEC-012
amendment 7) and before the file is hashed, a sum. If the file's length
minus the excluded lengths is zero, the check returns one
`assertion.dataHash.mismatch`: *"the exclusions leave no byte of the
47773-byte file to hash: [0, 33], [33, 46074], [46107, 1666]; a data hash
over nothing binds nothing"*. At that point the ranges are sorted,
non-overlapping and inside the file, so the sum is exact.

`bin/make-exclusion-coverage-variants.php` gained `all-but-one-byte`,
which hashes the last byte of IEND's CRC. It is `Trusted` in all three
verifiers, and stays `Trusted` here: SPEC-068 AC3 pins where the floor
lies. Re-running the script made a new throw-away hierarchy, so every
file under `exclusion-coverage/` and its `c2patool` answers were
rewritten. The answers did not change.

## Measured

- Tests first: `vendor/bin/pest --group=SPEC-068` gave 4 failed and 2
  passed before the change. The four AC1 cases got `match` plus the
  informational where one `mismatch` was expected. AC2 and AC3 pin
  existing behaviour. After the change, 6 passed.
- Mutations: the bound at `<= 1` fails AC3, and the check moved before
  amendment 7's fails AC2.
- AC4, the corpus before and after: 793 fixture files outside
  `exclusion-coverage/`, without settings and under
  `trust/full.settings.json`. State, every status code, and the full
  explanation of every `assertion.dataHash.*` status are identical. A
  first comparison that included every explanation differed on files with
  expired certificates. Those explanations name the time of the check
  ("checked at now"). Two runs of `validity/expired.png` a second apart
  differ in the same way, without the change.
- `composer check`: 1,035 passed. `php bin/spec-check.php` OK.

## Divergence

`docs/comparison.md` gets a row under "Where this verifier differs by
design": `c2patool` 0.28.x calls `all-but-store.png` `Trusted`, and this
verifier calls it `Invalid`.
