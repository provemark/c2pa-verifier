# Step 121 — Fuzzed again, and four known gaps measured against both oracles

*2026-09-24. Measurement only: no specification, test or code changed.*

## 1. The fuzzers, after a day of changes to trust and hashing

**`bin/fuzz.php 20260924 60`** over the four corpora, the binding
variants, the three signed fixtures, and today's new fixtures
(`cose/x5chain-single.jpg`, `trust/anchors/eku-probe.jpg`, `absence/`,
`matrix/`): **7,722 runs over 135 files, 0 faults** (no exception escaped).
7,518 runs were `Invalid` and 204 stayed `Valid`. The slowest run took
0.04 s, peak memory was 38 MiB, and the whole run took 11 s.

The 204 that stayed `Valid` were each put to both `c2patool` versions:
**204 of 204 are `Valid` in 0.27.22 and in 0.28.0**. As in step 45, they
land in bytes the formats leave unbound: the matrix WebPs and JPEGs, and
the TrustNXT file's padding. None is a wrong `Valid`.

**A throwaway settings fuzzer** (session scratchpad) mutated all 35
settings files, in `trust/` and in `trust/anchors/`. It deleted keys,
injected the eight known keys with wrong types, repeated entries past the
bounds, and flipped raw bytes. Each result went to
`TrustSettings::fromJson()`, and whatever was accepted was verified on
`fixture-signed.jpg`: **10,500 runs, 5,109 refused with `TrustException`,
5,391 accepted and verified, 0 faults.** No other exception type escaped
the new `trust.anchors` parser.

## 2. Four known gaps, asked of both oracles

Probe files were made with step 110's scratchpad CA (a leaf carrying the
claim-signing EKU and emailProtection under an intermediate), each
signed onto `fixture-unsigned.jpg` by `c2patool` 0.28.0 and verified
under that root by 0.27.22, 0.28.0 and `bin/c2pa-verify`:

| probe | the rule | 0.27.22 | 0.28.0 | here |
|---|---|---|---|---|
| control | none broken | `Trusted` | `Trusted` | `Trusted` |
| `c2pa.created` without `digitalSourceType` | issue #2, the created case | **`Invalid`**, `assertion.action.malformed` | **`Invalid`**, the same | `Trusted` |
| `c2pa.edited` without `digitalSourceType` | issue #2, the general case | `Trusted` | `Trusted` | `Trusted` |
| `c2pa.external-reference` whose `label` is `c2pa.actions.v2` | issue #3 | `Trusted` | **`Invalid`**, `assertion.external-reference.malformed` | `Trusted` |
| `reviewRatings` beside `dataSource: humanEntry.anonymous` (in the actions assertion's metadata) | issue #1 | `Trusted` | `Trusted` | `Trusted` |
| `icon` as free text in `claim_generator_info` | issue #11 | — | — | — |

The icon probe could not be made: `c2patool` 0.28.0's builder refuses a
non-reference `icon` (*"data did not match any variant of untagged enum
UriOrResource"*). While signing, 0.28.0 also printed its own validation of
the two probes it then calls `Invalid`: *"c2pa.created action must have a
digitalSourceType"* and *"external reference label 'c2pa.actions.v2' is
forbidden per C2PA 2.4"*.

## What the 2.4 text says (read)

- **External reference** — §15.10.3.2.2: if the `label` is one of
  thirteen (the actions, cloud-data, external-reference, every hash and
  every ingredient label) *"the claim shall be rejected with a failure
  code of `assertion.external-reference.malformed`"*. The same section
  also requires `location.url`, and `alg` and `hash` together or not at
  all. `StatusCode` has no such case: the public contract would grow.
- **`digitalSourceType`** — the validation rules for actions
  (§15.10.3.2.3) do not require it on any action. §18's actions section
  says a `digitalSourceType` *"shall be recorded with the c2pa.created
  action"*, which is a rule for the claim generator. Both `c2patool`
  versions enforce it at validation all the same, for `c2pa.created`
  only.
- **`reviewRatings` with `humanEntry`** — §18 says it *"shall not be
  present"*, which the catalogue lists as PRED-ASSE-024. Neither oracle
  enforces it.

## What was already known

The `created`-without-`digitalSourceType` case is not new. SPEC-018 named
*"the content family of c2pa-rs's `verify_actions` 2.b–2.f"*, including
*"`digitalSourceType` values"*, as out of scope: *"a manifest that breaks
one of them and nothing else is `Valid` here and `Invalid` at c2patool,
and the drift alarms name any corpus file that shows it (none does
today)"*. None does still. This probe is the first file that shows it.

## Summary for the maintainer

- **In both oracles, laxer here:** `c2pa.created` without
  `digitalSourceType` (a named leniency since SPEC-018).
- **New in 0.28.0, laxer here:** a forbidden external-reference label
  (2.4 §15.10.3.2.2, a *shall*, with a status code this verifier does
  not have).
- **Enforced by no one:** issue #2's general case and issue #1.
- **Not measurable:** issue #11.

None of these lets a changed byte through. They are rules about what a
manifest may say (category 2 of `docs/conformance.md`).
