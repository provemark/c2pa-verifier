# Step 01 — M0: the skeleton

*2026-09-19.* Everything that has to exist before the first line of the
verifier: a package, a single definition of green, a spec cycle a script can
enforce, CI, and the documents that say why. No verification code; `src/` is
empty at the end of this step, on purpose.

## What was decided first

Five decisions were taken before anything was built, each with an
explanation and a proposal, each answered by the maintainer. They are
recorded in [ADR-0001](../docs/adr/ADR-0001-dependencies.md) (what is written
here, what comes from Packagist) and
[ADR-0002](../docs/adr/ADR-0002-name-namespace-licence.md) (name, namespace,
licence, report contract), and in the README's "How this is built" section.
The fifth — that the first feature is JPEG APP11 extraction, SPEC-001 — is in
[`docs/milestones.md`](../docs/milestones.md).

## What was built, in order

1. **`composer.json`, `LICENSE`, directories.** `provemark/c2pa-verifier`,
   MIT, `php ^8.3`, `ext-openssl`, `ext-mbstring`, no packages in `require`.
   Measured: `composer validate --strict`, `composer install` (nothing to
   install, autoloader generated).
2. **Tool chain.** Pint (preset `laravel` + `declare_strict_types`), PHPStan
   level max with no ignores, Deptrac with one layer per milestone (parsers
   are leaves, the four checks never see each other, `Verifier` sees all),
   Pest on PHPUnit 13 with `failOnEmptyTestSuite` and friends. `composer
   check` runs them in order.
   Measured: Pint passed, PHPStan `[OK]`, Deptrac 0/0 — and **Pest exited 1
   on an empty suite.** That is the wanted behaviour (a suite that runs
   nothing must not be green), and it meant M0 could not be green without
   one real test, and every test needs a spec. Hence step 3.
3. **SPEC-000 and `bin/spec-check.php`.** A spec for the script that
   enforces the spec cycle: every test names a spec, every implemented spec
   has tests and a filled Traceability table, drafts have no tests, an
   unknown status is an error. Written as a draft, approved by the
   maintainer, then eleven tests against nine minimal fixture trees under
   `tests/Fixtures/spec-check/` — **seen red first** (`11 failed`, undefined
   function), then the script, then `11 passed`. The script is the first
   step of `composer check`; that it stops the chain was measured by hand on
   a copy with SPEC-000 set back to `draft` (exit 1, Pint never ran).
4. **CI.** `composer check` on PHP 8.3 / 8.4 / 8.5, `fail-fast: false`, an
   `all-green` job, no non-blocking legs, no cache. Rehearsed locally with a
   clean install and no lockfile (same versions resolved, `check` green).
   **Not yet run**: the repository has no remote, and a remote is the
   maintainer's decision.
5. **Documents.** README with the disclosure, the two ADRs, this note.

## What went differently from the plan

- **The Pest plugin.** `composer require --dev pestphp/pest` failed the
  first time: Composer blocks Composer plugins until `allow-plugins` names
  them. Fixed by allowing exactly that one plugin. `composer bump` then
  wrote `>=` constraints, replaced by `^` by hand — `>=` would accept a
  future major.
- **`ext-mbstring` looked missing and was not.** Composer's platform table
  said `provided by symfony/polyfill-mbstring`; `php -m` lists `mbstring`
  as loaded. Composer prefers to name the polyfill. Measured, not assumed.
- **AC10 of SPEC-000 has no Pest test.** A test that runs `composer check`
  from inside `composer check` is a loop. It was measured by hand and the
  Traceability row says so and points at the log entry — the first case of
  "measured, not tested" in the repository, recorded as such rather than
  hidden behind a test that would test nothing.

## Versions this step was measured with

PHP 8.5.8 (this machine; the package promises `^8.3` and CI will be the
first measurement of that), Composer 2.10.2, Pint 1.32.1, PHPStan 2.2.14,
Deptrac 4.7.2, Pest 5.2.1 on PHPUnit 13.3.4. `c2patool` 0.27.22 is available
locally but was not used in this step; nothing here needed an oracle yet.

## What opens the next step

SPEC-001, JPEG APP11 → manifest store bytes, as a draft for approval. Before
its tests can exist, a signed JPEG fixture has to be produced with the test
certificates, and the command and tool version that produced it go into
step 02's note.
