# Step 346 — The one-parent rule: the right section, and c2patool 0.28's narrower count

*2026-10-12.*

`update-manifest/two-parents.png` is a standard manifest with two
ingredient assertions whose relationship is `parentOf`. This verifier
calls it `Invalid` with `manifest.multipleParents` (SPEC-022 AC6). A
re-run of the Go comparison today showed that `c2patool` 0.28.2 calls it
`Trusted`. Step 107 had already seen the change with 0.28.0, and the
reading of the whole specification (step 312) had already named the cause
in `docs/reading-c2pa-2.4.md`. `docs/comparison.md` did not list it, the
test pinned only the 0.27.22 output, and while looking it up the section
number turned out to be wrong. SPEC-022 amendment 7 records both points.
No verdict changes.

## What changed

- **The section.** The rule is C2PA 2.4 §15.10.1.2 (Standard Manifest
  Assertions): *"Validate that there are zero or one c2pa.ingredient
  assertions whose relationship is parentOf. If there is more than one,
  the manifest shall be rejected with a failure code of
  manifest.multipleParents."* The explanation in the report, two code
  comments and SPEC-022 said §15.11, which is the ingredient walk. They
  now say §15.10.1.2.
- **The divergence, named and pinned.** c2pa-rs commit `be7f5ea2`
  ("Update for C2PA 2.3 spec", in c2pa 0.91.0) changed `verify_internal`
  to count a `parentOf` ingredient only when it names a manifest:
  `c2pa_manifest` up to v2, `c2pa_manifest` and a signature in v3. Both
  parents in `two-parents.png` name none, so 0.91 counts zero. The
  specification counts assertions, in 2.3 as in 2.4, and this verifier
  keeps doing so. The 0.28.2 output is stored as
  `tests/Fixtures/c2patool/update-manifest/two-parents--0.28.2.json`;
  a new test next to AC6 pins the section in the explanation, the two
  parents without a manifest reference, and 0.28.2's `Trusted`. A row in
  `docs/comparison.md` names it under "differs by design".

## Measured

- `c2patool <file> --settings tests/Fixtures/update-manifest/throw-away-root.settings.json`
  on `two-parents.png`: 0.27.22 `Invalid` (`manifest.multipleParents`),
  0.28.1 and 0.28.2 `Trusted`. `strings` on the 0.28.2 binary names
  `c2pa-0.91.2`.
- In a clone of `contentauth/c2pa-rs`: `git log -S has_valid_parent`
  gives `be7f5ea2` only; `git tag --contains be7f5ea2` starts at
  c2pa 0.91.0; `c2patool-v0.27.22` does not contain it.
- The specification text: the 2.3 and 2.4 HTML from spec.c2pa.org, both
  with the sentence above.
- The new test was red first (1 failed: the explanation said §15.11),
  then green. The `not->toContain('§15.11')` assertion was seen red by
  putting "§15.11" back into the message for one run.
- `composer check`: exit 0, 1,029 passed. `php bin/spec-check.php`: OK,
  67 specs.

## Reasoned

- That a parent without a manifest reference still counts. §15.10.1.2
  names the assertions by their relationship and nothing else. A
  `parentOf` ingredient without a manifest is still the thing the asset
  was derived from, so two of them are still two parents.
