# Step 511 — An assertion label of digits only is a label like any other

*2026-10-29.* Finding V3 of the security review before 0.10.0, a crash.
SPEC-007 amendment 10, approved before the test was written.

## The finding

`Manifest::$assertions` holds a manifest's assertions keyed by label
(C2PA 2.4 §6.2). PHP turns an array key made only of decimal digits
(`"5"`, `"0"`) into an integer. Every check that passed the key on as a
string then threw a `TypeError`, first `ManifestGraph` through
`IngredientAssertion::isIngredientLabel()`.

## What was measured

- `c2patool` 0.28.3 signs a PNG with an assertion labelled `5` beside
  `c2pa.actions.v2`, without complaint, and reads it as `Valid` (its own
  test key) — so this needs no forgery, only a signer.
  `bin/make-numeric-label-variants.php` signs three such files under a
  throw-away hierarchy: a control with `probe.note`, one with `5`, one
  with `0`. `c2patool` says `Trusted` for all three under the root.
- This verifier before the change (`a816258`): `Trusted` on the control,
  `TypeError` on `5` and `0`.
- AC15 on `a816258`: the control passes, `5` and `0` fail with the
  `TypeError`. After: all three pass — `c2patool`'s state and failures,
  `"label": "5"` in the JSON, `"5": {` in the cr-JSON, the evidence
  written. The five old source files against the final test once more:
  2 failed.
- The type of `$assertions` changed to `array<int|string, Assertion>`.
  PHPStan at level max then named five places in `src/` and one in
  `bin/` — but only after `vendor/bin/phpstan clear-result-cache`: the
  first run after the change said "No errors" from its result cache.
  CI starts without one.
- The reviewer's probe after: `5` and `0` read as the control
  `stds.note` reads, and the three documents are written.
- The corpus before (`a816258`, worktree, `vendor/` copied, the current
  fixtures) and after: 2,174 runs; only `numeric-label-5.png` and
  `numeric-label-0.png` differ, in both modes (an error before, `Trusted`
  or `Valid` after).
- `composer check` exit 0 (1,694 passed, 1 skipped), `vendor/bin/pest
  --parallel` exit 0, `bin/package-check.php` classifies every path.

## What was done

The label stays a label wherever it is read. The type of `$assertions`
says what PHP holds, so a place that takes the key as a string fails
`composer check`; each of the six places turns it back with `(string)`:
`IconReferenceCheck`, `ManifestGraph::assertionLabels()`,
`ManifestStore` (the JSON rendering), `UpdateManifestCheck`, and the
generator `bin/make-spec035-variants.php`. A caller outside that reads
the keys reads them back the same way (CHANGELOG). A manifest label of
digits only (finding V4) has the same cause one level up and is the next
step.
