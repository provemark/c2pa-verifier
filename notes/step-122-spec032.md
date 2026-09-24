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

## 122b — built

- **`ActionsCheck::checkAssertions()`** (rule A): in a v2 claim, for every
  well-formed actions assertion, each `c2pa.created` without a string
  `digitalSourceType` yields `assertion.action.malformed` on the
  assertion's url. The url is the one `c2patool` 0.28.0 and 0.27.22
  record. v1 claims return before the rule, as `c2pa-rs` does.
- **`Manifest\ExternalReferenceCheck`** (rule B, new, `@internal`):
  every `c2pa.external-reference` assertion the claim lists, created or
  gathered, any instance, gets the structure checks. There are fourteen
  forbidden labels, following `c2pa` 0.91.0. It runs from `Verifier`,
  where `checks_performed` names `externalReferences` only when the claim
  has such an assertion, and from `IngredientManifestCheck`. Its source
  holds no network call, and AC6 reads it to be sure.
- **`StatusCode::AssertionExternalReferenceMalformed`**: the surface goes
  111 → 112.

### What the red-to-green run found

- AC1's first green attempt was a test that compared too much: without
  settings the oracle also records `signingCredential.untrusted`. It now
  compares the rule's own entries, and the url matched throughout.
- Three older tests moved, each for a stated reason: SPEC-015 AC10's code
  count, SPEC-025's surface count, and SPEC-018 AC2, which now expects
  rule A's fault on `c2pa-rs/no_alg.jpg`.

### Measured: what changed across the corpus

903 runs (301 files × no settings, `full-plus-digicert-g4`,
`truepic-root`), old code in a separate worktree with its own copied
`vendor/` against new code. The first attempt symlinked `vendor/`, and
the autoloader would then have loaded the new sources through the real
path. That was caught before it counted.

**Every difference is one of the new probes turning `Invalid` as its
criterion asks, plus `c2pa-rs/no_alg.jpg` gaining
`assertion.action.malformed` with its verdict unchanged.** No other file
moved.

`composer check`: 441 passed, clean.

### The public record

- `docs/conformance.md`: `PRED-ASSE-027` **yes**, `PRED-ASSE-025`
  partial. The counts are now 55 / 13 / 7 / 21 / **15**.
- `README.md`, `SECURITY.md`: 15 gaps.
- `docs/comparison.md`: three rows (stricter than both oracles on a lone
  `alg`/`hash`, the external-reference checks being 0.28.0's, rule A
  being `c2patool`'s and not §15's).
- `CHANGELOG.md`: *Added*.
