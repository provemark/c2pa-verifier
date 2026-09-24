# Step 131 — the `c2pa.redacted` action, measured

*2026-09-24. A measurement, no spec. Asked for after SPEC-036: the last
redaction rule this verifier does not apply. Step 123 had found no oracle
refusing a bare `c2pa.redacted`, and SPEC-033 and SPEC-035 left the rule
out for that reason.*

## What the rule is

**C2PA 2.4 §15.10.3.2.3** (read at `4eb2c67`): *"If the action field is
`c2pa.redacted`: Check the `redacted` field that is a member of the
`parameters` object for the presence of a JUMBF URI. If the JUMBF URI is
not present, or cannot be resolved to an assertion, the claim shall be
rejected with a failure code of `assertion.action.redactionMismatch`."*
§18.15.4.7 says the field shall hold the URI of the redacted assertion.
§18.15.4.2 says the action's `reason` shall hold the rationale; no
validation step checks that.

**`c2pa` `claim.rs` `verify_actions`, rule 2.d** (read at `6c92bc3`) is
narrower. It runs only for v2 claims (v1 only in strict mode), and only
when the action **has `parameters`**. Then:
- `parameters.redacted` is absent, or names no manifest label (a relative
  URI), or names a manifest that is not in the store →
  `assertion.action.redactionMismatch`, *"redaction uri must be a valid
  reference"*;
- it names a manifest in the store, but that manifest's claim lists no
  assertion with the label → `assertion.notRedacted`, *"The assertion was
  not redacted"*;
- the label is in that claim's list → passes. That includes an assertion
  that was **not** redacted at all.

Both codes carry the url of the actions assertion that holds the action.

## Measured

Probes were made with `c2patool` 0.28.0's builder, as in step 129a. The
parent (`fixture-unsigned.png`) carries an extra `com.example.secret`. Each
child is made with `-p parent` and, unless noted, `redactions` naming the
secret, and one `c2pa.redacted` action with `reason: c2pa.PII.present`.
The keys were shredded; the probes stay in the scratchpad.

| probe: the action's `parameters` | 0.27.22 | 0.28.0 | here |
|---|---|---|---|
| `redacted` = the secret (the valid shape) | `Trusted` | `Trusted` | `Trusted` |
| no `parameters` | `Trusted` | `Trusted` | `Trusted` |
| `parameters` without `redacted` | `Invalid`, `redactionMismatch` | the same | **`Trusted`** |
| `redacted` names a manifest not in the store | `Invalid`, `redactionMismatch` | the same | **`Trusted`** |
| `redacted` names a label the parent's claim does not list | `Invalid`, `notRedacted` | the same | **`Trusted`** |
| `redacted` is relative (`self#jumbf=c2pa.assertions/…`) | `Invalid`, `redactionMismatch` | the same | **`Trusted`** |
| `redacted` names the parent's actions, which were not redacted | `Trusted` | `Trusted` | `Trusted` |
| `redacted` = the secret, but no `redactions` list at all | `Trusted` | `Trusted` | `Trusted` |
| `redacted: 42` | — | — | — (the builder refuses: *"expected a string"*) |

Every oracle fault sits on
`self#jumbf=/c2pa/<active>/c2pa.assertions/c2pa.actions.v2`, and nothing
else fails. The two versions agree on every probe.

## What it means

- **Four probes are `Invalid` in both oracles and `Trusted` here.** No byte
  of the asset changed in any of them. What is wrong is the claim's own
  account of a redaction: the reference points at nothing. It is the same
  kind of gap as step 123's actions rules before SPEC-033, and
  `docs/conformance.md` already names `c2pa.redacted` as not enforced.
  `docs/comparison.md` now carries it as a row.
- **The oracles are more lenient than §15.10.3.2.3 in three places:**
  - a `c2pa.redacted` action with no `parameters` passes, where 2.4 wants
    `redactionMismatch`;
  - a reference to an assertion that was never redacted passes, as long as
    it resolves; 2.4 asks only for *"resolved to an assertion"*, so that
    part agrees with it;
  - a reference that resolves while there is no `redactions` list passes
    too, for the same reason.
- **One naming difference:** for a label its manifest does not list,
  `c2pa-rs` says `assertion.notRedacted`, where 2.4 says
  `assertion.action.redactionMismatch` (*"cannot be resolved to an
  assertion"*).
- `StatusCode` lacks `assertion.action.redactionMismatch`;
  `assertion.notRedacted` exists since SPEC-035.

## Size, reasoned

One small spec on SPEC-033's `ActionsCheck`: rule 2.d for v2 claims, one
new code, and fixtures from this step's builder route (no surgery). The
decisions it needs:
1. whether a `c2pa.redacted` without `parameters` passes (the oracles) or
   fails (2.4);
2. whether to copy `c2pa-rs`'s `notRedacted` for an unlisted label, or
   2.4's `redactionMismatch`.

Following the oracles on both keeps the verdicts and codes equal to
`c2patool`'s, which is this project's rule, and closes the four `Trusted`
holes.
