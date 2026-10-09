# Step 323 — Section numbers for timestamps (C3)

*2026-10-09. Documentation and comments only.*

The reading of §14 (step 309) found candidate C3: 22 places cite "C2PA
2.4 §14.6" or "§14.6.1" for timestamps, and 2.4 has no §14.6. The numbers
date from an earlier version of the specification. In 2.4:

| what | section |
|---|---|
| the timestamp, `sigTst` / `sigTst2`, the CounterSignature and its payload | §10.3.2.5 (Time-stamps) |
| obtaining and validating the token | §15.8, §15.8.1, §15.8.2 |
| a signer judged at a trusted timestamp's time, else at the current time | §15.8.2 |

Each citation now names the section that holds what it cites. The places
are five source comments, a generator's docblock, two fixture READMEs, ADR-0004, `docs/comparison.md`, and SPEC-016, -017,
-043, -044 and -062. Those five specs are approved, so each gets a one-line
editorial amendment that says what changed: numbers only, no rule.

C3 had a second half: `docs/conformance.md` heads the anchors-per-EKU
rule §14.5.1.2, and the reading said it should be §14.4.1. Reading
§14.5.1.2 again, the quoted sentence ("the validator shall use only the
trust anchors it associates with EKUs present in the certificate") is
there; it refers to §14.4.1 itself. The heading is right. The reading
document records the correction.

- No behaviour changes. `composer check`: 971 passed.
