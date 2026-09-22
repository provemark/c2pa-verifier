# Step 57 — Update manifests (SPEC-022): tests first

*2026-09-22.* SPEC-022 reads the `c2um` box, applies C2PA 2.4 §11.2.3's
rules, finds the hard binding through the `parentOf` chain (§15.12) and
adjusts its stale exclusion (§15.12.1.1). This note records the
tests-first step (57a); the implementation (57b) is appended when it
exists.

## The variants (measured)

`bin/make-update-manifest-variants.php <scratch>` builds six. Five edit
`c2pa-rs/update_manifest.jpg` — whose store is one APP11 segment of
43 595 bytes, rebuilt around the edited store (the script asserts a
byte-exact round trip on the *unchanged* store first, the lesson of step
56) — and one edits the PNG fixture. The *parent* manifest keeps its own
Adobe signature; only the update manifest's claim is re-signed with a
throw-away hierarchy, and the settings file carries that root **and** the
C2PA test anchors so that nothing but the fault under test can make a
variant `Invalid`.

| variant | the edit | c2patool 0.27.22 | this verifier today |
|---|---|---|---|
| `pixel-changed` | one byte after the store; nothing re-signed | `Invalid`: `assertion.dataHash.mismatch` | `general.error` (the `c2um` refusal) |
| `action-not-allowed` | `c2pa.opened` → `c2pa.edited` in the actions assertion (same length) | `Invalid`: `manifest.update.invalid` | `general.error` |
| `hash-in-update` | the `c2pa.time-stamp` assertion renamed `c2pa.hash.data` | **exit 1**, no JSON: "assertion missing: url = c2pa.hash.data" | `general.error` |
| `ingredient-inputto` | `parentOf` → `inputTo` | **exit 1**: "claim missing hard binding" | `general.error` |
| `no-standard-parent` | the parent box's UUID `c2ma` → `c2um`, the reference hashes recomputed | **exit 1**: "claim missing hard binding" | `general.error` |
| `two-parents` | the PNG fixture with two `parentOf` ingredient assertions | `Invalid`: `manifest.multipleParents` | **`Trusted`** |

The last row is a leniency this verifier has today: a standard manifest
with two parents is `Trusted` here and `Invalid` at c2patool. It is not a
wrong `Valid` in the hard-binding sense — nothing is forged — but it is a
rule of §15.11 we do not apply, and SPEC-022 AC6 closes it.

Three of the five JPEG variants make c2patool exit without a report,
which is its habit when a lookup fails before the rule is reached
(step 50's `docs/comparison.md` names that difference); their standard
error is recorded beside the JSON of the others.

## The tests

`tests/Unit/Verifier/UpdateManifestTest.php`, nine tests in group
`SPEC-022`, one per acceptance criterion.

## Measured

- `vendor/bin/pest --group=SPEC-022`: **9 failed**, each for its own
  reason, not one shared missing class:
  - AC1, AC2 — `RuntimeException: no store`: the file has no readable
    store at all, because `JumbfParser` refuses `c2um`;
  - AC3, AC8 — `JumbfException: superbox at offset 18862: update
    manifests (c2um) are not supported` (AC3 on reading the parent's
    exclusion, AC8 on the empty `claim_generator_info`);
  - AC4 — `Failed asserting that false is true`: no
    `manifest.update.invalid` where c2patool has one;
  - AC5 — no `claim.hardBindings.missing` (size 0 against 1);
  - AC6 — `Undefined constant StatusCode::ManifestMultipleParents`;
  - AC7 — `Exception JumbfException not thrown` for `c2tm`: the parser
    refuses `c2cm` and `c2um` by name and lets a `c2tm` box through as an
    unknown box, which SPEC-022 turns into a refusal of its own;
  - AC9 — `update_manifest still counts as refused`: it is still in
    `SPEC013_RS_MULTI`.
- Pint passes on the new files; PHPStan is clean on the script and
  reports only the missing class and property on the test.

## Reasoned

- AC4's fourth rule ("more than one ingredient assertion") has no signed
  variant: adding a second ingredient means splicing an entry into the
  update manifest's claim, whose CBOR uses **indefinite lengths**
  (`claim_generator_info` starts `bf`), and the byte-level helpers this
  project's tooling uses read definite lengths only. The rule is tested
  at the seam (`UpdateManifestCheck::rules()`), as SPEC-018 and SPEC-020
  did where no fixture could be made. Recorded for the spec's amendment
  list.
- AC4(c) uses `inputTo` rather than the spec's `componentOf`: both break
  "exactly one `parentOf`", and `inputTo` is one byte shorter than
  `parentOf`, which keeps the edit inside the assertion without moving
  the claim. Also for the amendment list.
