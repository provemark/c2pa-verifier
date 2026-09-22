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

---

# 63b — the checker, and three things it taught on the way

`bin/package-check.php` makes all thirteen green: **366 passed** in all,
`composer check` exit 0. Three of the discoveries are worth more than the
code.

## 1. AC6 could not pass as approved, and the criterion was wrong

AC6 said "extracted into an empty directory, with no `vendor/`". It cannot
pass — and not because the package is broken. `bin/c2pa-verify` is a shim
that requires an autoloader, looking first for the project's
`vendor/autoload.php` and then for `../../../autoload.php`, which is where
it sits once Composer has installed it under
`vendor/provemark/c2pa-verifier/bin/`. A PHP library without an autoloader
loads no classes. Asking it to run without one measures nothing.

Amended in the spec before the first line of the checker was written
(SPEC-023 amendment 1): the criterion now describes the layout Composer
creates, and the autoloader is built from the `autoload.psr-4` map in the
**archive's own** `composer.json` — not this repository's, and not
Composer's generated files. That distinction is the point. Reading the
package's own declaration tests whether the declaration and the shipped
files agree; generating a rival autoloader would be a second truth and
would pass even if the map were wrong.

The test now proves the real thing: extract, write the autoloader, run
`bin/c2pa-verify` from inside `vendor/provemark/c2pa-verifier/` on a signed
fixture passed by absolute path from outside. Exit 0, `Trusted`.

## 2. `git archive` without `--worktree-attributes` is not "without .gitattributes"

The first version of AC3's second test asked for the archive with the flag
off, assuming that gives the unfiltered dist. It gave 1.9 MB. The flag adds
the **working tree's** `.gitattributes` to the one already committed; git
applies a committed `.gitattributes` either way. Since step 62 committed
the file, both archives are filtered.

So the test now names the historical fact instead of a flag: the commit
that added `.gitattributes` is found with `git log --diff-filter=A`, and
its parent is archived. That is the dist as it really would have shipped —
**62.9 MB, 760 entries under `tests/`** — and both findings fire on it.
The flag is passed as `false` there, because with it on, git would paste
today's `.gitattributes` onto a commit that predates it and hand back
1.9 MB again.

This matters beyond the test. Anyone reasoning about "what would ship" by
toggling that flag will reason wrongly.

CI checks out at depth 1, where that parent commit does not exist, so
`.github/workflows/ci.yml` now sets `fetch-depth: 0`. When the history is
absent the test skips itself and says why, rather than passing quietly.

## 3. Two small traps, recorded so they are not re-learned

- **`phar://` paths are canonicalised.** Computing an entry's relative path
  by stripping a prefix built from the tar's own filename fails on macOS,
  where `/var` becomes `/private/var`; the paths came back as
  `520.tar/AI-LOG.md`. The iterator's own `getSubPathname()` is the answer,
  and `PharData[$path]` for reading — no string surgery at all.
- **A Pest `skip()` closure may not be `static`.** Pest binds it to the
  test case; a static closure cannot be bound, and the failure surfaces as
  a `TypeError` inside the error renderer rather than anything that names
  the cause.

## What the checker refuses to guess

"Shipped" is a declared list (`PACKAGE_SHIPPED`), not the complement of
`export-ignore`. Under the complement reading a directory added next year
ships by default and AC1 can raise no finding, which is the failure the
criterion exists for. A path on both lists is its own finding: a
contradiction may not be resolved silently in either direction.

Run as a script it prints what it found:

```
$ php bin/package-check.php
13 shipped, 9 export-ignore: every top-level path is classified
dist: 192 files, 1.9 MB
```

It is not added to `composer check`: the thirteen tests already run there,
and building the archive twice per check buys nothing.
