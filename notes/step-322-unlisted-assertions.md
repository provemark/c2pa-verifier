# Step 322 — Assertions §15.10.3.2 does not list, by design (SPEC-063 amendment 2)

*2026-10-09.*

Four candidates of the reading of C2PA 2.4 had the same shape as L2 in
step 319. `c2patool` refuses an assertion whose shape breaks its schema;
this verifier does not read that shape. The candidates are L4 (metadata
without `@context`), L6 (certificate status without `ocspVals`), L8 (soft
binding without `blocks`) and P08-3 (an action's `when` as an integer).

The validator rule is §15.10.3.2. It lists the assertions a validator
checks further (cloud data, external reference, actions, metadata,
session keys, time-stamp, alternative content representation). It says
an assertion not on that list *"does not require any additional
validation steps"*. For metadata, §15.10.3.2.4 says *"No
assertion-specific validation is required"*. The actions steps
(§15.10.3.2.3) name no field types. None of the four feeds a verdict
here: not the binding, the signer or the timestamp. This verifier reads
OCSP responses from `rVals` only, not from a certificate-status assertion.
Maurice decided to name the differences, not to adopt `c2patool`'s
decoding.

## Measured

`bin/make-manifest-probe-variants.php` has `c2patool` 0.28.1 sign one
well-formed control with all four assertions. It then breaks one shape
per probe with the same-length edit of step 321 (all its fixtures were
rebuilt with new keys, with the same answers as before):

| probe | `c2patool` 0.28.1 | 0.27.22 | here |
|---|---|---|---|
| `unlisted-control` | `Trusted` | `Invalid` (its old `@context` rule) | `Trusted` |
| `unlisted-metadata-no-context` | error: cannot decode | error | `Trusted` |
| `unlisted-certificate-status-no-ocspvals` | error: cannot decode | error | `Trusted` |
| `unlisted-soft-binding-no-blocks` | `Invalid` (`claim.malformed`) | `Invalid` | `Trusted` |
| `unlisted-action-when-integer` | error: cannot decode | error | `Trusted` |

AC9 in `tests/Unit/Manifest/CloudDataCheckTest.php` pins these answers
down. It passed at once, which is right for a test of unchanged
behaviour; it is not a red-then-green test. No file under `src/` changed,
so the corpus and the fuzzer were not run again.

`docs/reading-c2pa-2.4.md` moves five rule rows from *candidate* to *by
design*, and marks L7 and L11 fixed in the measured table: 59 by design
and 50 candidates now.

- `composer check`: 971 passed.
