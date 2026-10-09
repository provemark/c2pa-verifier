# Step 317 — A BMFF hash's algorithm, and a merkle map's count (fix F2)

*2026-10-09. SPEC-027 amendment 8, SPEC-028 amendment 2.*

Step 315 measured two ISOBMFF candidates of the reading (L1 and L12).
This step makes their probes fixtures and fixes both.

## The probes as fixtures

`bin/make-bmff-probe-variants.php` (new) repeats step 315's scratch work as
tooling. `c2patool` 0.28.1 signs with a throw-away P-256 hierarchy. It signs
an MP4 with a SHA-384 claim, and an init segment of a stream that `ffmpeg`
cut into DASH fragments. The builder then edits a few bytes of the hash
assertion without changing their length and signs the claim again. The
keys are deleted at the end; both `c2patool` versions' answers are kept
under `tests/Fixtures/c2patool/bmff-probes/`.

| probe | `c2patool` 0.28.1 | 0.27.22 | here before | here after |
|---|---|---|---|---|
| `sha384-control` | `Trusted` | `Trusted` | `Trusted` | `Trusted` |
| `sha384-bmff-no-alg` | `Trusted` | `Trusted` | **`Invalid`** | `Trusted` |
| `init-alone` | `Invalid` | `Invalid` | `Invalid` | `Invalid` |
| `init-no-count` | error: cannot decode | error | **`Trusted`** | `Invalid` |
| `init-count-zero` | `Invalid` | `Invalid` | **`Trusted`** | `Invalid` |

`init-count-zero` is new in this step: a count written as 0 was taken as
an empty tree too.

## What changed

- **`BmffHashCheck::assertionOf()`** takes the claim's `alg`. Without the
  assertion's own `alg`, the claim's applies, and SHA-256 only when
  neither names one, as `c2pa-rs`'s `Claim::alg()` does and as
  `DataHashCheck` already did (SPEC-027 amendment 8, AC9).
- **`BmffHashCheck::checkMerkle()`** requires an integer `count` of at least
  1, else `assertion.bmffHash.malformed`; a tree of no leaves binds nothing
  (SPEC-028 amendment 2, AC9).

## Measured

- **Tests first.** `tests/Unit/Hash/BmffProbesTest.php`: 2 failed, then 2
  passed. The other hash and verifier tests stay green (356).
- **The corpus.** 851 files under no settings and 159 settings files,
  before and after: 480 runs moved, all of them the three changed probes,
  each towards `c2patool`'s answer. No real file moved.
- **The fuzzer.** Seed 20261005 × 60 without settings: 0 faults, 238
  suspects. With `--trust` (240 pairs, the probes included): 20261005 × 60
  gave 534 suspects and 20261009 × 200 gave 1,765. Each was judged by
  `c2patool` 0.28.1 under the same settings, and none is more lenient here.
- `composer check`: 957 passed. PHPStan in Docker `php:8.3-cli`: no
  errors. (SPEC-027 keeps its open questions after its amendments; the
  amendment was first placed among them, and `spec-check` caught it.)
