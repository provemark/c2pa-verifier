# Step 406 — A plan for testing the manifest graph, and what byte fuzzing reaches

*2026-10-24.* The review of 2026-10-23 found four wrong `Valid` or
`Trusted` verdicts in released code; three of them sat in how manifests
refer to each other (a recorded ingredient status acknowledging a fault of
the manifest that recorded it, step 389; a redaction reaching the active
manifest, step 390; trust-list timelines, step 391). Maurice asked for a
measurement plan aimed at that part, and approved it. This step writes the
plan down and measures its premise. No code in `src/`.

## The premise, measured

The premise: `bin/fuzz.php` flips bytes in signed files, and nearly every
flip breaks a signature or a hash before the graph code decides anything,
so it cannot reach the faults step 389 and 390 fixed, which need manifests
an attacker signed themselves.

Line coverage was measured with pcov (PHP 8.5.12), for
`ManifestGraph`, `IngredientManifestCheck`, `UpdateManifestCheck`,
`IngredientAssertion` and `Verifier`, three ways:

| run | `ManifestGraph` | `IngredientManifestCheck` | `UpdateManifestCheck` | `IngredientAssertion` | `Verifier` |
|---|---|---|---|---|---|
| `fuzz.php 406 5 --trust` (1,425 mutated runs over 285 pairs) | 87 / 95 | 150 / 156 | 55 / 58 | 92 / 98 | 201 / 208 |
| `fuzz.php 406 0 --trust` (the 285 unmutated pairs only) | 87 | 147 | 55 | 92 | 196 |
| the Pest suite (1,316 tests; clover, statements) | 87 / 87 | 148 / 155 | 55 / 57 | 92 / 98 | 184 / 191 |

(pcov and clover count executable lines slightly differently; the two
fuzz rows are comparable with each other.)

- The mutations add three lines in `IngredientManifestCheck` (the `catch`
  around reading an ingredient's COSE and chain) and five in `Verifier`
  (a container error, and a file without a store). Not one line of
  `ManifestGraph`, `UpdateManifestCheck` or `IngredientAssertion` is
  reached by a mutation that the unmutated corpus does not already reach.
- Line coverage is near complete in all three runs. It therefore cannot
  tell whether this part is tested.
- **The falsification:** the same unmutated run on `v0.7.1` (a worktree
  with a copied `vendor/`), the release before step 389. Its
  `recordedInStore()`, which pooled every recorded status in the store and
  so let a manifest acknowledge its own fault, was covered 5 of 5 lines,
  and `acknowledge()` 16 of 16. The fault was in code every run executed.
  It was a wrong *combination* (who recorded what, about which manifest),
  and no line count shows that.

The 58 suspects of the mutated run (mutated files still `Trusted` under
their settings) were put to `c2patool` 0.27.22 and 0.28.1
(`check-suspects.sh`): 56 `Valid` in both. The other two, by hand: a flip
in the Pixel 10 JPEG's XMP segment, which that file's own data hash
excludes (bytes 51805 + 65458; `c2patool` reports the same failures with
and without the flip), and a block in the zero padding after the
signature in `pixel-shape.mp4` (step 297's class; `c2patool` 0.28.1 and
0.28.2 `Valid` with and without it, 0.27.22 `Invalid` on the unmutated
file already). In both, this verifier's report is byte-identical to the
unmutated file's. No fault, no wrong verdict.

## The plan

**An instrument that builds graphs, not one that flips bytes.** A
generator in `bin/` builds whole manifest stores in which every manifest
is correctly signed again, so that the only fault left is the one it
placed. It reuses what the `make-*-variants.php` scripts already do (CBOR,
assertion boxes, COSE, rebinding a PNG). Seeded and replayable, like
`fuzz.php`.

- Shapes: chains of depth 1 to 6, shared ingredients, cycles, a manifest
  naming itself, references to a manifest that is not there, duplicate
  labels; `parentOf`, `componentOf`, `inputTo`; update manifests;
  redactions at every level; recorded `validationStatus` and
  `validationResults` that are right, wrong, or about another manifest.
- Faults, per manifest: signature broken, a hashed URI wrong, an
  ingredient hash wrong, a certificate not trusted, a certificate expired
  without a timestamp, no hard binding.
- Container: PNG first; JPEG only if PNG finds something.
- Keys: throw-away keys outside the repository, deleted after the run,
  behind SPEC-078 amendment 1's inode guard (Maurice, 2026-09-21: tooling
  may sign with throw-away keys; the product never signs). The attacker
  may hold a key under a trusted anchor (Maurice, 2026-10-24): without
  one `Trusted` is out of reach, and the faults of 2026-10-23 needed one.

**Oracles.**

1. The plan itself. The generator knows where it put each fault, so the
   right answer is known without trusting another program:
   - I1: a fault in the active manifest never gives `Valid` or `Trusted`,
     whatever the ingredients record (SPEC-021 AC5; CAI-12751).
   - I2: a fault in manifest M counts as acknowledged only by a record in
     a manifest above M, never by M itself or one below it, cycles
     included (SPEC-070 amendment 3).
   - I3: a redaction reaches only below the manifest that declares it;
     the active manifest's assertions are never redactable (SPEC-035
     amendment 6).
   - I4: adding a fault, or a manifest nothing refers to, never improves
     the verdict; the order of manifests in the store changes nothing.
   - I5: no exception, no PHP warning, and a time bound, with cycles and
     deep chains too.
2. `c2patool` 0.28.2, as a comparison and not as the truth, on the
   suspect runs only (Maurice, 2026-10-24). A suspect is a run where this
   verifier answers better than `c2patool`; a run where `c2patool`
   answers better than the plan allows is kept apart.

**The instrument tested first.** Before a clean run on 0.8.0 means
anything, the generator must find the known faults: run against `v0.7.1`,
it must report step 389's self-acknowledgement and step 390's redaction
of the active manifest. If it does not, it is too weak.

**Steps.**

| step | what | `src/` |
|---|---|---|
| 406 | this plan and the coverage measurement | no |
| 407 | the generator and the plan oracle, tested against `v0.7.1` | no |
| 408 | runs on 0.8.0 (three seeds; the number of graphs set after timing the first 100), then `c2patool` on the suspects | no |
| 409 on | each real finding its own step with a red test, fixed quietly under `SECURITY.md`'s policy | yes |

The generator is committed to `bin/` (Maurice, 2026-10-24), so that
every finding replays.

## Measured and reasoned

- Measured: the three coverage runs above (`php -d pcov.enabled=1
  -d pcov.directory=src` around `bin/fuzz.php 406 5 --trust` and
  `406 0 --trust`; `vendor/bin/pest --coverage-clover`); the same
  unmutated run on `v0.7.1`; the 58 suspects against `c2patool` 0.27.22,
  0.28.1 and 0.28.2, the two outliers by hand.
- Reasoned: that a byte flip cannot produce a correctly signed graph of a
  different shape (it would need a signing key); that the invariants
  I1–I5 follow from the specs and amendments named beside them.
- Decided by Maurice: the plan; an attacker holding a trusted key; the
  `c2patool` comparison on suspects; the generator committed.
