# Step 328 — The shape rules of §18 by design, and C7 through the AKI rules

*2026-10-09. Documentation only.*

## Nineteen rows of §18

Nineteen rows of `docs/reading-c2pa-2.4.md` still read *candidate*. They
are shape rules in chapter 18: at most one `c2pa.thumbnail.claim` per
manifest; the allowed values of an action's `reason`, `digitalSourceType`
and custom `parameters`; the required fields of `c2pa.asset-ref`,
`c2pa.asset-type.v2`, the GDepth depth map, `font.info`, the
environmental-impact and `c2pa.ai-disclosure` assertions and the
repository receipt; and the like.

They have the same shape as the four of step 322. The rules bind the
claim generator. §15.10.3.2 lists what a validator checks for a specific
assertion, and says that an assertion not on its list *"does not require
any additional validation steps"*. None of these shapes feeds a verdict
here. Each row is now *by design*, with that reason. The rows of §18 that
belong to the larger open work stay candidates: §18.6 (ISOBMFF details),
§18.14 (alternative content, issue #5) and §18.18 (the time-stamp
assertion, issue #6).

## C7

The candidate C7 asked for a Subject Key Identifier in every CA
certificate. Step 327's probes show that a CA without one is already
refused. The certificate below it has no AKI keyid, and the AKI rules
refuse that: the leaf's (SPEC-015) and, since step 327, those above it
(SPEC-014 amendment 8). `c2patool` and OpenSSL refuse it too. Maurice
decided to record C7 as covered through the AKI rules, with no separate
rule.

The tallies are now 296 covered, 63 partial, 78 by design, 101 n/a and 23
candidates.

- No behaviour changes. `composer check`: 972 passed.
