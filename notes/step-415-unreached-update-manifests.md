# Step 415 — The update-manifest rules skip an unreached manifest (SPEC-022 amendment 8)

*2026-10-24.* Step 414 found one difference with `c2patool`, stricter
here. Maurice asked for it to be explained first, and then approved
amending it ("akkoord, begin met amendment 8").

## The difference

`UpdateManifestCheck::check()` applied the rules of C2PA 2.4 §11.2.3 (an
update manifest: no hard binding, four actions, exactly one `parentOf`)
and §15.10.1.2 (a standard manifest: at most one `parentOf`) to every
manifest in the store, as SPEC-022's Scope said. That included manifests
no ingredient names.

Measured on two calibration plans of the graph fuzzer:

| file | here (0.8.1) | `c2patool` 0.28.2 | `c2patool` 0.27.22 |
|---|---|---|---|
| a `c2um` nothing names, with the action `c2pa.edited` | `Invalid`, `manifest.update.invalid` | `Trusted` | `Trusted` |
| a standard manifest nothing names, with two `parentOf` | `Invalid`, `manifest.multipleParents` | `Trusted` | `Trusted` |

## Why it changes

- C2PA 2.4 §15.11.3.3: "Validators should ignore any additional C2PA
  Manifests that appear in the C2PA Manifest Store but are not in the
  list of ingredient manifests."
- This verifier does so everywhere else: `ManifestGraph::$unreferenced`,
  `M7AbsenceTest`, `docs/conformance.md` `PRED-INGR-006` (yes). An
  unreached manifest with a broken signature leaves the file `Trusted`,
  so an unreached manifest with a wrong action made the file worse than
  one with a broken signature.
- ADR-0005: stricter only where it protects a verdict. This protected
  none (reasoned). An unreached manifest's redactions and recorded
  statuses already count for nothing (SPEC-035 amendment 5, SPEC-021
  amendment 6). The binding manifest is found up `parentOf` from the
  active one, so it is always reached. Step 150's fault, where an
  unreferenced `c2um` did change a verdict, was fixed in 0.2.2, and its
  shape stays in the fuzzer's calibration.

## What changed

- `UpdateManifestCheck::check()` takes the graph's `unreferenced` list
  and skips those manifests; `Verifier` passes it.
- SPEC-022 amendment 8 and criterion AC11; the Traceability row.
- `tests/Fixtures/graph-fuzz-update/`: five calibration plans exported
  (the two unreached ones, the same update manifest reached through an
  ingredient, an active manifest with two parents, and an update
  manifest on a standard one as the control), with their root and
  settings and a README. The `c2patool` 0.28.2 output is under
  `tests/Fixtures/c2patool/graph-fuzz-update/`.
- `bin/graph-fuzz.php`: two calibration plans more (49), and the oracle
  applies the rules to reached manifests only.
- `docs/reading-c2pa-2.4.md`, the §15.11.3.3 row.

## Measured

- AC11 red first: the two unreached datasets failed (`Invalid`), the
  three others passed. Green after the change.
- Both mutations caught. With the skip removed, AC11 fails on the two
  unreached datasets. With every manifest but the active one skipped, it
  fails on the dataset "reached through an ingredient" (run, then
  reverted).
- `c2patool` 0.28.2 and 0.27.22 on all five fixtures: the states and
  failure codes AC11 asserts.
- The corpus, 1,882 runs (every fixture, without settings and with
  `trust/full.settings.json`), `v0.8.1` against this tree on the same
  fixtures: only the two unreached fixtures move, under both settings,
  `Invalid` to `Trusted`.
- `bin/graph-fuzz.php`: calibration 0 of 49 with a finding; seeds
  414–416, 2,000 plans each: 0 wrong, 0 stricter, 0 order, 0 faults.
- `composer check`: exit 0, 1,340 passed.

## Reasoned

That the strictness protected no verdict: from the rules named above,
not from a search for a counter-example beyond the fuzzer's seeds.

**Weight**: a verdict moves from `Invalid` to `Trusted`. It moves only
for a fault in a manifest the specification says to ignore.

Decided by Maurice: amendment 8 as explained.
