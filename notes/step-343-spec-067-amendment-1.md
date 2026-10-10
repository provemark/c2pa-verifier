# Step 343 — SPEC-067 amendment 1, from an independent review

*2026-10-10.*

An independent review of step 342 found no path to a wrong `Valid` or
`Trusted`, and confirmed the corpus result (the same 10 runs move; the eight
fixtures with an unreferenced `c2db` store do not). It found one slowdown and
four smaller points; Maurice approved them as amendment 1.

## What changed

- **Each data box is hashed once per algorithm** within a manifest's check
  (`IconReferenceCheck::check()` keeps `$digests`, as `HashedUriCheck` does
  since SPEC-045). The review's probe — one claim with 2,000 icons naming
  an 8 MB box — took 42.5 s; it needs no key, because the check runs with a
  broken signature too. The new test (300 icons, a 4 MB box) took 3.07 s
  before and 0.03 s after.
- **`alg` as `HashedUriCheck` handles it**: the icon's or the claim's; none,
  a non-string one or one outside sha256/384/512 is `algorithm.unsupported`;
  a wrong or non-byte hash is `assertion.hashedURI.mismatch`.
  `checkDataBox()` became reachable (internal) for those tests.
- **Accepted and pinned:** a redaction naming a data box is now checked
  (`assertion.notRedacted` when the box keeps its bytes; it used to be
  skipped), and a data box that breaks the JUMBF rules refuses the store
  even when no icon names it (measured: the description box's toggles are
  `0x13`; clearing Requestable gives "Requestable is not set").
- A misplaced docblock and an argument-less `sprintf`; AC6 now says two
  settings, which is what the corpus tool runs.

## Measured

- Tests red then green (20 in `DataBoxIconTest`); five mutations, four
  caught, the fifth (a separate hash-length check) dead and removed.
- The corpus against `e4a59e4`: 1,792 runs, the same 10 move; identical to
  step 342's run.
- `composer check`: 1,028 passed. `bin/api-check.php`: the contract unchanged.
