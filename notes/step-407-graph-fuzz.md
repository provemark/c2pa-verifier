# Step 407 — A fuzzer for the manifest graph, tested against 0.7.1

*2026-10-24.* Step 406's plan, second step: a generator of manifest
stores in which every manifest is signed again correctly, judged against
a prediction made from its own plan. Maurice approved it ("akkoord, begin
met step 407"). No code in `src/`.

## What was built

`bin/graph-fuzz.php <seed> <graphs> <scratch-dir> [--verifier <root>]
[--calibrate-only]`.

- **The store is written by the script itself** — CBOR, JUMBF, claim v2,
  ingredient v3 assertions, `c2pa.actions.v2`, `c2pa.hash.data`, a detached
  ES256 COSE_Sign1 with `x5chain` in the protected header — into the
  unsigned PNG fixture as a `caBX` chunk. It uses none of the verifier's
  classes, so `--verifier` can point at any checkout and the same plans
  are judged by that version.
- **A plan** has one to six manifests, each with a key (trusted, under an
  untrusted root, or expired), its own faults (signature broken after
  signing, a hashed URI wrong, the active manifest without a hard
  binding), up to three ingredients (to another manifest, to itself, to a
  label the store does not hold, or to none; `parentOf`, `componentOf`,
  `inputTo`; the reference's hash right or wrong), redactions of another
  manifest's (or its own) probe assertion, and in each ingredient up to
  three recorded statuses, mostly aimed at a fault the plan predicts, of
  any manifest, under their own kind or another, absolute or relative.
- **The oracle** predicts every fault from the plan alone and which of
  them a record may acknowledge: a fault of manifest X only by a record of
  its code and url, under its kind, held by a reached manifest that X lies
  below, never a fault of the active manifest; a redaction only by a
  reached manifest above its target, never of the active manifest; a box
  removed without such a redaction refuses the store. Untrusted lowers the
  state to `Valid`; any other fault left makes it `Invalid`.
- **Four outcomes**: WRONG (the verifier answers better than predicted),
  STRICTER (worse), ORDER (the other manifests reversed in the store give
  another state or other failures), FAULT (an exception or a PHP warning).
- **Calibration** first, 27 fixed plans: plans without a fault (which must
  be `Trusted`, or the run stops), every fault alone in the active manifest
  and in an ingredient, acknowledgements that may and may not count, the
  three wrong verdicts steps 389 and 390 fixed, and redactions.
- Keys: a throw-away hierarchy made with `openssl` for each run in a
  directory outside the repository (SPEC-078 amendment 1's guard), read
  into memory, the key files deleted at once and the directory on exit.

## Corrections made while building, before any result was trusted

Each was found because a plan without a fault, or a single-fault plan,
did not come out as predicted:

1. Every signer was `signingCredential.invalid`: OpenSSL 4.0.3 writes no
   AuthorityKeyIdentifier unless asked, and SPEC-015's profile requires
   one. The extension section now asks for it. (The older `make-*` scripts
   ran under OpenSSL 3.)
2. A box removed without a redaction that may take it does not give a
   status that a record could acknowledge: the store cannot be read
   (`assertion.missing` on `self#jumbf=/c2pa`). The oracle now treats it so.
3. The same holds for such a box in a manifest the walk never reaches:
   every manifest is read first and redactions count only from reached
   ones (SPEC-035 amendment 5). Twelve STRICTER runs out of 200 came from
   the oracle missing this; after the correction, none.

## The instrument tested: v0.7.1

The same calibration and the same 2,000 plans (seed 407), judged by
`v0.7.1` (a worktree with a copied `vendor/`) and by `0.8.0`:

| | 0.7.1 | 0.8.0 |
|---|---|---|
| calibration plans with a finding | 5 of 27 | 2 of 27 |
| random plans WRONG | 139 of 2,000 | 3 of 2,000 |
| STRICTER, ORDER, FAULT | 0, 0, 0 | 0, 0, 0 |
| predicted `Trusted` / `Valid` / `Invalid` | 427 / 26 / 1,547 | the same |
| got `Trusted` / `Valid` / `Invalid` | 564 / 27 / 1,409 | 430 / 26 / 1,544 |
| time | 8.7 s | 7.8 s |

On `v0.7.1` the calibration's three plans for steps 389 and 390 (a
manifest acknowledging its own fault, a child acknowledging its parent's,
an ingredient redacting the active manifest) are WRONG; on 0.8.0 they are
not. The random plans find the same classes on `v0.7.1` without being
written for them: faults acknowledged by a manifest that may not speak
for them (cyclic references, missing manifests, expired and broken
signers), and boxes removed by a redaction that may not take them. The
instrument finds what step 406 asked it to find.

## Two wrong verdicts in 0.8.0

Both are `Trusted` where the plan says `Invalid`, in released code, and
both are in the calibration now as replayable plans.

1. **Only the first reference to a manifest has its hash checked.** An
   active manifest with two ingredient assertions naming the same
   manifest, the second with a wrong hash, is `Trusted`.
   `IngredientManifestCheck::check()` validates each referenced manifest
   once, through the first assertion that names it, and so never compares
   the second assertion's hash (C2PA 2.4 §15.11.3.3.2). `c2patool` 0.28.2:
   `Invalid`, `ingredient.manifest.mismatch` under the second assertion.
   Found by the calibration and by random plan 407-1440.
2. **A redaction of the active manifest through a cycle.** A reached
   ingredient whose references lead back to the active manifest has the
   active manifest "below" it, and `ManifestStore::scopedRedactions()`
   then lets it redact an assertion of the active manifest; step 390's
   rule that the active manifest's assertions are never redactable is not
   held there, while acknowledgement has its own guard for the active
   manifest. The cycle's own `assertion.ingredient.malformed` can be
   acknowledged by a manifest above it, so the whole store comes out
   `Trusted` with an assertion of the active manifest gone. `c2patool`
   0.28.2 refuses such a store ("cyclic ingredient found in path").
   Found by random plans 407-359 and 407-1250.

Neither is fixed in this step (one concept per step). Under
`SECURITY.md`'s policy they are not described publicly before a release
with the fix; this commit stays local until then.

## Measured and reasoned

- Measured: `php bin/graph-fuzz.php 407 2000 <scratch>` against this tree
  (0.8.0) and with `--verifier` against `v0.7.1`; `--calibrate-only` on
  both; `c2patool` 0.28.2 with the run's settings on the three 0.8.0
  findings; `composer check` (exit 0, 1,316 passed; PHPStan level max on
  the script).
- Reasoned: that the attacker of finding 2 needs the active manifest's
  label before it is signed, or to be its signer, so it is hard to use;
  it is still a verdict the rules do not allow.
- Decided by Maurice: the plan (step 406); the generator committed.
