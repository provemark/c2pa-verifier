# Step 512 — A manifest label of digits only keeps every document's shape

*2026-10-29.* Finding V4 of the security review before 0.10.0, a crash.
SPEC-007 amendment 11, approved before the test was written; its
criterion AC16 was corrected, and approved again, before the fix.

## The finding

`ManifestStore::$manifests` holds the store's manifests keyed by label,
the label of each manifest's JUMBF description box (C2PA 2.4 §8.1), which
no signature covers. PHP turns a key of decimal digits (`"5"`, `"0"`) into
an integer, the cause of step 511 one level up. The reviewer's probes
showed `evidenceToJson()` throwing a `TypeError`, and the JSON and the
evidence writing `manifests` as a list when the only key was `0`.

## What was measured

- A claim v2 manifest with such a label never reaches the key: its label
  is not a C2PA URN, and the store is refused with `claim.malformed`, as
  `c2patool` 0.28.3 refuses it. AC16 first named that file as its
  fixture; the test failed for that reason, and the criterion was
  corrected.
- A claim v1 manifest is not held to the URN form (nor is it in
  c2pa-rs). `databox/c2pasign-logo.png`, signed by `c2patool` 0.9.12,
  with its active manifest relabelled `5` or `0`: on `7ac06cf`,
  `verify()` itself threw a `TypeError`, in
  `IngredientAssertion::fromAssertion()` called from
  `ManifestGraph::fromStore()`. `c2patool` 0.28.3: `Invalid`,
  `claimSignature.missing`, `active_manifest` `"5"`.
- `bin/make-numeric-label-variants.php` makes six files: the claim v1
  manifest relabelled in its description box only (the claim's
  signature URI still names the old label), relabelled in the claim's
  URIs too (the URI resolves, the signature fails:
  `claimSignature.mismatch` in both tools), and the claim v2 control.
- AC16 on `7ac06cf`: the four claim v1 cases fail with the `TypeError`,
  the two claim v2 controls pass. After: all six pass. The changed source
  files against the final test once more: 4 failed.
- PHPStan without its result cache, after the types changed, named the
  places in `src/` (the graph, the update-manifest check, the evidence
  writer) and in tests and `bin/`. One place it cannot see: an
  `in_array(..., true)` in `UpdateManifestCheck` compared the integer key
  with a list of string labels; found by reading every loop over the
  manifests.
- The corpus before (`7ac06cf`, worktree, `vendor/` copied, the current
  fixtures) and after: 2,186 runs; only the four claim v1 files differ,
  in both modes (an error before, `Invalid` after).
- `composer check` exit 0 (1,700 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0, `bin/package-check.php` classifies every path.

## What was done

The type of `$manifests`, of the keys of `VerificationReport::evidence()`
and of the graph's maps says `int|string`, what PHP holds.
`ManifestGraph::fromStore()` turns the label back to a string before it
names a manifest with it, and `UpdateManifestCheck` compares it as a
string. `toJson()` and the evidence write `manifests` as a JSON object
whatever keys PHP holds; `toJson()` without a store keeps its `[]`.
cr-JSON already wrote a list of manifests, each with its label as a
string.

When the claim names the old label, this verifier refuses the store
(`claimSignature.missing`, `active_manifest` null), where `c2patool`
still names the relabelled manifest. That difference is older than this
step and is not changed here.
