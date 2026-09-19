# AI log

This project is built with Claude Code (Anthropic), driven and reviewed by
Maurice van Loon. Every contribution the assistant produces is logged here,
newest at the bottom, in the same commit as the work it describes. What is
*measured* (a command was run) is kept apart from what is *reasoned* (a
conclusion from reading). Decisions are Maurice's; the assistant proposes.

The assistant is not listed as an author in commit metadata; this log and the
README are where the disclosure lives.

## 2026-09-19 — Repository created

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: start a new repository in its own folder for a pure-PHP C2PA verifier
  and set it up so a later session can continue; everything in English; keep a
  clear log of everything the assistant makes; no author attribution.
- Produced: `.gitignore` (derived from the sister project's, without the
  signing-service entries and with `*.key` ignored unconditionally — no
  private key belongs here, test key or otherwise); this `AI-LOG.md`; a local,
  gitignored project brief (landscape, design decisions, milestones, way of
  working) that the next session reads first. Two commits, no remote.
- Measured, in the session that wrote the brief: GitHub repository search
  (`c2pa language:php`), the wordpress.org plugin API, eight plugin zips
  grepped for `openssl_verify` / COSE / CBOR (none verifies a signature),
  WordPress/ai PRs #459 and #294 and issue #421 read via the GitHub API,
  WordPress core Trac ticket #65952 read in a browser, Packagist searches for
  `c2pa`, `jumbf`, `cose`, `cbor`. Reasoned: that a pure-PHP verifier is
  buildable from existing parts, and the milestone order.
- Decided by Maurice: the project exists; it is spec-driven and deliberately
  slower than the sister project, with every addition explained first; the
  repository starts private; English throughout; disclosure through this log,
  no author attribution.

## 2026-09-19 — Start-up decisions and M0.1 (package skeleton)

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: read the local brief and set the repository up, carefully and slowly.
- Produced: `composer.json` (name `provemark/c2pa-verifier`, MIT, `php ^8.3`,
  `ext-openssl`, `ext-mbstring`; `ext-sodium` under `suggest` only; no
  packages in `require`, `require-dev` still empty), `LICENSE` (MIT),
  `src/.gitkeep`, `tests/Fixtures/README.md` (what may and may never live
  there). One commit.
- Measured: `composer validate --strict` (valid); `composer install` (nothing
  to install, autoloader generated); `php -r 'require "vendor/autoload.php"'`
  (loads). Reasoned: `ext-mbstring` is required because the brief lists it,
  but no call site is known yet — to be confirmed or removed via a spec in M2.
- Decided by Maurice, one item at a time (§10 of the brief):
  1. Package `provemark/c2pa-verifier`, namespace `Provemark\C2paVerifier`,
     licence MIT, organisation `provemark`, repository private until further
     notice. (To be recorded as ADR-0002.)
  2. Dependencies: JUMBF and CBOR written in this repository; COSE_Sign1
     verification first through `web-auth/cose-lib`, added only when M3's
     spec introduces it, replaceable by own code once proven equal on the same
     vectors; ASN.1/X.509/RFC 3161 left open until M5. (ADR-0001.)
  3. README disclosure: an own heading "How this is built", naming the
     maintainer in full and linking this log; no author attribution in commit
     metadata.
  4. The verifier ships its own report object with c2patool's vocabulary; the
     sister library gets an adapter later. Dependency direction is library →
     verifier, never the reverse.
  5. SPEC-001 = JPEG APP11 → manifest store bytes, then PNG (002) and WebP
     (003); M0 first. A signed JPEG fixture will be produced when SPEC-001
     starts, with the command recorded.

## 2026-09-19 — M0.2: Pint, PHPStan, Deptrac, Pest and `composer check`

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: add the tool chain and the single definition of green, before any code.
- Produced: `require-dev` with `laravel/pint ^1.32` (1.32.1), `phpstan/phpstan
  ^2.2` (2.2.14), `deptrac/deptrac ^4.7` (4.7.2), `pestphp/pest ^5.2` (5.2.1,
  on PHPUnit 13.3.4); `config.allow-plugins` for `pestphp/pest-plugin` only;
  `pint.json` (preset laravel + `declare_strict_types`), `phpstan.neon` (level
  max, `src` and `tests`, no ignores), `deptrac.yaml` (one layer per milestone,
  parsers are leaves, the four checks never see each other, `Verifier` sees
  all), `phpunit.xml` (fails on warning/deprecation/notice/empty suite),
  `tests/Pest.php`, `tests/Unit/`, `tests/Integration/`; composer scripts
  `format`, `lint` (`pint --test`), `analyse` (`--memory-limit=512M`),
  `deptrac`, `test`, `check` = lint → analyse → deptrac → test.
- Measured: `composer require --dev` first failed — Composer blocks the Pest
  plugin until `allow-plugins` names it (fixed, measured again). `composer
  bump` wrote `>=`; replaced by `^` by hand, `composer validate --strict` ok.
  `php -m` lists `mbstring`, `openssl`, `sodium` as loaded (Composer's
  platform table had suggested mbstring came from a polyfill; it does not).
  `composer check`: Pint passed, PHPStan `[OK] No errors`, Deptrac 0
  violations / 0 uncovered, **Pest `No tests found` → exit 1**, so `check` is
  red on an empty test suite. That is the wanted fail-closed behaviour (a
  suite that runs nothing must not report green); how M0 reaches green is a
  decision for Maurice, recorded in the next entry.
- Reasoned: the Deptrac layers and arrows follow the milestone table of the
  brief; every future arrow is a spec decision.
- Decided by Maurice: M0.2 as proposed.

## 2026-09-19 — M0.3a: spec template and SPEC-000 (draft)

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: after choosing option B (a spec for the traceability tool itself so
  `composer check` can be green with a tested checker), write the template
  and SPEC-000 as a draft.
- Produced: `specs/TEMPLATE.md` (the sister project's template with three
  changes: no pointer to a file outside the repository, a new References
  section separating the specification/oracle from what is reasoned, and the
  fail-closed sentence in Behavior), `specs/SPEC-000-spec-traceability.md`
  (status `draft`, AC1–AC10, AC7 the malformed-input path),
  `tests/Unit/.gitkeep` and `tests/Integration/.gitkeep` (the two directories
  from M0.2 that Git could not record while empty).
- Measured: nothing to run yet. Reasoned: the ten criteria; AC4 as an error
  rather than the sister project's warning.
- Decided by Maurice: option B (SPEC-000) over accepting a red `composer
  check` until SPEC-001; M0.3a as proposed. Approval of SPEC-000 itself is
  pending his reading.

## 2026-09-19 — SPEC-000 approved

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: set SPEC-000 to `approved`.
- Produced: the Status and Approved rows of
  `specs/SPEC-000-spec-traceability.md`; nothing else changed.
- Measured: none. Reasoned: none.
- Decided by Maurice: SPEC-000 approved as written, including AC4 as an
  error, exact status matching, and the `superseded` question left open.

## 2026-09-19 — `docs/milestones.md`

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "where are all the M-steps?" — they were in the local brief and in
  the conversation, nowhere in the repository.
- Produced: `docs/milestones.md`: the M0–M8 table with "done when" per
  milestone, the fixed design rules, M0 broken into M0.1–M0.6 with status
  and commit per step, and what opens M1. To be updated in the same commit
  as each step it tracks.
- Measured: none. Reasoned: the split between this page (the plan) and
  `NOTES.md` (the record, from M0.5).
- Decided by Maurice: the plan goes into the repository before M0.3b.

## 2026-09-19 — M0.3b: SPEC-000 tests, seen red

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the failing tests for SPEC-000 before any implementation.
- Produced: `tests/Unit/SpecCheckTest.php` (eleven tests, all
  `->group('SPEC-000')`: one per AC1–AC9, two for AC8, plus "this repository
  itself is clean"); nine fixture trees under `tests/Fixtures/spec-check/`
  (`clean`, `unknown-group`, `no-group`, `draft-with-tests`,
  `implemented-no-test`, `implemented-empty-traceability`, `bad-status`,
  `multi-group`, `dirty`), each a minimal `specs/` + `tests/Unit/` pair;
  `bin/spec-check.php` as an empty file (a `declare` and a comment) so the
  tests fail per test instead of at `require_once`.
- Measured: `vendor/bin/pest` → `11 failed (0 assertions)`, every one `Call
  to undefined function specCheck()`. `vendor/bin/pest --list-tests | grep -c
  Fixtures` → 0: the fixture "tests" are not collected. `pint --test` passed.
  `phpstan` → 40 errors, all `Function specCheck not found` and its type
  consequences in the test file; none in the fixtures.
- Reasoned: the tests expect a result object (`findings`, `specs`, `render()`,
  `exitCode()`) rather than the `list<string>` of the spec's API sketch,
  because AC1 and AC8 also fix the output text and the counts. The sketch is
  non-binding; no amendment. AC10 (`composer check` runs the script first) is
  not a Pest test — it would call `composer check` from inside `composer
  check` — and will be measured by hand in M0.3c. The checker skipping
  `tests/Fixtures/` is read from the Scope's "`*Test.php` under `tests/`" and
  is asserted by the last test.
- Decided by Maurice: M0.3b as proposed, with those two readings.
