# Step 321 — The cloud-data assertion's structure (SPEC-063, F5)

*2026-10-09.*

SPEC-063, drafted in step 320 and approved by Maurice, makes this verifier
check `c2pa.cloud-data` assertions as C2PA 2.4 §15.10.3.2.1 says. Before,
it did not read them at all. Nothing is fetched.

## The probes

`bin/make-manifest-probe-variants.php` grows by eight probes. `c2patool`
0.28.1 refuses to write a malformed cloud-data assertion, so it signs a
well-formed one for a placeholder label. The new `mqEditAssertion()`
then changes a few bytes in that assertion without changing their length,
re-hashes the claim's hashed URI for it and signs the claim again.

| probe | `c2patool` 0.28.1 | 0.27.22 | here before | here after |
|---|---|---|---|---|
| `cloud-ok` (as `c2patool` writes it: `location.hash` as text) | `Trusted` | `Trusted` | `Trusted` | `Trusted` |
| `cloud-hash-bytes` (`location.hash` a byte string) | `Trusted` | `Trusted` | `Trusted` | `Trusted` |
| `cloud-hash-data` | `Invalid` (`hardBinding`) | `Trusted` | **`Trusted`** | `Invalid` (`hardBinding`) |
| `cloud-size-zero` | `Invalid` (`malformed`) | `Trusted` | **`Trusted`** | `Invalid` (`malformed`) |
| `cloud-actions` (`c2pa.actions.v2`) | `Invalid` (`malformed`) | `Trusted` | **`Trusted`** | `Invalid` (`malformed`) |
| `cloud-no-location` | `Invalid` (`malformed`) | `Trusted` | **`Trusted`** | `Invalid` (`malformed`) |
| `cloud-in-ingredient` (parent `cloud-hash-data`, signed by 0.28.1) | `Trusted` | `Trusted` | `Trusted` | `Trusted` |
| `cloud-in-ingredient-unrecorded` (the same, signed by 0.27.22) | `Invalid` (delta `hardBinding`) | `Trusted` | **`Trusted`** | `Invalid` (delta `hardBinding`) |

The two ingredient probes show the delta rule of SPEC-021 at work. 0.28.1
records the ingredient's cloud-data failure when it signs, so reading the
file reports no new failure. 0.27.22 records none, so the failure is a
delta, here and in 0.28.1.

## What changed

- **`src/Manifest/CloudDataCheck.php`** (new). It checks the structure
  (`label`, `size` of at least 1, `location` with `url`, `alg` and
  `hash`) and the label lists, splitting hard bindings from the other
  forbidden labels as `c2pa-rs` 0.91.1 does. It is called by `Verifier`
  (check name `cloudData`, only where the claim carries one) and by
  `IngredientManifestCheck`.
- **`StatusCode`**: `assertion.cloud-data.malformed`, `.hardBinding` and
  `.actions`, verbatim. This is an addition to the public API: the
  recorded surface, and the two counters that guard it, grow by three.
- **SPEC-063 amendment 1, for Maurice to confirm.** It records two
  measurements made while building. First, `location.hash` is accepted
  as text as well as bytes, because `c2patool` writes text. Second, AC7
  is shown with the two ingredient probes instead of a unit test.

## Measured

- **Tests first.** `tests/Unit/Manifest/CloudDataCheckTest.php`: 7 failed,
  then 7 passed. AC7 was rewritten after the ingredient probes; it was
  then shown red by removing the call in `IngredientManifestCheck` (1
  failed), and green with it.
- **The corpus.** 870 files under no settings and 160 settings files,
  before and after: 1,127 runs moved, all of them the cloud-data probes.
  For `cloud-ok` and `cloud-hash-bytes` only the report changed (the
  check is named). No real file moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, 238
  suspects. With `--trust` (250 pairs): 20261005 × 60 gave 534 suspects
  and 20261009 × 200 gave 1,765. Each was judged by `c2patool` 0.28.1
  under the same settings, and none is more lenient here.
- `composer check`: 970 passed. PHPStan in Docker `php:8.3-cli`: no
  errors.
