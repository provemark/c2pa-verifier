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

## 2026-09-19 — M0.3c: `bin/spec-check.php`, SPEC-000 implemented

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the implementation that turns the eleven SPEC-000 tests green, the
  script as the first step of `composer check`, and the spec's bookkeeping.
- Produced: `bin/spec-check.php` (`SpecCheckResult`, `specCheck()` and five
  helpers; no options, no warnings; CLI part guarded so `require_once` is
  side-effect free); `bin` added to PHPStan's paths (Pint already covered
  it); composer script `spec-check` and `check` = spec-check → lint →
  analyse → deptrac → test; SPEC-000 Status `implemented`, Traceability
  filled for AC1–AC10; `docs/milestones.md` M0.3c done.
- Measured: `vendor/bin/pest` → 11 passed (29 assertions), first run after
  writing the script. `composer check` → spec-check `OK: 1 spec(s), 1 test
  file(s)`, Pint passed, PHPStan `[OK] No errors` (now including `bin/`),
  Deptrac 0 violations / 0 uncovered, Pest 11 passed; exit 0. AC10 by hand:
  a copy of the repository in the scratchpad with SPEC-000's Status edited
  back to `draft` — `composer check` there printed `SPEC-000: status draft
  but tests/Unit/SpecCheckTest.php carries its group — tests precede
  approval`, `FAIL: 1 finding(s)`, exit 1, and Pint's output line appeared 0
  times, so the chain stopped at the first step. Copy deleted afterwards.
- Reasoned: AC10 has no Pest test because a test running `composer check`
  from inside `composer check` is a loop; the Traceability row says so and
  points here.
- Decided by Maurice: M0.3c as proposed.

## 2026-09-19 — M0.4a: CI workflow, written and rehearsed, not yet run

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the CI workflow; running it waits for a remote, which waits for
  Maurice's explicit go.
- Produced: `.github/workflows/ci.yml` — `composer validate --strict`,
  `composer install` (no lockfile: newest versions inside the constraints,
  as a consumer gets), `composer check`, on PHP 8.3 / 8.4 / 8.5 with
  `fail-fast: false`; an `all-green` job that succeeds only when the matrix
  result is `success`. No `continue-on-error`, no non-blocking job, no
  vendor cache. `docs/milestones.md` M0.4 marked written, not run.
- Measured: `ruby -ryaml` parses the file. `rm -rf vendor` + `composer
  install --no-interaction --prefer-dist --no-progress` without a lockfile
  installed 75 packages and resolved to the same pint 1.32.1 / phpstan 2.2.14
  / deptrac 4.7.2 / pest 5.2.1 as earlier today; `composer check` exit 0
  afterwards. Not measured: the workflow itself, on any PHP version other
  than this machine's 8.5.8 — that is what the first run on GitHub is for.
- Reasoned: `if: always()` on `all-green` so that a cancelled or skipped
  matrix leg is read as not-success instead of leaving the job unrun.
- Decided by Maurice: M0.4a as proposed; M0.4b (repository on GitHub,
  private; push; read the first run per job) is a separate decision, not yet
  taken.

## 2026-09-19 — M0.5: README, NOTES, ADR-0001, ADR-0002

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the documents that record today's decisions for an outside reader.
- Produced: `README.md` (what it is and is not, status "M0: nothing verifies
  anything yet", design rules, how to work on it, relation to the sister
  library, the "How this is built" disclosure as decided, licence);
  `docs/adr/ADR-0001-dependencies.md` and
  `docs/adr/ADR-0002-name-namespace-licence.md` (Nygard shape: context,
  decision, alternatives rejected, consequences; "Decided: Maurice van
  Loon"); `NOTES.md` (index) and `notes/step-01-m0-skeleton.md` (the story
  of M0 including what went differently); `docs/milestones.md` M0.5 done.
- Measured: `grep -ril "claude.md" README.md NOTES.md notes docs specs` →
  nothing (no reference to a file outside the repository); `composer check`
  exit 0. Reasoned: the README makes no "only …" claim — that waits until
  the verifier exists.
- Decided by Maurice: M0.5 as proposed, in one commit.

## 2026-09-19 — M0.6: M0 closed by measurement

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the measurement that closes M0, as a step of its own.
- Produced: `docs/milestones.md` (M0.6 done, M0 done, the CI run named as
  the one open end); this entry.
- Measured: `ls -la src/` and `git ls-files src/` → only `.gitkeep`, 0
  bytes. `composer check` → spec-check `OK: 1 spec(s), 1 test file(s)`,
  Pint passed, PHPStan `[OK] No errors`, Deptrac 0 violations / 0 uncovered,
  Pest 11 passed (29 assertions), exit 0. `git log --format=%B | grep -ci
  "claude\|anthropic"` over the whole history → 0. `git status --short` →
  empty before this commit. Not measured: the CI workflow on any PHP version
  but 8.5.8, because there is no remote; M0.4b stays open until Maurice
  decides on the repository's GitHub home.
- Reasoned: nothing.
- Decided by Maurice: M0.6 as proposed. M0 is done; SPEC-001 is next.

## 2026-09-19 — Step 02: the signed JPEG fixture, measured

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: produce the signed JPEG fixture SPEC-001 needs, explain the JPEG
  structure in plain Dutch (twice — the first explanation assumed too much),
  and measure rather than recite.
- Produced: `tests/Fixtures/fixture-signed.jpg`,
  `tests/Fixtures/fixture-unsigned.jpg` (copied from the sister library),
  `tests/Fixtures/fixture-signed.manifest.json` (key paths replaced by
  placeholders), `notes/step-02-jpeg-fixture.md`, `NOTES.md` row 02,
  `docs/milestones.md` "M1, step by step".
- Measured: `c2patool 0.27.22 fixture-unsigned.jpg -m manifest.json -o
  fixture-signed.jpg` with the c2pa-rs ES256 test chain (key read from the
  sister repository's gitignored `certs/`, never copied); verdicts `Valid`
  without and `Trusted` with `c2pa-trust.settings.json`; `No claim found` on
  the unsigned file. A throw-away probe walked every JPEG segment: two APP11
  pieces at offsets 20 and 64,032, CI `JP`, En 529, Z 1 and 2, LBox 94,740,
  TBox `jumb` repeated in both; reassembled (header once + data) = 94,740
  bytes = LBox, SHA-256 `f47af93e…46a3`. Two variants: COM moved between the
  pieces → c2patool extracts and validates the signature, then
  `assertion.dataHash.mismatch`; pieces swapped → `Error: invalid embedded
  file box`. C2PA 2.4 §A.3.1 fetched from spec.c2pa.org and quoted in full.
  The IPTC term for the fixture was changed from `digitalCapture` (a false
  claim about an ffmpeg test pattern) to `algorithmicMedia`, a term the
  sister library uses (SPEC-026).
- Reasoned: the per-segment header layout is known from the measurement,
  not from ISO/IEC 18477-3 or ISO 19566-5 D.2 (paywalled, not read); that
  c2patool reads pieces in file order (from the swapped variant's error)
  and therefore SPEC-001 must not sort by Z.
- Decided by Maurice: step 02 as proposed; the simpler explanation is the
  one that counts.

## 2026-09-19 — SPEC-001 written (draft)

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: SPEC-001, JPEG APP11 → manifest store bytes, as a draft.
- Produced: `specs/SPEC-001-jpeg-app11-extraction.md` (AC1–AC12: the
  fixture's hash as the happy path, no-APP11 as an outcome, swapped pieces
  an error and a gap tolerated — both as c2patool does —, truncation,
  missing piece, differing LBox/TBox, non-`JP` APP11 skipped, limits, not a
  JPEG, two En values, default limits); `docs/milestones.md` row updated.
- Measured: `php bin/spec-check.php` → `spec SPEC-001 draft`, `OK: 2
  spec(s), 1 test file(s)`. Everything the criteria rest on was measured in
  step 02. Reasoned: the default limits (2048 pieces, 64 MiB), stopping at
  SOS, skipping non-`JP` APP11, two En values as an error — all marked as
  reasoned in the spec's References, the last two with a measurement
  promised before approval.
- Decided by Maurice: none yet; the draft is his to read.

## 2026-09-19 — SPEC-001 open questions measured before approval

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: measure the two open questions of the SPEC-001 draft before
  Maurice reads it for approval.
- Produced: SPEC-001 draft amended — AC8 and AC11 now carry their oracle,
  new AC13 (pieces after SOS → `null`), References list five measured
  variants, the open question marked resolved; addendum in
  `notes/step-02-jpeg-fixture.md`.
- Measured, c2patool 0.27.22 on three variants of the fixture built by
  moving whole segments or changing one field: piece 2's En 529 → 530 →
  `Error: invalid embedded file box`; both pieces after the scan data →
  `Error: No claim found`; an APP11 with `XX` instead of `JP` before piece
  1 → extracts, `claimSignature.validated`, `assertion.dataHash.mismatch`.
  `php bin/spec-check.php` → `OK: 2 spec(s), 1 test file(s)`.
- Reasoned: nothing new; all three matched the draft, so the amendment is
  provenance (reasoned → measured), not behaviour.
- Decided by Maurice: measure first, then read.

## 2026-09-19 — SPEC-001 approved

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: set SPEC-001 to `approved`.
- Produced: the Status and Approved rows of
  `specs/SPEC-001-jpeg-app11-extraction.md`; `docs/milestones.md` row.
- Measured: `php bin/spec-check.php` → OK. Reasoned: none.
- Decided by Maurice: SPEC-001 approved as written, thirteen criteria, the
  default limits 2048 pieces / 64 MiB.

## 2026-09-19 — Step 03a: SPEC-001 tests, seen red

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the failing tests for SPEC-001 and the fixture variants they need.
- Produced: `bin/make-jpeg-variants.php` (splits `fixture-signed.jpg` into
  named segments and recomposes nine variants; prints SHA-256 per file);
  `tests/Fixtures/jpeg/` — the nine variants and a `README.md` with what is
  wrong in each, c2patool's verdict, and the hashes;
  `tests/Unit/Container/JpegManifestStoreExtractorTest.php` (fourteen
  tests, `->group('SPEC-001')`: one per AC1–AC13, two for AC9; the error
  messages are fixed here, in the test, and the implementation must produce
  them); `docs/milestones.md` row.
- Measured: c2patool 0.27.22 on the nine committed variants: swapped,
  missing-piece-2, lbox-differs, two-instance-numbers → `Error: invalid
  embedded file box`; truncated-in-piece-2 → `Error: asset could not be
  parsed: Could not parse input JPEG`; not-a-jpeg.bin → `Error: Unsupported
  file type`; gap-between-pieces and app11-not-jp → extracts,
  `claimSignature.validated`, `assertion.dataHash.mismatch`; pieces-after-sos
  → `Error: No claim found`. `vendor/bin/pest` → `14 failed, 11 passed`,
  every failure `Class "Provemark\C2paVerifier\Container\
  JpegManifestStoreExtractor" not found`. `pint --test` passed after
  formatting the script. PHPStan: 31 errors, all in the new test file, all
  consequences of the missing classes. `php bin/spec-check.php` → `OK: 2
  spec(s), 2 test file(s)`.
- Reasoned: no stub classes in `src/` this time — `src/` stays empty until
  the implementation; the red is "class not found" per test.
- Decided by Maurice: step 03a as proposed.

## 2026-09-19 — M0.4b: the remote, and the first CI run read per job

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: create the remote now ("ja, maak de remote"), then save everything
  because the next session opens in another IDE.
- Produced: GitHub repository `provemark/c2pa-verifier`, **private**, as
  `origin`; `main` pushed (18 commits); `docs/milestones.md` M0.4 row; this
  entry. No branch protection, no tags, no Packagist, no change to the
  sister repository.
- Measured, before the push: 0 attribution lines in the whole history; 0
  tracked `*.key`; 0 tracked files with a local path; the only tracked
  mention of the local brief is its own `.gitignore` line; maintainer is
  `admin` of the org; the name was free. After: `gh repo view --json
  visibility` → `PRIVATE`. First run `35435586024` on `d7e83d0`: all four
  jobs `failure`. Read per job, not by the aggregate: **8.4 and 8.5 fail at
  PHPStan** with `Class …Container\ContainerException not found` — the
  expected red of the fourteen SPEC-001 tests written before their
  implementation; **8.3 fails earlier, at `composer install`**: `pestphp/pest
  [v5.2.0, …, v5.2.1] require php ^8.4 -> your php version (8.3.33) does not
  satisfy that requirement`, so `composer check` never ran there. Locally:
  `composer show pestphp/pest` → `php ^8.4`; `phpunit/phpunit` → `>=8.4.1`;
  the sister library pins `pestphp/pest ^4.0`, and Pest 4.7.8 requires
  `php ^8.3.0` (Packagist). The dev machine runs 8.5.8, which is why this
  never showed locally — CI's first measurement did exactly what M0.4 is
  for.
- Reasoned: three ways out — A: `pestphp/pest ^4.0` (as the sister
  library; one runner on all three versions; recommended), B: `^4.0 ||
  ^5.0` (two runners in one matrix), C: `php ^8.4` (breaks the brief's
  promise; cheap hosting is where 8.3 lingers). Not applied: awaiting the
  maintainer's choice.
- Decided by Maurice: create the remote, private, under `provemark`. The
  Pest constraint: not yet decided.

## 2026-09-19 — Pest constraint: `^4.0`, so the PHP 8.3 leg can install
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "resume session", then "A" — option A of the open Pest question.
- Produced: `composer.json` `require-dev` `pestphp/pest` `^5.2` → `^4.0`;
  `docs/milestones.md` M0.4 row; this entry. `composer.lock` is gitignored,
  so CI resolves per PHP version.
- Measured: `composer update pestphp/pest --with-all-dependencies` →
  Pest v5.2.1 → v4.7.8, PHPUnit 13.3.4 → 12.5.33; `composer show
  pestphp/pest` → requires `php ^8.3.0`. `vendor/bin/pest` → **14 failed,
  11 passed** (36 assertions) — the SPEC-001 tests still red, SPEC-000
  still green, nothing else moved. `composer check` still stops at PHPStan
  on the missing SPEC-001 classes, as intended until step 03b. CI result
  of this commit: read after the push, per job.
- Reasoned: one Pest major on all three PHP versions, and the same major
  as the sister library, is the smallest surprise; B (`^4.0 || ^5.0`) would
  run two different test runners in one matrix.
- Decided by Maurice: A.

## 2026-09-19 — Step 03: the JPEG extractor (SPEC-001 implemented)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord" on step 03b; then "explain why c2patool's `Valid` is a
  divergence in the safe direction, and is it a bug in c2patool?"; then
  "behouden voor nu" for AC7.
- Produced: `src/Container/ManifestStoreBytes.php`, `ContainerException.php`,
  `JpegManifestStoreExtractor.php` (`.gitkeep` removed); SPEC-001 →
  `implemented` with Traceability; `notes/step-03-jpeg-extractor.md`,
  `NOTES.md` row, `docs/milestones.md` M1 row; `bin/make-jpeg-variants.php`
  `$lboxOffset` 10 → 12 and a regenerated `tests/Fixtures/jpeg/lbox-differs.jpg`
  with its README row and hash corrected; two PHPStan-driven edits in the
  test file (`?->bytes ?? ''` → `->bytes ?? ''`, semantics unchanged).
- Measured: `vendor/bin/pest` before → 14 failed, 11 passed; first run
  after → 13 passed, 1 failed (AC7, `LBox 1914008084`); `xxd -s 64032`
  showed the variant's LBox written two bytes early; after the fix only
  `lbox-differs.jpg` changed (script output) and `c2patool 0.27.22` on it →
  `Valid`, `claimSignature.validated`, `assertion.dataHash.match`;
  `curl` of c2pa-rs `main` `jpeg_io.rs`, `read_c2pa`: continuation pieces
  append `raw_vec[16..]` without reading LBox/TBox, CI unused, `z > count`
  accepted. Final `composer check` → exit 0, 25 passed (58 assertions).
- Reasoned: the divergence is in the safe direction (error where the oracle
  says Valid, never the reverse); LBox < 8 and pre-SOS RST/TEM markers
  rejected without a fixture, fail closed.
- Decided by Maurice: keep AC7 as approved ("behouden voor nu"); an
  amendment, not a code change, would reverse it.

## 2026-09-19 — SPEC-001 amendment 1: AC14 and AC15
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "explain JpegManifestStoreExtractor to me, simply and at length";
  then "yes, add that fixture, and I do not understand the 0xC0 boundary,
  explain it"; then "ja akkoord" on the amendment as proposed.
- Produced: SPEC-001 Scope rule, AC14, AC15, References line, Amendments
  section, two Traceability rows; `bin/make-jpeg-variants.php` two variants;
  `tests/Fixtures/jpeg/{truncated-in-app0,rst-before-sos}.jpg` and their
  README rows and hashes; two tests; `hasLengthField()` replacing the
  `< 0xC0` check (SOI/EOI constants removed); `notes/step-03` "Amendment 1"
  section; `NOTES.md`, `docs/milestones.md`; this entry.
- Measured: the two variants built in the scratchpad and run through
  `c2patool 0.27.22` before the amendment (`Could not parse input JPEG`;
  `No claim found`) and through the then-current extractor (`offset 2`;
  `expected a marker at offset 65537, found A7`); the committed files are
  `cmp`-identical to the measured ones. `spec-check` red on the two empty
  Traceability rows (2 findings) until filled. AC15 red, then green. AC14
  green at once; mutation (probe removed) stayed green under the substring
  assertion, then red under the exact-message assertion — mutant message
  `… at offset 20: wanted 1 bytes, got 0`. Final `composer check` exit 0,
  27 passed (62 assertions).
- Reasoned: T.81 Table B.1 for which markers carry a length field (TEM,
  RST0–7, SOI, EOI do not; 02–BF reserved). Corrected in the note: the
  probe does not prevent a `null` (unreachable without SOS); it names the
  right segment.
- Decided by Maurice: add the fixture; approve amendment 1 as proposed.

## 2026-09-19 — First green CI run
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `56e6dd4` and `bbb7299` to `origin/main`; three
  `docs/milestones.md` rows that still said the CI run was pending; this
  entry.
- Measured: 0 attribution lines in the history before the push. Run
  `35444321627` on `bbb7299`: conclusion `success`; per job, `composer
  check (PHP 8.3)`, `(PHP 8.4)`, `(PHP 8.5)` each `success` with `Tests:
  27 passed` in the log; `all green` `success`. The first green run of the
  project, and the first in which the 8.3 leg installed at all.
- Decided by Maurice: push.

## 2026-09-19 — Step 04: the signed PNG fixture and its measurement
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat houdt spec2 in?", then "ja, leg stap 04 uit", then "akkoord".
- Produced: `tests/Fixtures/fixture-unsigned.png` (the sister library's
  `fixture.png`), `fixture-signed.png`, `fixture-signed-png.manifest.json`
  (placeholders for key and certificate paths); `bin/make-png-variants.php`
  and ten files under `tests/Fixtures/png/` with their README;
  `notes/step-04-png-fixture.md`; `NOTES.md` row; `docs/milestones.md` M1
  rows; this entry. No `src/` change, no spec yet.
- Measured: `c2patool 0.27.22 fixture-unsigned.png -m <manifest> -o
  fixture-signed.png` (the manifest with real paths lived in the session
  scratch directory and was deleted); `c2patool` on the signed file →
  `Valid`, with `--settings` → `Trusted`, on the unsigned → `No claim
  found`; the chunk walk with a PHP probe (all four CRCs recomputed and
  equal); `caBX` at offset 33, 46,025 bytes, LBox 46,025, store SHA-256
  `1a018eb8…57df`; the ten variants through c2patool (table in the
  README) — notably `crc-wrong.png` → `Valid` and `lbox-differs.png` →
  `Valid`, the changed bytes confirmed with `xxd`; `curl` of c2pa-rs
  `main` `png_io.rs`: the CRC is read and discarded, `caBX` counted (> 1 →
  error), position not checked, LBox not compared. `git ls-files | grep -i
  key` → nothing. `fread($f, 0)` → `ValueError` in PHP 8.5.
- Reasoned: the PNG chunk frame and the type-letter flags from ISO/IEC
  15948; the proposals for SPEC-002 at the end of the note (stricter than
  the oracle on CRC and LBox; extract regardless of position; empty or
  too-short `caBX` an error).
- Decided by Maurice: proceed with step 04 as explained.

## 2026-09-19 — SPEC-002 (draft): PNG `caBX` → manifest store bytes
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "schrijf SPEC-002 als draft".
- Produced: `specs/SPEC-002-png-cabx-extraction.md`, status `draft`, 14
  acceptance criteria, each tied to a step-04 variant and its measured
  c2patool result; `docs/milestones.md` row; this entry. No code, no tests.
- Measured: nothing new; every oracle line cites step 04. `bin/spec-check.php`
  → `spec SPEC-002 draft`, `OK: 3 spec(s), 2 test file(s)`; `composer check`
  exit 0.
- Reasoned: AC6 and AC7 stricter than c2patool (CRC, LBox vs chunk length),
  written next to the criterion; AC8/AC9 extract regardless of position, as
  c2patool, so M4 can give the precise verdict; AC11 treats an empty `caBX`
  as malformed, not absent; AC14 (truncated before `IEND`) has no fixture
  yet — listed as an open question, a blocker for `implemented` only.
- Decided by Maurice: none yet; the draft awaits his approval.

## 2026-09-20 — SPEC-002 approved; the maintainer's first test (AC1), seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Is test 1 in PngManifestStoreExtractorTest goed?" — the
  maintainer had written `tests/Unit/Container/PngManifestStoreExtractorTest.php`
  (constants, three helpers, the AC1 test) himself; then "goedgekeurd, en
  pas jij de vijf punten aan".
- Produced: SPEC-002 → `approved` (own commit `4825d4d`); five edits to the
  maintainer's file: the missing `use` for `PngManifestStoreExtractor`,
  `?ManifestStoreBytes` as the helper's return type (AC2 needs `null`),
  `/** @return resource */` on `spec002Stream()`, "byte extract" →
  "byte-exact" in the test name, Pint's blank lines; plus the header comment
  and the helper docblock in SPEC-001's style. The assertions and the
  structure are his, unchanged. `docs/milestones.md` row; this entry.
- Measured, before the edits: spec-check `SPEC-002: status draft but …
  carries its group — tests precede approval` (SPEC-000 AC4 firing as
  designed); Pint fail (two fixers); PHPStan 6 findings; Pest `Class
  "PngManifestStoreExtractor" not found` — red for the wrong reason (the
  global namespace). After: spec-check `OK: 3 spec(s), 3 test file(s)`,
  Pint passed, PHPStan 4 findings all `class.notFound` on the namespaced
  extractor, Pest `1 failed` on `Provemark\C2paVerifier\Container\
  PngManifestStoreExtractor not found` — red for the right reason.
- Reasoned: the review itself (the five points and what not to change).
- Decided by Maurice: SPEC-002 approved as drafted, all fourteen criteria
  and the three open questions as proposed; the five fixes applied by
  Claude.

## 2026-09-20 — The SPEC-002 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "kan je test 2 schrijven?", "en test 3", "Waarom de exception
  message hex code?", "laat het zo, en test 4", "laat het zo, en test 5",
  "maak alle tests" — one test at a time with an explanation, then the rest.
- Produced: AC2–AC14 in `tests/Unit/Container/PngManifestStoreExtractorTest.php`
  (15 tests in all; AC11 has two), the `ContainerException` import;
  `truncated-between-chunks.png` in `bin/make-png-variants.php` and
  `tests/Fixtures/png/` with its README row and hash; `docs/milestones.md`
  row; this entry. No `src/` change.
- Measured: the new variant is 33 bytes ending on `IHDR`'s last CRC byte
  (`xxd`), `c2patool 0.27.22` → `PNG out of range`; regenerating changed no
  other file. The two `caBX` offsets in `two-cabx.png` walked with a probe:
  33 and 46,070. `vendor/bin/pest --group=SPEC-002` → **15 failed**, every
  one on `Class "Provemark\C2paVerifier\Container\PngManifestStoreExtractor"
  not found`; `spec-check` `OK: 3 spec(s), 3 test file(s)`; PHPStan only
  `class.notFound` and its consequences.
- Reasoned: exact phrases for the messages the criteria name (`stored CRC
  83278C6A, computed 83278C6B`, `LBox 46026 differs from the chunk length
  46025`, `length 4 is shorter than the 8-byte box header`, `length 46025
  exceeds the limit of 1000`, `offsets 33 and 46070`); AC3's expected
  signature in hex because the bytes are not printable and found bytes are
  untrusted; substring matches on offsets kept as in SPEC-001, to be
  tightened once the wording exists.
- Decided by Maurice: keep AC3's hex message; keep AC4's substring match;
  Claude writes AC6–AC14.

## 2026-09-20 — Step 05: the PNG extractor (SPEC-002 implemented)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Schrijf PngManifestStoreExtractor af" — the maintainer had
  created `src/Container/PngManifestStoreExtractor.php` with the namespace
  and an empty class.
- Produced: the body of that class (`final readonly`, `maxChunkLength`,
  `extract()`, `readExactly()`, `skip()`, `tell()`, `hex()`); SPEC-002 →
  `implemented` with Traceability, the AC14 open question resolved;
  `tests/Fixtures/png/README.md` SPEC-002 column; `notes/step-05-png-extractor.md`;
  `NOTES.md` row; `docs/milestones.md` row; this entry.
- Measured: first run 14 passed, 1 failed — AC14: `unexpected end of file
  inside the chunk at offset 8` where `offset 33` was expected, because the
  probe copied from the JPEG extractor cannot tell "ends inside the chunk"
  from "ends exactly after it". After replacing the probe with an
  end-of-file look-up: `composer check` exit 0, **42 passed (93
  assertions)**, spec-check `OK: 3 spec(s), 3 test file(s)`, PHPStan `No
  errors`, Deptrac 0 violations.
- Reasoned: LBox before CRC (AC10 names the cause), limit before the LBox
  read (AC12); the JPEG probe's same imprecision left alone (no criterion,
  not this step) and flagged for the shared stream reader when WebP comes.
- Decided by Maurice: build step 05b ("Schrijf … af" after the step was
  proposed).

## 2026-09-20 — CI green on SPEC-002
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `21e2d1e`…`293cc10` (six commits) to `origin/main`; this
  entry.
- Measured: before the push, 0 attribution lines in the history and no
  tracked file matching `key`. Run `35492160497` on `293cc10`: conclusion
  `success`; `composer check (PHP 8.3)`, `(PHP 8.4)`, `(PHP 8.5)` each
  `success` with `Tests: 42 passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-20 — Step 06: the signed WebP fixture and its measurement
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat is stap 6?", then "akkoord".
- Produced: `tests/Fixtures/fixture-unsigned.webp` (the sister library's
  `fixture.webp`), `fixture-signed.webp`, `fixture-signed-webp.manifest.json`
  (placeholders); `bin/make-webp-variants.php` and fifteen files under
  `tests/Fixtures/webp/` with their README; `notes/step-06-webp-fixture.md`;
  `NOTES.md` row; `docs/milestones.md` M1 rows; this entry. No `src/`
  change, no spec yet.
- Measured: a probe signing in the scratch directory first (to confirm
  c2patool 0.27.22 signs WebP at all; deleted), then the fixture with the
  recorded command (the manifest with real paths in the scratch directory,
  deleted); `Valid` / `Trusted` / `No claim found`; the RIFF walk with a PHP
  probe: header size equal to file − 8, `VP8L` at 12, `C2PA` at 312 with
  length 100,635 (odd, pad byte present, walk ends exactly at the file
  length), LBox 100,635, store SHA-256 `5062cb0a…3999`; fifteen variants
  through c2patool (table in the README); `curl` of c2pa-rs `main`
  `riff_io.rs`: `read_c2pa` returns on the first `C2PA`, never checks the
  form type, walks within the header's size. `git ls-files | grep -i key`
  → nothing.
- Reasoned: the RIFF frame and pad rule from the RIFF/WebP container
  specifications; the proposals for SPEC-003 at the end of the note (error
  on two `C2PA`, on a non-`WEBP` form type, on LBox ≠ length; extract
  regardless of position; the RIFF-size and pad-byte questions left open).
- Decided by Maurice: proceed with step 06 as explained.

## 2026-09-20 — SPEC-003 (draft): WebP RIFF `C2PA` → manifest store bytes
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "beide fout, schrijf SPEC-003 als draft" — the RIFF-size and
  pad-byte questions from step 06 both decided as errors.
- Produced: `specs/SPEC-003-webp-riff-extraction.md`, status `draft`, 16
  criteria; one more variant, `chunk-overruns-file.webp` (`C2PA` length
  +1,000 with a correct header size — the only truncation shape AC5's
  header check does not catch), in `bin/make-webp-variants.php` and the
  README; `docs/milestones.md` row; this entry. No code, no tests.
- Measured: the new variant through `c2patool 0.27.22` → `RIFF chunk
  declared size exceeds file size`; regenerating changed no other file;
  `pad-missing.webp` header size 100,947 = file − 8 (so AC12, not AC5,
  fires on it); `spec-check` `OK: 4 spec(s), 3 test file(s)`; `composer
  check` exit 0.
- Reasoned: the header-size check first (AC5, AC16) so that every
  truncated or padded file is one error naming both numbers; AC4 (form
  type), AC7 (two chunks), AC9/AC10 (LBox) stricter than the oracle, each
  with the oracle's behaviour next to it; the shared stream reader left to
  its own step after this spec.
- Decided by Maurice: RIFF size ≠ file length → error; pad byte missing or
  non-zero → error. The draft awaits his approval.

## 2026-09-20 — SPEC-003 approved; its tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, maak alle tests".
- Produced: SPEC-003 → `approved` (own commit `2e77205`);
  `tests/Unit/Container/WebpManifestStoreExtractorTest.php`, 21 tests for
  16 criteria (AC5 has four, AC11 and AC12 two each); `docs/milestones.md`
  row; this entry. No `src/` change.
- Measured: the numbers the messages name, walked with a probe — the
  second `C2PA` of `two-c2pa.webp` at 100,956; header sizes 100,949 / 304 /
  100,948 / 100,948 against file lengths 100,956 / 100,956 / 1,320 / 312.
  `vendor/bin/pest --group=SPEC-003` → **21 failed**, every one on
  `Class "Provemark\C2paVerifier\Container\WebpManifestStoreExtractor" not
  found`; `spec-check` `OK: 4 spec(s), 4 test file(s)`; PHPStan only
  `class.notFound` and its consequences.
- Reasoned: message phrases for the criteria (`RIFF size 100949 in the
  header, 100948 bytes in the file` — the file length minus the 8-byte
  header, the number RIFF's size field promises; `chunk at offset 312
  declares 101635 bytes`; `pad byte at offset 100955 is FF, not 00`);
  the found form type shown as text when it is printable ASCII, as SPEC-003
  AC4 asks it to be named.
- Decided by Maurice: SPEC-003 approved as drafted.

## 2026-09-20 — Step 07: the WebP extractor (SPEC-003 implemented); M1 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja ga verder" on step 07b.
- Produced: `src/Container/WebpManifestStoreExtractor.php`; SPEC-003 →
  `implemented` with Traceability; `tests/Fixtures/webp/README.md` SPEC-003
  column; `notes/step-07-webp-extractor.md`; `NOTES.md` row;
  `docs/milestones.md` (SPEC-003 row, M1 marked done); this entry.
- Measured: first run 20 passed, 1 failed — AC16, `ftell` at the file end
  because the size comparison threw before the stream was put back after
  the file-end seek; repositioned first, then `composer check` exit 0,
  **63 passed (139 assertions)**, spec-check `OK: 4 spec(s), 4 test
  file(s)`, PHPStan `No errors`, Deptrac 0 violations.
- Reasoned: the size check before the walk, so the loop's end is the file's
  end and the overrun check (AC6) is the only remaining truncation shape;
  the found form type shown as text only when all four bytes are printable
  ASCII; the shared stream reader named as the next step before M2.
- Decided by Maurice: build step 07b.

## 2026-09-20 — CI green on M1
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `98e4abf`…`6b81862` (five commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35493241785` on `6b81862`: conclusion `success`;
  `composer check (PHP 8.3)`, `(PHP 8.4)`, `(PHP 8.5)` each `success` with
  `Tests: 63 passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-20 — Step 08: one stream reader (SPEC-004 implemented; SPEC-001 amendment 2)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak de gedeelde stream-reader" — answered first with the spec,
  as the rules require; then "goedgekeurd, maak alle tests".
- Produced: `specs/SPEC-004-stream-reader.md` (draft → approved →
  implemented, own commits); SPEC-001 amendment 2 (AC16, AC14's message);
  `truncated-between-segments.jpg` in `bin/make-jpeg-variants.php` and the
  README; `tests/Unit/Container/StreamReaderTest.php` (7 tests) and the
  AC16 test; `src/Container/StreamReader.php`; the three extractors moved
  onto it, their private helpers removed; Traceability in SPEC-001/002/004;
  `notes/step-08-stream-reader.md`; `NOTES.md`; `docs/milestones.md`; this
  entry.
- Measured: the new JPEG variant through `c2patool 0.27.22` → `Could not
  parse input JPEG`, and through the old extractor → `inside the segment at
  offset 2` (the imprecision); regenerating changed no other file. Red: 7 +
  2 (commit `302cfd0`). After the class alone: 6 green, AC1 red on the
  copies. After the move: `grep fread|fseek|ftell` over the three
  extractors → nothing; `composer check` exit 0, **71 passed (164
  assertions)**, spec-check `OK: 5 spec(s), 5 test file(s)`.
- Reasoned: `readUpTo()` added beyond the API sketch (three callers need a
  short read; recorded in SPEC-004 Open questions and the note); `end()`'s
  two seeks per skip left unmeasured.
- Decided by Maurice: SPEC-004 and amendment 2 approved as proposed.

## 2026-09-20 — CI green on SPEC-004
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `346ac73`, `302cfd0`, `69d5a55` to `origin/main`; this
  entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35493634964` on `69d5a55`: conclusion `success`;
  `composer check (PHP 8.3)`, `(PHP 8.4)`, `(PHP 8.5)` each `success` with
  `Tests: 71 passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-20 — Step 09: the manifest store from the inside (M2 measurement)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg de meetstap voor M2 uit", then "akkoord voor stap 09" (with
  two side questions answered in between: why ISO/IEC 19566-5 is
  paywalled — CHF 135, measured on iso.org — and what each standard in
  that table is).
- Produced: `notes/step-09-manifest-store-inside.md`; `NOTES.md` row;
  `docs/milestones.md` M2 table (step 09 done, SPEC-005/006/007 planned);
  this entry. No `src/` change, no spec, no fixture yet. Two throw-away
  probes and `spomky-labs/cbor-php` 3.4.2 lived in the session scratch
  directory only.
- Measured: the three stores extracted with our own extractors (hashes
  equal to steps 02/04/06); the JUMBF tree of each (8 `jumb`, 8 `jumd`, 4
  `cbor`, `bfdb`+`bidb`, depth 4, every walk ending on its LBox; toggles
  3 and 19; the private box is `c2sh`, 16 bytes); the CBOR of all 12
  blobs walked over the raw encoding and cross-checked with cbor-php —
  major types 0/2/3/4/5/6/7, additional info ≤ 25, tag 18 once, zero
  indefinite lengths, floats, negatives; the COSE_Sign1 with a `null`
  (detached) payload, protected header keys 1 (−7) and 33 (2 certs,
  651 + 622 bytes), 10,932 bytes of `pad`, 64-byte signature; the leaf
  certificate parsed with `openssl_x509_parse`; `c2patool --detailed` on
  the PNG compared field for field (claim: our 7 keys + derived
  `claim_version`; hash.data exclusion `start 33, length 46037` = the
  `caBX` chunk with frame); `c2pa-org/public-testfiles` listed via the
  GitHub API (2.2 image dirs empty; legacy 1.4: 26 JPEGs, legend read;
  licence CC BY-SA 4.0; commit `22beccc0`); `adobe-20220124-C.jpg` fetched
  to scratch, extracted by our SPEC-001 code (51,118 bytes), c2patool
  `Valid`, claim v1, `timeStamp.validated`; its tree and CBOR measured
  (a `json` box, x5chain and sigTst in the *unprotected* header, 3 certs,
  512-byte RSA signature, depth 7, still no indefinite/float/negative).
- Reasoned: the proposed shape of M2 (three specs) and the fixture
  proposal at the end of the note; toggle bits 2 and 3 from the text.
- Decided by Maurice: proceed with step 09. Pending: whether to add
  `adobe-20220124-C.jpg` (CC BY-SA 4.0) as a fixture.

## 2026-09-20 — First public test file added; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, voeg de fixture toe, push maar en lees de CI-run".
- Produced: `tests/Fixtures/public-testfiles/adobe-20220124-C.jpg` (from
  `c2pa-org/public-testfiles` at commit `22beccc07570`, unchanged) with a
  README carrying the CC BY-SA 4.0 attribution and the measured facts; a
  paragraph in `tests/Fixtures/README.md`; this entry.
- Measured: the committed file is `cmp`-identical to the copy measured in
  step 09; SHA-256 `75a8da33…`; `composer check` exit 0 (no test uses it
  yet — that is SPEC-007's job).
- Decided by Maurice: add the fixture; push.

## 2026-09-20 — CI green after step 09 and the public fixture
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: pushed `6af34b1` and `abf9a62` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35496464128` on `abf9a62`: conclusion `success`;
  PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 71 passed`; `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-005 (draft): JUMBF, the manifest store as a tree of boxes
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat is het volgende dat gedaan moet worden?", "leg SPEC-005 uit",
  then "akkoord, beide zoals je adviseert, schrijf SPEC-005 als draft".
- Produced: `specs/SPEC-005-jumbf-box-tree.md`, status `draft`, 17
  criteria; `docs/milestones.md` row; this entry. No code, no tests, no
  fixtures.
- Measured: C2PA 2.4 downloaded as HTML (1,065,169 bytes) into the scratch
  directory and read as text — §11.1.2–§11.1.4.4 and §8.4.2.3 quoted in
  the explanation and cited in the spec (the fetch tool could not hold the
  page). Before writing AC5: SHA-256 over each assertion superbox's
  payload (contents without the 8-byte header) of the PNG store,
  base64 → exactly the three `hash` values in the claim's
  `created_assertions` / `gathered_assertions` as `c2patool --detailed`
  prints them (`Cxd9Xp…`, `ECufvn…`, `O1ACO/…`). The Adobe store's UUIDs
  listed: the same C2PA UUIDs plus `json` (`6a736f6e…`). `spec-check` →
  `OK: 6 spec(s), 5 test file(s)`; `composer check` exit 0.
- Reasoned: `UnknownBox` for unknown type UUIDs (the spec's "shall skip"
  kept as a tree node so M4 can still hash it); errors for `brob`,
  `c2cm`, `c2um`; the default limits (16, 4,096); the hex-formatter
  placement left open (Jumbf is a leaf layer).
- Decided by Maurice: unknown boxes kept as `UnknownBox`; compressed and
  update manifests an error now, own spec later. The draft awaits the
  variant measurement and his approval.

## 2026-09-21 — Step 10: the SPEC-005 variants, measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "doe de meetstap voor de varianten".
- Produced: `bin/make-jumbf-variants.php`; 23 `.bin` stores and 23 `.png`
  carriers under `tests/Fixtures/jumbf/` with a README carrying the
  c2patool column; SPEC-005 draft updated (AC3 32-byte salt, oracle notes
  on AC7/10/12/15, the 20-byte salt as a grown box, References, the
  blocking open question resolved); `notes/step-10-jumbf-variants.md`;
  `NOTES.md`; `docs/milestones.md`; this entry.
- Measured: the script checks the extracted store's length and hash before
  editing; the grown variants and `unknown-uuid` read back with the
  step-09 probe (20- and 32-byte salts, every superbox ending on its
  LBox; `depth-17` 17 levels after correcting 18); `c2patool 0.27.22` on
  all 23 `.png` files — 14 errors, 4 `Invalid` (label `/`, label U+0001:
  `claim.multiple`; salt-20, salt-32: `hashedURI.mismatch` +
  `dataHash.mismatch`), 3 **`Valid`** (`root-label`, `root-lbox-plus-one`,
  `toggles-bit5`), 1 error on `unknown-uuid` (`could not create valid
  JUMBF for claim`). PHPStan on the script: 7 findings (`hex2bin` →
  `string|false`), fixed; Pint 3 fixers, fixed; hashes unchanged after the
  fixes; `composer check` exit 0.
- Reasoned: the `unknown-uuid` error is the claim's (SPEC-007), not the
  box's (SPEC-005 keeps it as `UnknownBox`); the three `Valid`s are
  divergences in the safe direction; c2patool's `unexpected end of file`
  messages read as "read past the fault" without opening `jumbf_io.rs`.
- Decided by Maurice: do the measurement step. SPEC-005 now awaits his
  approval.

## 2026-09-21 — SPEC-005 approved; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, push maar en lees de CI-run".
- Produced: SPEC-005 → `approved`; `docs/milestones.md` row; this entry;
  pushed `7cc1a1c`, `a8a7efb` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`; `spec-check` `OK: 6 spec(s), 5 test file(s)`. The CI
  run: see the next entry.
- Decided by Maurice: SPEC-005 approved as drafted after step 10, with the
  two non-blocking open questions left open.

## 2026-09-21 — CI green after SPEC-005's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35571033890` on `900217ef`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 71 passed`; `all green`
  `success`. The 46 new fixture files and the variants script pass PHPStan
  and Pint on all three versions.
- Decided by Maurice: push.

## 2026-09-21 — The SPEC-005 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak alle tests".
- Produced: `tests/Unit/Jumbf/JumbfParserTest.php`, 19 tests for 17
  criteria (AC3 and AC16 have two each), with helpers that take the
  stores through the M1 extractors, read the step-10 variants, count a
  tree (superboxes, description boxes, content boxes by type, unknown
  boxes, nesting depth with the root at 1) and list labels;
  `docs/milestones.md` row; this entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-005` → **19 failed**, every one
  on `Class "Provemark\C2paVerifier\Jumbf\JumbfParser" not found`;
  `spec-check` `OK: 6 spec(s), 6 test file(s)`; PHPStan findings all
  downstream of the missing classes.
- Reasoned: the message phrases the criteria will be held to (`offset
  33026: LBox 0`, `offset 32831 ends at 33036, past its parent, which
  ends at 33026`, `label 63 32 70 61 2F … contains a character that is
  not permitted` — the label as hex, never raw; `compressed manifests
  (c2cm) are not supported`; `depth 17 exceeds the limit of 16`; `box 11
  exceeds the limit of 10 boxes`); depth counted as superbox nesting with
  the root at 1, so the step-09 tree is depth 4 and the synthetic store
  depth 17; `ContentBox::$offset` is the box's offset, its data starts 8
  bytes on.
- Decided by Maurice: Claude writes all the tests.

## 2026-09-21 — Step 11: the JUMBF parser (SPEC-005 implemented); SPEC-004 amendment 1
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bouw de parser" (including the `Support` layer as
  proposed).
- Produced: `src/Support/Bytes.php` and the `Support` layer in
  `deptrac.yaml`; `StreamReader::hex()` and the WebP `printable()` moved
  there, the JPEG extractor's inline hex routed through it, the SPEC-004
  AC5 test renamed to `Bytes::hex()`, SPEC-004 amendment 1 recorded;
  `src/Jumbf/{JumbfParser,JumbfWalk,Superbox,DescriptionBox,ContentBox,
  UnknownBox,JumbfException}.php`; SPEC-005 → `implemented` with
  Traceability, its hex open question resolved;
  `notes/step-11-jumbf-parser.md`; `NOTES.md`; `docs/milestones.md`; this
  entry.
- Measured: first run of the parser 18 passed, 1 failed (AC10: the root
  bounded by the store gave the overrun message); after reading the root
  header unbounded, 19 green; PHPStan one finding (unused `$maxDepth` in
  `JumbfWalk`), removed; Deptrac 0 violations with the new layer; `composer
  check` exit 0, **90 passed (287 assertions)**.
- Reasoned: `JumbfWalk` as a small mutable helper so `JumbfParser` stays
  `readonly`; one shared store string per tree instead of a copy per node;
  unknown content-box types kept as `UnknownBox` (SPEC-005's third open
  question, "keep").
- Decided by Maurice: build step 11b with the `Support` layer.

## 2026-09-21 — CI green on SPEC-005
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `e5840b6` and `d433527` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35572440777` on `d433527`: conclusion `success`;
  PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 90 passed`; `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-006 (draft): CBOR, the measured subset
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg SPEC-006 uit", then "akkoord, beide zoals je adviseert,
  schrijf SPEC-006 als draft".
- Produced: `specs/SPEC-006-cbor-decoder.md`, status `draft`, 15
  criteria; `docs/milestones.md` row; this entry. No code, no tests, no
  fixtures.
- Measured: C2PA 2.4's text searched for its CBOR requirements — the claim
  (§10.3) and every standard assertion "shall comply with the Core
  Deterministic Encoding Requirements of CBOR (RFC 8949, clause 4.2.1)";
  RFC 8949 fetched from the RFC Editor (185,226 bytes) and Appendix A
  (the example table) and Appendix F (well-formedness errors) read; the
  vectors in AC4–AC10 are copied from them, not from memory. `spec-check`
  `OK: 7 spec(s), 6 test file(s)`; `composer check` exit 0.
- Reasoned: the subset from step 09's inventory; integers beyond PHP's int
  an error rather than a float; UTF-8 checked on text strings (RFC §3.1);
  deterministic encoding not enforced on input (a writer's obligation;
  the signature covers the bytes as they are) — left as an open question
  to measure against c2patool.
- Decided by Maurice: `CborBytes` as a separate type for byte strings; all
  tags passed through as `CborTag`. The draft awaits the measurement step
  and his approval.

## 2026-09-21 — Step 12: the CBOR values recorded, four faults measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, doe de meetstap".
- Produced: `tests/Fixtures/cbor/` — sixteen `<store>--<label>.json` files
  with the decoded values, `bin/make-cbor-vectors.php`, four `.cbor` and
  four `.png` variants, a README with the measurements; SPEC-006 draft
  updated (AC6/AC7/AC13 oracle notes and the two `.cbor` cases, the two
  open questions resolved, References); `notes/step-12-cbor-vectors.md`;
  `NOTES.md`; `docs/milestones.md`; this entry.
- Measured: the sixteen boxes located with the SPEC-005 parser and decoded
  with cbor-php 3.4.2 in the scratch directory (two API mismatches in the
  throw-away script fixed on the way: `MapObject` iterates `MapItem`s); the
  four variants inspected by bytes (`9f a2 63 75 … ff 73 67`) and through
  cbor-php (three decode, the duplicate key refused); `c2patool 0.27.22`
  on the four PNG carriers: indefinite array → `Invalid`
  `claimSignature.mismatch`; float → `Error: claim could not be converted
  from CBOR`; duplicate key → `Error: unknown algorithm`; non-shortest int
  → `Invalid` `assertion.hashedURI.mismatch`. `composer check` exit 0.
- Reasoned: c2pa-rs reads indefinite lengths, duplicate keys and
  non-shortest integers (the failures are all downstream, in the crypto);
  SPEC-006 stays stricter on the first two (the format requires it) and
  not on the third (consistent with the oracle); the float measurement
  proves only a type error and is recorded as such.
- Decided by Maurice: do the measurement step. SPEC-006 now awaits his
  approval.

## 2026-09-21 — SPEC-006 approved; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, push maar en lees de CI-run".
- Produced: SPEC-006 → `approved`; `docs/milestones.md` row; this entry;
  pushed `1059aaf`, `8d3ffbf` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`; `spec-check` `OK: 7 spec(s), 6 test file(s)`. The CI
  run: see the next entry.
- Decided by Maurice: SPEC-006 approved as drafted after step 12.

## 2026-09-21 — CI green after SPEC-006's approval; the key check sharpened
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35573418733` on `b8e44adf`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 90 passed`; `all green`
  `success`. The pre-push `git ls-files | grep -ci key` returned 2 for the
  first time — `tests/Fixtures/cbor/claim-duplicate-key.{cbor,png}`, a
  file *name*, not key material; `grep -iE "\.key$"` returns nothing.
  From now on the pre-push check is `git ls-files | grep -iE '\.key$'`
  (private keys) — `*.pem` are public certificates and allowed under
  `tests/Fixtures/README.md`.
- Reasoned: a substring check on "key" was too coarse once fixture names
  started describing faults; the file-extension check is what
  `.gitignore`'s `*.key` line protects.
- Decided by Maurice: push.

## 2026-09-21 — The SPEC-006 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak alle tests".
- Produced: `tests/Unit/Cbor/CborDecoderTest.php`, 16 tests for 15
  criteria, with helpers that cut each recorded box out of the store at
  its recorded offset (checking the recorded SHA-256), render the
  decoder's output in the recorded JSON form (`$bytes`, `$map`, `$tag`),
  and hold the RFC 8949 Appendix A/F vectors as hex; `docs/milestones.md`
  row; this entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-006` → **16 failed**, every one
  on `Class "Provemark\C2paVerifier\Cbor\CborDecoder"` (or `CborBytes`)
  `not found`; `spec-check` `OK: 7 spec(s), 7 test file(s)`; PHPStan
  findings all downstream of the missing classes. The recorded files hold
  no empty map, so the render helper's "an empty PHP array is a list" is
  safe for AC2.
- Reasoned: the message phrases the criteria will be held to (`integer at
  offset 0 does not fit a 64-bit signed integer`, `indefinite length at
  offset N is not supported`, `float at offset N is not supported`,
  `simple value 23 (undefined) at offset 0`, `additional information 28
  at offset 0 is reserved`, `break at offset 2`, `unexpected end of input
  at offset N`, `1 byte(s) remain after the value, which ended at offset
  1`, `text string at offset 0 is not valid UTF-8: C3 28`, `duplicate map
  key "a" at offset 5`, `depth 33 exceeds the limit of 32`, `array at
  offset 0 declares 25 items, above the limit of 10`, `byte string at
  offset 0 needs 4294967296 bytes, 0 available`); the AC4 rows compared
  with `toEqual` so `CborBytes`/`CborTag` compare by value.
- Decided by Maurice: Claude writes all the tests.

## 2026-09-21 — Step 13: the CBOR decoder (SPEC-006 implemented)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bouw de decoder".
- Produced: `src/Cbor/{CborDecoder,CborBytes,CborTag,CborException}.php`;
  SPEC-006 → `implemented` with Traceability (and one hex typo in AC13
  fixed); three fixes in the test file (int-coerced hex keys, a
  miscounted offset, type narrowing); `notes/step-13-cbor-decoder.md`;
  `NOTES.md`; `docs/milestones.md`; this entry.
- Measured: first run 13 passed, 3 failed (all test-file mistakes);
  second run 1 failed (`a2000000`: duplicate reported before truncation —
  the duplicate check moved after the value); PHPStan 29 + 1 findings
  resolved by narrowing and prose; `composer check` exit 0, **106 passed
  (528 assertions)**.
- Reasoned: the order "value, then duplicate check" so the RFC's
  truncation reading wins; the `"1"`/`1` key-collision refusal; integers
  beyond 2⁶³−1 detected through `unpack('J')` reading back negative.
- Decided by Maurice: build step 13b.

## 2026-09-21 — CI green on SPEC-006
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `c9eec06` and `5251e72` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35574264542` on `5251e72`: conclusion `success`; PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 106 passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-007 (draft): the claim and the manifest
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg SPEC-007 uit", then "akkoord, beide zoals je adviseert,
  schrijf SPEC-007 als draft".
- Produced: `specs/SPEC-007-claim-and-manifest.md`, status `draft`, 14
  criteria; `docs/milestones.md` row; this entry. No code, no tests, no
  new fixtures, no composer change yet.
- Measured: C2PA 2.4 §10.2.1 (the `claim-map` and `claim-map-v2` CDDL),
  §10.2.2, §10.2.3 read from the downloaded text; the sister library's
  `ManifestStoreParser` (which JSON keys it reads: `active_manifest`,
  `manifests`, `claim_generator_info`, `signature_info`, `assertions[].label/data`,
  `validation_status`) and `ManifestReport` (actions matched by the label
  prefix `c2pa.actions`); Packagist lists `provemark/content-credentials`
  v0.15.1; `c2patool 0.27.22` default JSON for the PNG and the Adobe
  file: manifest keys, `claim_generator_info` always a list, `assertions`
  without `c2pa.hash.data` and without the thumbnail (a separate
  `thumbnail` field), the v1 `c2pa.actions` label rendered as
  `c2pa.actions.v2`, `claim_generator` string and `format` for v1.
  `spec-check` `OK: 8 spec(s), 7 test file(s)`; `composer check` exit 0.
- Reasoned: `claim_version` from the box label; v1 `claim_generator_info`
  optional against the 2.4 CDDL (the only deliberate leniency, with the
  measured reason); URI resolution rules from the two measured forms;
  labels kept as stored in the JSON view (the sister parser matches the
  prefix, so accessors agree either way).
- Decided by Maurice: v1 without `claim_generator_info` is accepted; the
  sister library becomes a dev dependency for the equivalence test.

## 2026-09-21 — Step 14: c2patool's JSON recorded; the SPEC-007 variants measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja doe die stap".
- Produced: `tests/Fixtures/c2patool/{jpg,png,webp,adobe-20220124-C}.json`;
  `bin/make-claim-variants.php` (with a definite-length CBOR walker to cut
  map pairs); fifteen `.bin` and `.png` under `tests/Fixtures/claim/` with
  a README; SPEC-007 draft updated (oracle notes on AC8–AC14, References,
  the blocking open question resolved); `notes/step-14-claim-variants.md`;
  `NOTES.md`; `docs/milestones.md`; this entry.
- Measured: `c2patool 0.27.22` default JSON on the four fixtures (all
  `Valid`); every variant parsed with the SPEC-005 parser (all fifteen
  trees intact); c2patool on the fifteen carriers — 11 errors, 3
  `Invalid` (`assertion-store-label` → `claim.multiple`; `hash-as-text` →
  `hashedURI.mismatch`; `json-broken` → `assertion.json.invalid` +
  `assertion.required.missing`), 0 `Valid`. PHPStan on the script: 3
  findings fixed; hashes unchanged; `composer check` exit 0.
- Reasoned: SPEC-007 stricter than c2pa-rs on a mistyped `hash` and a
  mislabelled assertion store; broken JSON is a status code for c2patool
  and an error for this parse layer — the Verifier layer must map it.
- Decided by Maurice: do the measurement step. SPEC-007 now awaits his
  approval.

## 2026-09-21 — SPEC-007 approved; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, push maar en lees de CI-run".
- Produced: SPEC-007 → `approved`; `docs/milestones.md` row; this entry;
  pushed `9a88d9f`, `2599876` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 8 spec(s), 7 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-007 approved as drafted after step 14.

## 2026-09-21 — CI green after SPEC-007's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35575268917` on `d8819b9e`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 106 passed`; `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-21 — The SPEC-007 tests, seen red; the sister library as a dev dependency
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak alle tests".
- Produced: `composer require --dev provemark/content-credentials:^0.15`
  (v0.15.1 locked, plus `php-http/discovery` 1.20.0 and three PSR
  interface packages it pulls in; `composer.lock` is gitignored, so only
  `composer.json` changed); `tests/Unit/Manifest/ManifestStoreTest.php`,
  14 tests for 14 criteria; `docs/milestones.md` row; this entry. No
  `src/` change.
- Measured: the install (above); the sister parser reads the recorded
  c2patool JSON of the PNG — `hasManifest` true, `isAiGenerated` false,
  `digitalSourceTypes` `[…/algorithmicMedia]`, `declaredSpecVersion` null
  — so AC6's oracle side works before our side exists;
  `vendor/bin/pest --group=SPEC-007` → **14 failed**, every one on
  `Class "Provemark\C2paVerifier\Manifest\ManifestStore" not found`;
  `spec-check` `OK: 8 spec(s), 8 test file(s)`; PHPStan findings all
  downstream of the missing classes; `composer check` red on those, as
  intended.
- Reasoned: the message phrases (`claim (version 2) is missing the
  required field signature`, `… does not resolve to a box`, `… is not in
  the assertion store`, `… resolves to an unknown box (UUID ffffffff-…)`,
  `hashed URI …: hash is text, not a byte string`, `manifest …: 2 claim
  boxes, expected one`, `the store holds no manifest`, `assertion
  stds.schema-org.CreativeWork: invalid JSON`); the AC6 accessor list from
  the sister's `spec019Accessors()` restricted to what M2 knows.
- Decided by Maurice: Claude writes all the tests; the dev dependency
  (decided earlier today).

## 2026-09-21 — Step 15: the Manifest layer (SPEC-007 implemented); M2 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bouw de Manifest-laag".
- Produced: `src/Manifest/{ManifestStore,Manifest,Claim,Assertion,HashedUri,
  EmbeddedFile,ManifestException}.php`; SPEC-007 → `implemented` with
  Traceability, the require-dev open question resolved; two `assert`s in
  the test file; `notes/step-15-manifest-layer.md`; `NOTES.md`;
  `docs/milestones.md` (SPEC-007 row, M2 marked done); this entry.
- Measured: first run **14 passed**, AC6 included — our JSON and
  c2patool's through the sister library's `fromJson()` give equal
  `hasManifest`/`isAiGenerated`/`digitalSourceTypes`/`softwareAgents`/
  `declaredSpecVersion` for all four fixtures; PHPStan 3 + 1 findings
  resolved (an always-false guard removed, two test offsets narrowed, a
  narrowing branch restructured); `composer check` exit 0, **120 passed
  (632 assertions)**, Deptrac 0 violations.
- Reasoned: cross-manifest URIs refused until M7; a tag inside assertion
  data rendered as its content in the JSON view; a non-thumbnail embedded
  file rendered with base64 bytes.
- Decided by Maurice: build step 15b.

## 2026-09-21 — CI green on M2
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `a0ead45` and `11b6b6d` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35576013965` on `11b6b6d`: conclusion `success`; PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 120 passed`, each installing
  `provemark/content-credentials (v0.15.1)` from Packagist; `all green`
  `success`. The AC6 equivalence test runs on CI without Docker or a
  network call during the test itself.
- Decided by Maurice: push.

## 2026-09-21 — Step 16: the four signatures verified by hand; cose-lib measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg de meetstap voor M3 uit", then "ja akkoord".
- Produced: `bin/make-cose-variants.php`, three `.bin` + `.png` under
  `tests/Fixtures/cose/` with a README; `notes/step-16-cose-signature.md`;
  `NOTES.md`; `docs/milestones.md` (M3 table); this entry. No `src/` or
  `composer.json` change; the probe, the throw-away RSA key (deleted) and
  cose-lib 4.8.2 lived in the scratch directory only.
- Measured: C2PA 2.4 §13.2.1–13.2.6 and the x5chain clauses read; the
  four COSE_Sign1 structures decoded with our own code; the Sig_structure
  built and verified with `openssl_verify` — ES256 ×3 valid (R‖S → DER),
  PS256 (Adobe) valid because the leaf key's SPKI is `rsassaPss` and
  OpenSSL applies PSS for that key type (`openssl_pkey_get_details` type
  −1; `openssl_public_decrypt` refuses the key); one claim byte flipped →
  invalid ×4; cross-fixture → invalid; a plain-RSA PSS signature made
  with OpenSSL → `openssl_verify` invalid, manual EMSA-PSS valid, v1.5
  the reverse; cose-lib: 3 packages / 2.5 MB, ES256 = the same
  `openssl_verify` call, PS256 via brick/math + EMSA-PSS, **cannot read
  the Adobe certificate**; c2patool on the three variants →
  `claimSignature.mismatch` ×3. PHPStan/Pint on the script clean;
  `composer check` exit 0.
- Reasoned: the two-path PS256 design; accepting an unprotected x5chain
  as c2patool does; three M3 specs; the ADR-0001 amendment proposal.
- Decided by Maurice: do the measurement step. Pending: the ADR-0001
  amendment (COSE written here on `ext-openssl`).

## 2026-09-21 — ADR-0001 amendment 1; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, amendeer ADR-0001, push maar en lees de CI-run".
- Produced: `docs/adr/ADR-0001-dependencies.md` — status "accepted,
  amended 2026-09-21", the COSE line of the Decision replaced, an
  Amendments section with the original decision, the measurements of step
  16 and the amended decision; two consequences of the original closed
  (CBOR measured against cbor-php; `ext-mbstring` now has call sites);
  `docs/milestones.md` row; this entry; pushed `0ba7887` and this commit.
- Measured: `grep -rn "mb_" src` → 2 call sites; before the push, 0
  attribution lines and no tracked `*.key`. The CI run: next entry.
- Decided by Maurice: ADR-0001 amended — COSE_Sign1 verification written
  here on `ext-openssl`, `cose-lib` as reference reading only.

## 2026-09-21 — CI green after step 16 and the ADR amendment
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35577079598` on `8e3bac96`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 120 passed`; `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-008 (draft): COSE_Sign1, the structure and the headers
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg SPEC-008 uit", then "akkoord, beide zoals je adviseert,
  schrijf SPEC-008 als draft".
- Produced: `specs/SPEC-008-cose-sign1-structure.md`, status `draft`, 12
  criteria; `docs/milestones.md` row; this entry. No code, no tests, no
  new fixtures.
- Measured: C2PA 2.4 §14.5 and the `sigTst`/`pad` clauses read; the
  `Sig_structure` of all four fixtures built with the step-16 encoder and
  hashed (PNG 1,895 B `065a22da…`, JPEG 1,895 B `c80e74eb…`, WebP 1,896 B
  `f47dba5f…`, Adobe 602 B `1a33b3e7…`); the Adobe protected header is 4
  bytes `a1 01 38 24`, its `sigTst` token 5,951 bytes, its `pad` 6,457;
  `spec-check` `OK: 9 spec(s), 8 test file(s)`; `composer check` exit 0.
- Reasoned: the header lookup order and "33 wins"; the detached-payload
  rule including the empty byte string; the limits; the single-function
  encoder with shortest-form lengths as the one CBOR the verifier writes.
- Decided by Maurice: a missing `x5chain` is an error and an unprotected
  one is accepted (with the step-16 reasoning); the supported-algorithm
  list belongs to SPEC-009. The draft awaits the variant measurement and
  his approval.

## 2026-09-21 — Step 17: the SPEC-008 variants measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja doe die stap".
- Produced: `bin/make-cose-variants.php` extended (a splice helper, a
  shortest-form byte-string head, three whole-header replacements);
  eleven new `.bin` + `.png` under `tests/Fixtures/cose/`, the README
  rewritten with all fourteen rows; SPEC-008 draft updated (oracle notes
  on AC7–AC11, References, the blocking open question resolved);
  `notes/step-17-cose-variants.md`; `NOTES.md`; `docs/milestones.md`; this
  entry.
- Measured: every variant parsed as a JUMBF tree; each checked at the
  CBOR level for the fault intended (one rebuilt: `a2` → `82` gave
  trailing bytes, `a2` → `84` gives a clean list); c2patool on the eleven
  carriers — `payload-present` **`Valid`**, five structural faults `could
  not generate a trusted time stamp`, three chain faults `could not find
  signing certificate chain`, the broken leaf `COSE error parsing
  certificate`, `double-label` `claimSignature.mismatch`; PHPStan one
  finding on the script, fixed, outputs byte-identical; `composer check`
  exit 0.
- Reasoned: c2pa-rs's timestamp-labelled message is its COSE parse
  failure surfacing early; AC11 rests on §14.5, not on an observable
  oracle.
- Decided by Maurice: do the measurement step. SPEC-008 now awaits his
  approval.

## 2026-09-21 — SPEC-008 approved; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, push maar en lees de CI-run".
- Produced: SPEC-008 → `approved`; `docs/milestones.md` row; this entry;
  pushed `63439f8`, `550d490` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 9 spec(s), 8 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-008 approved as drafted after step 17.

## 2026-09-21 — CI green after SPEC-008's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run; and, in passing, "is c2pa-rs
  then not entirely correct?" — answered in conversation, summarised
  here because it bears on how the oracle is used: the divergences
  measured so far (steps 03, 05, 07, 10, 12, 14, 17) are all cases where
  c2pa-rs *reads on* past a fault the text forbids and lets the crypto or
  the hash binding catch it, or fails with a message from the wrong code
  path; none is a wrong `Valid` on a file whose bytes were tampered with.
  The oracle is trusted for verdicts, not for messages or for structural
  strictness.
- Produced: this entry.
- Measured: run `35578144778` on `7c64fc1f`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 120 passed`; `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-21 — The SPEC-008 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak alle tests".
- Produced: `tests/Unit/Cose/CoseSign1Test.php`, 12 tests for 12 criteria,
  with helpers that reach a fixture's or a variant's active manifest
  through SPEC-005/007, read a certificate's CN with `openssl_x509_parse`,
  and build synthetic COSE_Sign1 bytes for the limit case;
  `docs/milestones.md` row; this entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-008` → **12 failed**, every one
  on `Class "Provemark\C2paVerifier\Cose\CoseSign1" not found`;
  `spec-check` `OK: 9 spec(s), 9 test file(s)`.
- Reasoned: the message phrases (`expected tag 18 (COSE_Sign1_Tagged),
  found tag 19` / `found an untagged array`; `expected four items, found
  3`; `the payload must be detached (nil); an empty byte string does not
  count`; `the protected header is not a map`; `the protected header has
  no alg (label 1)`; `alg under the string label "alg" is not allowed`;
  `no x5chain in either header bucket`; `the leaf certificate is not an
  X.509 certificate`; `x5chain is empty`; `chain of 3 certificates
  exceeds the limit of 2`; `certificate of 20000 bytes exceeds the limit
  of 16384`); AC6 checks the encoder both by its bytes and by decoding
  the result with SPEC-006.
- Decided by Maurice: Claude writes all the tests.

## 2026-09-21 — Step 18: CoseSign1 (SPEC-008 implemented)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bouw CoseSign1".
- Produced: `src/Cose/{CoseSign1,CoseException}.php`; SPEC-008 →
  `implemented` with Traceability, the open questions resolved and the
  `otherHeaders` reading recorded; two test-file fixes (AC5's prefix
  length, AC11's expected keys); `notes/step-18-cose-sign1.md`;
  `NOTES.md`; `docs/milestones.md`; this entry.
- Measured: first run 8 passed, 3 failed, 1 warning — AC5 (test cut 20
  bytes for an 18-byte prefix), AC4/AC11 (`otherHeaders` definition:
  everything but labels 1 and 33), the warning from `openssl_x509_read`
  on the broken leaf (now caught through a scoped error handler and the
  OpenSSL error queue drained); PHPStan six `chr()` findings → `pack('C')`;
  `composer check` exit 0, **132 passed (730 assertions)**, Deptrac 0
  violations.
- Reasoned: the unreachable large-length branches of the encoder; the
  error-queue drain on principle.
- Decided by Maurice: build step 18b.

## 2026-09-21 — CI green on SPEC-008
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `edad933` and `47e48e5` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35579159736` on `47e48e5`: conclusion `success`; PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 132 passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-009 (draft): verifying the claim signature
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "scrijf spec009".
- Produced: `specs/SPEC-009-signature-verification.md`, status `draft`, 11
  criteria; `docs/milestones.md` row; this entry. No code, no tests, no
  fixtures.
- Measured: the COSE identifiers confirmed in cose-lib's source (ES256 −7,
  ES384 −35, ES512 −36, PS256 −37, PS384 −38, PS512 −39, EdDSA −8);
  OpenSSL's curve names `prime256v1`/`secp384r1`/`secp521r1`;
  `OPENSSL_ALGO_SHA384`/`SHA512` present; on PHP 8.5 `ext-openssl`
  signs and verifies Ed25519 with digest `0` (an in-memory throw-away
  key), and `sodium_crypto_sign_verify_detached` verifies the same
  signature with the last 32 bytes of the 44-byte SPKI; `spec-check`
  `OK: 10 spec(s), 9 test file(s)`; `composer check` exit 0.
- Reasoned: `true`/`false`/`CoseException` as the three outcomes; the
  two PSS paths by key type (step 16); EdDSA via sodium first, OpenSSL
  second, else an error; the key-fits-algorithm table from §13.2.1; the
  list of synthetic vectors the measurement step must produce.
- Decided by Maurice: none yet; the draft awaits the vector step and his
  approval.

## 2026-09-21 — Step 19: the SPEC-009 vectors, and what they found
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja doe die stap".
- Produced: `bin/make-signature-vectors.php`; fourteen JSON vectors under
  `tests/Fixtures/signatures/` with a README; SPEC-009 draft updated
  (long-form DER length in Scope and AC10, the PSS-parameter refusal and
  the three outcomes of `openssl_verify` in Scope, AC7 extended,
  References, the blocking open question resolved);
  `notes/step-19-signature-vectors.md`; `NOTES.md`; `docs/milestones.md`;
  this entry.
- Measured: OpenSSL 3.6.3; every vector self-verified by OpenSSL in the
  script; keys deleted (printed); every vector re-verified in PHP through
  the step-16 paths — `es512-p521` returned −1 until the DER `SEQUENCE`
  length was written in long form (then 1, flipped byte 0);
  `ps384-under-rsapss-sha256-key` → `openssl_verify(SHA-384)` −1 and
  SHA-256 1; `ps256-rsa2048-v15` → `openssl_verify` 1, EMSA-PSS false;
  `es256-p256k1` and `ps256-rsa1024` verify mathematically (1 / true);
  `eddsa-ed25519` → sodium true, `openssl_verify(0)` 1; PHPStan eleven
  findings on the script (a closure's docblock), fixed with a named
  function; `composer check` exit 0.
- Reasoned: the salt-length convention; PHP 8.3/8.4 Ed25519 support left
  to CI.
- Decided by Maurice: do the measurement step. SPEC-009 now awaits his
  approval.

## 2026-09-21 — SPEC-009 approved; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, push maar en lees de CI-run".
- Produced: SPEC-009 → `approved`; `docs/milestones.md` row; this entry;
  pushed `f5a8d3a`, `ab26a17` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 10 spec(s), 9 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-009 approved as drafted after step 19.

## 2026-09-21 — CI green after SPEC-009's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35580128105` on `ebb50671`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 132 passed`; `all green`
  `success`. The vector script passes PHPStan and Pint on all three.
- Decided by Maurice: push.

## 2026-09-21 — The SPEC-009 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak alle tests".
- Produced: `tests/Unit/Cose/SignatureVerifierTest.php`, 11 tests for 11
  criteria, with helpers that load a vector's JSON into a `CoseSign1`
  through SPEC-008 (`d2 84 <protected> a0 f6 <signature>`), flip a claim
  byte, and reach the fixtures and `cose/` variants through SPEC-005/007;
  `docs/milestones.md` row; this entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-009` → **11 failed**, on
  `Class "Provemark\C2paVerifier\Cose\SignatureVerifier"` (9) and
  `EcdsaSignature` (1) `not found` — the vectors themselves parse through
  `CoseSign1::fromBytes()` before the missing class is reached;
  `spec-check` `OK: 10 spec(s), 10 test file(s)`.
- Reasoned: the message phrases (`key does not fit ES256 (alg -7): EC key
  on secp256k1 … §13.2.1`, `alg -65535 is not supported`, `EdDSA cannot
  be verified: neither ext-sodium nor OpenSSL Ed25519 …`); AC8 tests the
  OpenSSL-only Ed25519 path as `true` — CI on PHP 8.3/8.4 is the
  measurement of whether that holds there; AC10 tests `EcdsaSignature::toDer`
  directly, including the P-521 long form.
- Decided by Maurice: Claude writes all the tests.

## 2026-09-21 — Step 20: SignatureVerifier (SPEC-009 implemented)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bouw SignatureVerifier" (after "kan een hacker wat met
  die c2pa-rs bug?", answered in conversation: no forgery — the signature
  covers the claim box's bytes whatever the payload field holds; the rule
  guards against ambiguity between verifiers, not against forgery).
- Produced: `src/Cose/{SignatureVerifier,EcdsaSignature,PublicKey,RsaPss,
  OpenSsl}.php`; SPEC-009 → `implemented` with Traceability, the open
  question resolved and the two added classes recorded; one test-file
  fix (a DER length miscounted in AC10); `notes/step-20-signature-verifier.md`;
  `NOTES.md`; `docs/milestones.md`; this entry.
- Measured: first run 10 passed, 1 failed (the test's arithmetic: 38, not
  37); PHPStan two findings fixed; `composer check` exit 0, **143 passed
  (802 assertions)**, Deptrac 0 violations; AC8's OpenSSL-only Ed25519
  path `true` on PHP 8.5.8 / OpenSSL 3.6.3.
- Reasoned: key classification by SPKI OID; the RSA upper bound without a
  vector; `OpenSsl::quiet()` on principle for every `openssl_*` call.
- Decided by Maurice: build step 20b.

## 2026-09-21 — CI on SPEC-009: PHP 8.3 cannot verify Ed25519 through ext-openssl
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run".
- Produced: pushed `2d32724` and `1faba25`; after the run, AC8's test
  rewritten to encode the measured boundary, SPEC-009 amendment 1 (a
  measurement, no criterion changed), `composer.json` `suggest` for
  `ext-sodium` reworded, `notes/step-20` updated; this entry; pushed
  again.
- Measured: run `35581104498` on `1faba25`: PHP 8.4 and 8.5 `success`,
  **PHP 8.3 `failure`** — `142 passed, 1 failed`: AC8's OpenSSL-only path
  threw `EdDSA cannot be verified: neither ext-sodium nor OpenSSL Ed25519
  support is available on this PHP`; the sodium path passed on 8.3. Locally
  after the change: `composer check` exit 0, 143 passed. The next run is
  in the next entry.
- Reasoned: the verifier behaved as specified (a named exception, never a
  silent `false`); the test had asserted the 8.4+ behaviour everywhere.
  The boundary is now a tested fact, not a comment.
- Decided by Maurice: push.

## 2026-09-21 — CI green on SPEC-009, all three PHP versions
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35581284766` on `8f53ee32`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 143 passed`; `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-21 — Composer download cache on CI
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ik zie nu veel installs van content_credentials op packagist,
  komt dit door ons?" — yes: every CI job installed it fresh (no cache in
  `ci.yml`), 12 runs × 3 jobs today alone; then "ja, voeg de cache toe,
  push maar en lees de CI-run".
- Produced: `.github/workflows/ci.yml` — Composer's `cache-files-dir`
  cached with `actions/cache@v4`, keyed on OS, PHP version and the hash
  of `composer.json`, with a restore-key prefix per version; the comment
  records why. This entry. Infrastructure, no spec: nothing about the
  verifier's behaviour changes.
- Measured: `grep cache .github/workflows/ci.yml` → nothing before; 12
  runs on 2026-09-21 (`gh run list`), 7 on 2026-09-20 since the
  dependency was added — about 60 Packagist downloads from this CI. The
  first run after the change is a miss (it fills the cache); the second
  should hit: next entries.
- Reasoned: the cache holds archives only; resolution still runs fresh
  because `composer.lock` is not committed.
- Decided by Maurice: add the cache.

## 2026-09-21 — CI with the cache: the filling run
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35581674251` on `c9e4cf23`: conclusion `success`, PHP
  8.3 / 8.4 / 8.5 each `143 passed`; per job `Cache not found for input
  keys: composer-Linux-php8.x-8a61b732…` then `Cache saved with key …` —
  the expected miss that fills the cache. The next run must restore it;
  that is the measurement of whether the Packagist downloads stop.
- Decided by Maurice: add the cache; push.

## 2026-09-21 — CI with the cache: the first hit
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run 35581790139 on f55bfc15 (the push of the previous log entry):
  conclusion `success`, 143 passed on each PHP; `Cache restored from
  key: composer-Linux-php8.x-8a61b732…` in all three jobs; the string
  `Downloading` appears in none of them — the sister library and every
  other package came from the restored archive cache. From this run on,
  our CI no longer counts as downloads on Packagist.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-010 (draft): the report, status codes verbatim
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg SPEC-010 uit", then "akkoord, alle drie zoals je adviseert,
  schrijf SPEC-010 als draft".
- Produced: `specs/SPEC-010-report-claim-signature.md`, status `draft`,
  10 criteria, with SPEC-007 amendment 1 and SPEC-008/009 amendment 1
  (status codes on the exceptions) defined inside it; `docs/milestones.md`
  row; this entry. No code, no tests.
- Measured: C2PA 2.4 §15.2.2's table rows for the twelve codes (meaning
  and box) and §15.6/§15.7's procedure read from the downloaded text;
  c2patool's recorded PNG JSON: `validation_status` holds failures and
  informational only, successes sit under
  `validation_results.activeManifest.success`, each `{code, url,
  explanation}`, url `self#jumbf=/c2pa/<label>/c2pa.signature`; and the
  PNG carries the failure `signingCredential.untrusted` with state
  `Valid` — kept for M5; the sister parser reads `validation_status[].code`.
  `spec-check` `OK: 11 spec(s), 10 test file(s)`; `composer check` exit 0.
- Reasoned: the exception-to-code table; `general.error` only where §15
  has no word; an empty report is `Invalid`; `checks_performed` as the
  one key c2patool lacks, so a partial report cannot pass for a verdict.
- Decided by Maurice: key-does-not-fit → `signingCredential.invalid`;
  structural COSE faults → `general.error` with the message; `Valid`
  stays internal until the Verifier layer's spec, with the checks named.

## 2026-09-21 — Step 21: c2patool's JSON for three variants (SPEC-010's oracle)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja doe die stap".
- Produced: `tests/Fixtures/c2patool/variants/{claim-title-changed,
  signature-changed,json-broken}.json` with a README; SPEC-010 draft
  updated (AC7 compares the code, not the url, with the reason; the
  blocking open question resolved; References);
  `notes/step-21-report-oracle.md`; `NOTES.md`; `docs/milestones.md`; this
  entry.
- Measured: three `c2patool 0.27.22` runs saved unchanged; the mismatch
  urls equal the absolute signature URI; the `assertion.json.invalid` url
  is the bare label `stds.schema-org.CreativeWork` while the
  `hashedURI.mismatch` on the same box has the absolute URI.
- Reasoned: c2patool's bare label read as an inconsistency; SPEC-010
  keeps the absolute form.
- Decided by Maurice: do the measurement step. SPEC-010 now awaits his
  approval.

## 2026-09-21 — SPEC-010 approved; push
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "goedgekeurd, push maar en lees de CI-run".
- Produced: SPEC-010 → `approved`; `docs/milestones.md` row; this entry;
  pushed `07afc75`, `f28a758`, `6d2bbc6` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 11 spec(s), 10 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-010 approved as drafted after step 21.

## 2026-09-21 — CI green after SPEC-010's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35583334608` on `0d3ff9ee`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 143 passed`, each with
  `Cache restored`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — The SPEC-010 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak alle tests".
- Produced: `tests/Unit/Report/ReportTest.php`, 10 tests for 10 criteria
  — the fixtures and variants through SPEC-001/2/3 → 005 → 007 → the
  check; the step-19 vectors through a `checkBytes($cose, $claim, $url)`
  entry next to `check(Manifest)` (an addition to the sketch, for inputs
  that have no manifest); the step-12 CBOR variants spliced into the PNG
  store's claim box for `claim.cbor.invalid`; the sister library's
  `validationStatusCodes()` / `validationState()` on our `toArray()`
  merged into SPEC-007's store array; `docs/milestones.md` row; this
  entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-010` → **10 failed**, every one
  on a missing class (`ClaimSignatureCheck` ×5, `StatusCode` ×2,
  `ValidationStatus` ×2, `ValidationResult` ×1); `spec-check` `OK: 11
  spec(s), 11 test file(s)`.
- Reasoned: AC9 compares the whole array except the explanation text of
  the success entry (ours, not c2patool's); AC10 pins the twelve strings
  sorted, and "an empty report is Invalid".
- Decided by Maurice: Claude writes all the tests.

## 2026-09-21 — Step 22: the Report layer (SPEC-010 implemented); M3 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bouw de Report-laag".
- Produced: `src/Report/{StatusCode,ValidationState,ValidationStatus,
  ValidationResult}.php`; `src/Cose/ClaimSignatureCheck.php`;
  `ManifestException` and `CoseException` with a `StatusCode`, set at
  every throw site in `ManifestStore`, `Manifest`, `Claim`, `CoseSign1`,
  `PublicKey`, `SignatureVerifier`; SPEC-010 → `implemented` with
  Traceability; amendment sections in SPEC-007, SPEC-008 and SPEC-009;
  four test-file fixes (three `toContain()` message arguments, one sort
  order) and type narrowing; `notes/step-22-report-layer.md`;
  `NOTES.md`; `docs/milestones.md` (M3 marked done); this entry.
- Measured: first run 6 passed, 4 failed (all test-side: Pest's
  variadic `toContain`, `mismatch` < `missing`); PHPStan 21 + 1 findings
  resolved; `composer check` exit 0, **153 passed (938 assertions)**,
  Deptrac 0 violations. AC1/AC2: our code and url equal c2patool's
  recorded JSON for the four fixtures and the two one-byte variants;
  AC9: the sister parser reads our `toArray()`.
- Reasoned: `checks_performed` in every array; an empty report is
  `Invalid`; the structural COSE faults left at `general.error`.
- Decided by Maurice: build step 22b.

## 2026-09-21 — CI green on M3
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ja, push maar en lees de CI-run" (after "wat zou de zusterrepo
  hier al mee kunnen?" — answered in conversation: a third reader that
  reads without judging, `validationState()` withheld until M4/M5; not
  before the repository is public).
- Produced: pushed `2b74ab9` and `476e326` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35584423141` on `476e326`: conclusion `success`; PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 153 passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — Step 23: the hash binding measured before M4
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg de meetstap voor M4 uit", then "ja akkoord".
- Produced: `bin/make-binding-variants.php`, `tests/Fixtures/binding/`
  (5 file-level variants, 8 store-level `.bin` + `.png`, README with
  every c2patool verdict), `tests/Fixtures/c2patool/variants/{pixel-changed,
  exclusions-overlap}.json` and its README, `notes/step-23-binding-measured.md`,
  `NOTES.md`, `docs/milestones.md` (M4 table), this entry. No `src/`
  change, no spec.
- Measured: a throw-away probe (not committed) on M2's classes — every
  hashed URI matches and the streaming SHA-256 minus the exclusions
  matches `c2pa.hash.data` for all four fixtures; the exclusion holds the
  store plus 32/12/8/12 bytes of framing. `c2patool 0.27.22` on the
  thirteen variants: file-level edits → `assertion.dataHash.mismatch`
  only; assertion edits → `assertion.hashedURI.mismatch` and the data
  hash still evaluated (`pad` outside the hash); `hash-missing` and
  `assertion-undeclared` → a hard error, no JSON; a first `start 34`
  shift matched because the swapped bytes were both `00` — variant
  corrected to `start 32`. / Reasoned: §15.4, §15.10.1.2, §15.10.3,
  §15.12.1, §13.1 read from the 2.4 text; the codes for SPEC-011/012
  listed in the note.
- Decided by Maurice: the measurement step as explained.

## 2026-09-21 — CI green on step 23
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar en lees de CI-run".
- Produced: pushed `cfc0984` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35586595620` on `cfc0984`: conclusion `success`; PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 153 passed`, Composer cache restored
  on all three (no downloads); `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-011 draft: the hashed-URI check
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Kan je verder gaan met het project nu?" — the three SPEC-011
  choices from the previous session recapped; "akkoord op alle drie".
- Produced: `specs/SPEC-011-hashed-uri-check.md` (draft, ten criteria),
  the M4 row in `docs/milestones.md`, this entry. No `src/` change.
- Measured: nothing new — the spec rests on step 23's measurements
  (`notes/step-23-binding-measured.md`, `tests/Fixtures/binding/README.md`)
  and the step-14 JSON of the four fixtures; `php bin/spec-check.php`
  → `OK: 12 spec(s), 11 test file(s)`. / Reasoned: §8.4.2.3, §13.1,
  §15.4.2, §15.10.3 as read in step 23; the eight new variants AC3–AC8
  need are listed as an open question for the tests-first step, to be
  measured through c2patool before any test is written.
- Decided by Maurice: (1) report every entry and continue after a
  mismatch; (2) `assertion.undeclared` also for `UnknownBox` children of
  the assertion store; (3) a non-empty `redacted_assertions` →
  `general.error` until M7.

## 2026-09-21 — SPEC-011 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-011-hashed-uri-check.md` status `approved`, the
  M4 row in `docs/milestones.md`, this entry.
- Measured: `php bin/spec-check.php` → OK. / Reasoned: nothing.
- Decided by Maurice: SPEC-011 approved as drafted, including SPEC-007
  amendment 2 and the Deptrac arrow `Hash` → `Jumbf`.

## 2026-09-21 — Step 24a: the SPEC-011 variants made and measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 24a" — the eight variants SPEC-011 AC3–AC8
  name, built and run through c2patool before any test.
- Produced: `bin/variant-helpers.php` (the step-23 helpers, shared),
  `bin/make-binding-variants.php` (requires them; output unchanged),
  `bin/make-hashed-uri-variants.php`, 16 files under
  `tests/Fixtures/binding/` (+ README rows), 6 JSONs under
  `tests/Fixtures/c2patool/variants/` (+ README rows),
  `notes/step-24-hashed-uri-variants.md`, `NOTES.md`,
  `docs/milestones.md`, this entry. No `src/` change, no test.
- Measured: the step-23 script re-run after the helper move — identical
  SHA-256 lines, no fixture changed. A throw-away probe on M2's classes:
  every variant parses as the spec assumes. `c2patool 0.27.22` on the
  nine PNGs (`tools/c2patool <png>` in the sister repository): see the
  note's table — `hashed-uris-two-changed` reports both mismatches,
  `uri-alg-sha384` three matches, `hashed-uri-truncated` a mismatch (a
  report); `assertion-duplicate-label`, `assertion-undeclared-unknown-uuid`
  and `claim-alg-missing` exit 1 with no report; `claim-alg-sha1` three
  `.mismatch`; `claim-redacted` `assertion.action.redacted`. Two extra
  redaction variants (not committed): redacting the thumbnail gives no
  status at all; redacting an actions box in another manifest gives
  `assertion.action.redacted`. / Reasoned: no criterion contradicted;
  three divergences kept (undeclared/duplicate/missing-alg as codes, a
  bad claim-level alg as `algorithm.unsupported`); AC8's `general.error`
  confirmed as the fail-closed choice because c2patool does not notice a
  "redacted" box that is still present.
- Decided by Maurice: step 24a as explained.

## 2026-09-21 — Step 24b: the SPEC-011 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 24b".
- Produced: `tests/Unit/Hash/HashedUriCheckTest.php` — ten tests, one per
  criterion, `->group('SPEC-011')`, own helpers (`spec011*`), the c2patool
  JSONs of steps 14/23/24 as oracle for code and url; AC10 uses a
  `checkEntry(Manifest, HashedUri)` seam, the counterpart of SPEC-010's
  `checkBytes()`; `docs/milestones.md`, this entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-011` → `10 failed (8
  assertions)`: nine `Class "Provemark\C2paVerifier\Hash\HashedUriCheck"
  not found`, AC9 `Failed asserting that two arrays are identical` (the
  enum has twelve cases, not fifteen). `composer check`: Pint passes,
  PHPStan reports only consequences of the missing class and the three
  missing enum cases; `bin/spec-check.php` → `OK: 12 spec(s), 12 test
  file(s)`. / Reasoned: SPEC-010's AC10 test asserts the enum's exact
  twelve values and will go red when SPEC-011 adds three; it is updated
  in the implementation commit, where the enum changes.
- Decided by Maurice: step 24b as explained.

## 2026-09-21 — Step 25: SPEC-011 implemented, the hashed-URI check
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met stap 25".
- Produced: `src/Hash/HashedUriCheck.php` (`check()`, `checkEntry()`,
  private `entry()`); `src/Report/StatusCode.php` (+3 cases,
  `isSuccess()` for two); `src/Manifest/Manifest.php` (`$assertionStore`
  public — SPEC-007 amendment 2, recorded there); `deptrac.yaml` (`Hash`
  → `Jumbf`); `tests/Unit/Report/ReportTest.php` AC10 (the twelve
  present, the exact fifteen left to SPEC-011 AC9 — SPEC-010 amendment
  1, recorded there); SPEC-011 → `implemented` with Traceability;
  `notes/step-25-hashed-uri-check.md`, `NOTES.md`, `docs/milestones.md`,
  this entry.
- Measured: `composer check` → Pint passed, PHPStan `[OK] No errors`,
  Deptrac `Violations 0, Allowed 147`, Pest `163 passed (1101
  assertions)` — the ten SPEC-011 tests green on the first run after
  being red in step 24b; `bin/spec-check.php` → `OK: 12 spec(s), 12 test
  file(s)`. / Reasoned: identity rather than label for "undeclared";
  `hash_equals` as habit; the digests in the explanation as the added
  value over c2patool's line.
- Decided by Maurice: step 25 as explained.

## 2026-09-21 — CI green on SPEC-011
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `d5ba2aa..ffb9a31` (five commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  `gh repo view --json visibility` → `PRIVATE`. Run `35621588330` on
  `ffb9a31`: conclusion `success`; PHP 8.3 / 8.4 / 8.5 each `success`
  with `Tests: 163 passed` (1099 assertions on 8.3 — the two Ed25519
  assertions SPEC-009 amendment 1 skips there — 1101 on 8.4 and 8.5),
  Composer cache restored on all three; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — SPEC-012 draft: the data-hash check
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "ga door met SPEC-012 als draft".
- Produced: `specs/SPEC-012-data-hash-check.md` (draft, ten criteria),
  the M4 row in `docs/milestones.md`, this entry. No `src/` change.
- Measured: nothing new — the spec rests on step 23's probe and table,
  step 02's gap variant, and the step-14/23 c2patool JSON (all four
  fixtures carry `assertion.dataHash.match` under `success`, checked with
  `jq`); `php bin/spec-check.php` → `OK: 13 spec(s), 12 test file(s)`.
  / Reasoned: §18.5, §15.10.1.2, §15.12.1, §15.4.2, §13.1 as read in
  step 23; the exact-range rule for the store's exclusion (stricter than
  c2patool's literal reading, same verdict on every measured variant);
  `ManifestStoreBytes::$ranges` as the way the Container layer tells the
  check where the store is; the twelve new variants listed as an open
  question for the tests-first step.
- Decided by Maurice: none yet — the draft awaits his reading.

## 2026-09-21 — SPEC-012 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-012-data-hash-check.md` status `approved`, the
  M4 row in `docs/milestones.md`, this entry.
- Measured: `php bin/spec-check.php` → OK. / Reasoned: nothing.
- Decided by Maurice: SPEC-012 approved as drafted — the exact-range rule
  for the store's exclusion, `ManifestStoreBytes::$ranges` as the
  SPEC-001/002/003 amendment, the first informational code, the Deptrac
  arrow `Hash` → `Container`, and the hashed-URI ordering deferred to the
  Verifier layer.

## 2026-09-21 — Step 26a: the store's range and the SPEC-012 variants measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 26a".
- Produced: `bin/make-data-hash-variants.php`, 24 files under
  `tests/Fixtures/binding/` (+ README rows), 7 JSONs under
  `tests/Fixtures/c2patool/variants/` (+ README rows), SPEC-012
  amendment 1 (AC8 and Scope item 1: the manifest's url; boxes counted,
  not labels), `notes/step-26-data-hash-variants.md`, `NOTES.md`,
  `docs/milestones.md`, this entry. No `src/` change, no test.
- Measured: a throw-away range probe on the four fixtures and the gap
  JPEG — merged piece ranges equal the assertion's exclusion exactly on
  all four, two ranges on the gap file. A parse probe on the twelve
  variants caught three script faults (exclusion not shrunk with the
  store, the sha384 hash written 16 bytes too far, the claim's LBoxes
  not shifted by a longer label) before c2patool ran. `c2patool 0.27.22`
  on the twelve PNGs: `exclusion-extra`/`exclusions-unsorted`
  `dataHash.match` + informational; `alg-missing`/`alg-sha384`
  `dataHash.match`; three shape faults and `hard-binding-missing`/`-bmff`
  exit 1 with no report; `hash-as-text` and `exclusions-too-many`
  decode and mismatch; `hard-bindings-two` `assertion.multipleHardBindings`
  with the manifest's url. `composer check` → all green, 163 passed
  (the script under Pint/PHPStan). / Reasoned: the missing-binding url
  by analogy with the measured multiple-binding url; `.malformed` kept
  for `hash-as-text` and the exclusion bound where c2patool is looser.
- Decided by Maurice: step 26a as explained. Amendment 1 made under the
  spec's first Open question ("amends the criterion before the tests"),
  for his confirmation.

## 2026-09-21 — Step 26b: the SPEC-012 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 26b".
- Produced: `tests/Unit/Hash/DataHashCheckTest.php` — ten tests, one per
  criterion, `->group('SPEC-012')`, own helpers (`spec012*`); AC1 asserts
  the four fixtures' `$ranges` and flips the WebP pad byte in a temp
  copy; AC9 writes a 48 MiB temp file in 1 MiB chunks and bounds the
  peak-memory growth; SPEC-012 amendment 2 (AC4: `validation_status`
  holds failures only); `docs/milestones.md`, this entry. No `src/`
  change.
- Measured: `vendor/bin/pest --group=SPEC-012` → `10 failed (3
  assertions)`: eight `Class "Provemark\C2paVerifier\Hash\DataHashCheck"
  not found`, AC1 `Failed asserting that null is identical to Array`
  (`$ranges` does not exist), AC10 `Failed asserting that two arrays are
  identical` (fifteen cases, not twenty-one); `bin/spec-check.php` →
  `OK: 13 spec(s), 13 test file(s)`. With `jq` on the recorded JSON:
  `validation_status` of `exclusion-extra.json` is
  `[signingCredential.untrusted, claimSignature.mismatch]` — the
  informational is under `activeManifest.informational` only, which
  contradicts SPEC-010's description of the shape. / Reasoned: the
  shape must be c2patool's because the sister library's
  `validationCodes()` reads `validation_status`; `toArray()` changes in
  the implementation commit as SPEC-010 amendment 2.
- Decided by Maurice: step 26b as explained. Amendment 2 for his
  confirmation.

## 2026-09-21 — Step 27: SPEC-012 implemented, the data-hash check; M4 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met stap 27".
- Produced: `src/Hash/DataHashCheck.php`; `src/Container/ManifestStoreBytes.php`
  (`$ranges`, merged in the constructor) and the three extractors
  (SPEC-001/002/003 amendment, recorded in each); `src/Report/StatusCode.php`
  (+6, `isInformational()` real); `src/Report/ValidationResult.php`
  (`validation_status` failures only; `Valid` needs a success — SPEC-010
  amendment 2, recorded there, Scope text adjusted); `deptrac.yaml`
  (`Hash` → `Container`); `tests/Unit/Report/ReportTest.php` AC10 and
  `tests/Unit/Hash/HashedUriCheckTest.php` AC9 widened (SPEC-011
  amendment 1); `tests/Unit/Hash/DataHashCheckTest.php` corrected (three
  variadic `toContain()` calls; AC1's pad-byte clause — SPEC-012
  amendment 3); SPEC-012 → `implemented` with Traceability;
  `notes/step-27-data-hash-check.md`, `NOTES.md`, `docs/milestones.md`
  (M4 done), this entry.
- Measured: first run `4 failed, 6 passed` — three test faults (Pest's
  variadic `toContain()`, again), one AC1 clause SPEC-003 makes
  impossible (`pad byte at offset 100955 is 01, not 00`), and one code
  fault (informational alone came out `Valid`). After: `composer check`
  → Pint passed, PHPStan `[OK] No errors`, Deptrac `Violations 0`, Pest
  `173 passed (1342 assertions)`; AC9's 48 MiB file under 4 MiB peak
  growth in 0.13 s; `bin/spec-check.php` → `OK: 13 spec(s), 13 test
  file(s)`. / Reasoned: `Valid` = a success and no failure; the
  exact-range rule's messages name the store's range and the nearest
  exclusion; the digests in a mismatch's explanation.
- Decided by Maurice: step 27 as explained. Amendment 3 and SPEC-010
  amendment 2's second half (`Valid` needs a success) for his
  confirmation.

## 2026-09-21 — CI green on M4
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" (and, in the same breath, "en dan b" — the Verifier
  layer next; its explanation was given, SPEC-013 awaits "akkoord").
- Produced: pushed `4c9a7c9..8397646` (six commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  `gh repo view --json visibility` → `PRIVATE`. Run `35624439126` on
  `8397646`: conclusion `success`; PHP 8.3 / 8.4 / 8.5 each `success`
  with `Tests: 173 passed` (1340 assertions on 8.3, 1342 on 8.4/8.5 —
  the Ed25519 pair, as before); `all green` `success`.
- Decided by Maurice: push; the Verifier layer before M5.

## 2026-09-21 — SPEC-013 draft: the Verifier
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "en dan b" (the Verifier layer before M5), then "akkoord,
  schrijf SPEC-013 als draft".
- Produced: `specs/SPEC-013-verifier.md` (draft, ten criteria), the row
  in `docs/milestones.md`, this entry. No `src/` change.
- Measured: `c2patool 0.27.22` on `fixture-unsigned.{jpg,png,webp}` →
  `Error: No claim found`; on `jpeg/not-a-jpeg.bin` → `Error:
  Unsupported file type`; on `binding/pad-nonzero.png` →
  `validation_status` `[signingCredential.untrusted,
  assertion.hashedURI.mismatch]` (no data-hash failure). With `jq` on
  the recorded JSON: after `claimSignature.mismatch` c2patool still
  reports `assertion.hashedURI.mismatch` and `assertion.dataHash.mismatch`
  (`hashed-uri-truncated.json`) — it does not stop at a broken signature;
  the sister `ManifestReport`'s accessor names read from its source.
  `php bin/spec-check.php` → `OK: 14 spec(s), 13 test file(s)`. /
  Reasoned: the order from §15.3; continuing after a signature failure
  (the explanation earlier this session said "stop" — the measurement
  says the oracle continues, and a failure on top of a failure changes
  no verdict, so the draft continues); the skip after a hashed-URI
  mismatch as decided in SPEC-011; "no manifest" as `Invalid` without
  statuses, an unknown format as `general.error`; AC10's normalisation
  table from the divergences SPEC-011/012 recorded.
- Decided by Maurice: the Verifier layer before M5; the draft awaits his
  reading.

## 2026-09-21 — SPEC-013 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-013-verifier.md` status `approved`, the row in
  `docs/milestones.md`, this entry.
- Measured: `php bin/spec-check.php` → OK. / Reasoned: nothing.
- Decided by Maurice: SPEC-013 approved as drafted — continuing after a
  broken signature (the oracle's behaviour), the data-hash skip after a
  hashed-URI mismatch, "no manifest" as `Invalid` without statuses,
  SPEC-007 amendment 3, AC10's normalisation table.

## 2026-09-21 — Step 28: the SPEC-013 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met stap 28" (after a side question on why
  the project verifies but never signs — answered in conversation, no
  file changed).
- Produced: `tests/Unit/Verifier/VerifierTest.php` — ten tests, one per
  criterion, `->group('SPEC-013')`; the corpus of 22 recorded c2patool
  JSONs mapped to their carriers; AC10's normalisation as the spec
  states it, printing file / ours / theirs on failure; SPEC-013
  amendment 1 (AC10's subset list); `docs/milestones.md`, this entry.
  No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-013` → `10 failed (0
  assertions)`, all `Class "Provemark\C2paVerifier\Verifier\Verifier"
  not found`; Pint passes; PHPStan reports only the unknown classes;
  `bin/spec-check.php` → `OK: 14 spec(s), 14 test file(s)`. With `jq`
  while writing AC10: on `hashed-uri-changed` and
  `hashed-uris-two-changed` c2patool's `assertion.dataHash.match` is a
  success, not a failure, so their failure sets equal ours although the
  data hash is skipped — the spec's list said otherwise; corrected as
  amendment 1. / Reasoned: the mapping of `algorithm.unsupported` to
  the mismatch of its box by url and `checksPerformed`.
- Decided by Maurice: step 28 as explained. Amendment 1 for his
  confirmation.

## 2026-09-21 — Step 29: SPEC-013 implemented, the Verifier
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met stap 29" (after "maar is c2patool nu fout
  of c2pa-rs?" — answered from c2pa-rs's source, sparse-cloned into the
  scratchpad, not committed; the findings are in the step note).
- Produced: `src/Container/FormatDetector.php`, `src/Verifier/Verifier.php`,
  `src/Verifier/VerificationReport.php`; `src/Manifest/ManifestException.php`
  (`$url`, `at()`) and `src/Manifest/Manifest.php` (`at()` wrapping,
  `theOne()` url — SPEC-007 amendment 3, recorded there); `deptrac.yaml`
  (`Verifier` → `Support`, SPEC-013 amendment 2); SPEC-013 →
  `implemented` with Traceability; `notes/step-29-verifier.md`,
  `NOTES.md`, `docs/milestones.md`, this entry.
- Measured: after SPEC-007 amendment 3 alone, SPEC-007 and SPEC-010
  still `14 passed` / `10 passed`. First run of the Verifier:
  `10 passed (300 assertions)`; `composer check` → Pint passed, PHPStan
  `[OK] No errors`, Deptrac `Violations 1` (the missing `Support`
  arrow) then `0`, Pest `183 passed (1642 assertions)`;
  `bin/spec-check.php` → `OK: 14 spec(s), 14 test file(s)`. In c2pa-rs
  `main` (`58eac79`, 2026-09-21): `sdk/src/store.rs:2117–2139`
  (`verify_claim` then `verify_hash_binding`), `sdk/src/claim.rs:3684–3696`
  (`.failure(log, err)?`) and `sdk/src/status_tracker/mod.rs:95–96`
  (`ContinueWhenPossible => Ok`) — the data hash after a hashed-URI
  mismatch is by design; `sdk/src/claim.rs:3725–3745` — `ASSERTION_UNDECLARED`
  logged, then an unconditional `return Err(AssertionMissing)`. /
  Reasoned: the second is a defect-shaped inconsistency; whether to
  raise it upstream is Maurice's call.
- Decided by Maurice: step 29 as explained. Amendment 2 for his
  confirmation.

## 2026-09-21 — CI green on SPEC-013
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `465743a..abadd04` (six commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  `gh repo view --json visibility` → `PRIVATE`. Run `35634988650` on
  `abadd04`: conclusion `success`; PHP 8.3 / 8.4 / 8.5 each `success`
  with `Tests: 183 passed` (1640 assertions on 8.3, 1642 on 8.4/8.5);
  `all green` `success`.
- Decided by Maurice: push; then the M5 measurement step.

## 2026-09-21 — Step 30: trust measured before M5
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de meetstap voor M5".
- Produced: `tests/Fixtures/trust/` (4 public PEM/cfg files from the
  sister repository, 8 settings variants, README),
  `tests/Fixtures/c2patool/trusted/` (9 JSONs, README),
  `notes/step-30-trust-measured.md`, `NOTES.md`, `docs/milestones.md`
  (M5 table), this entry. No `src/` change, no spec. No private key
  copied (`git ls-files | grep '\.key$'` → 0; `grep -l 'PRIVATE KEY'
  tests/Fixtures/trust/*` → 0).
- Measured: `openssl x509` on all 7 certificates (two hierarchies, EC
  and RSA-PSS, identical names); the four fixtures' x5chains through
  `CoseSign1` + `openssl_x509_parse` (EC fixtures: leaf + intermediate,
  no root; Adobe: leaf + intermediate + RSA-PSS root); `c2patool
  0.27.22 --settings` under nine variants on the PNG and Adobe fixtures
  (table in the note: `Trusted` with anchors or allowed list;
  `anchors-wrong-eku` still `Trusted`; `verify-off` no credential code;
  `validation_status` key absent when there are no failures);
  c2pa-rs `main` `58eac79`: `certificate_trust_policy.rs:186–232`
  (allowed list first, by SHA-256), `certificate_trust/openssl.rs:22–80`
  (X509_STRICT | PARTIAL_CHAIN, time = timestamp else now),
  `certificate_profile.rs` (the §14.5 checks), `valid_eku_oids.cfg` and
  `has_allowed_eku()` (EKUs additive); PHP 8.4 / OpenSSL 3.6.3:
  `openssl_x509_verify` per link 1/1/−1 as expected,
  `openssl_x509_checkpurpose` true only with the intermediate file and
  the right root. / Reasoned: the ADR direction (own chain walk on
  `openssl_x509_verify`, no phpseclib for M5, no temp files); the EKU
  question left to Maurice.
- Decided by Maurice: the measurement step as explained. Open for him:
  the EKU list — mirror c2pa-rs (additive) or `trust_config` as the
  list when present.

## 2026-09-21 — ADR-0003 draft: X.509 on ext-openssl
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "optie a, en schrijf ADR-0003 als draft".
- Produced: `docs/adr/ADR-0003-x509-on-ext-openssl.md` (draft), the row
  in `docs/milestones.md`, this entry. No `src/` change.
- Measured: nothing new — the ADR rests on step 30. / Reasoned: the
  decision (own chain walk on `openssl_x509_verify`, allowed list first,
  the §14.5 profile from `openssl_x509_parse`, `checkpurpose` only as a
  second oracle in tests, `phpseclib` not for M5, RFC 3161 open until
  M6); the two-spec split (SPEC-014 chain and settings, SPEC-015
  profile); the consequences for `ValidationState`, `signature_info`
  and `validation_status`.
- Decided by Maurice: the EKU list as c2pa-rs keeps it (option a).

## 2026-09-21 — ADR-0003 accepted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op accepted".
- Produced: `docs/adr/ADR-0003-x509-on-ext-openssl.md` status `accepted`,
  the row in `docs/milestones.md`, this entry.
- Measured: nothing. / Reasoned: nothing.
- Decided by Maurice: ADR-0003 as drafted — X.509 written here on
  `ext-openssl`, allowed list first, the EKU list as c2pa-rs keeps it,
  `checkpurpose` as a second oracle only, RFC 3161 open until M6.

## 2026-09-21 — SPEC-014 draft: trust settings, allowed list, chain walk
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-014 als draft".
- Produced: `specs/SPEC-014-trust-settings-and-chain.md` (draft, ten
  criteria), the row in `docs/milestones.md`, this entry. No `src/`
  change.
- Measured: the PNG fixture's COSE headers through `CoseSign1` —
  protected labels `[1, 33]`, unprotected `["pad"]`: `x5chain` is
  protected, so AC4's leaf-only variant breaks the signature as well,
  and the criterion says so. Everything else rests on step 30. `php
  bin/spec-check.php` → `OK: 15 spec(s), 14 test file(s)`. / Reasoned:
  the walk's termination rule (DER-equal to an anchor or signed by one)
  as the reading of c2pa-rs's `PARTIAL_CHAIN`; the three-state rule
  from the measured JSON (`untrusted` alone keeps `Valid`); settings
  whole or absent; the second oracle in the tests.
- Decided by Maurice: none yet — the draft awaits his reading.

## 2026-09-21 — SPEC-014 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-014-trust-settings-and-chain.md` status
  `approved`, the row in `docs/milestones.md`, this entry.
- Measured: `php bin/spec-check.php` → OK. / Reasoned: nothing.
- Decided by Maurice: SPEC-014 approved as drafted — settings whole or
  absent, allowed list first, the walk ending at a certificate equal to
  or signed by an anchor, the three-state rule, `validation_status`
  omitted when empty, `Verifier::verify($stream, ?TrustSettings)`.

## 2026-09-21 — Step 31a: the SPEC-014 variants made and measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 31a".
- Produced: `bin/make-trust-variants.php`, `tests/Fixtures/binding/x5chain-leaf-only.{bin,png}`
  (+ README rows), `tests/Fixtures/trust/{intermediate-anchor,allowed-plus-wrong-root}.settings.json`
  (+ README), four JSONs under `tests/Fixtures/c2patool/trusted/` (+
  README rows), `notes/step-31-trust-variants.md`, `NOTES.md`,
  `docs/milestones.md`, this entry. No `src/` change, no test.
- Measured: the COSE_Sign1 layout in the PNG store (protected 1,288
  bytes with two certificates 654 + 625, pad 10,932); the variant keeps
  the store at 46,025 bytes (asserted by the script); a parse probe:
  one certificate, pad 11,557, `claimSignature.mismatch`, three
  `hashedURI.match`. `c2patool 0.27.22`: leaf-only + full settings →
  `Invalid`, `signingCredential.untrusted` + `claimSignature.mismatch`
  only; PNG + intermediate-anchor → `Trusted` ("System trust anchors");
  PNG + allowed-plus-wrong-root → `Trusted` ("EndEntity trust
  anchors"); pixel-changed + full → `Invalid` with `trusted` success
  and `dataHash.mismatch` failure. `composer check` → all green, 183
  passed (the script under Pint/PHPStan). / Reasoned: growing the pad
  instead of shrinking the store, so that one thing changes.
- Decided by Maurice: step 31a as explained.

## 2026-09-21 — Step 31b: the SPEC-014 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 31b".
- Produced: `tests/Unit/Trust/ChainCheckTest.php` — ten tests, one per
  criterion, `->group('SPEC-014')`, own helpers (`spec014*`); AC7 makes
  a throw-away EC key in the test to prove the message does not echo
  key material; AC8 writes the anchors and intermediates to temporary
  files for `openssl_x509_checkpurpose`; `SPEC013_CORPUS` moved from
  `VerifierTest.php` to `tests/Pest.php` (shared by SPEC-013 and
  SPEC-014 AC10); `docs/milestones.md`, this entry. No `src/` change.
- Measured: `vendor/bin/pest --group=SPEC-014` → `10 failed (1
  assertion)`: eight `Class "Provemark\C2paVerifier\Trust\TrustSettings"
  not found`, AC9 an undefined enum case, AC10 `actual size 21 matches
  expected size 23`; SPEC-013 still `10 passed` after the move; Pint
  passes; `bin/spec-check.php` → `OK: 15 spec(s), 15 test file(s)`. /
  Reasoned: nothing new.
- Decided by Maurice: step 31b as explained.

## 2026-09-21 — Step 32: SPEC-014 implemented, the chain check; the first Trusted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met stap 32".
- Produced: `src/Trust/{TrustSettings,Certificate,TrustException,ChainCheck}.php`;
  `src/Report/StatusCode.php` (+2), `ValidationState.php` (`Trusted`),
  `ValidationResult.php` (the three-state rule; `validation_status`
  omitted when empty); `src/Verifier/Verifier.php` (`verify($stream,
  ?TrustSettings)`, `trust` in `checks_performed`),
  `VerificationReport.php` (the key omitted); `deptrac.yaml` (`Trust` →
  `Cose`, `Support`); six older tests adjusted for the two intended
  changes (SPEC-010 amendment 3, SPEC-011 amendment 2, SPEC-012
  amendment 4, SPEC-013 amendment 3, recorded in each); SPEC-014 →
  `implemented` with Traceability; `notes/step-32-chain-check.md`,
  `NOTES.md`, `docs/milestones.md`, this entry.
- Measured: first run `8 passed, 1 failed, 1 warning` — the failure a
  depth wording (now: links walked to the anchor), the warning OpenSSL's
  on a truncated certificate through an `@` PHPUnit ignores (now a
  scoped error handler); then `6 failed` older tests on the enum's new
  success and the omitted key, adjusted. After: `composer check` → Pint
  passed, PHPStan `[OK] No errors`, Deptrac `Violations 0`, Pest
  `193 passed (1829 assertions)`; `bin/spec-check.php` → `OK: 15
  spec(s), 15 test file(s)`. AC8: `openssl_x509_checkpurpose` agrees
  with `ChainCheck` on all four fixtures under both anchor sets. AC10:
  the 22-file corpus with the full settings — four `Trusted`, the rest
  unchanged. / Reasoned: "depth" as links walked; the private-key block
  refused by its BEGIN line so the message never sees the body.
- Decided by Maurice: step 32 as explained.

## 2026-09-21 — CI green on SPEC-014; re-signing with throw-away keys allowed for tooling
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar"; then, put as an explicit question, whether a
  tooling script may re-sign the PNG fixture with throw-away keys to
  make SPEC-015's certificate-profile variants.
- Produced: pushed `2ddfe18..9ebac39` (ten commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` in `tests/Fixtures/trust/`, visibility `PRIVATE`.
  Run `35640449671` on `9ebac39`: conclusion `success`; PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 193 passed` (1827 assertions on 8.3,
  1829 on 8.4/8.5); `all green` `success`.
- Decided by Maurice: push. And: a tooling script under `bin/` may
  re-sign the fixture with keys that exist only during the run and are
  never written — public certificates, the re-signed variants and
  c2patool's verdicts (with the throw-away root as anchor) are what is
  committed; the product never signs, no key enters the repository.
  (He first answered too quickly, asked for the question to be put
  again, and confirmed the same answer.)

## 2026-09-21 — Step 33: the certificate profile measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met stap 33" (after confirming, on a second,
  explicit question, that tooling may re-sign with throw-away keys).
- Produced: `bin/make-profile-variants.php`, `tests/Fixtures/profile/`
  (12 × `.bin`/`.png`/`.leaf.pem`, `throw-away-root.pem`,
  `throw-away-root.settings.json`, README), `tests/Fixtures/c2patool/profile/`
  (12 JSONs, README), `notes/step-33-profile-measured.md`, `NOTES.md`,
  `docs/milestones.md`, this entry. No `src/` change, no spec. No
  private key written under the repository (`grep -l PRIVATE
  tests/Fixtures/profile/*` → 0; the run's key directory deleted, `ls`
  → 0).
- Measured: the verifier's own `SignatureVerifier` on the twelve
  re-signed stores — ten `verifies`, `rsa-1024` and `curve-secp256k1`
  refused at the key check (SPEC-009); `c2patool 0.27.22` with the
  throw-away root as anchor: `good`, `eku-c2pa`, `no-digital-signature`
  → `Trusted`; `expired` → `signingCredential.expired`; the eight
  others → `signingCredential.invalid`; `signingCredential.trusted`
  logged as a success on all twelve. c2pa-rs `certificate_profile.rs`
  lines 380–520: KU good on digitalSignature or nonRepudiation or
  keyCertSign; AKI required on the leaf; unknown critical extensions
  refused; no EKU accepted only on a CA. `composer check` → all green,
  193 passed. / Reasoned: the pad-sizing so that only the signature
  box changes; the KU question left to Maurice.
- Decided by Maurice: tooling may sign with throw-away keys (recorded
  in the script's header and the README). Open for him: KU as c2pa-rs
  (nonRepudiation alone accepted) or `digitalSignature` required.

## 2026-09-21 — SPEC-015 draft: the certificate profile
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "optie a, en schrijf SPEC-015 als draft".
- Produced: `specs/SPEC-015-certificate-profile.md` (draft, ten
  criteria), the row in `docs/milestones.md`, this entry; the `v1`
  variant corrected in `tests/Fixtures/profile/README.md` and
  `notes/step-33-profile-measured.md`. No `src/` change.
- Measured: `openssl_x509_parse()` / `openssl_pkey_get_details()` on all
  twelve leaves and the EC test leaf — OpenSSL 3.6's names (`E-mail
  Protection`, `Any Extended Key Usage`, a dotted OID for the C2PA
  EKU, `Digital Signature, Non Repudiation`, `CA:TRUE`, `version` 2 for
  v3, `serialNumberHex`); the intended `v1` variant is v3 with SKI/AKI
  (OpenSSL adds them) and no KU/EKU. `php bin/spec-check.php` → `OK: 16
  spec(s), 15 test file(s)`. / Reasoned: the rules for v1, the
  algorithm list and AKI on hand-built data; the EKU name → OID table;
  unknown critical extensions as the one named gap until M6; two
  measurements deferred to the tests-first step (profile check without
  settings / with verify_trust off; expired + wrong anchor).
- Decided by Maurice: KU as c2pa-rs keeps it (option a).

## 2026-09-21 — SPEC-015 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-015-certificate-profile.md` status `approved`,
  the row in `docs/milestones.md`, this entry.
- Measured: `php bin/spec-check.php` → OK. / Reasoned: nothing.
- Decided by Maurice: SPEC-015 approved as drafted — the eight profile
  rules, KU as c2pa-rs (option a), one `.invalid` per fault,
  `signature_info` per manifest, unknown critical extensions as the
  named gap until M6.

## 2026-09-21 — Step 34a: the profile check measured without settings
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 34a".
- Produced: five JSONs under `tests/Fixtures/c2patool/profile/` (+
  README rows), SPEC-015 amendment 1 (the Verifier bullet, the
  `check()` signature, `checksPerformed` `certificate`, AC8), the
  addendum in `notes/step-33-profile-measured.md`, `docs/milestones.md`,
  this entry. No `src/` change, no test.
- Measured: `c2patool 0.27.22` — `expired.png` with no settings →
  `signingCredential.expired` + `.untrusted`; with `verify_trust: false`
  → `.expired` alone; with the EC test root as anchor → `.expired` +
  `.untrusted`; `no-eku.png` with no settings → `.invalid` +
  `.untrusted`; `good.png` with no settings → `Valid`, `.untrusted`,
  `signature_info` identical to the settings run. / Reasoned: the
  profile belongs to the signature's certificate, not to the operator's
  trust — the check runs always, after the signature check, with its own
  entry in `checks_performed`.
- Decided by Maurice: step 34a as explained. Amendment 1 made under the
  spec's Open question, for his confirmation.

## 2026-09-21 — Step 34b: the SPEC-015 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 34b".
- Produced: `tests/Unit/Trust/CertificateProfileCheckTest.php` — ten
  tests, one per criterion, `->group('SPEC-015')`; AC4/AC6 use a
  `Certificate::fromParsed()` seam fed with the good leaf's real
  `openssl_x509_parse()` data, altered; AC7 compares `signature_info`
  with the oracle's block and lets the sister parser's `signer()` read
  it; AC10 re-runs the 22-file corpus with the full settings and the
  12 profile variants; `docs/milestones.md`, this entry. No `src/`
  change.
- Measured: `vendor/bin/pest --group=SPEC-015` → `10 failed (15
  assertions)`: `Class … CertificateProfileCheck not found`, `Undefined
  constant StatusCode::SigningCredentialExpired`, `actual size 23
  matches expected size 24`, `checksPerformed` without `certificate`,
  no `signature_info`, no `.invalid`; `bin/spec-check.php` → `OK: 16
  spec(s), 16 test file(s)`. AC9 fails on one more thing: without
  settings this verifier emits no credential code, c2patool emits
  `signingCredential.untrusted` (no anchors → untrusted; `png.json` of
  step 14, `good-no-settings.json` of step 34a) — SPEC-014 AC6's
  "no settings equals verify-off" contradicts the oracle. / Reasoned:
  to be settled in step 35 as SPEC-014 amendment 1: without settings
  the trust check runs with no anchors and says `untrusted`;
  `verify_trust: false` alone says nothing.
- Decided by Maurice: step 34b as explained.

## 2026-09-21 — Step 35: SPEC-015 implemented, the certificate profile; M5 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met stap 35".
- Produced: `src/Trust/CertificateProfileCheck.php`;
  `src/Trust/Certificate.php` (the profile fields, `fromDer()`,
  `fromParsed()`, `hexToDecimal()`, RSA-PSS by SPKI OID);
  `src/Report/StatusCode.php` (+`signingCredential.expired`);
  `src/Verifier/Verifier.php` (profile always; trust without settings
  → no anchors; `signatureInfo()`); `src/Verifier/VerificationReport.php`
  (`$signatureInfo` under the active manifest); callers moved to
  `Certificate::fromDer()`; ten older tests adjusted (SPEC-013 amendment
  4, SPEC-014 amendment 1, SPEC-015 amendment 2 — recorded in each);
  SPEC-015 → `implemented` with Traceability;
  `notes/step-35-certificate-profile.md`, `NOTES.md`,
  `docs/milestones.md` (M5 done), this entry.
- Measured: first run `5 failed, 5 passed` — an RSASSA-PSS key is type
  −1 to `openssl_pkey_get_details()` (fixed by the SPKI OID
  `1.2.840.113549.1.1.10`, measured on the Adobe leaf); c2patool's
  `signature_info` has `time` on the Adobe file (M6's, excluded from
  AC7); then the SPEC-013/014 consequences (`certificate`/`trust` in
  `checksPerformed`, `untrusted` without settings). After: `composer
  check` → Pint passed, PHPStan `[OK] No errors`, Deptrac `Violations
  0`, Pest `203 passed (2071 assertions)`; `bin/spec-check.php` → `OK:
  16 spec(s), 16 test file(s)`. AC9 (M5's "done when") and AC10 (22 +
  12 files) equal to c2patool's state and credential codes. /
  Reasoned: one `.invalid` per fault; the byte search for the PSS OID
  as "not ASN.1 parsing".
- Decided by Maurice: step 35 as explained. For his confirmation:
  SPEC-014 amendment 1 (no settings → `untrusted`), SPEC-015 amendment
  2, SPEC-013 amendment 4.

## 2026-09-21 — CI green on M5
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `a0518c1..1fba881` (nine commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` under `tests/Fixtures/trust/` or `tests/Fixtures/profile/`,
  visibility `PRIVATE`. Run `35649014938` on `1fba881`: conclusion
  `success`; PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 203
  passed` (2069 assertions on 8.3, 2071 on 8.4/8.5) — the throw-away
  hierarchy's certificates and the OpenSSL-name EKU table hold on the
  CI runner's OpenSSL too; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — Step 36: the official test files
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat er nu staat, kan dat goed getest worden met andere
  fixtures die online te vinden zijn" — then "akkoord, en optie 1: fail
  closed tot M7".
- Produced: 25 JPEGs added to `tests/Fixtures/public-testfiles/`
  (README extended), 24 JSONs under `tests/Fixtures/c2patool/public-testfiles/`
  (README), `notes/step-36-public-testfiles.md`, `NOTES.md`,
  `docs/milestones.md`, this entry. No `src/` change, no test.
- Measured: `c2pa-org/public-testfiles` at `22beccc07570` — `2.2/` is
  placeholders, `legacy/1.4/image/jpeg/` holds 26 files (licence
  CC-BY-SA-4.0 per the GitHub API); all 26 through `Verifier::verify()`
  with `trust/full.settings.json` and through `c2patool 0.27.22` with
  the same settings: 20 of 26 states equal; the six differences: two
  files without a manifest (designed), four camera files refused at
  parse (`invalid CBOR: float … is not supported` in `stds.exif` and
  `com.truepic.custom.odometry`), one `Trusted` on `E-uri-CIE-sig-CA`
  whose fault is in an ingredient manifest. Manifest counts per file
  from the JSON (1–6). / Reasoned: floats decode without touching any
  hash (SPEC-006 amendment next); the multi-manifest rule as the
  fail-closed interim.
- Decided by Maurice: the 26 files as fixtures; a store with more than
  one manifest is `Invalid` until M7 (option 1).

## 2026-09-21 — Step 37: floats decode; the camera files through the front door
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met stap 37" (after "20 van 26 states gelijk.
  moet dit niet gefixt?" — answered: yes, in steps 37 and 38, with the
  table of which difference each fixes).
- Produced: `specs/SPEC-006-cbor-decoder.md` (AC7 rewritten, Scope,
  amendment 2), `tests/Unit/Cbor/CborDecoderTest.php` (AC7: the RFC
  vectors, truncation, the four camera files), `src/Cbor/CborDecoder.php`
  (`float()`); `src/Trust/CertificateProfileCheck.php` (+2 RSA
  algorithms), `specs/SPEC-015-certificate-profile.md` (amendment 3),
  `tests/Unit/Trust/CertificateProfileCheckTest.php` (AC6 positive
  case); `notes/step-37-floats.md`, `NOTES.md`, `docs/milestones.md`,
  this entry.
- Measured: AC7 red (`float at offset 0 is not supported`), then
  `composer check` → `203 passed (2102 assertions)`, Pint/PHPStan/
  Deptrac green. The four camera files with the full settings: Nikon
  `Invalid` with `signingCredential.expired` + `.untrusted`, equal to
  c2patool; Truepic ×3 `Invalid` here (`expired`, `invalid`,
  `untrusted`, `dataHash.mismatch`) vs `Valid` (`untrusted`) there.
  c2pa-rs `certificate_profile.rs:181–188`: SHA-384/512 with RSA
  allowed (my step-30 reading was short). Truepic's exclusion
  `[0, 206316]` vs the store at `[13617, 192699]`; c2patool's
  `signature_info.time` `2023-02-12T18:44:26+00:00` on a one-day
  certificate. / Reasoned: the half-float conversion; the exclusion
  invariant is "covers", not "equals" — proposed as SPEC-012 amendment
  5 for Maurice's decision; the validity time is M6's.
- Decided by Maurice: floats decode (step 36's discussion). Open for
  him: the exclusion rule (equals → covers).

## 2026-09-21 — Step 38a: the store's exclusion must cover the store (SPEC-012 amendment 5)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, covers in plaats van equals".
- Produced: `specs/SPEC-012-data-hash-check.md` (Scope item 5, AC3,
  amendment 5, Traceability), `tests/Unit/Hash/DataHashCheckTest.php`
  (AC3 renamed; the Truepic file as the covering case),
  `src/Hash/DataHashCheck.php` (every store piece inside an exclusion;
  the covering exclusion is the store's, the rest additional),
  `docs/milestones.md`, this entry.
- Measured: AC3 red on `truepic-20230212-camera.jpg` (`Failed asserting
  that two arrays are identical`: mismatch where match is required);
  after the change `composer check` → `203 passed (2103 assertions)`,
  the step-23 variants still `.mismatch` (part of the store uncovered),
  the Truepic file `assertion.dataHash.match` with one success entry
  in c2patool's JSON. / Reasoned: the invariant is containment; an
  exclusion wider than the store is the signer's own choice inside the
  signed claim.
- Decided by Maurice: covers instead of equals.

## 2026-09-21 — Step 38b: more than one manifest is refused until M7; the official corpus as a drift alarm
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (continuing "optie 1: fail closed tot M7" from step 36).
- Produced: `specs/SPEC-013-verifier.md` (AC11, amendment 5,
  Traceability), `tests/Pest.php` (`SPEC013_PUBLIC_CORPUS`, `_MULTI`,
  `_NO_TIMESTAMP`), `tests/Unit/Verifier/VerifierTest.php` (AC11),
  `src/Verifier/Verifier.php` (the manifest count → `general.error`),
  `notes/step-38-cover-and-multi-manifest.md`, `NOTES.md`,
  `docs/milestones.md`, this entry.
- Measured: AC11 red (`cannot open …` on the first attempt was the
  test's path, then the rule itself: no `general.error`), then
  `composer check` → `204 passed (2206 assertions)`. The corpus: 13 of
  24 states equal, 11 stricter on purpose and named (eight multi-
  manifest → M7, three Truepic → M6), zero where this verifier is
  more lenient than c2patool. One more variadic `toContain()` stumble
  in the test, fixed with `in_array`. / Reasoned: the error is added
  after the active manifest's checks so the reader still gets them.
- Decided by Maurice: fail closed until M7 (step 36).

## 2026-09-21 — CI green on the official corpus
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `ce1d345..1695977` (six commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` under the trust and profile fixtures, visibility
  `PRIVATE`. Run `35652036005` on `1695977`: conclusion `success`; PHP
  8.3 / 8.4 / 8.5 each `success` with `Tests: 204 passed` (2204
  assertions on 8.3, 2206 on 8.4/8.5) — the 26 official files and both
  drift alarms hold on the CI runner; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-21 — Step 39: the c2pa-rs fixtures as a third corpus; indefinite lengths, a null field, a CAWG assertion
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "is nu alles wel goed met die fixtures? kan je er meer vinden?"
  → "optie a, en neem de 33 c2pa-rs-bestanden op als derde corpus".
- Produced: `tests/Fixtures/c2pa-rs/` (33 files + the two c2pa-rs
  licences + README), `tests/Fixtures/c2patool/c2pa-rs/` (17 JSONs +
  README); SPEC-006 amendment 3 (AC6 rewritten, red → green;
  `CborDecoder::chunks()`, `atBreak()`, indefinite `array()`/`map()`);
  SPEC-007 amendment 4 (`Claim::fromMap()`: null is absent, a null
  required field is missing; ManifestStoreTest "AC5 (amendment 4)");
  SPEC-013 AC12 + amendment 7 (`Verifier`: `cawg.identity` →
  `general.error`; `SPEC013_RS_*` in `tests/Pest.php`); SPEC-010
  amendment 4 and SPEC-013 amendment 6 (the CBOR-fault example is
  `claim-duplicate-key`); `notes/step-39-c2pa-rs-corpus.md`, `NOTES.md`,
  `docs/milestones.md`, this entry.
- Measured: c2pa-rs at `58eac79`, `sdk/tests/fixtures/` — 249 files,
  33 images, licence Apache-2.0 OR MIT; the Encypher conformance suite
  holds rubric vectors, no image corpus. All 33 through the front door
  next to c2patool with the full settings: first 5 of 33 states equal;
  the nine `claim.cbor.invalid` were indefinite lengths; `ocsp.jpg`'s
  first claim has `claim_generator_info: null`; `C_with_CAWG_data.jpg`
  has c2patool's `signingCredential.trusted` under success and
  `.untrusted` under failure — the CAWG identity's credential — and
  `cawg.identity.well-formed`; `cloud.jpg` is `Valid` at c2patool
  because it fetched the manifest from the network. After the three
  changes: 8 of 17 equal, 9 stricter by name, 0 more lenient. AC6 red
  (`indefinite length at offset 0 is not supported`), AC5-amendment-4
  red (`not a non-empty list of maps`), AC12 red (`Trusted` vs
  `Invalid` on the CAWG file), then `composer check` → `206 passed
  (2253 assertions)`, Pint/PHPStan/Deptrac green; `bin/spec-check.php`
  → OK. / Reasoned: bounds cover the resource concern of indefinite
  lengths; a null field is an absent one; the CAWG refusal by the same
  principle as the ingredient one.
- Decided by Maurice: indefinite lengths accepted (option a); the 33
  files as a corpus. For his confirmation: the CAWG refusal (SPEC-013
  amendment 7), SPEC-007 amendment 4, SPEC-010 amendment 4, SPEC-013
  amendment 6.

## 2026-09-21 — CI green on the c2pa-rs corpus
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `db3e45e..dcdb72f` (three commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` under the trust, profile and c2pa-rs fixtures,
  visibility `PRIVATE`. Run `35654841579` on `dcdb72f`: conclusion
  `success`; PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 206
  passed` (2251 assertions on 8.3, 2253 on 8.4/8.5); `all green`
  `success`.
- Decided by Maurice: push.

## 2026-09-22 — Step 40: the timestamp measured before M6
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "okay, wat moet er nu gebeuren?" (a roadmap: M6 first, the
  measurement step before its ADR and specs), then "akkoord, begin met
  stap 40".
- Produced: `notes/step-40-timestamp-measured.md`; rows in `NOTES.md`
  and `docs/milestones.md` (a new "M6, step by step" section); this
  entry. No code, no fixtures, no specs — a measurement step. Scratch
  material (extracted tokens, two reconstruction scripts, the 0.90.22
  sources fetched from GitHub) stays outside the repository.
- Measured: the `sigTst`/`sigTst2` headers of the three corpora
  through the project's `CoseSign1` (shape, TSA, `genTime`, imprint
  algorithm — `openssl ts -reply -text`); the countersigned bytes
  rebuilt over `CoseSign1`/`claimBytes()` and compared with the
  imprint on four tokens (equal) and on `E-sig-CA` (not equal, as
  c2patool's `timeStamp.mismatch`); `jq` over 41 oracle JSONs for the
  `timeStamp.*` codes and `signature_info.time`; `CA_ct.jpg`'s
  `genTime` `20240806216337Z` (minute 63) as the cause of
  `timeStamp.malformed`; `signingTime` == `genTime` and 0 indefinite
  lengths on five tokens (`openssl cms -cmsout -print`,
  `openssl asn1parse`); `openssl_cms_verify` with `NOVERIFY` on a temp
  file → `true`, with the real anchor → `unsuitable certificate
  purpose` (no `-purpose` in PHP); the CMS signature by hand:
  `signedAttrs` re-tagged `A0`→`31`, `openssl_verify` with the TSA
  leaf key → `1`, one bit flipped → `0`, `messageDigest` ==
  `sha256(eContent)`, also on the RSA-4096 2025 token; c2patool with
  no settings / full test settings / `verify_timestamp_trust`
  true and false on `C.jpg` and `CACA.jpg` (`trusted` and `untrusted`
  unchanged by any of it); `strings` over the c2patool binary (seven
  test PEMs, no TSA names, `c2pa/0.90.22`); `diff` of the 2023 and
  2025 DigiCert leaf certificates (issuer, dates, URLs only).
  Reasoned: c2pa-rs `time_stamp/verify.rs` (0.90.22, line-referenced)
  for the order of checks and the informational logging;
  `cose/sigtst.rs` (main) for `cose_countersign_data`; both
  `check_certificate_trust` implementations at the tag for the
  empty-anchor case — which contradicts the measured `trusted`; left
  unresolved and written down as such.
- Decided by Maurice: start step 40 (the measurement) before ADR-0004.

## 2026-09-22 — ADR-0004 as a draft
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf ADR-0004 als draft".
- Produced: `docs/adr/ADR-0004-rfc3161-on-an-own-der-reader.md` (status
  `draft`, decided by nobody yet); a row in `docs/milestones.md`; one
  finding folded into `notes/step-40-timestamp-measured.md` §6 and the
  ADR; this entry.
- Measured: `openssl_pkcs7_read()` on the `C.jpg` token — `false` on the
  DER (`no start line`), the three certificates as PEM when the same
  bytes are base64-wrapped as `BEGIN PKCS7`; the header kind per corpus
  JPEG through the step-40 scratch script (35 `sigTst`, 2 `sigTst2`,
  12 none). Reasoned: the decision rests on step 40 and on ADR-0001/0003's
  rules (no temp files, no dependency for convenience).
- Decided by Maurice: none yet — the draft proposes an own DER reader
  over `phpseclib`, TSA trust only through configured anchors, and
  `genTime` over `signingTime` with a difference as `malformed`.

## 2026-09-22 — ADR-0004 accepted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op accepted".
- Produced: ADR-0004 status `accepted`, decided by Maurice van Loon;
  the milestones row; this entry.
- Measured: nothing new. Reasoned: nothing new.
- Decided by Maurice: ADR-0004 as drafted — an own DER reader
  (`src/Asn1/`) over `phpseclib`; the CMS signature on `openssl_verify`;
  the TSA judged with M5's code and trusted only through configured
  anchors; `timeStamp.*` informational with the time as its one effect;
  `genTime` over `signingTime`, a difference `malformed`.

## 2026-09-22 — SPEC-016 as a draft
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-016 als draft".
- Produced: `specs/SPEC-016-der-reader-and-timestamp-token.md` (draft,
  ten acceptance criteria, API sketch for `Asn1\{Der,DerReader,TagClass,
  Asn1Exception}` and `Timestamp\{TimestampHeader,TimeStampToken,
  SignedData,SignerInfo,TstInfo,TstAccuracy,TimestampException}`); a
  row in `docs/milestones.md`; this entry. `bin/spec-check.php`: OK,
  17 specs.
- Measured: for the criteria's literals — `openssl ts -reply -token_in
  -text` and `openssl cms -cmsout -print` on the five step-40 tokens
  (policy, imprint algorithm and prefix, serial, genTime, nonce,
  accuracy, TSA name; SignerInfo `sid`, digest and signature
  algorithms, the signed attributes by OID); `openssl asn1parse` for
  the `eContent` sizes (112/114/227/113/113) and the signed-attribute
  offsets; the Truepic TSA key (RSA-4096); the epoch of
  `240806215337Z` and the decimal of the `C.jpg` serial (`php`).
  Reasoned: the ten-tag grammar and its refusals from X.690 §8/§10;
  "exactly one signer" from RFC 3161 §2.4.2; the re-tagging from
  RFC 5652 §5.4; both wrappers from both headers as c2pa-rs.
- Decided by Maurice: none yet (the draft awaits approval).

## 2026-09-22 — SPEC-016 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-016 status `approved`; the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing new.
- Decided by Maurice: SPEC-016 approved as drafted.

## 2026-09-22 — Step 41a: the SPEC-016 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 41a".
- Produced: `tests/Unit/Asn1/DerReaderTest.php` (28 tests),
  `tests/Unit/Timestamp/TimeStampTokenTest.php` (36 tests, with a
  test-side DER walker for the patches), the `Asn1` layer and
  `Timestamp` rules in `deptrac.yaml`, SPEC-016 amendment 1, one
  sentence of ADR-0004 corrected, `notes/step-41-timestamp-tests.md`,
  rows in `NOTES.md` and `docs/milestones.md`; this entry.
- Measured: `vendor/bin/pest --group=SPEC-016` → 64 failed, every one
  on `Asn1\DerReader` (28), `Timestamp\TimeStampToken` (30) or
  `Timestamp\TimestampHeader` (5) not found; the full suite 64 failed /
  206 passed; Pint clean; Deptrac 0 errors. The twelve patched tokens
  written from the test helpers and run through `openssl asn1parse`
  (all parse; a structural diff shows only the intended change; the
  two structural TSTInfo patches checked with `-strparse`). The
  timestamp header of every corpus file in all three formats (38 files,
  one token each, none with both headers); depth and element count per
  token (`asn1parse`: d=18, 311 at most). Three faults in my own
  helpers found by those checks and fixed before the commit (the
  imprint's OCTET STRING off the re-encoding path; an Extension length
  of 11 for 10 bytes; a walker descending into digest bytes until
  memory ran out). Reasoned: nothing beyond the spec.
- Decided by Maurice: start 41a.

## 2026-09-22 — Step 41b: the DER reader and the timestamp token, green
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 41b".
- Produced: `src/Asn1/{TagClass,Der,DerReader,Asn1Exception}.php`,
  `src/Timestamp/{TimestampHeader,TimeStampToken,SignedData,SignerInfo,
  TstInfo,TstAccuracy,TimestampException}.php`; `Support\Bytes::hexToDecimal`
  and `printableText` (the first moved from `Trust\Certificate`, which
  delegates); the two test files retagged per test (no `describe()`),
  `$this->fail()` removed, six literals corrected; SPEC-016 amendment 2,
  status `implemented`, Traceability filled; the 41b section of
  `notes/step-41-timestamp-tests.md`; rows in `NOTES.md` and
  `docs/milestones.md`; this entry.
- Measured: the first run with the classes in place — DerReader 25/27
  (the OID vector's own length byte), token tests 30/37 (Truepic's
  certificate order, `ocsp*.jpg` with two certificates, three literals,
  the `[3]` patch); `openssl pkcs7 -print_certs` on the Truepic and
  `ocsp.jpg` tokens (root first; "Adobe SHA256 ECC256 Timestamp
  Responder 2025 1"); after the fixes `composer check` green: 17 specs,
  Pint passed, PHPStan 0 errors, Deptrac 0 violations, `Tests: 270
  passed (2889 assertions)`. Reasoned: the `subjectKeyIdentifier` sid
  path (no corpus token uses it); the first-two-arcs OID rule and the
  DER minimal-length rules from X.690.
- Decided by Maurice: go on with 41b.


## 2026-09-22 — CI green on SPEC-016
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `dcdb72f..f3311a9` (eight commits: the CI record,
  step 40, ADR-0004 draft and accepted, SPEC-016 draft and approved,
  steps 41a and 41b) to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines in `git log`, no
  tracked `*.key`, no `PRIVATE KEY` under `tests/Fixtures`, visibility
  `PRIVATE`, tree clean. Run `35701477192` on `f3311a9`: conclusion
  `success`; `composer check` on PHP 8.3 / 8.4 / 8.5 each `success`
  with `Tests: 270 passed` (2887 assertions on 8.3, 2889 on 8.4/8.5);
  `all green` `success`.
- Decided by Maurice: push.

## 2026-09-22 — SPEC-017 as a draft
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat moet er nog allemaal gebeuren voordat deze verifier public
  kan worden?" (answered in conversation with a four-part list; no file)
  and "akkoord, schrijf SPEC-017 als draft".
- Produced: `specs/SPEC-017-timestamp-check.md` (draft, ten criteria,
  API sketch, the amendments it will need in SPEC-010/013/014/015 named);
  a factual correction in `notes/step-40-timestamp-measured.md` (the
  `exp-test1` example); a row in `docs/milestones.md`; this entry.
- Measured: c2patool's success/informational/failure order on `C.jpg`,
  `CACA.jpg`, the Truepic and `exp-test1` JSONs (`timeStamp.*` first;
  `signature_info.time` present only with `validated`;
  `claimSignature.insideValidity` on 39 of 41 JSONs including the expired
  Nikon file); `exp-test1.png`'s six manifests, its self-signed one, and
  its active signer's validity (2022-03-01..2023-03-01) against the stamp
  (2022-04-20) — the step-40 note had this wrong; the header name against
  the claim version on six corpus files (v1 ↔ `sigTst`, v2 ↔ `sigTst2`);
  c2pa-rs `sigtst.rs` at main for the header choice ("first time stamp
  header" only). Reasoned: the order and codes from `verify.rs` as read
  in step 40; PSS and the SKI path (no corpus token).
- Decided by Maurice: none yet (the draft awaits approval).

## 2026-09-22 — SPEC-017 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-017 status `approved`; the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing new.
- Decided by Maurice: SPEC-017 approved as drafted.

## 2026-09-22 — Step 42a: the SPEC-017 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 42a".
- Produced: `tests/Unit/Timestamp/TimestampCheckTest.php` (15 tests);
  `tests/Support/Corpus.php` and `DerPatch.php` (the SPEC-016 helpers,
  moved; SPEC-016 Traceability updated); `tests/Fixtures/trust/
  {truepic-root,digicert-trusted-root-g4}.pem` and three settings files
  (README rows); `tests/Fixtures/c2patool/timestamp/` (six JSONs, README);
  `tests/Pest.php` and `VerifierTest` with the `_TSA_NOT_CONFIGURED`
  lists; `notes/step-42-timestamp-check-tests.md`; rows in `NOTES.md`
  and `docs/milestones.md`; this entry.
- Measured: `openssl pkcs7 -print_certs` for the two anchors and their
  validity; c2patool 0.27.22 under the three settings files on the
  Truepic three, `C.jpg`, `CACA.jpg` and `exp-test1.png` (states and
  codes in the note); `vendor/bin/pest --group=SPEC-017` → 15 failed
  (13 assertions), the whole suite 15 failed / 270 passed, SPEC-013
  12 passed, SPEC-016 64 passed after the helper move; AC1's file count
  35 with no unreadable store; Pint passed; PHPStan only the missing
  symbols. One mistake of mine caught by the suite: a greedy regex that
  removed the SPEC-016 tests along with the helpers (64 → 31), restored
  from git and redone. Reasoned: nothing beyond the spec.
- Decided by Maurice: start 42a.

