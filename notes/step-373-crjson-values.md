# Step 373 — SPEC-074: CBOR values as crJSON values, built

*2026-10-23.* After SPEC-006 amendment 3 (step 372), the mapping itself.

## What changed

- `Cbor\CrJsonValue::of()`: crJSON Table 1 over a value decoded with
  `keepMaps: true`. Byte strings `b64'…'` (closing quote), tag 0 as its
  text, maps as objects (`{}` when empty) with keys as strings, lists,
  scalars and finite floats as they are. Refused with
  `CrJsonValueException`, naming the reason and the path: a tag other
  than 0, tag 0 over non-text, NaN, the infinities, and a keyed PHP
  array (a map decoded without the mode).
- `tests/Fixtures/c2patool/crjson/`: `c2patool` 0.28.2 `--crjson` for 59
  files, kept as written, with a README naming the command.
- `docs/comparison.md`, "crJSON values": where this differs from
  `c2pa-rs`'s output, and why.

## Red, green, mutations

- Red first: 33 of 33 (the class did not exist).
- Two corrections while making it green, both in the test: AC3's file
  had no `when` (replaced by `writers/openai-20260826-c2pa_2x.png`), and
  AC7 compared objects with their key order (now sorted on both sides;
  JSON objects are unordered). And one cost: `expect($store)->not->
  toBeNull()` exported a whole manifest store for its message on every
  call and ran out of memory; the store is now fetched by a helper that
  throws instead.
- Green: 33 passed, 298 assertions compared in AC7.
- Mutations, each failing: no closing quote, every tag as tag 0, maps as
  PHP arrays, a keyed array let through.
- `composer check`: 1,167 passed, 1 skipped.

## Next

The crJSON document (the second spec of step 370): root, manifests in
reverse order, claim, assertions (with `{}` for a refused one), the
per-manifest validation results and deltas, `validationTime`,
`specVersion`, and `bin/c2pa-verify --crjson`.
