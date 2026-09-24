# Step 122 — SPEC-032: two rules the oracles enforce

*2026-09-24. SPEC-032 approved the same day. On open question 2, the
maintainer chose to follow `c2patool`; questions 1, 3 and 4 adopted their
proposals.*

## 122a — the probes, and the tests seen red

`bin/make-spec032-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`
signs eleven probes onto `fixture-unsigned.jpg` with `c2patool` 0.28.0,
under a throwaway root, intermediate and leaf (claim-signing +
emailProtection; keys shredded, and a surviving key is an error). It
records both versions' answers without settings and with the root:

| probe | 0.27.22 (root) | 0.28.0 (root) |
|---|---|---|
| `created-without-source-type` | `Invalid` | `Invalid` |
| `created-with-source-type` (and a `c2pa.edited` without one) | `Trusted` | `Trusted` |
| `reference-forbidden-label` | `Trusted` | `Invalid` |
| `reference-no-location`, `-no-url`, `-empty-url`, `-not-a-map` | `Trusted` | `Invalid` |
| `reference-alg-without-hash`, `-hash-without-alg` | `Trusted` | **`Trusted`** |
| `reference-unhashed`, `reference-hashed` | `Trusted` | `Trusted` |

Open question 3 is answered by measurement: `c2patool`'s builder writes
every shape. No probe needed surgery.

One finding was not in the spec. With `alg` or `hash` alone, **both
oracles say `Trusted`**. `c2pa-rs` reads such a `location` as the unhashed
kind and ignores the lone field. §15.10.3.2.2 says *"If the location field
contains one of alg or hash but not both, the claim shall be rejected"*,
and AC5 follows the text. Here this verifier becomes stricter than both
oracles, and the test says so per probe.

`tests/Unit/Manifest/AssertionRulesTest.php`, run as
`vendor/bin/pest --group=SPEC-032`: **5 failed, 2 passed**.

- AC1 is red on the state (`Trusted`, not `Invalid`).
- AC4 and AC5 are red with a `ValueError`: the status code does not exist.
- AC6 is red because `checks_performed` does not name `externalReferences`.
- AC7 is red because the code is missing.
- **AC2 and AC3 are green from birth, on purpose.** They are guards,
  exactly as SPEC-030 AC9 was: v1 claims keep their verdicts, and the
  control stays `Trusted`. They must still be green after the change.

Committed locally, not pushed, so that `main` does not go red.
