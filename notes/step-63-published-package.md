# Step 63 — The published package, tests first

*2026-09-22.* SPEC-023 was approved the same day. This step is its red
phase: thirteen failing tests and the fixture trees AC1 needs, no
implementation.

## What the red had to prove, and the trap in it

Two of these criteria are **true today**. AC4 — every relative link in the
shipped markdown resolves inside the package — was measured in step 62:
96 markdown files, 0 links pointing outward. AC5 is true too. A test that
confirms a thing already true, and was never seen failing, proves only
that it can read.

So each criterion is written with a case it must catch, and the broken
case is asserted beside the real one:

| criterion | the case it must catch |
|---|---|
| AC1 | a top-level path in neither set; a path in **both**; a missing `.gitattributes` |
| AC2 | a dist with no file under `src/`; a `bin` entry `composer.json` declares but the archive lacks |
| AC3 | the same commit **without** `.gitattributes` — 62.9 MB of fixtures, the state of the repository before step 62 |
| AC4 | a README linking to a path that is `export-ignore` |
| AC5 | an archive with `AI-LOG.md` removed; a README with no "How this is built" section |
| AC6 | nothing synthetic: it extracts the real archive into an empty directory and runs the CLI there |

AC3's second test is the one worth pointing at: it is not a mock. It asks
git for the archive as it would have been shipped before step 62 and
requires both findings to fire on it. The 62.9 MB is real, and if the
criterion ever stops noticing, that test says so.

## A decision the criteria forced

AC1 needs a **declared** list of what ships. The obvious reading —
"shipped means not `export-ignore`" — cannot work: under it a
`benchmarks/` directory added next year ships by default and no finding is
possible, which is the exact failure the criterion exists for. So the
shipped set is explicit and the two statements are checked against each
other; a path on both lists is a finding of its own, because a
contradiction may not be resolved silently in either direction.

## The guard at the top of the test file, and why it is there

The first red was a single fatal:

```
Failed opening required '.../bin/package-check.php'
Tests: 13 failed
```

— and measured, that was **the whole suite**: the other 353 tests stopped
reporting because of one missing file. A red phase that hides every other
test is worse than no red phase. The `require_once` is therefore guarded
by `is_file()`, and the result is thirteen separate failures, each naming
the function it wants:

```
Call to undefined function packageCheck()      × 5   (AC1)
Call to undefined function packageDistCheck()  × 6   (AC2–AC5)
Call to undefined function packageGitArchive() × 1   (AC3)
Call to undefined function packageExtract()    × 1   (AC6)
Tests: 13 failed, 353 passed (7176 assertions)
```

The guard stays after the green phase. It is not hiding a missing file: it
makes a checker deleted later fail these thirteen tests loudly instead of
taking the suite down with it.

## Shelling out to git

AC2, AC3, AC5 and AC6 need the archive Composer would actually fetch, and
only `git archive` produces it. The maintainer decided on 2026-09-22 that a
test in this spec's group may invoke `git`; no other test may, and nothing
in `src/` ever may. The rule against `exec` binds the verification path and
is unchanged there. `ext-phar` is added to `require-dev` for reading the
tar, which needs no second process.

## What is red on purpose after this step

`composer check` does not pass, and CI on this commit will be red in two
places, both expected of a tests-first step:

- Pest: 13 failed, 353 passed.
- PHPStan: 83 errors, every one of them in `tests/Unit/PackageTest.php`,
  all of the form "undefined function" and the types that follow from it.
  This repository has been here before — the first milestone's CI was red
  at PHPStan on the classes SPEC-001 had not yet created.

Both go green in 63b, which writes `bin/package-check.php`.
