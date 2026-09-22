# Step 56 — Validating the ingredient manifests (SPEC-021): tests first

*2026-09-22.* SPEC-021 validates the manifests the graph found and lifts
the refusal of multi-manifest stores. This note records the tests-first
step (56a); the implementation (56b) is appended when it exists.

## The variants (measured)

`bin/make-ingredient-manifest-variants.php <scratch>` builds three signed
variants; the active claim is re-signed with a throw-away P-256 hierarchy
whose root goes, **together with the C2PA test anchors**, into
`tests/Fixtures/ingredient-manifest/throw-away-root.settings.json` — the
ingredient manifests of `CACA` are signed by the test hierarchy, and only
the fault under test may make a variant `Invalid`.

| variant | what it is | c2patool 0.27.22 |
|---|---|---|
| `ingredient-signature-broken` | `c2pa-rs/CACA.jpg` with one byte of the **ingredient** manifest's COSE signature flipped, then the referring `activeManifest` hash, the active claim's hashed URI for that assertion and the active signature recomputed — so the ingredient's **box hash still matches** | `Invalid`; the delta holds `ingredient.manifest.validated` **and** `claimSignature.mismatch` |
| `records-active-fault` | the PNG fixture with an ingredient assertion whose `validationStatus` records two faults, both with urls inside the **active** manifest, and the active signature then broken | `Invalid` with `claimSignature.mismatch`; the recorded `ingredient.unknownProvenance` is still in the delta |
| `redacted` | the PNG fixture whose claim names its own actions assertion in `redacted_assertions` | `Invalid`: `assertion.selfRedacted`, `assertion.action.redacted` |

Writing an edited store back into a JPEG took two tries: an APP11 piece is
`FF EB`, length, `JP`, box instance, **packet sequence (4 bytes)**, LBox,
TBox, then data, and the reassembled store is LBox|TBox once followed by
every piece's data (SPEC-001). The script now asserts that putting the
*unchanged* store back gives the original file byte for byte before it
writes the edited one — without that assertion the first attempt produced
a file c2patool read as "No claim found", and the variant would have
measured nothing.

One measurement worth keeping: c2patool **does not drop** the recorded
`ingredient.unknownProvenance` of `records-active-fault`, because its url
names the active manifest — the CAI-12751 guard covers a recorded status
about *any* box of the active manifest, not only its signature.

## The tests

`tests/Unit/Verifier/IngredientManifestCheckTest.php`, nine tests in group
`SPEC-021`, one per acceptance criterion (AC10 is the existing drift
alarms, which 56b will have to keep green).

## Measured

- `vendor/bin/pest --group=SPEC-021`: **7 failed, 2 passed**. The two that
  pass were already true: AC6 (a claim with `redacted_assertions` is
  refused — the rule has been in `HashedUriCheck` since SPEC-011, and
  SPEC-021 only names and keeps it) and AC7 (the data hash runs on the
  active manifest only, because nothing else is checked yet). The seven
  red ones are red for the right reasons, not only for missing classes:
  - AC1 `Undefined constant StatusCode::IngredientManifestValidated`;
  - AC2 `Failed asserting that an array contains 'ingredient.manifest.validated'`
    — the file is `general.error` (the multi-manifest refusal) where
    c2patool reports the broken ingredient signature;
  - AC3 the seventeen files' failure codes are `['general.error']`
    against c2patool's `[]`;
  - AC4 `adobe-20220124-CIE-sig-CA` is `Invalid` here, `Trusted` there;
  - AC5 `Class "…\Verifier\IngredientManifestCheck" not found`;
  - AC8 `checks_performed` has no `ingredients`;
  - AC9 no validation status is scoped to an ingredient assertion yet.
- Pint passes on the new files; PHPStan's findings on the test are the
  missing class and its consequences.

## Reasoned

- The mismatch case (AC1's third) has no corpus file: no writer ships a
  reference that matches neither form. It is tested through the seam
  (`IngredientManifestCheck::hash()`) with the reference's hash replaced
  by zeros — the same "no fixture, so name the seam" choice SPEC-018 made
  for the v1 actions rule.
- AC5 is tested twice: at the seam (a recorded status about the active
  manifest is kept while one about the ingredient is dropped) and on the
  file. The file alone would not show the guard, because c2patool's own
  answer there is "nothing is dropped".
