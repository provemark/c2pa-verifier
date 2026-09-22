# SPEC-023: The published package — what `composer require` delivers

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-22                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Every other spec in this repository is anchored in the C2PA specification.
This one is not, and that is worth saying plainly: its subject is what a
stranger receives when they type `composer require provemark/c2pa-verifier`.
Only SPEC-000 shares that footing — it enforces rules about specs that were
otherwise only on paper. This spec does the same for the package.

Step 62 audited the repository by hand and found three things wrong, one of
them serious: Composer fetches the dist archive, which is `git archive`, and
without a `.gitattributes` every user pulled 62.9 MB — of which `tests/` was
63 440 kB against `src/` 500 kB — into their `vendor/` directory to run none
of it. That is fixed. Nothing stops it coming back.

The failure modes are specific, and none of them is caught by anything in
`composer check` today:

1. **A new top-level directory.** Someone adds `benchmarks/` or `examples/`
   and never thinks about the dist. It ships (weight, or worse, something
   that should not be public) or it does not (a documented path that is
   missing for a consumer). Both are silent.
2. **A dist that is small but broken.** `export-ignore` cut something `src/`
   or `bin/c2pa-verify` needs. The repository's own test suite proves
   nothing here: it runs against the working tree, where the file is still
   present. A consumer discovers it; we do not.
3. **A package that stops describing itself.** Step 62 decided to ship
   `docs/`, `specs/`, `notes/` and `AI-LOG.md` on purpose, so that every
   link in the README resolves inside the package and the disclosure travels
   with the code. That decision lives in a comment in `.gitattributes` and
   in a note. A later `export-ignore` could quietly reverse it.

What this spec adds is one thing: the audit becomes a test that runs on
every commit instead of a memory of an afternoon.

The governing decisions are in `docs/adr/` (ADR-0001 dependencies, ADR-0002
name and licence) and in the README's "How this is built" section, which is
this project's disclosure and is required to reach whoever installs the
package.

## Scope

**In scope**

- Classification of every top-level path in the repository as shipped or
  `export-ignore`, with no third state.
- The contents of the dist archive: what must be present, what must be
  absent, and an upper bound on its size.
- That every relative link in the markdown the package ships resolves to
  something the package also ships.
- That the disclosure (`AI-LOG.md` and the README section that points at it)
  is in the package.
- That the package, unpacked into an empty directory with nothing else,
  runs: the CLI verifies a signed file and reports `Trusted`.

**Out of scope** (each needs its own spec before it may be built)

- When to tag, what version number to use, and whether to register on
  Packagist. Those are the maintainer's decisions; a criterion would turn a
  decision into a rule.
- The `CHANGELOG.md` format and its upkeep.
- A code of conduct, contribution templates, issue templates.
- Anything about the repository's visibility or branch protection: settings
  of the hosting platform, not properties of the package.
- Reproducibility of the archive byte-for-byte across git versions.

## Behavior

- **AC1 — every top-level path is classified** *(required: the error path)*
  - Given the repository's tracked top-level paths and the contents of
    `.gitattributes`
  - When the package check runs
  - Then each path is either in the shipped set or marked `export-ignore`,
    and a path in neither is a finding naming that path and saying that a
    decision is missing — not a default either way. Run against a fixture
    tree carrying an unclassified directory, the check reports exactly that
    finding and a non-zero exit; run against the repository, no findings.

- **AC2 — the dist carries what a consumer needs**
  - Given the archive `git archive` produces with `.gitattributes` applied
  - When its entries are listed
  - Then `composer.json`, `LICENSE`, `README.md` and `src/` are present, and
    every path in `composer.json`'s `bin` array exists in the archive.

- **AC3 — the dist carries no fixture, and stays small**
  - Given that archive
  - When its entries and its size are measured
  - Then no entry lies under `tests/`, and the archive is at most 4 MB
    (measured 1.9 MB on the day this spec was written; the ceiling is there
    to fire long before it is 60 MB again, not to track the real size).

- **AC4 — the package is self-describing**
  - Given every markdown file in the archive
  - When each relative link in them is resolved against the archive
  - Then every one names a file or directory the archive also contains.
    Links with a scheme (`http:`, `https:`, `mailto:`) and absolute paths
    are out of this criterion; a link with a fragment is resolved on the
    part before the `#`.

- **AC5 — the disclosure travels with the package**
  - Given the archive
  - When it is inspected
  - Then `AI-LOG.md` is present, and `README.md` contains the section that
    names how this software is built and points at that log. The package may
    not become a copy of the code with the provenance left behind.

- **AC6 — the package runs where Composer would put it, and nowhere else**
  *(amended 2026-09-22, see Amendments 1)*
  - Given the archive extracted into `vendor/provemark/c2pa-verifier/` of an
    otherwise empty directory, with an autoloader at `vendor/autoload.php`
    built from nothing but the `autoload.psr-4` map the archive's own
    `composer.json` declares, no development dependencies, and nothing else
    from this repository beside it
  - When `bin/c2pa-verify` there is run on a signed fixture with the test
    trust settings, both passed by absolute path from outside that directory
  - Then it exits 0 and its report has `validation_state` `Trusted`, the
    same verdict the repository's own suite gets for that file. A missing
    file, a namespace the declared map does not reach, or a `bin` entry that
    was `export-ignore`d fails here and nowhere else.

## References

- Specification: none. This spec governs the package, not the format; it is
  the second in this repository with no C2PA section behind it (SPEC-000 is
  the first).
- Oracle: `git archive --format=tar --worktree-attributes HEAD`, the same
  archive Composer fetches as `dist`. Measured 2026-09-22 (step 62): 62.9 MB
  without `.gitattributes`, 1.9 MB with it; `tests/` 63 440 kB against
  `src/` 500 kB; the archive holds 96 markdown files with 0 relative links
  pointing outside it.
- Composer's documentation on `dist` and on `.gitattributes`
  `export-ignore` is the reason the archive is the thing to measure; the
  behaviour above was measured on this repository, not taken from it.
- Reasoned: that a consumer's failure to load a class from a broken dist
  cannot be caught by a suite running against the working tree. Not measured
  by breaking the package on purpose — AC6 is the measurement, and it is the
  reason AC6 exists.

## API sketch

Tooling, like `bin/spec-check.php`: outside the Deptrac layers, inside
PHPStan level max and Pint. Nothing here is part of the library's public
API, and nothing in `src/` may depend on it.

```php
// bin/package-check.php  — illustrative, not binding

/** @param list<string> $topLevelPaths tracked top-level entries, from the caller */
function packageCheck(string $root, array $topLevelPaths): PackageCheckResult;

final readonly class PackageCheckResult
{
    /** @param list<string> $findings one sentence each, naming the path */
    public function __construct(public array $findings, public array $shipped, public array $ignored) {}

    public function exitCode(): int;      // 0 when findings is empty
    public function render(): string;
}
```

AC1 takes the top-level paths as an argument rather than discovering them,
so the check can run against a fixture tree the way `specCheck()` does —
`tests/Fixtures/package-check/` with one tree per finding. AC2 to AC6 need a
real archive and belong in a test that builds one.

## Open questions

Both blockers were decided on 2026-09-22, the day the draft was written;
the answers are recorded in place below rather than removed, so that the
reasoning that led to them stays readable.

1. **Shelling out to `git` from a test.** AC2, AC3, AC5 and AC6 need the
   archive Composer would fetch, and only `git archive` produces it;
   re-implementing it in PHP would be a second truth of exactly the kind
   this project refuses. The rule against `exec` binds the verification
   path, not the tooling — `bin/fuzz.php` and the fixture builders already
   run outside it — but this is the first time a *test* would do it. The
   proposal is to allow it in this spec's tests only, named here, with the
   test skipping itself when `git` is not on the path. **Decided by
   Maurice van Loon, 2026-09-22: yes.** A test in this spec's group may
   invoke `git`; no other test may, and nothing in `src/` ever may. The
   rule against `exec` is unchanged where it counts — the verification
   path — and this exception is written here so that a reader who finds
   `exec` in a test knows it was a decision and where it was taken.
2. **Reading the tar.** `PharData` is core and needs no dependency;
   extracting with the `tar` binary is one more `exec`. Proposal:
   `PharData`, and `ext-phar` named in `require-dev`. Non-blocker.
3. **The ceiling in AC3.** 4 MB is proposed against 1.9 MB measured. A
   tighter ceiling catches drift sooner and nags more often. Non-blocker.
4. **Whether `specs/` and `notes/` should ship at all.** Step 62 decided
   they should, so that the package carries its own record; AC4 turns that
   into a rule, and AC4 is meaningless if the answer changes. If the package
   should instead be lean — `src/`, `bin/`, `composer.json`, `LICENSE`,
   `README.md` and nothing else — then AC4 becomes "the shipped markdown has
   no relative links at all" and AC5 needs another home for the disclosure.
   **Decided by Maurice van Loon, 2026-09-22: they ship.** The package
   carries its own record — `docs/`, `specs/`, `notes/` and `AI-LOG.md`
   travel with the code, and AC4 and AC5 stand as written. The cost is
   measured and small: under 2 MB in all, against 500 kB of source.

## Amendments

1. **2026-09-22, step 63b, before a line of the checker was written** —
   AC6 said "extracted into an empty directory, with no `vendor/`". That
   cannot pass, and not because the package is broken: `bin/c2pa-verify`
   is a shim that requires an autoloader, looking first for the project's
   `vendor/autoload.php` and then for `../../../autoload.php`, which is
   where it sits once Composer has installed it under
   `vendor/provemark/c2pa-verifier/bin/`. A PHP library without an
   autoloader loads no classes; asking it to is asking for a failure that
   says nothing about the package.

   The criterion now describes the layout Composer actually creates, and
   the autoloader is built from the `autoload.psr-4` map in the **archive's
   own** `composer.json` — not from this repository's, and not from
   Composer's generated files. That is deliberate: what AC6 has to prove is
   that the declaration and the shipped files agree, so a `src/` file lost
   to `export-ignore`, or a namespace the map does not cover, still fails
   here. Reading the package's own declaration is not a second truth;
   generating a rival autoloader would be.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

All tests are in `tests/Unit/PackageTest.php`, group `SPEC-023`; all source
is `bin/package-check.php`, which is tooling and outside the Deptrac layers.

| Acceptance criterion | Test (name) | Source (symbol) |
|---|---|---|
| AC1 | `AC1: a top-level path that is neither shipped nor export-ignore is a finding`; `AC1: a clean tree has no findings, and says what ships and what does not`; `AC1: a path that is both shipped and export-ignore is a finding, neither winning silently`; `AC1: a missing .gitattributes is a finding, not an empty ignore set`; `AC1: the repository itself classifies every top-level path it tracks` | `packageCheck()`, `packageExportIgnored()`, `packageTrackedTopLevel()`, `PACKAGE_SHIPPED`, `PackageCheckResult` |
| AC2 | `AC2: the dist carries composer.json, LICENSE, README.md, src/ and every declared bin`; `AC2: a dist without src/ is a finding, and so is a bin composer.json declares but the archive lacks` | `packageDistCheck()`, `packageBinEntries()`, `packageUnder()`, `PACKAGE_REQUIRED` |
| AC3 | `AC3: the dist holds no fixture and stays under the ceiling`; `AC3: the dist as it would have shipped before .gitattributes is what this criterion exists to catch` | `packageDistCheck()`, `packageArchiveBeforeGitattributes()`, `packageGitArchive()` |
| AC4 | `AC4: every relative link in the markdown the package ships resolves inside the package`; `AC4: a scheme, an absolute path and a bare fragment are not this criterion's business` | `packageLinks()`, `packageResolves()` |
| AC5 | `AC5: the disclosure travels with the package` | `packageDistCheck()`, `PACKAGE_DISCLOSURE_SECTION` |
| AC6 | `AC6: the package, installed where Composer would put it and nothing else, verifies a file` | `packageInstall()`, `packageRun()`, `PackageTarArchive::extractTo()` |
