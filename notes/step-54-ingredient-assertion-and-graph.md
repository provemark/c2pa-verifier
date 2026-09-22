# Step 54 — The ingredient assertion and the manifest graph (SPEC-020): tests first

*2026-09-22.* SPEC-020 reads the ingredient assertions, builds the graph
over the manifest store and gives the report its second half,
`ingredientDeltas`. This note records the tests-first step (54a); the
implementation (54b) is appended when it exists.

## The variants (measured)

`bin/make-ingredient-variants.php <scratch>`: eleven signed variants of
the PNG fixture with one ingredient assertion *added* — an assertion box in
front of the thumbnail (so every later offset moves by its length), an
entry in the claim's `created_assertions`, the hard binding re-bound to the
longer store, the claim re-signed with a throw-away P-256 hierarchy (the
absence-audit method of step 48; keys outside the repository, deleted at
the end of the run). A 60-line CBOR encoder in the script writes the
assertion content. Before SPEC-020 every one of them is `Valid` under this
verifier (`bin/c2pa-verify`, 2026-09-22): the signature and the binding
are intact and nothing reads the assertion.

c2patool 0.27.22 on the eleven (`tests/Fixtures/c2patool/ingredient/`):

| variant | c2patool |
|---|---|
| `componentof` (the control) | `Valid`; `ingredient.unknownProvenance` under `ingredientDeltas`, explanation "ingredient.png: ingredient does not have provenance" |
| `inputto` | `Valid`, no `ingredientDeltas` |
| `no-relationship`, `relationship-childof`, `relationship-int`, `v1-no-title` | **exit 1, no JSON**: "could not decode assertion … the assertion had a mandatory field: relationship that could not be decoded" (`dc:title` for the v1 one) |
| `v4` | exit 1: "capability is not supported by this version: Ingredient version to new" |
| `data-array` | exit 1: "could not decode assertion" |
| `manifest-no-results` | `Invalid`: `assertion.ingredient.malformed` ("ingredient V3 must have validation results") *and* `ingredient.manifest.missing` in the delta — c2pa-rs logs the malformation and goes on to look the manifest up |
| `manifest-and-dst` | `Invalid`: `ingredient.manifest.missing` only — c2pa-rs has no rule for `activeManifest` next to `digitalSourceType` (C2PA 2.4 §18.16.12.3 has); this verifier follows the specification, stricter |
| `hash-text` | `Invalid`: `ingredient.manifest.missing` only — a text-string `hash` decoded as a hash; here a hashed URI's hash is bytes (SPEC-011), stricter |

Six of the nine malformed cases are a hard exit at c2patool; their
standard error is recorded next to the JSON. The three that yield JSON
agree on `assertion.ingredient.malformed` where c2pa-rs has the rule and
show the two rules c2pa-rs does not have. A malformed reference is not
followed here (no `ingredient.manifest.missing` next to the malformed
code) — the assertion is not what the specification says it is, and the
graph does not walk what it cannot read.

## The tests

Thirteen tests in group `SPEC-020`:

- `tests/Unit/Manifest/IngredientAssertionTest.php` — AC1 (v1 on the
  official `CA`, v2 on Lightroom, three v3 on Photoshop in claim order, a
  v3 with both hashed URIs and `validationResults` on c2pa-rs `CACA`),
  AC2 (the nine malformed variants at the object level — status, url, a
  word of the message — and through the Verifier: `Invalid`, one scoped
  failure, under `ingredientDeltas`, equal to c2patool's delta on
  `manifest-no-results`), AC4 (`inputto`: no code, no key, `Valid`; the
  control's delta byte-equal to c2patool's).
- `tests/Unit/Manifest/ManifestGraphTest.php` — AC5 on the seventeen
  `_MULTI` files this verifier's JUMBF parser reads (`update_manifest` is
  a `c2um` box, SPEC-022): root = the oracle's `active_manifest` = the
  last box; the referenced labels = the `active_manifest` of every
  ingredient c2patool rendered (1, 2, 3 or 5 per file); `missing` only
  on the two `E-clm` copies with the bare label and the naming assertion;
  no unreferenced manifest, no redaction; the walk order = c2patool's
  `ingredientDeltas` order. AC7 through the `fromIngredients()` seam on
  synthetic graphs: a two-manifest cycle, a chain of 33 (refused) and 32
  (accepted), 257 assertions (refused).
- `tests/Unit/Verifier/IngredientDeltasTest.php` — AC3 (the sixteen
  single-manifest files: `ingredientDeltas` byte-equal to c2patool's,
  nothing `ingredient.*` under `activeManifest`, no new failure, the
  state equal to c2patool's outside the `_REMOTE` names), AC6 (`E-clm`:
  the missing manifest once, scoped, next to the amendment-5 refusal),
  AC8 (the `ingredients` rendering on 33 files, key by key against
  c2patool, and the `assertions` list shortened by the ingredient and
  ingredient-thumbnail assertions to c2patool's count), AC9
  (`ValidationResult`: grouping, order, the flat list, the state rule).
  AC10 is SPEC-013's alarms and SPEC-019 AC11, which keep running.

## Measured

- `vendor/bin/pest --group=SPEC-020`: **13 failed** (5 assertions). Ten
  are the missing classes (`IngredientAssertion`, `ManifestGraph`,
  `Relationship`, the three `StatusCode` cases, the scope on
  `ValidationStatus`); three are red on today's code with a substantive
  line: AC3 — `Failed asserting that null is identical to Array` (no
  `ingredientDeltas` where c2patool has one on `adobe-20220124-CA`); AC8
  — `Failed asserting that 0 is identical to 1` (no ingredient rendered);
  AC9 — the scope parameter does not exist.
- Pint passes on the four new files; PHPStan on `bin/` is clean; the
  findings on the tests are the missing classes.
- The variants were generated twice (a PHPStan fix in the script's CBOR
  head, then a re-run): every re-run makes a new throw-away root and new
  signatures, so the c2patool JSON was recorded again after the second
  run — the recorded `signature_info` must be the file's. The fixture
  README says so.

## Reasoned

- The malformed rules the tests pin down are the union of the
  specification's (§15.11.3.2, §18.16.12.3, §15.11.3.3) and c2pa-rs's
  required fields; where the two differ the specification wins and the
  difference is named (two variants).
- Reading an ingredient assertion whose hashed URI mismatches (the `E-clm`
  case: c2patool reads it and reports the missing manifest next to the
  mismatch) follows the approved spec's AC6 and c2patool; the
  specification's §15.11.3.3 says to *skip* such an assertion. SPEC-011
  decision 1 and SPEC-018 take the skip side for the data hash and the
  actions. This is a point for the maintainer: consistency would argue
  for skipping (a subset of c2patool's codes, `Invalid` either way); the
  approved text says read. Left as approved; raised for 54b's amendment
  list.
