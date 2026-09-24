# Step 118 — a hard binding the claim only gathered is not its own

*2026-09-24. SPEC-013 amendment 13 (AC19).*

## Why

Before tagging `0.2.0`, the last step-107 differences with `c2patool`
0.28.0 were run through this verifier. One file was still `Valid` here
while 0.28.0 refused it: `absence/hash-data-gathered.png`, the step-48
variant whose `c2pa.hash.data` is referenced from `gathered_assertions`
and not from `created_assertions`. 0.28.0 exits with *"Error: claim
missing hard binding"*. 0.27.22 had called it `Valid`, and `Trusted`
under its root.

## Read

C2PA 2.4 §10.2.2 (claim fields): *"The created_assertions field shall be
present and it shall contain one or more URI references to assertions
being made by this claim. In a standard manifest, it shall contain, at
minimum, a reference to an assertion that represents a hard binding."*
The validation step for a missing binding (§15.10.1.2) uses
`claim.hardBindings.missing`.

Step 48 had accepted this file on `c2pa-rs` 0.90's reading, that the
placement is attribution and nobody validates it. The oracle has moved,
and the text was there all along.

## Not a byte hole

The gathered binding was verified before this change, and it matched. No
byte of the asset escaped. What was wrong was calling a manifest valid
that the specification calls malformed. This is category 2 of
`docs/conformance.md`, not category 0.

## Red, then green

- `SPEC-013 AC19` was red on its first assertion: the state was `Valid`.
- `Verifier::hardBindingGatheredOnly()` returns the binding's label when
  the claim references it only from `gathered_assertions`, resolving
  each reference to its box label. In that case the verifier reports one
  `claim.hardBindings.missing` (naming `gathered_assertions` and §10.2.2)
  and does not read the binding. A v1 claim (one list, read as created)
  and the update-manifest path are unaffected.
- `spec-check` refused the build until AC19 had its row (step 116's rule).
- `composer check`: 434 passed, clean.

## Measured: what changed

870 runs of `bin/c2pa-verify` (290 corpus files × no settings,
`full-plus-digicert-g4`, `truepic-root`), with the old and the new
`Verifier.php`: **three lines differ, all this file**, `Valid` →
`Invalid` with `claim.hardBindings.missing`. Nothing else moved.
