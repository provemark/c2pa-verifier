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

## 56b — the implementation

*2026-09-22, same day.*

- `src/Verifier/IngredientManifestCheck.php` — `check()` walks
  `ManifestGraph::$referenced` in walk order; per manifest: `hash()` (box
  payload → `ingredient.manifest.validated`; the pre-1.3 hash over the
  claim's CBOR → silence; neither → `ingredient.manifest.mismatch`; an
  algorithm PHP cannot compute → `algorithm.unsupported`), then
  `manifest()` — timestamp, signature, certificate profile, chain and
  trust, hashed URIs, actions, **no data hash** — every status re-scoped
  to the assertion's URI, then `drop()` with `recorded()`.
  It lives in `Verifier`, not `Manifest`: the parsers may not depend on
  `Cose`, `Trust`, `Hash` or `Timestamp` (Deptrac), and the spec's own
  open question answered itself that way.
- `Report\StatusCode` gains `ingredient.manifest.validated` (a success)
  and `ingredient.manifest.mismatch`; the enum is 36 cases.
- `Verifier`: the check runs after `actions`, adds `ingredients` to
  `checks_performed` where the graph reached a manifest, and the
  multi-manifest refusal of SPEC-013 amendment 5 is gone.

### Measured

- 7 red (2 already true) → **9 green**; `composer check` exit 0 with
  **336 tests**. `bin/fuzz.php 20260922 3`: 312 runs over 104 files, **0
  faults**, one survivor that stayed `Valid` — `c2patool` calls the same
  file `Trusted`, so it is a byte the format leaves uncovered, the
  category step 45 already measured.
- Five existing criteria had to change with this one (SPEC-021
  amendment 3): SPEC-013 AC11 (a multi-manifest store is measured like
  any other now), SPEC-013 AC12's stricter list, SPEC-017's helper (the
  active manifest's statuses only — an ingredient's timestamp is checked
  too now), the enum count, and SPEC-020 AC6 (the E-clm file's refusal
  is gone: it is `Invalid` on its own merits).
- Two test literals of the approved text were wrong and were corrected
  against the files (amendments 1–2): a second delta exists on the AC2
  variant (the ingredient manifest has an ingredient of its own), and
  `E-clm-CAICAI` gets no `ingredients` check because its one reference
  names a manifest that is not in the store.
- Pest's variadic `toContain($needle, $message)` caught me a seventh
  time; the message read as a second needle. `in_array(...)` plus
  `toBeTrue($message)`, as everywhere else in this suite.
- From the shell, `adobe-20220124-CACA.jpg` with the test anchors:
  `Trusted`, `checks_performed` with `ingredients`, and two deltas — the
  ingredient manifest fully validated (its own timestamp, signature,
  trust and six hashed URIs) and its parent's `ingredient.unknownProvenance`.

### What this closes, and what it does not

Seventeen of the eighteen multi-manifest corpus files are now measured
rather than refused, and their verdicts are c2patool's — sixteen exactly,
two (`ocsp`, `ocsp_with_assertion`) `Invalid` here by the named TSA
leniency. `adobe-20220124-E-uri-CIE-sig-CA`, the file that made SPEC-013
amendment 5, is `Invalid` for c2patool's reason; `adobe-20220124-CIE-sig-CA`,
whose ingredient signature is genuinely broken but *recorded* by the
assertion that used it, is `Trusted` — the specification's rule, copied,
with the guard that nothing an ingredient assertion says can cancel a
fault in the manifest being verified.

Still refused by name: `update_manifest` (a `c2um` box — SPEC-022), the
CAWG file, a claim with redactions, and the graph's bounds.
