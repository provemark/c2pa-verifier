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
  visibility` → `PRIVATE`. First run `35435586024` on `4b3ff90`: all four
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
- Produced: pushed `926b2f0` and `3438016` to `origin/main`; three
  `docs/milestones.md` rows that still said the CI run was pending; this
  entry.
- Measured: 0 attribution lines in the history before the push. Run
  `35444321627` on `3438016`: conclusion `success`; per job, `composer
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
- Produced: SPEC-002 → `approved` (own commit `f920553`); five edits to the
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
- Produced: pushed `6a4bcb8`…`d5368aa` (six commits) to `origin/main`; this
  entry.
- Measured: before the push, 0 attribution lines in the history and no
  tracked file matching `key`. Run `35492160497` on `d5368aa`: conclusion
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
- Produced: SPEC-003 → `approved` (own commit `559bf4a`);
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
- Produced: pushed `3ed0111`…`e4da806` (five commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35493241785` on `e4da806`: conclusion `success`;
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
  2 (commit `36172c2`). After the class alone: 6 green, AC1 red on the
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
- Produced: pushed `0139922`, `36172c2`, `ed81e84` to `origin/main`; this
  entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35493634964` on `ed81e84`: conclusion `success`;
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
- Produced: pushed `5a708d7` and `3c49882` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35496464128` on `3c49882`: conclusion `success`;
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
  pushed `54ad8c1`, `b620c37` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`; `spec-check` `OK: 6 spec(s), 5 test file(s)`. The CI
  run: see the next entry.
- Decided by Maurice: SPEC-005 approved as drafted after step 10, with the
  two non-blocking open questions left open.

## 2026-09-21 — CI green after SPEC-005's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35571033890` on `b9f8a28f`: conclusion `success`; PHP
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
- Produced: pushed `47f9ded` and `731a79f` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`. Run `35572440777` on `731a79f`: conclusion `success`;
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
  pushed `7c8a34c`, `c72b896` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked file
  matching `key`; `spec-check` `OK: 7 spec(s), 6 test file(s)`. The CI
  run: see the next entry.
- Decided by Maurice: SPEC-006 approved as drafted after step 12.

## 2026-09-21 — CI green after SPEC-006's approval; the key check sharpened
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35573418733` on `0dead7de`: conclusion `success`; PHP
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
- Produced: pushed `67a2d56` and `1cd449f` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35574264542` on `1cd449f`: conclusion `success`; PHP 8.3 / 8.4 /
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
  pushed `e87176c`, `ea38990` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 8 spec(s), 7 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-007 approved as drafted after step 14.

## 2026-09-21 — CI green after SPEC-007's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35575268917` on `9c4102ff`: conclusion `success`; PHP
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
- Produced: pushed `d7551c9` and `908cccf` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35576013965` on `908cccf`: conclusion `success`; PHP 8.3 / 8.4 /
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
  `docs/milestones.md` row; this entry; pushed `ada82ee` and this commit.
- Measured: `grep -rn "mb_" src` → 2 call sites; before the push, 0
  attribution lines and no tracked `*.key`. The CI run: next entry.
- Decided by Maurice: ADR-0001 amended — COSE_Sign1 verification written
  here on `ext-openssl`, `cose-lib` as reference reading only.

## 2026-09-21 — CI green after step 16 and the ADR amendment
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35577079598` on `2388663e`: conclusion `success`; PHP
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
  pushed `b4adc36`, `81d90d5` and this commit to `origin/main`.
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
- Measured: run `35578144778` on `f118edfb`: conclusion `success`; PHP
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
- Produced: pushed `452ddbc` and `e5e9873` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35579159736` on `e5e9873`: conclusion `success`; PHP 8.3 / 8.4 /
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
  pushed `0b0183f`, `31cfaa2` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 10 spec(s), 9 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-009 approved as drafted after step 19.

## 2026-09-21 — CI green after SPEC-009's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35580128105` on `b7588cae`: conclusion `success`; PHP
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
- Produced: pushed `2b645a6` and `e41686c`; after the run, AC8's test
  rewritten to encode the measured boundary, SPEC-009 amendment 1 (a
  measurement, no criterion changed), `composer.json` `suggest` for
  `ext-sodium` reworded, `notes/step-20` updated; this entry; pushed
  again.
- Measured: run `35581104498` on `e41686c`: PHP 8.4 and 8.5 `success`,
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
- Measured: run `35581284766` on `f383c0bf`: conclusion `success`; PHP
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
- Measured: run `35581674251` on `194b743f`: conclusion `success`, PHP
  8.3 / 8.4 / 8.5 each `143 passed`; per job `Cache not found for input
  keys: composer-Linux-php8.x-8a61b732…` then `Cache saved with key …` —
  the expected miss that fills the cache. The next run must restore it;
  that is the measurement of whether the Packagist downloads stop.
- Decided by Maurice: add the cache; push.

## 2026-09-21 — CI with the cache: the first hit
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run 35581790139 on a9e7d924 (the push of the previous log entry):
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
  pushed `bae5bda`, `132df08`, `f50c801` and this commit to `origin/main`.
- Measured: before the push, 0 attribution lines and no tracked `*.key`;
  `spec-check` `OK: 11 spec(s), 10 test file(s)`. The CI run: next entry.
- Decided by Maurice: SPEC-010 approved as drafted after step 21.

## 2026-09-21 — CI green after SPEC-010's approval
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (same request) read the CI run.
- Produced: this entry.
- Measured: run `35583334608` on `70228815`: conclusion `success`; PHP
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
- Produced: pushed `a00c969` and `2ab1463` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35584423141` on `2ab1463`: conclusion `success`; PHP 8.3 / 8.4 /
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
- Produced: pushed `e293b6c` to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines and no tracked `*.key`.
  Run `35586595620` on `e293b6c`: conclusion `success`; PHP 8.3 / 8.4 /
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
- Produced: pushed `921ce0f..0ea6db7` (five commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  `gh repo view --json visibility` → `PRIVATE`. Run `35621588330` on
  `0ea6db7`: conclusion `success`; PHP 8.3 / 8.4 / 8.5 each `success`
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
- Produced: pushed `0644ba2..fb9b3a0` (six commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  `gh repo view --json visibility` → `PRIVATE`. Run `35624439126` on
  `fb9b3a0`: conclusion `success`; PHP 8.3 / 8.4 / 8.5 each `success`
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
- Produced: pushed `7b2b55b..ef04cb0` (six commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  `gh repo view --json visibility` → `PRIVATE`. Run `35634988650` on
  `ef04cb0`: conclusion `success`; PHP 8.3 / 8.4 / 8.5 each `success`
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
- Produced: pushed `db369b3..206e1a0` (ten commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` in `tests/Fixtures/trust/`, visibility `PRIVATE`.
  Run `35640449671` on `206e1a0`: conclusion `success`; PHP 8.3 / 8.4 /
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
- Produced: pushed `3caf879..430528f` (nine commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` under `tests/Fixtures/trust/` or `tests/Fixtures/profile/`,
  visibility `PRIVATE`. Run `35649014938` on `430528f`: conclusion
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
- Produced: pushed `0fbad16..353b481` (six commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` under the trust and profile fixtures, visibility
  `PRIVATE`. Run `35652036005` on `353b481`: conclusion `success`; PHP
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
- Produced: pushed `922720b..94bd1c5` (three commits) to `origin/main`;
  this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  no `PRIVATE KEY` under the trust, profile and c2pa-rs fixtures,
  visibility `PRIVATE`. Run `35654841579` on `94bd1c5`: conclusion
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
- Produced: pushed `94bd1c5..6a89fe6` (eight commits: the CI record,
  step 40, ADR-0004 draft and accepted, SPEC-016 draft and approved,
  steps 41a and 41b) to `origin/main`; this entry.
- Measured: before the push, 0 attribution lines in `git log`, no
  tracked `*.key`, no `PRIVATE KEY` under `tests/Fixtures`, visibility
  `PRIVATE`, tree clean. Run `35701477192` on `6a89fe6`: conclusion
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

## 2026-09-22 — Step 42b: the timestamp check, green; M6 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 42b".
- Produced: `src/Timestamp/{TimestampCheck,TimestampResult}.php`; six
  cases in `src/Report/StatusCode.php`; `$ekus`/`$reason` in
  `src/Trust/CertificateProfileCheck.php`; `checkCertificates()` in
  `src/Trust/ChainCheck.php`; the Verifier wiring and `signature_info.time`
  in `src/Verifier/Verifier.php`; SPEC-017 amendment 1, Traceability,
  status `implemented`; SPEC-010 #5, SPEC-013 #8, SPEC-014 #2, SPEC-015 #4;
  nine older tests and six literals of the new one adjusted (named in the
  amendments); the 42b section of `notes/step-42-timestamp-check-tests.md`;
  rows in `NOTES.md` and `docs/milestones.md` (M6 done); this entry. The
  code and specs went into commit `acbd0bc`; a bookkeeping script of mine
  stopped on a wrong anchor string before the note, rows and this entry
  were written, so they follow in the amended commit.
- Measured: the first run with the code in place — 9 of 15 green, six
  literal faults; then 285/285 with `composer check` green (18 specs,
  Pint, PHPStan 0, Deptrac 0, 3377 assertions); a hand run of the
  Verifier on Truepic (with and without the root), `C.jpg` and Nikon
  (states, codes, `time`, the `expired` explanations — in the note).
  Reasoned: the PSS path and the SKI sid path (no corpus token); the
  chain ordering rule from c2pa-rs's `order_certificates_leaf_to_root`.
- Decided by Maurice: go on with 42b.


## 2026-09-22 — Push of M6, CI red on Deptrac, fixed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `6a89fe6..bce063a` (five commits); run `35704251235`
  on `bce063a` failed on all three PHP versions — Deptrac: `Timestamp`
  may not depend on `Cose` and `Trust` (32 violations, all in
  `TimestampCheck`). SPEC-017's Scope had named exactly those two layers
  ("Cose and Trust join in SPEC-017") and I did not write the rule into
  `deptrac.yaml`; worse, my local `composer check` read-out grepped for
  "Errors" and not for "Violations", so the red line went by unseen.
  Fixed: `deptrac.yaml` allows `Timestamp` → `Cose`, `Trust`, `Manifest`;
  this entry; pushed as the next commit.
- Measured: `vendor/bin/deptrac analyse` — 32 violations before, 0 after,
  428 allowed; `composer check` green, 285 tests.
- Decided by Maurice: push.

## 2026-09-22 — CI green on M6
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (the same "push maar").
- Produced: this entry.
- Measured: run `35704398866` on `4679bb7`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 285
  passed` (3375 assertions on 8.3, 3377 on 8.4/8.5); `all green` `success`.
- Decided by Maurice: none.

## 2026-09-22 — Step 43: more fixtures, from other writers
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Ik wil weten of de huidige code echt goed functioneert.
  Misschien zijn er meer fixtures te vinden om tegen te testen?" and
  "akkoord, begin met stap 43".
- Produced: `tests/Fixtures/writers/` (five files, three licence files,
  README), `tests/Fixtures/c2patool/writers/` (five JSONs, README),
  `notes/step-43-more-fixtures.md`, rows in `NOTES.md` and
  `docs/milestones.md`; this entry. No code.
- Measured: the file trees of nine repositories through the GitHub API
  (counts in the note); md5 of two duplicates; c2patool 0.27.22 on ten
  candidate files and this verifier on the same (states, codes,
  `signature_info.time`); `openssl ts -reply -text` on the three new
  tokens (negative nonces, fractional genTime); the JPEG segment walk and
  the XMP `dcterms:provenance` of the Photoshop file. Reasoned: the
  fixes proposed for step 44 (signed nonce, kept fraction, a remote
  manifest note) and the ranking of what is still unmeasured.
- Decided by Maurice: start step 43.

## 2026-09-22 — Step 44: the writers corpus's findings fixed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met stap 44, en neem bevinding 3 erbij".
- Produced: SPEC-016 amendment 3, SPEC-017 amendments 2–3, SPEC-013
  amendment 9 (text first); tests seen red — `TimeStampTokenTest` AC11 ×5,
  `TimestampCheckTest` AC11, `VerifierTest` AC13–AC14, `SPEC013_WRITERS_*`
  in `tests/Pest.php`; then `src/Asn1/Der.php` (`integer(signed:)`,
  `timeFraction()`), `src/Timestamp/{TstInfo,TimestampResult,SignerInfo,
  TimestampCheck}.php` (fraction, `timeIso()`, the DER-canonical SET,
  `ecdsaDer()`), `src/Container/RemoteManifestDetector.php`,
  `src/Verifier/{VerificationReport,Verifier}.php` (`remote_manifest`);
  Traceability rows; `notes/step-44-writers-fixes.md`; rows in `NOTES.md`
  and `docs/milestones.md`; this entry.
- Measured: the new tests red (7 of 8; AC13 green at once) and the
  reasons; after the nonce fix the `c2pa-ts` token still failing, then
  `openssl_verify` by hand over five candidate inputs (only the sorted
  SET verifies); `composer check` green, `Tests: 293 passed (3472
  assertions)`; the writers corpus through the front door (the table in
  the note). Two test slips of mine caught by the red run (an unimported
  `Asn1Exception` making `toThrow` read the class name as a message; a
  list destructuring of an associative array). Reasoned: X.690 §11.6's
  ordering rule; RFC 3279's DER form versus `c2pa-ts`'s raw one; the
  DER-safe acceptance rule.
- Decided by Maurice: step 44 with finding 3 included.


## 2026-09-22 — CI green on steps 43–44
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `4679bb7..9047bb4` (three commits) to `origin/main`;
  one wording change in `tests/Fixtures/writers/README.md` (below); this
  entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`,
  visibility `PRIVATE`, tree clean, Deptrac 0 violations (checked by
  name this time); the `PRIVATE KEY` grep over the fixtures hit **one
  file** — the writers README, whose own sentence quoted the grep. No
  key: a search for the PEM header `-----BEGIN … PRIVATE KEY-----` finds
  nothing anywhere under `tests/Fixtures`. The README sentence is
  reworded so the plain grep stays a usable check. Run `35705987729` on
  `9047bb4`: conclusion `success`; `composer check` on PHP 8.3 / 8.4 /
  8.5 each `success` with `Tests: 293 passed` (3470 assertions on 8.3,
  3472 on 8.4/8.5); `all green` `success`.
- Decided by Maurice: push.

## 2026-09-22 — Step 45: light fuzzing
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "heb je nu alle fixtures die je nodig hebt …" (answered in
  conversation: covered / thin / uncovered) and "akkoord, begin met de
  fuzz-stap".
- Produced: `bin/fuzz.php` (PHPStan max, Pint), `notes/step-45-fuzz.md`,
  rows in `NOTES.md` and `docs/milestones.md`; this entry. No change
  under `src/`.
- Measured: three campaigns, 70 870 runs over 101 files (seeds 1–5 ×60,
  11–13 ×80, 100 ×200): 0 faults, 312 `Valid` survivors, each run
  through c2patool 0.27.22 — 312 `Valid`, 0 disagreements; a seed
  replays identically (md5 of the suspect list); where sample survivors'
  flips landed (the `pad` header — 23 665 of 27 070 store bytes in the
  `c2pa-ts` file; the timestamp token in `C.jpg`); peak memory 36 MiB,
  slowest run 0.03 s. One script fault of my own on the first run (the
  store ranges read as a pair instead of `start`/`length`; the `store8`
  kind flipped byte 0 eight times) — fixed before any campaign counted.
  Reasoned: why the survivors are right (the bytes the specification
  leaves uncovered) and the limits of random mutation.
- Decided by Maurice: the fuzz step first.


## 2026-09-22 — CI green on step 45
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `9047bb4..6aece53` (two commits); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean, Deptrac 0 violations. Run `35706917105` on `6aece53`:
  conclusion `success`; `composer check` on PHP 8.3 / 8.4 / 8.5 each
  `success` with `Tests: 293 passed` (3470 assertions on 8.3, 3472 on
  8.4/8.5); `all green` `success`.
- Decided by Maurice: push.

## 2026-09-22 — Step 46: more writers, from Wikimedia Commons
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met meer schrijvers".
- Produced: two files in `tests/Fixtures/writers/` (Pixel 10, public
  domain; Lightroom Classic, CC BY-SA 4.0 with attribution) with
  c2patool's JSON; `tests/Fixtures/trust/google-c2pa-mobile-ica.pem`,
  `google-c2pa-pixel-tsa-ica.pem`, `google-pixel-intermediates.settings.json`
  and c2patool's JSON under them; `SPEC013_WRITERS_*` extended;
  `TimestampCheckTest` AC12; README rows; `notes/step-46-more-writers.md`;
  rows in `NOTES.md` and `docs/milestones.md`; this entry.
- Measured: the Commons API over eight categories (counts in the note),
  36 downloads probed with c2patool (4 with a manifest), licences and
  sha1s from `extmetadata`; c2patool and this verifier on both files with
  and without the Google anchors (equal states and codes; `time`
  byte-equal); the signer and TSA chains of the Pixel file; the
  front-door run of `binding/hard-binding-missing.png` (Invalid for
  `claimSignature.mismatch` only, `checks_performed` without `dataHash`)
  — the hole; `composer check` green, 294 tests. One rate-limit refusal
  from the Commons API after too many quick calls; slowed down and
  identified with a contact address, as their policy asks. Reasoned: the
  hole's consequence (a signed manifest without a hard binding would be
  `Valid`) — to be shown red in step 47 before it is closed.
- Decided by Maurice: start with more writers.

## 2026-09-22 — Step 47: the wrong Valid closed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met stap 47" (and, on the way, "wat is
  hard-binding?" — answered in conversation and in the note).
- Produced: `bin/make-no-hard-binding-variant.php`;
  `tests/Fixtures/binding/no-hard-binding.{png,bin}`,
  `no-hard-binding-root.{pem,settings.json}` (README rows there and under
  `c2patool/variants/`); SPEC-013 AC15 (`VerifierTest`), amendment 10,
  Traceability; the data-hash gate in `src/Verifier/Verifier.php`;
  `SPEC013_SUBSET_ONLY` minus `claim-alg-sha1`;
  `notes/step-47-no-hard-binding.md`; rows in `NOTES.md` and
  `docs/milestones.md`; this entry.
- Measured: the variant built (45 743 bytes; its store parses, the claim
  names two assertions, the signature verifies under the throw-away
  leaf; the key directory deleted, no PEM private-key header under
  `tests/Fixtures`); c2patool on it with and without the root as anchor
  (`Error: claim missing hard binding`, exit 1); this verifier before the
  fix (`Valid` / `Trusted`, `checks_performed` without `dataHash`); AC15
  red on `Valid` ≠ `Invalid`; after the fix `composer check` green with
  295 tests; the first gate's side effects on `hard-bindings-two`
  (narrowed to the mismatch code) and `claim-alg-sha1` (now equal to
  c2patool's failure set); a fuzz replay (seed 100 ×40: 0 faults). One
  script slip caught by its own guard (the JUMBF type read at +8 instead
  of +4). Reasoned: why the hole was invisible to every corpus and to
  fuzzing (no writer omits the binding), and the corpus-policy lesson.
- Decided by Maurice: start step 47.


## 2026-09-22 — CI green on steps 46–47
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `6aece53..97cab87` (three commits); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean, Deptrac 0 violations. Run `35708908130` on `97cab87`:
  conclusion `success`; `composer check` on PHP 8.3 / 8.4 / 8.5 each
  `success` with `Tests: 295 passed` (3502 assertions on 8.3, 3504 on
  8.4/8.5); `all green` `success`.
- Decided by Maurice: push.

## 2026-09-22 — Step 48: the absence audit
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat adviseer je nu te doen?" (answered: the absence audit
  first, then release hygiene, then M7) and "akkoord, begin met de
  afwezigheids-audit".
- Produced: `bin/make-absence-variants.php`; `tests/Fixtures/absence/`
  (four signed variants, the throw-away root and its settings, README);
  `tests/Fixtures/c2patool/absence/` (eight JSONs, README);
  `notes/step-48-absence-audit.md` (the inventory and the findings);
  rows in `NOTES.md` and `docs/milestones.md`; this entry. No change
  under `src/`.
- Measured: every existing variant through the front door, listing
  those whose only failures are the broken signature (all intended-valid
  variants, none an absence); the four new variants built (keys deleted,
  no PEM private-key header under the fixtures) — the first build showed
  `no-actions`/`no-thumbnail` `Invalid` for `assertion.dataHash.mismatch`
  because a removed box shortens the store, hence the re-binding in the
  script; c2patool on each with and without the root (`no-actions`
  `Invalid` — `assertion.action.malformed`; the others `Valid`/`Trusted`
  or both refuse); this verifier on each (`no-actions` `Valid`/`Trusted`);
  c2pa-rs `claim.rs` 0.90.22 `verify_actions` read for the rule and its
  v1 exemption; `composer check` green, 295 tests. One c2patool call of
  mine failed on a quoted argument and was rerun. Reasoned: the
  inventory of gates and the SPEC-018 proposal.
- Decided by Maurice: start the absence audit.

## 2026-09-22 — SPEC-018 as a draft
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-018 als draft".
- Produced: `specs/SPEC-018-actions-assertion.md` (draft, six criteria,
  API sketch); a row in `docs/milestones.md`; this entry.
- Measured: every readable corpus manifest (49) — claim version, actions
  labels, which list holds the assertion and its first action: eight v2
  manifests all open with `created`/`opened`; six v1 manifests carry no
  actions assertion and one (`exp-test1.png`) opens with `c2pa.edited`,
  all accepted by c2patool as v1. Reasoned: the two normative rules
  versus c2pa-rs's content family; the v1 exemption from
  `verify_actions`; the gate for unvouched assertions.
- Decided by Maurice: none yet (the draft awaits approval).

## 2026-09-22 — SPEC-018 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-018 status `approved`; the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing new.
- Decided by Maurice: SPEC-018 approved as drafted.

## 2026-09-22 — Step 49a: the SPEC-018 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 49a".
- Produced: `bin/make-absence-variants.php` extended (two content
  variants, `rehashEntry()`); `tests/Fixtures/absence/actions-first-edited.*`,
  `actions-empty.*` and the regenerated six with their root; c2patool's
  JSON/stderr under `tests/Fixtures/c2patool/absence/`; README rows;
  `tests/Unit/Manifest/ActionsCheckTest.php` (six tests); SPEC-018
  amendment 1 and the `checkAssertions()` seam in its API sketch;
  `notes/step-49-actions-check.md`; rows in `NOTES.md` and
  `docs/milestones.md`; this entry.
- Measured: the six variants built (keys deleted, no PEM private-key
  header under the fixtures); c2patool on each with and without the root
  (`actions-first-edited` `Invalid` on the manifest url; `actions-empty`
  exit 1 "No Action array in Actions"); this verifier `Valid`/`Trusted`
  on both; `vendor/bin/pest --group=SPEC-018` → 6 failed, AC1 on the
  verdict (`Valid` ≠ `Invalid`), the rest on the missing enum case and
  class; the whole suite 295 passed beside them; Pint clean. Reasoned:
  why no signed v1 variant can come from the v2 fixture.
- Decided by Maurice: start 49a.

## 2026-09-22 — Step 49b: ActionsCheck, green
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 49b".
- Produced: `src/Manifest/ActionsCheck.php`; `StatusCode::AssertionActionMalformed`;
  the `actions` step in `src/Verifier/Verifier.php`; SPEC-018 amendment 2,
  Traceability, status `implemented`; nine older tests' `checks_performed`
  literals and the enum count; the 49b section of
  `notes/step-49-actions-check.md`; rows in `NOTES.md` and
  `docs/milestones.md`; this entry.
- Measured: the first run with the class in place — 2 of 6 green; the
  manifest url in c2patool's JSON (the bare label) and two variadic
  `toContain` slips of mine; then `composer check` green with 301 tests;
  the six absence variants and two corpus files by hand (the table in
  the note); a fuzz replay, 0 faults. Reasoned: copying c2patool's
  bare-label url for the drift alarms' sake.
- Decided by Maurice: go on with 49b.


## 2026-09-22 — CI green on steps 48–49
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `97cab87..c4c10c0` (six commits); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean, Deptrac 0 violations. Run `35711271938` on `c4c10c0`:
  conclusion `success`; `composer check` on PHP 8.3 / 8.4 / 8.5 each
  `success` with `Tests: 301 passed` (3616 assertions on 8.3, 3618 on
  8.4/8.5); `all green` `success`.
- Decided by Maurice: push.

## 2026-09-22 — Step 50: release hygiene
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de release-hygiëne".
- Produced: `README.md` (rewritten), `SECURITY.md`, `CONTRIBUTING.md`,
  `CHANGELOG.md`, `docs/comparison.md`, `notes/step-50-release-hygiene.md`;
  rows in `NOTES.md` and `docs/milestones.md`; this entry. No code.
- Measured: a live report of `fixture-signed.jpg` under the full settings
  (the README example); the exception lists in `tests/Pest.php` and
  `VerifierTest` for the comparison's counts (two counts corrected after
  a first draft: 10 official `_MULTI` files, four subset-only variants);
  the milestone dates; `composer check` green, 301 tests; the five files
  searched for local paths and the local instruction file — none.
  Reasoned: the wording of scope, vulnerability and non-vulnerability in
  `SECURITY.md`; the contributor rules as a restatement of the project's
  own.
- Decided by Maurice: start the release hygiene.


## 2026-09-22 — CI green on step 50
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `c4c10c0..d12c5a7` (two commits); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean. Run `35711867418` on `d12c5a7`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 301
  passed`; `all green` `success`.
- Decided by Maurice: push.

## 2026-09-22 — Step 51: the amendment list
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "wat adviseer je nu te doen?" (answered: confirm the amendments,
  then a CLI spec, then the market-scan note, then the public/private
  decision, then M7) and "maak de amendment-lijst".
- Produced: `notes/step-51-amendments-for-confirmation.md` (51 amendments
  over 17 specs, by weight, with the ones Maurice decided at the time
  marked and a confirmation column); SPEC-006 amendment 1 (floats)
  written out — it had been a stub ("step 37 …") since step 37, and the
  list's numbering (AC6/AC7 and two cross-references in SPEC-010 and
  SPEC-013 spoke of amendments 2 and 3) brought in line with the two
  entries that exist; rows in `NOTES.md` and `docs/milestones.md`; this
  entry.
- Measured: the Amendments sections of every spec extracted by script
  (counts per spec in the note's total); `bin/spec-check.php` OK.
  Reasoned: the weight classes and which amendments carry a decision.
- Decided by Maurice: none yet — the page awaits his confirmation.

## 2026-09-22 — The amendments confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "bevestigd, alle drie de groepen".
- Produced: the confirmation column of `notes/step-51-amendments-for-confirmation.md`
  filled and dated; the sixteen group-A amendment lines in nine specs
  stamped "confirmed by Maurice van Loon, 2026-09-22"; the milestones
  row; this entry.
- Measured: `bin/spec-check.php` OK; 16 stamps placed by script, 44
  table rows dated. Reasoned: nothing.
- Decided by Maurice: all 51 amendments confirmed — the six rules he had
  decided at the time and the ten rules made under the measurement
  clause (SPEC-013 #7 the CAWG refusal among them), the fifteen
  report/API changes, the twenty literals.


## 2026-09-22 — CI green on step 51; no NLnet application
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar", then "ik ga geen NLNet-aanvraag doen".
- Produced: pushed `d12c5a7..9c367ed` (three commits); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean. Run `35712457950` on `9c367ed`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success` with `Tests: 301
  passed`; `all green` `success`.
- Decided by Maurice: push; **no NLnet/Restack application** — the
  3 November 2026 deadline no longer binds anything in this repository,
  and "private until after the application" no longer gates going public.

## 2026-09-22 — SPEC-019 drafted: the command line; M7 before public
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-019 als draft"; mid-way, "Ik wil M7 wel
  voordat het publiek gaat".
- Produced: `specs/SPEC-019-command-line.md` (draft: `bin/c2pa-verify
  <file> [--settings <path>]`, `toJson()` + newline on stdout, `Error: …`
  on stderr, exit 0/1/2 by verdict, a `Cli\Command::run()` tested
  in-process, AC1–AC12); the milestones section "After M6"; this entry.
- Measured: c2patool 0.27.22's exit status on ten inputs (`c2patool <file>
  >/dev/null 2>err; rc=$?`): 0 on a Valid *and on an Invalid* report, 1
  with `Error: …` and no JSON on no manifest / no hard binding /
  unsupported type / missing file / unparsable settings, **0 with a
  missing settings file silently ignored**, 2 on usage faults. A first
  measurement that read `$?` after a command substitution said 0 for
  everything and was discarded. Reasoned: that the exit status should
  carry the verdict (0 Trusted/Valid, 1 Invalid, 2 no report) and that an
  unreadable settings file must be a refusal — both departures from
  c2patool, named in the spec; my earlier proposal "exit code as
  c2patool's" was reasoned and wrong, corrected in the spec's Problem.
- Decided by Maurice: SPEC-019 as a draft; **M7 (ingredient manifests)
  before the repository goes public**.

## 2026-09-22 — SPEC-019 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-019 status `approved`, the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing.
- Decided by Maurice: SPEC-019 approved as drafted — the exit status
  carries the verdict (0 / 1 / 2) and an unreadable settings file is a
  refusal, both departures from c2patool.

## 2026-09-22 — Step 52a: the SPEC-019 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 52a".
- Produced: `tests/Unit/Cli/CommandTest.php` (12 tests, AC1–AC12),
  `tests/Fixtures/trust/unknown-key.settings.json` (+ README row),
  `notes/step-52-command-line.md`, the milestones and NOTES rows; this
  entry.
- Measured: `vendor/bin/pest --group=SPEC-019` — 12 failed, all
  `Class "Provemark\C2paVerifier\Cli\Command" not found`; Pint passes;
  PHPStan's three findings on the file are the missing class.
  `fopen()` on a directory succeeds on macOS and the verifier then reads
  nothing (a `fread` notice, "unsupported file type") — the command must
  refuse directories itself (AC6's second case). Reasoned: AC11 uses the
  library, not c2patool's JSON, as its oracle — the report's equality with
  c2patool is SPEC-013's alarm.
- Decided by Maurice: start 52a.

## 2026-09-22 — Step 52b: the command line built; SPEC-019 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 52b".
- Produced: `src/Cli/Command.php`, `bin/c2pa-verify`, `composer.json`
  `bin`, the `Cli` layer in `deptrac.yaml`; SPEC-019 → `implemented`
  (Traceability, amendment 1); `src/Manifest/ManifestStore.php`
  (`claim_generator_info` through `plain()`), SPEC-007 amendment 5 and
  its test; README "From the shell", `docs/comparison.md` (two rows),
  `CHANGELOG.md`; the 52b half of `notes/step-52-command-line.md`, the
  milestones and NOTES rows; this entry.
- Measured: 12 SPEC-019 tests red → green; on the way AC11 found the
  corpus count wrong (two names shared across corpora; keyed by path
  now, 69 files) and then `VerificationReport::toJson()` throwing
  `JsonException` on `writers/openai-20260826-c2pa_2x.png` — a
  `CborBytes` at `claim_generator_info/0/icon/hash`, located with a
  walk over `toArray()`; regression test seen red, then green.
  `composer check` exit 0, 314 tests. Shell runs: Trusted → 0, Invalid →
  1, missing file / missing settings → 2 with `Error: …`. Reasoned:
  `is_dir()` as a guard before `fopen()`; settings read before the file.
- Decided by Maurice: go ahead with 52b.

## 2026-09-22 — Push after step 52; CI red on PHP 8.5, a flaky second
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar", then "begin daarna met de m7 meting".
- Produced: pushed `9c367ed..ded2fcd` (five commits); run 35714422755:
  8.3 and 8.4 `success`, 8.5 `failure` — SPEC-019 AC11 on
  `truepic-20230212-camera`: the `signingCredential.expired` message
  names "now" to the second and the two runs straddled one. AC11 masks
  that timestamp on both sides (`tests/Unit/Cli/CommandTest.php`); the
  note's CI paragraph; this entry.
- Measured: pre-push 0 attribution lines, no `*.key`, no PEM private-key
  header, visibility `PRIVATE`, tree clean; the failing diff read from
  `gh run view --log-failed`; `composer check` exit 0, 314 tests.
  Reasoned: masking one clock-bearing message keeps the rest byte-exact.
- Decided by Maurice: push; the M7 measurement next.

## 2026-09-22 — CI green on step 52
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (the push above).
- Produced: pushed `ded2fcd..92b1825`; this entry.
- Measured: run 35714591867 on `92b1825`: conclusion `success`, `composer
  check` on PHP 8.3 / 8.4 / 8.5 each `success`, 314 passed. Reasoned:
  nothing.
- Decided by Maurice: none.

## 2026-09-22 — Step 53: ingredients measured before M7
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "begin daarna met de m7 meting".
- Produced: `notes/step-53-ingredients-measured.md`, the M7 section in
  `docs/milestones.md`, the NOTES row; this entry. No code.
- Measured: a scratch script over the 18 multi-manifest corpus files with
  this verifier's own parsers — manifest boxes and kinds, every ingredient
  assertion's version/relationship/fields, each manifest reference hashed
  as box payload and as claim CBOR against the hashed URI (12 files legacy,
  6 payload, 1 refused `c2um`, 1 reference not in the store), active =
  last box against every oracle; c2patool's recorded JSON summarised
  (state, active failures, per-delta codes, recorded ingredient
  statuses). Read: C2PA 2.4 (downloaded, §8.4.2.3, §11.2.3, §15.11,
  §15.12, §18.16), c2pa-rs 0.90.22 `store.rs`, `validation_results.rs`,
  `validation_status.rs` (fetched at the tag). Reasoned: the three-spec
  split and the four proposals in the note's §4.
- Decided by Maurice: none yet — the note ends with the proposals.

## 2026-09-22 — SPEC-020 drafted: the ingredient assertion and the manifest graph
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-020 als draft".
- Produced: `specs/SPEC-020-ingredient-assertion-and-manifest-graph.md`
  (draft, AC1–AC10), the milestones row; this entry.
- Measured: the sixteen single-manifest oracle files with ingredient
  assertions all carry `ingredient.unknownProvenance` under
  `ingredientDeltas` (a python pass over `tests/Fixtures/c2patool/`);
  c2patool's `ingredients[]` keys on three oracles (`manifest_data`,
  `label`, `active_manifest`, `validation_status` omitted when empty,
  thumbnail identifiers made absolute against the referring manifest);
  c2patool leaves `c2pa.ingredient*` and `c2pa.thumbnail.ingredient.*`
  out of `assertions[]` where this verifier lists them today. Read:
  c2pa-rs 0.90.22 `assertions/ingredient.rs` (fetched at the tag) —
  the required fields per version, `relationship` an enum, version > 3
  refused, no `digitalSourceType` check. Reasoned: the scope field on
  `ValidationStatus`, the bounds 32/256, following the specification
  (not c2pa-rs) on `activeManifest` + `digitalSourceType`.
- Decided by Maurice: SPEC-020 as a draft; the step-53 proposals
  (three specs, attested failures copied with the guard, redactions
  refused until a fixture, legacy hash accepted, bounds) taken as
  agreed by "akkoord".

## 2026-09-22 — SPEC-020 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-020 status `approved`, the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing.
- Decided by Maurice: SPEC-020 approved as drafted.

## 2026-09-22 — Step 54a: the SPEC-020 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 54a".
- Produced: `bin/make-ingredient-variants.php`, `tests/Fixtures/ingredient/`
  (11 signed variants + throw-away root + README),
  `tests/Fixtures/c2patool/ingredient/` (5 JSON, 6 stderr, README),
  `tests/Unit/Manifest/IngredientAssertionTest.php`,
  `tests/Unit/Manifest/ManifestGraphTest.php`,
  `tests/Unit/Verifier/IngredientDeltasTest.php` (13 tests),
  `notes/step-54-ingredient-assertion-and-graph.md`, the milestones and
  NOTES rows; this entry.
- Measured: every variant `Valid` here today (`bin/c2pa-verify`);
  c2patool on the eleven (exit codes, stderr, JSON — see the note);
  `pest --group=SPEC-020` 13 failed (three substantive); Pint and PHPStan
  on the script clean. The variants were regenerated once and the
  c2patool JSON re-recorded after it. Reasoned: the malformed rules as
  the union of specification and c2pa-rs; the read-vs-skip question on a
  mismatched ingredient assertion, raised for the maintainer.
- Decided by Maurice: start 54a.

## 2026-09-22 — Step 54b: the ingredient graph built; SPEC-020 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 54b".
- Produced: `src/Manifest/{Relationship,IngredientAssertion,ManifestGraph}.php`;
  three `StatusCode` cases, `ValidationStatus::$ingredientUri`,
  `ingredientDeltas` in `ValidationResult::toArray()`, `ingredients` in
  `ManifestStore::toArray()`, the graph in `Verifier`; SPEC-020 →
  `implemented` with Traceability and amendments 1–3; the 54b half of
  `notes/step-54-ingredient-assertion-and-graph.md`, CHANGELOG,
  `docs/comparison.md`, the milestones and NOTES rows; this entry.
- Measured: 13 red → green; `composer check` exit 0, 327 tests; five
  enum drift alarms grown (34 codes). Four findings on real files
  corrected the approved text: the two remote corpus files carry no
  manifest store; c2pa-rs writes `alg: sha256` on its ingredient
  reference; the `E-clm` files keep an unreferenced manifest; c2patool
  omits `manifest_data` for a label not in the store and prints an
  ingredient thumbnail where it lives. c2patool's delta list is a
  subsequence of the walk (it drops statuses the ingredient assertion
  recorded — SPEC-021's subject). Reasoned: the walk as a private static
  method rather than a closure (PHPStan ignores docblocks on closures).
- Decided by Maurice: go ahead with 54b.

## 2026-09-22 — Step 55 and SPEC-021 drafted: validating the ingredient manifests
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "doe eerst validatie van de gevonden manifests".
- Produced: `notes/step-55-ingredient-validation-measured.md`,
  `specs/SPEC-021-ingredient-validation.md` (draft, AC1–AC10), the
  milestones and NOTES rows; this entry. No code.
- Measured: two scratch scripts over the seventeen readable
  multi-manifest files — six references hash the manifest box payload,
  eleven the claim's CBOR (legacy; c2patool stays silent there); this
  verifier's existing checks on every referenced manifest, then the
  dropping of what the ingredient assertion recorded, give c2patool's
  delta failure codes on 14 of 17 and its `validation_state` on 16 of 18
  (the two: `signingCredential.expired` from the named TSA leniency);
  the delta contents of four oracles read code by code; `openssl verify`
  on the cawg chain, which showed an apparent leniency to be a settings
  artefact (the writers oracles were recorded without settings).
- Decided by Maurice: validate the found manifests before pushing —
  SPEC-021 first, the push after.

## 2026-09-22 — SPEC-021 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-021 status `approved`, the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing.
- Decided by Maurice: SPEC-021 approved as drafted — the ingredient
  manifests validated, what the ingredient assertion recorded dropped
  with the active-manifest guard, redactions refused until a fixture,
  SPEC-013 amendment 5 lifted.

## 2026-09-22 — Step 56a: the SPEC-021 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 56a".
- Produced: `bin/make-ingredient-manifest-variants.php`,
  `tests/Fixtures/ingredient-manifest/` (3 variants + throw-away root +
  settings + README), `tests/Fixtures/c2patool/ingredient-manifest/`
  (3 JSON + README), `tests/Unit/Verifier/IngredientManifestCheckTest.php`
  (9 tests), `notes/step-56-ingredient-validation.md`, the milestones and
  NOTES rows; this entry.
- Measured: c2patool on the three variants (the broken ingredient
  signature is reported *next to* `ingredient.manifest.validated`; the
  recorded `ingredient.unknownProvenance` naming the active manifest is
  not dropped; the redacting claim is `assertion.selfRedacted`);
  `pest --group=SPEC-021` 7 failed, 2 passed — the two were already true
  (the redaction refusal lives in `HashedUriCheck` since SPEC-011, and
  the data hash runs on the active manifest only). The JPEG rebuild was
  wrong at first (the 4-byte packet sequence number skipped) and produced
  a file c2patool read as "No claim found"; a byte-exact round-trip
  assertion now guards it. Reasoned: the hash-mismatch case through the
  seam (no writer ships one), AC5 tested at the seam and on the file.
- Decided by Maurice: start 56a.

## 2026-09-22 — Step 56b: the ingredient manifests validated; SPEC-021 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 56b".
- Produced: `src/Verifier/IngredientManifestCheck.php`, two `StatusCode`
  cases, `ingredients` in `checks_performed`, the multi-manifest refusal
  removed from `Verifier`; SPEC-021 → `implemented` (Traceability,
  amendments 1–3), SPEC-013 amendment 11 (amendment 5 lifted); five
  existing criteria updated (SPEC-013 AC11/AC12, SPEC-017's helper, the
  enum count, SPEC-020 AC6); the 56b half of the note, CHANGELOG,
  `docs/comparison.md`, the milestones and NOTES rows; this entry.
- Measured: 7 red → 9 green; `composer check` exit 0, 336 tests;
  `bin/fuzz.php 20260922 3` — 312 runs, 0 faults, one survivor that
  c2patool also calls `Trusted`. Seventeen multi-manifest files are
  measured instead of refused; sixteen verdicts equal c2patool's, the
  two others (`ocsp`, `ocsp_with_assertion`) by the named TSA leniency.
  Two approved test literals were wrong and were corrected against the
  files. Reasoned: the check belongs in the Verifier layer (Deptrac).
- Decided by Maurice: go ahead with 56b.

## 2026-09-22 — CI green on steps 53–56 (M7's first two specs)
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `92b1825..76ef774` (ten commits: the CI record of step
  52, step 53's measurement, SPEC-020 draft/approve/red/green, step 55's
  measurement, SPEC-021 draft/approve/red/green); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean. Run `35724724999` on `76ef774`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success`, 336 passed;
  `all green` `success`. Reasoned: nothing.
- Decided by Maurice: push.

## 2026-09-22 — SPEC-022 drafted: update manifests
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-022 als draft".
- Produced: `specs/SPEC-022-update-manifests.md` (draft, AC1–AC9), the
  milestones row; this entry. No code.
- Measured: `c2pa-rs/update_manifest.jpg` read by hand (the parser
  refuses the `c2um` box): two manifests — the parent `c2ma` with seven
  assertions and a `c2pa.hash.data` excluding `{start 9964, length
  18874}`, and the active `c2um` (claim v2) with `c2pa.actions.v2`
  (`c2pa.opened`, `c2pa.edited.metadata`), `c2pa.time-stamp` and one
  `parentOf` ingredient; the store's range in the file is `{9964,
  43607}`, so the parent's exclusion is stale by 24 733 bytes — the
  §15.12.1.1 adjustment is the crux. c2patool: `Trusted`, with
  `assertion.dataHash.match` under the **active** manifest and one delta
  (`ingredient.manifest.validated`, url ending `/c2pa.claim`). The
  parent's `claim_generator_info` is `[]`, which this verifier refuses
  today. Read: C2PA 2.4 §11.2.3, §11.2.5, §15.11.2.2, §15.12, §15.12.1.1;
  c2pa-rs `claim.rs` (`ALLOWED_UPDATE_MANIFEST_ACTIONS`,
  `verify_internal`) and `store.rs` (`get_hash_binding_manifest`).
  Reasoned: the codes per broken rule, the adjustment's bounds (the
  cover rule still applies afterwards, so it can never hide bytes
  outside the store).
- Decided by Maurice: SPEC-022 as a draft.

## 2026-09-22 — SPEC-022 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-022 status `approved`, the milestones row; this entry.
- Measured: `bin/spec-check.php` OK. Reasoned: nothing.
- Decided by Maurice: SPEC-022 approved as drafted — `c2um` read, the
  §11.2.3 rules, the binding through the `parentOf` chain and the
  §15.12.1.1 exclusion adjustment with the cover rule kept over it.

## 2026-09-22 — Step 57a: the SPEC-022 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "begin met 57a".
- Produced: `bin/make-update-manifest-variants.php`,
  `tests/Fixtures/update-manifest/` (6 variants + throw-away root +
  settings + README), `tests/Fixtures/c2patool/update-manifest/`
  (3 JSON, 3 stderr, README),
  `tests/Unit/Verifier/UpdateManifestTest.php` (9 tests),
  `notes/step-57-update-manifests.md`, the milestones and NOTES rows;
  this entry.
- Measured: c2patool on the six variants — `manifest.update.invalid` for
  a disallowed action, `assertion.dataHash.mismatch` for a byte outside
  the store, `manifest.multipleParents` for two parents, and three hard
  exits without JSON ("assertion missing", "claim missing hard binding"
  ×2); this verifier says `general.error` on the five JPEG variants (the
  `c2um` refusal) and **`Trusted`** on the two-parent PNG — a leniency
  AC6 closes. `pest --group=SPEC-022`: 9 failed, each for its own
  reason. The APP11 writer asserts a byte-exact round trip before
  writing. Reasoned: AC4's fourth rule and AC4(c)'s relationship value
  are tested differently than the approved text says (the claim's CBOR
  uses indefinite lengths) — both for the amendment list.
- Decided by Maurice: start 57a.

## 2026-09-22 — Step 57b: update manifests; SPEC-022 implemented, M7 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 57b".
- Produced: `src/Manifest/UpdateManifestCheck.php`; `c2um` read and
  `c2tm` refused in `JumbfParser`; `Manifest::$isUpdateManifest`; the
  §15.12.1.1 adjustment in `DataHashCheck`; three `StatusCode` cases; the
  update exemption in `ActionsCheck`; the store-wide drop set in
  `IngredientManifestCheck`/`Verifier`; SPEC-022 → `implemented`
  (Traceability, amendments 1–5) and amendments in SPEC-005, SPEC-018
  (#3) and SPEC-021 (#4); the 57b half of the note, CHANGELOG,
  `docs/comparison.md`, `tests/Pest.php`, five existing criteria, the
  milestones and NOTES rows; this entry.
- Measured: 9 red → green; `composer check` exit 0, 345 tests;
  `bin/fuzz.php 20260922 2` — 208 runs, 0 faults. Four findings on the
  files: c2patool calls `hash-in-update` `Trusted` because c2pa-rs's rule
  for it is unreachable (we are stricter, by the specification and named);
  SPEC-018's update exemption existed only on paper; an empty
  `claim_generator_info` is rendered by c2patool and so kept; the drop
  set must be store-wide (one delta against our three). A near-miss:
  removing `update_manifest` from a list took it out of
  `SPEC013_RS_CORPUS` itself — the CLI's file count (68 against 69)
  caught it.
- Decided by Maurice: go ahead with 57b.

## 2026-09-22 — CI green on step 57: M7 complete
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `76ef774..3b69165` (five commits: the CI record of
  step 56, SPEC-022 draft/approve/red/green); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean. Run `35727857709` on `3b69165`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success`, 345 passed;
  `all green` `success`. Reasoned: nothing.
- Decided by Maurice: push.

## 2026-09-22 — Step 58: the amendment list since step 51
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak de amendment-lijst".
- Produced: `notes/step-58-amendments-for-confirmation.md` (seventeen
  amendments over eight specs, one line each, sorted by weight, with the
  confirmation column empty); SPEC-005's amendment given a proper
  `## Amendments` section and a number; the milestones and NOTES rows;
  this entry.
- Measured: the amendment counts per spec read out of the files
  (`awk` over each `## Amendments` section); `bin/spec-check.php` OK.
  Reasoned: the weights (A: six, B: two, C: nine) and the two lines
  flagged for a second look — SPEC-022 #2 (stricter than c2patool on a
  rule c2patool has but cannot reach) and SPEC-013 #11 (eighteen
  verdicts move).
- Decided by Maurice: none yet — the page is for his confirmation.

## 2026-09-22 — The seventeen amendments confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "bevestigd, alle drie de groepen".
- Produced: the confirmation column of
  `notes/step-58-amendments-for-confirmation.md` filled and dated, with a
  Confirmation section naming the two flagged lines; the seven group-A
  amendment lines in SPEC-005, SPEC-013, SPEC-018, SPEC-021 and SPEC-022
  stamped "confirmed by Maurice van Loon, 2026-09-22"; the milestones and
  NOTES rows; this entry.
- Measured: `bin/spec-check.php` OK; 18 table rows dated, 7 stamps
  placed by script. Reasoned: nothing.
- Decided by Maurice: all seventeen amendments confirmed — among them
  that a store with more than one manifest is no longer refused
  (SPEC-013 #11), that `c2um` is read and `c2tm` refused (SPEC-005),
  that the opening rule spares an update manifest (SPEC-018 #3), that a
  hash assertion in an update manifest stays **stricter than c2patool**
  (SPEC-022 #2), that an empty `claim_generator_info` is read
  (SPEC-022 #4), and that the drop set is store-wide (SPEC-021 #4).

## 2026-09-22 — CI green on step 58; the amendment round closed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `3b69165..c4b4ed8` (three commits: the CI record of
  step 57, the amendment list, its confirmation); this entry.
- Measured: before the push, 0 attribution lines, no tracked `*.key`, no
  PEM private-key header under the fixtures, visibility `PRIVATE`, tree
  clean. Run `35728766450` on `c4b4ed8`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success`, 345 passed;
  `all green` `success`. Reasoned: nothing.
- Decided by Maurice: push. Open, and his alone: whether the repository
  goes public now that M7 is done, and whether M8 (ISOBMFF) comes first.

## 2026-09-22 — Step 59: the coverage matrix
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Ik wil eerst zeker weten dat deze lib werkt op alle bestanden
  dat het aankan" — then "akkoord, begin met de matrix".
- Produced: `bin/make-matrix-fixtures.php`, `tests/Fixtures/matrix/`
  (23 signed files + the test-root settings + README),
  `tests/Fixtures/c2patool/matrix/` (46 oracle JSONs + README),
  `tests/Unit/Verifier/MatrixTest.php` (AC16–AC18, the fifth drift
  alarm), SPEC-013 amendment 12, `notes/step-59-coverage-matrix.md`, the
  milestones and NOTES rows; this entry.
- Measured: first what the fixtures cover — over the four corpora, 50
  files: jpeg 45 / png 4 / webp 1, Es256 11 / Es384 1 / Ps256 38, sha256
  in all fifty; over every fixture directory, 165 files: Es512, Ps384,
  Ps512 and Ed25519 in **no file at all**, sha512 in none. Then the
  matrix: 21 files signed with c2patool 0.27.22 and c2pa-rs's test
  certificates (pinned to `c2pa-v0.90.22`, keys deleted at the end of
  the run), plus `es256-sha384.png` and `es256-sha512.png` made by
  surgery because c2patool writes sha256 into the claim whatever the
  signature algorithm is. All 23: `Trusted` with the roots, `Valid`
  untrusted without settings, state, failure codes, `signature_info.alg`
  and certificate serial equal to c2patool's. `composer check` exit 0,
  348 tests. Reasoned: thumbnails off (100 KB → 15 KB per file); the
  gaps that remain (one oracle, no timestamp and no claim v1 in the
  matrix) named in the note rather than papered over.
- Decided by Maurice: the matrix first, before the public decision.

## 2026-09-22 — The matrix finds a verdict that depended on the PHP version
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (the push of step 59; CI answered).
- Produced: pushed `c4b4ed8..a1d2a5e`; CI run 35730308123 red on **PHP
  8.3 only**; the cause found and fixed in `src/Trust/Certificate.php`
  (`keyFacts()` reads the Ed25519 OID out of the SPKI), SPEC-015
  amendment 5, a regression test (AC11 in
  `tests/Unit/Trust/CertificateProfileCheckTest.php`), the note's fifth
  section, CHANGELOG, `docs/comparison.md`, the milestones and NOTES
  rows; this entry.
- Measured: `matrix/ed25519.jpg` `Invalid` on PHP 8.3 against c2patool's
  `Trusted`; reproduced locally with php@8.3 —
  `signingCredential.invalid: key of type other (256 bits)` while the
  signature itself verified (`claimSignature.validated`). After the fix,
  PHP 8.3 gives `Trusted`, the same as 8.4/8.5. Whole suite on both:
  349 passed on 8.5 and on 8.3. Reasoned: the bug has been there since
  SPEC-015 (M5) and no fixture could see it, because no file carried an
  Ed25519 signature until step 59.
- Decided by Maurice: none.

## 2026-09-22 — CI green again on step 59
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: (the fix above).
- Produced: pushed `a1d2a5e..e14ccf4`; this entry.
- Measured: run `35730903562` on `e14ccf4`: conclusion `success`;
  `composer check` on PHP 8.3 / 8.4 / 8.5 each `success`, 349 passed —
  the first run where PHP 8.3 agrees with the other two on every
  Ed25519 file. Reasoned: nothing.
- Decided by Maurice: none.

## 2026-09-22 — Step 60: the absence audit for M7
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de afwezigheids-audit".
- Produced: `bin/make-m7-absence-variants.php` (this project's first
  two-manifest store builder), `tests/Fixtures/m7-absence/` (4 stores +
  both-roots settings + throw-away root + README),
  `tests/Fixtures/c2patool/m7-absence/` (4 oracles + README),
  `tests/Unit/Verifier/M7AbsenceTest.php` (4 tests),
  `notes/step-60-m7-absence-audit.md`, two rows in
  `docs/comparison.md`, the milestones and NOTES rows; this entry.
- Measured: the inventory of every gate M7 added (13 rows), three of
  which had no file; the four stores through this verifier and c2patool
  with the same settings — control `Trusted` both, `ingredient-no-actions`
  `Invalid` with `assertion.action.malformed` both, `unreferenced-broken`
  `Trusted` both (its signature proven broken by checking it directly),
  `no-claim-signature` `Trusted` both. `composer check` exit 0, 353
  tests. Two build lessons recorded: the fixture's claim names its
  signature box absolutely (so a relabelled copy must be re-signed), and
  the copy must drop its thumbnail or the store passes 65535 bytes and
  the exclusion can no longer be a two-byte CBOR integer. Reasoned: no
  code of our own for an unreferenced manifest (§15 has none, and the
  project invents none) — it is named in `docs/comparison.md` instead.
- Decided by Maurice: run the absence audit.

## 2026-09-22 — CI green on step 60
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: pushed `5a89dc1..6268cbb` (the absence audit for M7); this
  entry.
- Measured: before the push, 0 attribution lines, no tracked key or PEM
  private-key header, visibility `PRIVATE`, tree clean. Run
  `35735714668` on `6268cbb`: conclusion `success`; `composer check` on
  PHP 8.3 / 8.4 / 8.5 each `success`, 353 passed. Reasoned: nothing.
- Decided by Maurice: push.

## 2026-09-22 — Step 61: a second, independent oracle
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de Go-verifier als tweede oracle".
- Produced: `tools/go-oracle/` (a dozen lines around `c2pa.Validate`,
  its go.mod/go.sum and a README), `notes/step-61-second-oracle.md`, a
  new section in `docs/comparison.md`, the milestones and NOTES rows;
  this entry. No product code changed.
- Measured: 257 files (92 corpus + matrix, 165 variants) through
  `richardwooding/c2pa` v0.22.0 in a `golang:1.26` container, each with
  the same trust anchors this verifier was given. Results: the Go
  verifier reports `assertion.dataHash.mismatch` on
  `c2pa-rs/update_manifest.jpg`, where hashing the file both ways shows
  the recorded digest matches the exclusion **adjusted** to the store's
  current range (C2PA 2.4 §15.12.1.1) — a false `Invalid` of theirs;
  thirteen files this verifier refuses are accepted by both other
  implementations (strictness already named in SPEC-001/002/003/005/007);
  two profile variants (`no-digital-signature`, `eku-c2pa`) are refused
  by the Go verifier and `Trusted` here and at c2patool, both by the
  maintainer's step-33 decision to mirror c2pa-rs. **No file where
  another implementation found a fault this verifier missed.** A first
  run showed ten more differences that were nothing but
  `signingCredential.untrusted` from passing the wrong anchors file;
  re-run per family, they disappeared. Reasoned: that the Go mismatch is
  a bug rather than a reading — the digest decides.
- Decided by Maurice: run the second oracle. Open for him: whether to
  report the finding to that project.

## 2026-09-22 — CI on step 61
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push the second-oracle step and the CI record of
  step 60.
- Produced: pushed `3dd5346` and `6018ecd` to `origin/main`. Before the
  push, `tools/go-oracle/` turned out to be untracked: `.gitignore`
  ignored all of `tools/`, a rule written for the downloaded `c2patool`
  binary, while `notes/step-61-second-oracle.md` and `docs/comparison.md`
  already pointed at the directory. The rule is now `tools/*` with
  `!tools/go-oracle/`, so the binary stays ignored and the four source
  files (~4.5 KB) are in the step-61 commit where the note can reach
  them.
- Measured: the pre-push checks — attribution 0, repository PRIVATE,
  tree clean; the private-key grep returned one hit, read and found to be
  prose *about* the check (`AI-LOG.md` and a removed line of the writers
  README), not key material. CI run 35738537014 on `6018ecd`: success on
  PHP 8.3, 8.4 and 8.5, 353 tests. Reasoned: nothing.
- Decided by Maurice: push. Open for him: whether the second-oracle
  finding goes to that project, and the public decision.

## 2026-09-22 — Step 62, public-readiness
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Ik wil eerst zeker weten dat de repo netjes is voor public" —
  establish that the repository is fit to be seen before it is, and then
  "akkoord, begin met stap 62" on the three repairs the audit named.
- Produced: `README.md` and `SECURITY.md` corrected (M0–M7, ingredient
  manifests moved from the not-verified list to the verified one, the
  corpus numbers re-counted, the second oracle named); `.gitattributes`
  added; the repository description and nine topics set;
  `notes/step-62-public-readiness.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: six secret patterns over the whole history, 0 each; local
  paths in tracked files, 0; `CLAUDE.md` referenced once, in `.gitignore`;
  dead relative links in tracked markdown, 0; `TODO`/`FIXME`/`XXX`/`HACK`,
  0; secrets in CI, none; `php bin/spec-check.php` OK on 23 specs and 28
  test files; the dist `git archive --format=tar HEAD | wc -c` 62.9 MB
  before and 1.9 MB with `--worktree-attributes` after, `tests/` being
  63 440 kB of it against `src/` 500 kB; the five corpora hold 96 distinct
  files, counted by path; `composer check` exit 0, 353 tests, 7176
  assertions. Reasoned: which paths still belong in the dist — everything
  the README links to, so the package stays self-describing.
- Decided by Maurice: run the audit before any visibility change, and
  carry out the three repairs. Open for him: a `CODE_OF_CONDUCT.md`; a
  spec for the published package, without which the `.gitattributes`
  guard cannot be a test; and the visibility change itself.

## 2026-09-22 — CI on step 62
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push the public-readiness step.
- Produced: pushed `8d4d826` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35739984051 on `8d4d826`:
  success on PHP 8.3, 8.4 and 8.5, 353 tests. Reasoned: nothing.
- Decided by Maurice: push. Open for him: SPEC-023 (the published
  package), a code of conduct, and the visibility change.

## 2026-09-22 — SPEC-023 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-023 als draft" — after asking what such a
  spec would contain, the maintainer approved drafting it.
- Produced: `specs/SPEC-023-published-package.md` (status `draft`, six
  acceptance criteria), a line in `docs/milestones.md`.
- Measured: before writing AC4, whether it is satisfiable at all — the
  archive holds 96 markdown files and 0 relative links pointing outside
  it, so the package is self-contained today; and that no shipped
  markdown links into `tests/`. `php bin/spec-check.php`: OK, 24 specs,
  28 test files, SPEC-023 reported `draft`. Reasoned: the three failure
  modes in the Problem section — a new top-level directory, a dist that
  is small but broken, and a package that stops describing itself; the
  second is why AC6 exists, since a suite running against the working
  tree cannot see a dist that lost a file.
- Decided by Maurice: that the spec should be written, with the scope
  proposed (AC1–AC6, publishing decisions left out). Open for him: the
  two blocking questions in the spec — whether a test may shell out to
  `git` for the real archive, and whether `specs/` and `notes/` ship at
  all, which decides what AC4 and AC5 mean.

## 2026-09-22 — SPEC-023's two blocking questions answered
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en ja op allebei" — push the draft and answer both
  blocking open questions with yes.
- Produced: pushed `ce28eb2`; recorded both decisions in place in
  `specs/SPEC-023-published-package.md` and in `docs/milestones.md`.
- Measured: `php bin/spec-check.php` OK, 24 specs, SPEC-023 still
  `draft`. Reasoned: nothing — this entry records decisions, not
  findings.
- Decided by Maurice: (1) a test in SPEC-023's group may invoke `git`,
  because only `git archive` produces the archive Composer fetches and
  re-implementing it would be a second truth; no other test may, and
  nothing in `src/` ever may. (2) `docs/`, `specs/`, `notes/` and
  `AI-LOG.md` ship with the package, so AC4 and AC5 stand as written.
  Still open: approval of the spec itself.

## 2026-09-22 — SPEC-023 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-023-published-package.md` status `draft` →
  `approved`, the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 24 specs, SPEC-023 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-023. Implementation may now begin,
  tests first.

## 2026-09-22 — Step 63a, the SPEC-023 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 63a" — the red phase of SPEC-023.
- Produced: `tests/Unit/PackageTest.php` (13 tests, group SPEC-023),
  `tests/Fixtures/package-check/` with four trees and a README,
  `ext-phar` in `require-dev`, `notes/step-63-published-package.md`.
- Measured: the first red was a single fatal on the missing
  `bin/package-check.php`, and it aborted the entire run — the other 353
  tests stopped reporting; with the `require_once` guarded by
  `is_file()`, 13 failed and 353 passed (7176 assertions), each failure
  naming its own missing function. `php bin/spec-check.php` OK, 24 specs,
  29 test files. Pint passed. PHPStan: 83 errors, all in the new test
  file, all following from the undefined functions — red on purpose, as
  the first milestone's CI was red on SPEC-001's missing classes.
  `composer validate` valid. Reasoned: that "shipped" cannot mean "not
  export-ignore" — under that reading a directory added later ships by
  default and AC1's finding is impossible, so the shipped set is
  declared and the two statements are checked against each other.
- Decided by Maurice: approval of SPEC-023 and both its blocking
  questions. Open for him: the AC3 ceiling (4 MB against 1.9 measured)
  and reading the tar with `PharData`, both carried into 63b as proposed.

## 2026-09-22 — Step 63b, bin/package-check.php, green
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 63b" — make the thirteen SPEC-023 tests
  green.
- Produced: `bin/package-check.php`; SPEC-023 amendment 1 with AC6
  rewritten, the Traceability table filled and the status set to
  `implemented`; `fetch-depth: 0` in `.github/workflows/ci.yml`; the
  second half of `notes/step-63-published-package.md`; `NOTES.md`,
  `docs/milestones.md`.
- Measured: `composer check` exit 0, 366 passed (7220 assertions), Pint
  and PHPStan level max clean; `php bin/package-check.php` prints "13
  shipped, 9 export-ignore" and "dist: 192 files, 1.9 MB", exit 0; the
  archive of the commit before `.gitattributes` is 62.9 MB with 760
  entries under `tests/`, and both findings fire on it. Reasoned: that
  AC6 as approved could not pass — the shim needs an autoloader, so the
  criterion had to describe Composer's layout; and that building the
  autoloader from the archive's own `autoload.psr-4` map tests the
  declaration against the shipped files, where a generated rival
  autoloader would pass even with a wrong map.
- Decided by Maurice: approval of SPEC-023 and both its blocking
  questions, earlier today. The AC6 amendment is written into the spec
  and awaits his confirmation with the next amendment round.

## 2026-09-22 — CI on step 63
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push the SPEC-023 approval, the red tests and the
  implementation.
- Produced: pushed `3c05617`, `3320284` and `baea083` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35742362853 on `baea083`:
  success on PHP 8.3, 8.4 and 8.5, 366 passed. The run was also the test
  of `fetch-depth: 0`: AC3's historical archive really ran there
  (`✓ it AC3: the dist as it would have shipped before .gitattributes`,
  0.17s on each version) rather than skipping itself, which is what a
  depth-1 checkout would have caused. Reasoned: the assertion count is
  7220 on 8.4 and 8.5 against 7218 on 8.3 — two assertions that a
  version-conditional test does not reach on 8.3; unchanged by this step
  and not investigated here.
- Decided by Maurice: push. Open for him: confirmation of SPEC-023
  amendment 1 at the next amendment round, and the visibility change.

## 2026-09-22 — Step 64, the amendment list since step 58
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak de amendment-lijst".
- Produced: `notes/step-64-amendments-since-58.md`, `NOTES.md`,
  `docs/milestones.md`.
- Measured: every `## Amendments` section of every spec, parsed —
  71 amendments in all, of which 51 were confirmed at step 51 and 17 at
  step 58, leaving exactly 3 new ones (SPEC-013 #12, SPEC-015 #5,
  SPEC-023 #1). The arithmetic is the check that none was missed; a
  first parse had missed four whose heading wraps across two lines, and
  the total would not have added up. Reasoned: the weight of each — one
  group A (a verdict changed on PHP 8.3), none in B, two in C.
- Decided by Maurice: nothing yet; the list awaits his confirmation.

## 2026-09-22 — Step 65, mutation testing
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Ik wil echt heel zeker weten dat alles goed en netjes is" —
  from which four gaps were named and the maintainer chose the first:
  "akkoord, begin met de mutatietesten", then "akkoord, begin met 65b".
- Produced: two tests (`tests/Unit/Cose/SignatureVerifierTest.php` — the
  EMSA-PSS trailer byte; `tests/Unit/Manifest/ManifestGraphTest.php` — a
  cycle followed by a real reference), the redundant Ed25519 branch
  removed from `src/Trust/Certificate.php`, Traceability rows in
  SPEC-009 and SPEC-020, `notes/step-65-mutation-testing.md`, `NOTES.md`,
  `docs/milestones.md`.
- Measured: `pest --mutate --everything --covered-only` over `src/`:
  4 547 killed, 90 untested, 3 timeouts, score **98.06%**, 935 s. Each
  new test was seen red by applying its own escaped mutation by hand and
  restoring it. Re-measured, `RsaPss` and `ManifestGraph` are at 100%.
  `composer check` exit 0, 368 passed on PHP 8.5 and on 8.3 locally.
  Infection was installed and removed again: it asks the test binary for
  the PHPUnit version, gets Pest, guesses "before 9.3" and writes a
  `<filter><whitelist>` block PHPUnit 12 rejects.
- Reasoned, and corrected by measurement: the first reading of the
  trailer-byte gap was that `hash_equals` would catch it anyway. It does
  not — changing only that byte leaves every later step agreeing, and
  `RsaPss::verify()` returns `true` for an encoding RFC 8017 §9.1.2 step
  4 rejects. Not a forgery route (the signature is the RSA operation over
  the whole encoded message), but an acceptance this verifier should not
  make. The test, not the reading, settled it.
- Decided by Maurice: run mutation testing, then 65b. Open for him: the
  eight cross-file test helpers that block `--parallel`, which need a
  step of their own by hand.

## 2026-09-22 — CI on steps 64 and 65
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push the amendment list and the mutation-testing
  step.
- Produced: pushed `d4fc838` and `5d3f3ad` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35748494988: success on PHP
  8.3, 8.4 and 8.5, 368 passed. The 8.4 job is the point of this run: it
  is the only place PHP reports `ed25519` key details, so it is what
  proves the branch removed in step 65b was truly redundant —
  `AC18: every matrix file is Trusted with the roots and untrusted
  without them` passes there, and the matrix carries Ed25519 in all
  three containers. Both new tests pass on all three versions.
  Reasoned: nothing.
- Decided by Maurice: push. Open for him: confirmation of the three
  amendments in step 64, the eight cross-file test helpers that block
  `--parallel`, and the visibility change.

## 2026-09-22 — Step 66, the resource audit
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de resource-audit" — the second of the four
  gaps named when the maintainer said he wanted to be certain the library
  is sound before it is seen.
- Produced: `notes/step-66-resource-audit.md`, `NOTES.md`,
  `docs/milestones.md`. Nothing in `src/` was changed.
- Measured (PHP 8.5, peak = `memory_get_peak_usage(true)`): the corpus
  costs 6–15 MB and 1–38 ms; a 256 MB asset costs 6.0 MB and 667 ms, so
  `c2pa.hash.data` streams; APP11 reassembly is linear (128/512/960
  pieces → 4/10/18 ms). Manifest stores: 8 MiB → 22 MB, 32 MiB → 70 MB,
  63 MiB → 132 MB and a **PHP fatal error** under `memory_limit=128M`
  inside `PngManifestStoreExtractor.php:121`, 64 MiB → refused by the
  bound at 6 MB. The same 60 MiB store as JPEG costs 66 MB, because the
  PNG path concatenates the chunk twice and again for the CRC. Store
  sizes across 212 corpus files: median 45 kB, p90 241 kB, largest
  3.36 MB.
- Reasoned: that a fatal error is not failing closed — it cannot be
  caught, the caller gets no status code, and the length that would have
  allowed a cheap refusal is declared in the chunk header before the read.
- Decided by Maurice: to run the audit. Open for him: the PNG double
  copy (no rule change), lowering the default bound from 64 MiB (a rule
  change in SPEC-001, SPEC-002, SPEC-003), and whether the bound should
  be relative to the host's memory limit rather than absolute.

## 2026-09-22 — SPEC-024 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf die spec als draft" — after the resource audit
  named three follow-ups, two of which change rules.
- Produced: `specs/SPEC-024-resource-bounds.md` (status `draft`, five
  acceptance criteria), a line in `docs/milestones.md`.
- Measured: nothing new; the spec rests on step 66's numbers, which are
  cited in it with the commands that produced them. `php
  bin/spec-check.php`: OK, 25 specs, SPEC-023 `implemented`, SPEC-024
  `draft`. Reasoned: that the PNG double copy belongs outside this spec
  because it changes no rule, and that the spec's numbers must assume it
  has not happened yet.
- Decided by Maurice: that the spec should be written. Open for him: the
  two blocking questions — the new default bound (16 MiB proposed against
  a largest-ever-seen store of 3.36 MB), and whether reading
  `ini_get('memory_limit')` is acceptable at all, since it would make the
  same file `Invalid` on one host and `Trusted` on another.

## 2026-09-22 — SPEC-024's two blocking questions answered
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en ja op allebei".
- Produced: pushed `3b9d7de` and `88e941c`; both decisions recorded in
  place in `specs/SPEC-024-resource-bounds.md` and in
  `docs/milestones.md`.
- Measured: `php bin/spec-check.php` OK, 25 specs, SPEC-024 still
  `draft`. Reasoned: the first question was put as a choice ("16 MiB, or
  lower?") rather than a yes/no, so "ja" is read as agreement with the
  proposed 16 MiB; the reading is written into the spec beside the
  decision, and reversing it costs one constant and one test literal.
- Decided by Maurice: (1) the default bound becomes 16 MiB — 4.8× the
  largest store measured in 212 corpus files and 350× the median. (2)
  `ini_get('memory_limit')` is read, accepting that the same file can be
  refused on a small host and read on a large one; AC2's explanation must
  therefore say that the file was not judged at all, so that a refusal is
  never mistaken for a verdict about its content. Still open: approval of
  the spec itself.

## 2026-09-22 — SPEC-024 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-024-resource-bounds.md` status `draft` →
  `approved`, the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 25 specs, SPEC-024 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-024. Implementation may now begin,
  tests first.

## 2026-09-22 — Step 67a, the SPEC-024 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 67a" — the red phase of SPEC-024.
- Produced: `tests/Unit/Container/ResourceBoundsTest.php` (6 tests, group
  SPEC-024), `tests/Support/verify-probe.php`,
  `notes/step-67-resource-bounds.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: Pest 5 failed, 369 passed (7279 assertions); Pint, PHPStan
  level max and `bin/spec-check.php` (25 specs, 30 test files) all clean,
  because every symbol the tests name already exists and only holds the
  wrong value. The harness had to be corrected once: a process killed by
  `memory_limit` does not print nothing — PHP writes its fatal-error text
  to stdout on the CLI, so two tests failed with `JsonException` instead
  of their own assertion; "died" is now "no line that parses as JSON".
- Reasoned: that the hostile stores should be generated rather than
  committed (a block of `0x41` is not evidence, and the corpus already
  weighs 63 MB), and that AC4 is green from birth — it is a regression
  alarm whose job starts the day an ordinary file becomes expensive, and
  that is written in the note rather than left to look like a pass.
- Decided by Maurice: approval of SPEC-024 and both its blocking
  questions. Open for him: the `DEFAULT_SHARE` of remaining memory, which
  67b must measure rather than assume.

## 2026-09-22 — Step 67b, SPEC-024 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 67b".
- Produced: `src/Support/MemoryBudget.php`; the bound lowered to 16 MiB
  and a budget check added in all three extractors; amendments in
  SPEC-001 (#4), SPEC-002 (#2) and SPEC-003 (#2); SPEC-024's Traceability
  filled and status set to `implemented`; the second half of
  `notes/step-67-resource-bounds.md`; `NOTES.md`, `docs/milestones.md`.
- Measured: the peak curve the share rests on — a store of 4 MiB peaks at
  14.0 MB, 8 MiB at 22.0 MB, 16 MiB at 38.0 MB, so peak is about twice
  the store plus six megabytes; `DEFAULT_SHARE = 0.25` therefore leaves
  about half the limit unused at the largest permitted size, and 16 MiB
  is survivable on a 64 MB host. Step 66's scenario repeated: the 63 MiB
  store that ended a 128 MB process now returns `Invalid` at 6.0 MB and
  2 ms, and does the same on 32 MB; a 15 MiB store is refused on 32 MB
  but read on 512 MB (36.0 MB, 38 ms). `composer check` exit 0, 374
  passed (7292 assertions).
- Reasoned: that null from `parseLimit()` must mean *no restriction* and
  never *no memory*, so that a configuration we failed to parse cannot
  become a reason to refuse valid files — which is what AC3 pins.
- Decided by Maurice: approval of SPEC-024, the 16 MiB bound and reading
  `memory_limit`. Open for him: confirmation of the three new amendments,
  which now stand at six awaiting a round.

## 2026-09-22 — CI on step 67
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push SPEC-024's approval, red tests and
  implementation.
- Produced: pushed `bebd14c`, `5541e40` and `4689dd0` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35751049349: success on PHP 8.3,
  8.4 and 8.5, 374 passed. The subprocess probe works on the runners as
  it does locally: `AC2: a store that does not fit the host is refused,
  and the process survives` passes on all three, which is the test that
  spawns a PHP process with `memory_limit=32M` and requires it to come
  back with a report rather than die. Reasoned: nothing.
- Decided by Maurice: push. Open for him: six amendments now await a
  confirmation round (three from step 64, three from step 67b), the
  cross-file test helpers that block `--parallel`, the PNG double copy,
  the public API surface, and the visibility change.

## 2026-09-22 — Step 68, the amendment list since step 58
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maak de amendment-lijst".
- Produced: `notes/step-68-amendments-since-58.md`, replacing step 64's
  page, which was never confirmed and covered only three of the six;
  step 64's note now carries a superseded banner. `NOTES.md`,
  `docs/milestones.md`.
- Measured: every `## Amendments` section of every spec, parsed — 74 in
  all, of which 51 were confirmed at step 51 and 17 at step 58, leaving
  exactly 6 (SPEC-001 #4, SPEC-002 #2, SPEC-003 #2, SPEC-013 #12,
  SPEC-015 #5, SPEC-023 #1). The arithmetic is the check that none was
  missed. Reasoned: their weight — two in group A, none in B, two
  entries in C; and that the three bound amendments are one decision, so
  they are one row.
- Decided by Maurice: nothing yet; the list awaits his confirmation.

## 2026-09-22 — The six amendments confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "bevestigd, alle zes".
- Produced: the confirmation dated on
  `notes/step-68-amendments-since-58.md` and stamped into the four
  group-A amendment lines themselves (SPEC-001 #4, SPEC-002 #2,
  SPEC-003 #2, SPEC-015 #5), as the procedure of steps 51 and 58
  requires; `docs/milestones.md`.
- Measured: `php bin/spec-check.php` OK, 25 specs. Reasoned: nothing.
- Decided by Maurice: all six amendments confirmed, the three flagged
  lines included — the store bound stays at 16 MiB with the
  host-relative refusal, and the key's kind stays read from the
  SubjectPublicKeyInfo OID on every PHP version. Seventy-four amendments
  are now confirmed, none outstanding.

## 2026-09-22 — Step 69, the API surface
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "begin daarna met het API-oppervlak" — the third of the four
  gaps named when the maintainer asked to be certain before this is seen.
- Produced: `notes/step-69-api-surface.md`, `NOTES.md`,
  `docs/milestones.md`. Nothing in `src/` was changed.
- Measured, by reflection over every class in `src/`: 69 public classes,
  interfaces and enums; 192 public methods, 137 public constants, 202
  public properties — **600 public symbols**, none carrying `@internal`.
  The README's example names two classes and four accessors; the CLI uses
  five classes. Drawing the contract at what a caller needs gives 99
  symbols, sixteen per cent. Eight exception types exist and SPEC-013
  turns seven of them into statuses before the public boundary; only
  `TrustException` escapes, from `TrustSettings::fromJson()`.
- Reasoned: that `VerificationReport::$store` re-admits the `Manifest`,
  `Jumbf` and `Cbor` layers to the contract through one property, so
  drawing a line at 99 symbols means deciding what that property is;
  and that `@internal` is documentation rather than enforcement, which
  is the right weight for a library whose layers are deliberately
  testable on their own.
- Decided by Maurice: to run the audit. Open for him: naming the
  contract, marking the rest `@internal`, a snapshot test of the public
  surface, and whether a first tag is `0.1.0` or `1.0.0`.

## 2026-09-22 — SPEC-025 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-025 als draft, en $store wordt
  @internal".
- Produced: `specs/SPEC-025-public-api.md` (status `draft`, five
  acceptance criteria), a line in `docs/milestones.md`.
- Measured: nothing new; the spec rests on step 69's reflection numbers,
  cited in it. `php bin/spec-check.php`: OK, 26 specs, SPEC-025 `draft`.
- Reasoned: that marking `$store` `@internal` takes nothing away — the
  property stays, it works, and the docblock says what it is; what ends
  is an implied support the project never gave. And that the version
  number of a first tag is not a property of the code, so it is out of
  scope: this spec makes either choice honest without making it.
- Decided by Maurice: that `VerificationReport::$store` is `@internal`,
  so the parse model is not frozen by a tag. Open for him: the seven
  layer exceptions a caller never meets (proposed `@internal`, with
  `TrustException` in the contract, which is the only one that escapes),
  where the contract is written, whether `Cli\Command` belongs in it, and
  the version number of the first tag.

## 2026-09-22 — SPEC-025's blocking question answered
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en ja op de zeven exceptions".
- Produced: pushed `1606c4f` and `caf44c8`; the decision recorded in
  place in `specs/SPEC-025-public-api.md`, with AC1's list grown from
  eight classes to nine; `docs/milestones.md`.
- Measured: `php bin/spec-check.php` OK, 26 specs, SPEC-025 still
  `draft`. Reasoned: nothing beyond what the decision records.
- Decided by Maurice: the seven layer exceptions
  (`Asn1Exception`, `CborException`, `ContainerException`,
  `CoseException`, `JumbfException`, `ManifestException`,
  `TimestampException`) are `@internal`, because SPEC-013 turns each into
  a status before the public boundary and no caller can meet them;
  `TrustException` joins the contract as the ninth class, being the one
  that escapes, from `TrustSettings::fromJson()`. Still open: approval of
  the spec, where the contract is written, whether `Cli\Command` belongs
  in it, and the version number of the first tag.

## 2026-09-22 — SPEC-025 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-025-public-api.md` status `draft` → `approved`,
  the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 26 specs, SPEC-025 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-025. Implementation may now begin,
  tests first.

## 2026-09-22 — Step 70a, the SPEC-025 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 70a" — the red phase of SPEC-025.
- Produced: `tests/Unit/ApiSurfaceTest.php` (7 tests, group SPEC-025),
  `tests/Fixtures/api/public-surface.txt` (91 lines),
  `notes/step-70-public-api.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: Pest 7 failed, 374 passed (7294 assertions), each failure
  naming its own missing function except AC3 and AC5, which fail on the
  missing `@internal` and the missing README section; PHPStan 27 errors,
  all following from the four undefined functions; Pint and
  `bin/spec-check.php` (26 specs, 31 test files) clean. The recorded
  surface was generated from today's code: 91 symbols across the nine
  contract classes, against the 600 the library exposes.
- Reasoned: that the snapshot belongs in a file rather than in an array
  inside the test, so that a change to a promise appears as a diff
  somebody reads instead of an assertion somebody edits; and that a red
  AC2 should assert on the count with three names in the message rather
  than print sixty findings, because a wall of output teaches nothing.
- Decided by Maurice: approval of SPEC-025, `$store` as `@internal`, and
  the seven exceptions as `@internal` with `TrustException` in the
  contract. Open for him: where the contract text lives, whether
  `Cli\Command` belongs in it, and the version number of the first tag.

## 2026-09-22 — Step 70b, SPEC-025 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 70b".
- Produced: `bin/api-check.php`; `@internal` on 60 classes in `src/` and
  on `VerificationReport::$store` with wording; a `## Public API` section
  in the README; SPEC-025 amendment 1, Traceability filled, status
  `implemented`; the second half of `notes/step-70-public-api.md`;
  `NOTES.md`, `docs/milestones.md`.
- Measured: `php bin/api-check.php` reports 69 public classes, 9 in the
  contract, 60 marked, 91 symbols recorded, no findings. `composer check`
  exit 0, 381 passed (7338 assertions). AC4 was shown rather than
  asserted: a public `reset()` added to `Verifier` by hand made AC1 fail
  and the checker name it (`Verifier\Verifier :: method reset: public but
  not recorded`); reverted, seven green.
- Reasoned: that AC3's "does not document `$store`" was the wrong rule —
  a reader whose IDE offers the property needs to be told it is
  unsupported, not left to infer it from silence. Amended in the spec
  before the test was changed. Also recorded: Pest's `toContain()` is
  variadic, so a second argument is another needle rather than a message
  (the eighth time here), and a README sentence wrapped across two lines
  is not the string a test asserts on.
- Decided by Maurice: approval of SPEC-025, `$store` as `@internal`, and
  the seven exceptions as `@internal`. Open for him: where the contract
  text lives long-term, whether `Cli\Command` belongs in it, the version
  number of a first tag, and confirmation of amendment 1.

## 2026-09-22 — CI on step 70
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push SPEC-025's approval, red tests and
  implementation.
- Produced: pushed `3ef70d4`, `7ec0a38` and `7f81575` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35752965247: success on PHP 8.3,
  8.4 and 8.5, 381 passed. Reasoned: nothing.
- Decided by Maurice: push. Open for him: confirmation of SPEC-025
  amendment 1, the version number of a first tag, where the contract text
  lives long-term, whether `Cli\Command` belongs in it, and the
  visibility change.

## 2026-09-22 — Step 71, the conformance suite
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de conformance-suite" — the last of the four
  gaps named when the maintainer asked to be certain.
- Produced: `notes/step-71-conformance-suite.md`; a correction to step
  43's note; `NOTES.md`, `docs/milestones.md`. Nothing in `src/`
  changed, and the suite is not vendored.
- Measured: `encypherai/c2pa-conformance-suite` at e2feae1 (2026-09-17)
  carries an **Apache-2.0** licence, where step 43's table said none was
  declared. It is a third independent implementation — its own container
  extractor, JUMBF/CBOR parser and crypto verification in Python — not a
  rubric. Run without a trust store: `fixture-signed.png`,
  `fixture-signed.webp`, `adobe-20220124-C.jpg` and OpenAI's file get
  `claimSignature.validated`; `fixture-signed.jpg` and `c2pa-rs/CA.jpg`
  get `claimSignature.missing` plus `dataHash.mismatch`, on files
  c2patool 0.27.22, this verifier and the Go implementation all accept.
  Its extraction of our JPEG is 94740 bytes, byte-count identical to
  ours, so the divergence is downstream and container-specific. Its
  coverage on our files is thin: 128 of 150 predicates skipped on the
  JPEG. Its catalogue holds 150 predicates formalising 237 normative
  C2PA 2.4 rules; 101 apply to JPEG/PNG/WebP (ASSE 27, CRYP 25, STRU 19,
  INGR 8, CONT 7, CROSS 6, IMG 4, TIME 4, TRUS 1).
- Reasoned: that three independent readings against one settles who is
  wrong here, and that their empty failure messages make the JPEG path a
  job for its authors rather than a question about ours. Also, reading
  `PRED-ASSE-009` led to a stale message of our own: `HashedUriCheck`
  tells a user redactions are "not supported before M7", and M7 closed in
  step 57 — and `ManifestGraph::$redactedAssertions` may be unreachable,
  because a claim declaring redactions is refused before the graph is
  walked.
- Decided by Maurice: to run the suite. Open for him: mapping the 101
  applicable predicates against what this verifier checks, and the stale
  redaction message with the question it raises.

## 2026-09-22 — CI on step 71
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push the conformance-suite step.
- Produced: pushed `81faefa` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35753874479: success on PHP 8.3,
  8.4 and 8.5, 381 passed. Reasoned: nothing; the step changed no code.
- Decided by Maurice: push. Open for him: mapping the 101 applicable
  predicates against this verifier's checks, the stale redaction message
  and the question it raises about `ManifestGraph::$redactedAssertions`,
  confirmation of SPEC-025 amendment 1, the version number of a first
  tag, and the visibility change.

## 2026-09-22 — Step 72, the redaction message
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met de redactie-vraag".
- Produced: the explanation and docblock in `src/Hash/HashedUriCheck.php`
  restated; the assertion in `tests/Unit/Hash/HashedUriCheckTest.php`
  that pinned `M7` replaced by two that pin the reason;
  `notes/step-72-redaction.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: 2 of 174 corpus files with a manifest store carry a non-empty
  `redacted_assertions`, and one of those is a tampered variant of our
  own. On the signed one, `ingredient-manifest/redacted.png`, both this
  verifier and c2patool 0.27.22 say `Invalid` — we with `general.error`,
  c2patool with `assertion.selfRedacted` and `assertion.action.redacted`,
  neither of which exists in this project's `StatusCode`. The redacted
  URI points at `c2pa.actions.v2` in the same manifest, so the file is
  both a self-redaction and an action redaction. And
  `ManifestGraph::fromStore()` on that file returns
  `redactedAssertions: 1` — the field is reachable. `composer check`
  exit 0, 381 passed.
- Reasoned: nothing was wrong with the rule, which SPEC-021 and
  `docs/comparison.md` record as the maintainer's decision with its real
  reason; what was wrong was a runtime message naming a milestone that
  had closed, which reads as "nearly over" when the refusal is
  deliberate. A test asserting `M7` was how that survived.
- Decided by Maurice: to look at the redaction question. Open for him:
  the same items as before, minus this one.

## 2026-09-22 — CI on step 72
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar" — push the redaction-message step.
- Produced: pushed `37cc37c` to `origin/main`.
- Measured: the pre-push checks — attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean. CI run 35754515668: success on PHP 8.3,
  8.4 and 8.5, 381 passed. Reasoned: nothing.
- Decided by Maurice: push. Open for him: confirmation of SPEC-025
  amendment 1, the version number of a first tag, and the visibility
  change — the technical list from "I want to be certain this is sound"
  is now empty.

## 2026-09-22 — Step 73, ISOBMFF measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "begin met m8".
- Produced: `tests/Fixtures/fixture-unsigned.mp4` and
  `fixture-signed.mp4` with `tests/Fixtures/c2patool/mp4.json`;
  `notes/step-73-isobmff-fixture.md`; entries in `tests/Fixtures/README.md`,
  `NOTES.md` and `docs/milestones.md`. Nothing in `src/` changed.
- Measured: the signed MP4 carries its manifest store in one top-level
  `uuid` box (`d8fec3d6-1b0e-483c-9297-5828877ec481`) with twenty-one
  bytes between the UUID and the JUMBF — four of version and flags, the
  null-terminated purpose `manifest`, and an eight-byte `merkle_offset`
  of zero. Peeling those off, `JumbfParser` and `ManifestStore` read it
  **unchanged**: 1 manifest, claim v2, `c2pa.actions.v2` and
  `c2pa.hash.bmff.v3`. That assertion's exclusions are box paths
  (`/ftyp`, `/mfra`, `/free`, `/skip`) plus `/uuid` matched on its bytes
  at offset 8 against the C2PA UUID — not byte ranges. c2patool 0.27.22
  says `Valid` with `assertion.bmffHash.match`; this verifier says
  `Invalid` with `unsupported file type`, naming the magic bytes.
- Reasoned: M8's first slice is the same shape as SPEC-001/002/003 and
  the smallest of the four, because every layer above the container
  already works; the BMFF hash is a separate algorithm rather than a
  variation of SPEC-012, and needs its own spec. Two corrections to the
  brief: the label is `c2pa.hash.bmff.v3`, and Merkle belongs to
  fragmented files rather than to the first path.
- Decided by Maurice: to begin M8. Open for him: approving the two specs
  that follow, in that order.

## 2026-09-22 — SPEC-026 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en schrijf daarna de eerste spec als draft".
- Produced: pushed `1e84eef`; `specs/SPEC-026-isobmff-extraction.md`
  (status `draft`, nine acceptance criteria), a line in
  `docs/milestones.md`.
- Measured: nothing new; the spec rests on step 73's measurements, cited
  in it with the byte offsets. `php bin/spec-check.php`: OK, 27 specs,
  SPEC-026 `draft`.
- Reasoned: that a `merkle` purpose must be refused by name rather than
  ignored, because ignoring it would make a fragmented file look like one
  with no Content Credentials; and that the oracle here cannot be a
  byte-exact store offset, since c2patool prints none for BMFF — so AC1
  compares the manifest the store yields against c2patool's
  `active_manifest` instead.
- Decided by Maurice: to begin M8 and to have this spec written. Open for
  him: approval; and four non-blocking questions — the `format` value
  (`isobmff` proposed), which `ftyp` brands are accepted (any, proposed),
  and the two literal amendments this forces in SPEC-024 AC1 and
  SPEC-013 AC6, both of which say "three containers".

## 2026-09-22 — SPEC-026 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-026-isobmff-extraction.md` status `draft` →
  `approved`, the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 27 specs, SPEC-026 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-026. Implementation may now begin,
  tests first.

## 2026-09-22 — Step 74a, the SPEC-026 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 74a" — the red phase of SPEC-026.
- Produced: `tests/Unit/Container/IsobmffManifestStoreExtractorTest.php`
  (9 tests, group SPEC-026), `bin/make-isobmff-variants.php` and ten
  variants under `tests/Fixtures/isobmff/` with a README,
  `tests/Fixtures/c2patool/isobmff/`, `fixture-unsigned.avif` and
  `fixture-signed.avif` with `c2patool/avif.json`,
  `notes/step-74-isobmff-tests.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: AVIF, which AC9 required to be measured rather than assumed —
  signed with the same test certificates, it is the same container in
  every respect (`ftyp`, then the C2PA `uuid` box at offset 32 with the
  same twenty-one-byte preamble, purpose `manifest`, merkle 0, a 13 534-
  byte store), the existing stack reads it into one claim v2 manifest
  with `c2pa.hash.bmff.v3`, and c2patool says `Valid` with
  `assertion.bmffHash.match`. No amendment was needed. c2patool over the
  ten variants: it agrees on seven, ignores an unknown purpose as "no
  claim found", and reads `size == 0` on a non-last box anyway. Pest 9
  failed / 381 passed; PHPStan 25 errors, all from the unknown class;
  Pint and `bin/spec-check.php` (27 specs, 32 test files) clean.
- Reasoned: that the two divergences are the right kind of strictness — a
  box that announces itself as C2PA and then says something unreadable is
  not a file without credentials, and a box claiming "to the end of the
  file" while something follows is a contradiction a reader must not
  resolve quietly. Also why these variants are committed where SPEC-024's
  were generated: the bytes here are evidence somebody can open.
- Decided by Maurice: approval of SPEC-026. Open for him: its four
  non-blocking questions, which 74b answers in code.

## 2026-09-22 — Step 74b, SPEC-026 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 74b".
- Produced: `src/Container/IsobmffManifestStoreExtractor.php`; `isobmff`
  in `FormatDetector` and a fourth arm in `Verifier`; SPEC-026 amendment
  1, Traceability filled with the measured store SHA-256, status
  `implemented`; SPEC-024 amendment 1 and its test widened to four
  containers; `docs/comparison.md` and the README; the second half of
  `notes/step-74-isobmff-tests.md`; `NOTES.md`, `docs/milestones.md`.
- Measured: `composer check` exit 0, 390 passed (7376 assertions).
  `bin/c2pa-verify` on the signed MP4: `Invalid`, `format: isobmff`,
  `has_manifest: true`, with `the hard binding c2pa.hash.bmff.v3 is not
  supported yet` — read, then refused by name. The store's SHA-256 is
  `58b8f2cce9f90ccb41239a8428c723a427862eb58670e8ca803dbd1a6f4a3a82`.
- Reasoned, and corrected by the implementation: AC7 asked to refuse a
  `size == 0` box that is not the last, and that case cannot occur —
  the declaration is what makes a box the last one. Amendment 1 says so
  and names the cost: such a box can swallow its successors and nothing
  in the container betrays it; the hard binding is what catches it, which
  is why c2patool answers `Invalid` there rather than refusing to parse.
  Also recorded: `StreamReader` is sequential and the first draft passed
  absolute offsets to it, which walked into the middle of a box; and
  PHPStan, not a test, is what forced the wiring decision into the open
  (`Match expression does not handle remaining value: 'isobmff'`).
  SPEC-024 AC1's test named three constants and could not see a fourth —
  a test that enumerates what it knows cannot notice what it does not.
- Decided by Maurice: approval of SPEC-026. Open for him: confirmation of
  three amendments now (SPEC-025 #1, SPEC-026 #1, SPEC-024 #1), and the
  BMFF hash spec.

## 2026-09-22 — CI on step 74, and the three amendments confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en bevestig alle drie de amendementen".
- Produced: pushed `aeb9525` and `ecf3cc9`;
  `notes/step-75-amendments-since-68.md`; the confirmation stamped into
  SPEC-024 #1, SPEC-025 #1 and SPEC-026 #1; `NOTES.md`,
  `docs/milestones.md`.
- Measured: every `## Amendments` section parsed — 77 in all, of which 74
  were confirmed by step 68, leaving exactly the three now confirmed.
  Pre-push checks: attribution 0, private-key patterns 0, repository
  PRIVATE, tree clean.
- Reasoned: that this is the first round with no group A and no group B —
  not one of the three changes a verdict — and that SPEC-024 #1 is worth
  the maintainer's eye anyway, because it exists only because a drift
  alarm that enumerates three constants could not see a fourth container
  arrive. PHPStan noticed instead, on an unhandled `match` arm. An alarm
  that lists what it knows about is blind to arrivals.
- Decided by Maurice: all three amendments confirmed. Open for him: the
  BMFF hash spec, the version number of a first tag, and the visibility
  change.

## 2026-09-22 — CI on steps 74 and 75
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the push that carried step 74 and the confirmations.
- Produced: pushed `ecf3cc9` and `0b493f8` to `origin/main`.
- Measured: CI run 35763727228: success on PHP 8.3, 8.4 and 8.5, 390
  passed — the first CI run in which this verifier reads an ISOBMFF file.
  Reasoned: nothing.
- Decided by Maurice: push, and all three amendments confirmed. Open for
  him: the BMFF hash spec, the version number of a first tag, and the
  visibility change.

## 2026-09-22 — Step 76, the BMFF hash, unfinished
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf de bmff-hash-spec als draft".
- Produced: `notes/step-76-bmff-hash-unfinished.md`, `NOTES.md`,
  `docs/milestones.md`. **The spec was not written**, and nothing in
  `src/` changed.
- Measured: c2pa-rs read at `sdk/src/assertions/bmff_hash.rs` and
  `sdk/src/asset_handlers/bmff_io.rs` — `bmff_to_jumbf_exclusions()`
  turns box-path exclusions into flat `(start, length)` ranges, filtered
  by the optional `length`, `version`, `flags`/`exact` and `data`
  (compared at an offset relative to the box start) and narrowed by
  `subset`; then `hash_stream_by_alg(..., bmff_v2 = true)`, the same
  machinery the data hash uses, which additionally feeds each excluded
  range's start offset into the digest as a big-endian `u64`
  (`hasher.update(&start.to_be_bytes())`). Six attempts to reproduce the
  stored hash of `fixture-signed.mp4` (`87d4d42c438fe632766d…`) all
  failed, including all 64 subsets of its five top-level boxes; the three
  exclusion ranges resolve to ftyp (0, 32), the C2PA box (32, 13578) and
  free (14555, 8).
- Reasoned: that the gap is either the marker placement rule, the
  exclusion set, or special handling of `mdat` in v3 — and that the way
  to settle it is to instrument c2pa-rs for this one fixture rather than
  read more of it, as step 61 learned with the Go verifier. Also that a
  criterion which cannot yet be measured must not be written: three specs
  here have already been amended because code showed a criterion wrong,
  and this is the case that comes before that.
- Decided by Maurice: to have the spec drafted — which is exactly what
  did not happen, and he is told why rather than handed a spec resting on
  a reconstruction.

## 2026-09-22 — Step 77, the BMFF hash reproduced
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en doe de instrumentatie".
- Produced: pushed `26eec33`; `notes/step-77-bmff-hash-reproduced.md`,
  `NOTES.md`, `docs/milestones.md`. Nothing in `src/` changed.
- Measured: c2pa-rs v0.90.22 built in a container with two `eprintln!`
  lines patched into its hashing loop, run against this repository's own
  `fixture-signed.mp4` and `fixture-signed.avif`. It prints a marker at
  13610 and 14563 for the MP4 and at 13611 and 13846 for the AVIF —
  which are exactly the offsets of the top-level boxes no exclusion
  matches (`moov`, `mdat`; `meta`, `mdat`). Recomputing by hand as
  `u64be(offset) || box bytes` per included top-level box reproduces both
  stored hashes exactly: `87d4d42c438fe632766d…` and
  `81955e02ee8dee9c5305…`.
- Reasoned: that the offsets are what binds position as well as content,
  which is why the excluded C2PA box is safe to exclude. Also that the
  first patch printed nothing because it matched only one of two
  identical loops — an instrument that silently measures nothing looks
  like one that measures zero. Two cases remain unmeasured and belong in
  the spec as open questions: a file whose first top-level box is
  included, and a nested exclusion path such as `/moov/trak`.
- Decided by Maurice: to instrument rather than read on. Open for him:
  the spec, which can now be written.

## 2026-09-22 — SPEC-027 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en schrijf de spec".
- Produced: pushed `ea32750`; `specs/SPEC-027-bmff-hash.md` (status
  `draft`, seven acceptance criteria), a line in `docs/milestones.md`.
- Measured: nothing new; the spec rests on step 77's reproduction and
  step 73's exclusion list, both cited with their numbers. `php
  bin/spec-check.php`: OK, 28 specs, SPEC-027 `draft`.
- Reasoned: that AC3 — a box that moved is a mismatch even when its bytes
  did not — is the criterion the offset markers exist for, and without it
  the marker code could be deleted and every other test would still pass.
  And that the filters `c2pa-rs` supports but no fixture here exercises
  (`length`, `version`, `flags`, `subset`, nested paths, `merkle`) must be
  **refused by name** rather than implemented: ignoring a filter would
  compute a digest over the wrong bytes and call the result a match,
  which is the one outcome this project refuses above all others, and
  implementing one against no fixture is an untested branch that looks
  tested.
- Decided by Maurice: to instrument, and then to have the spec written.
  Open for him: approval, and the blocking question — a file whose first
  top-level box is included, which neither fixture has and on which the
  two readings of the marker rule differ.

## 2026-09-22 — SPEC-027 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-027-bmff-hash.md` status `draft` → `approved`,
  the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 28 specs, SPEC-027 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-027. Implementation may now begin,
  tests first — and the red phase must build a file whose first top-level
  box is included, because that is what tells the measured marker rule
  apart from its alternative.

## 2026-09-22 — Step 78a, the SPEC-027 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 78a" — the red phase of SPEC-027.
- Produced: `tests/Unit/Hash/BmffHashCheckTest.php` (7 tests, group
  SPEC-027), `bin/make-bmff-variants.php` with three variants under
  `tests/Fixtures/bmff/` and their c2patool JSON,
  `notes/step-78-bmff-hash-tests.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: the spec's blocking question. `ftyp` must be the first box of
  a valid ISOBMFF file and `/ftyp` is in every exclusion list c2patool
  writes, so no file it produces can have an included first box. Replacing
  the four bytes `ftyp` inside the assertion's exclusion path with `zzzz`
  — every CBOR length unchanged — leaves an exclusion matching no box, and
  the instrumented c2pa-rs of step 77 then printed `marker offset=0`
  followed by `range 0..=31`. So a marker precedes **every** included
  top-level box, the first included. AC1 and AC3 stand; no amendment.
  c2patool on the three variants: `assertion.bmffHash.mismatch` on each,
  with `assertion.hashedURI.mismatch` beside it on `xpath-nested`. Pest 7
  failed / 390 passed; PHPStan 21 errors from the missing class and the
  missing status code; Pint and spec-check clean.
- Reasoned: that `box-moved` is the criterion the marker code exists for —
  delete the markers and every other criterion here still passes — which
  is why the test asserts the hashed bytes are byte-for-byte identical
  before it asserts anything about a verdict. And that `Hash` is the only
  layer without an exception of its own, so 78b adds `HashException`
  marked `@internal`, named here rather than appearing in a diff.
- Decided by Maurice: approval of SPEC-027. Open for him: nothing new;
  78b implements.

## 2026-09-22 — Step 78b, SPEC-027 implemented
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 78b".
- Produced: `src/Hash/BmffHashCheck.php`, `src/Hash/HashException.php`,
  two `StatusCode` cases, `topLevelBoxes()` on the ISOBMFF extractor, the
  dispatch in `Verifier`; SPEC-027 amendment 1, Traceability filled,
  status `implemented`; the recorded API surface, the enum count and two
  success lists updated; `docs/comparison.md`; the second half of
  `notes/step-78-bmff-hash-tests.md`; `NOTES.md`, `docs/milestones.md`.
- Measured: `composer check` exit 0, 397 passed (7427 assertions). The
  MP4 and the AVIF are `Trusted` with `bmffHash` in `checks_performed`;
  the PNG is unchanged with `dataHash`. Three alarms fired and all three
  were right: the SPEC-025 snapshot (91 → 93 symbols, a two-line diff
  naming exactly the two new codes), SPEC-015's enum count (39 → 41), and
  two exhaustive success lists in existing tests.
- Reasoned: that `Hash → Container` already existed in Deptrac, so the
  box walk could be exposed on the extractor rather than duplicated —
  SPEC-027's open question 3 answered without a new arrow. Two bugs of
  mine were found by the tests: the stream was at EOF when the check ran
  (one `rewind()`), and the oracle helper read `validation_status` where
  c2patool had used `validation_results` — a failure that looked like a
  verifier bug and was a test bug.
- Decided by Maurice: approval of SPEC-027. Open for him: confirmation of
  amendment 1 (weight B, the first since step 75), and what comes after
  M8.

## 2026-09-22 — The SPEC-027 amendment confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en bevestig het amendement".
- Produced: pushed `76551da` and `45d480f`;
  `notes/step-79-amendment-since-75.md`; the confirmation stamped into
  SPEC-027 #1; `NOTES.md`, `docs/milestones.md`.
- Measured: the amendment count across every spec — 78, of which 77 were
  confirmed by step 75, leaving the one now confirmed. Pre-push checks:
  attribution 0, private-key patterns 0, repository PRIVATE, tree clean.
- Reasoned: that this is the first group-B amendment in a while, and what
  it costs a caller is worth writing down rather than leaving implicit —
  a consumer keying off `dataHash` sees `bmffHash` on ISOBMFF files,
  which before SPEC-026 were refused outright, and no image report
  changes at all.
- Decided by Maurice: the amendment confirmed. Open for him: what comes
  after M8, the version number of a first tag, and the visibility change.

## 2026-09-22 — CI on steps 78 and 79
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the push that carried step 78 and the confirmation.
- Produced: pushed `45d480f` and `2cc98b6` to `origin/main`.
- Measured: CI run 35767885230: success on PHP 8.3, 8.4 and 8.5, 397
  passed — the first CI run in which this verifier checks a hard binding
  on video. Reasoned: nothing.
- Decided by Maurice: push, and the amendment confirmed. Open for him:
  what comes after M8, the version number of a first tag, and the
  visibility change.

## 2026-09-22 — Step 80, M8 is not done
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "maar is M8 nu af?", and then "waarom ging je dan al verder?".
- Produced: `notes/step-80-m8-is-not-done.md`; corrections in
  `README.md`, `docs/comparison.md` and `docs/milestones.md`;
  `NOTES.md`. Nothing in `src/` changed; 397 tests still pass.
- Measured: a MOV signed with c2patool 0.27.22 and the test certificates
  verifies here as `Trusted` with `bmffHash` in `checks_performed`, and
  c2patool calls the same file `Valid` — measured **after** the claim had
  been written, in a scratch directory, with no fixture or test to hold
  it.
- Reasoned, and it is a correction of my own conduct: SPEC-027 being
  implemented was treated as M8 being done. They are different, and M8's
  description names Merkle trees and exclusions that are not built. And
  MOV was written into three tracked files — the README and the
  comparison table among them — on the strength of ISOBMFF covering it in
  principle. That the guess held is luck; a claim that happens to be true
  is indistinguishable in the record from one that was checked. The
  project's own rule (measured ≠ reasoned, and say which) was not applied
  to a sentence about formats because it did not feel like a measurement,
  and it was one.
- Decided by Maurice: to ask. Open for him: a MOV fixture and HEIC
  measured (needing amendments to SPEC-026 AC9 and SPEC-027 AC1, whose
  literals say "the two fixtures"), then a fragmented fixture and the
  Merkle spec.

## 2026-09-22 — Step 80b, MOV and HEIC made into fixtures
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en leg MOV en HEIC vast".
- Produced: pushed `ad1e427`; `fixture-{un,}signed.mov` and
  `fixture-{un,}signed.heic` with `c2patool/mov.json` and `heic.json`;
  SPEC-026 amendment 2 (AC9 covers AVIF, MOV and HEIC, and gains the rule
  that a flavour named anywhere must be a fixture here) and SPEC-027
  amendment 2 (AC1 covers four files); the two tests widened; the README,
  `docs/comparison.md`, `tests/Fixtures/README.md`, `NOTES.md` and
  `docs/milestones.md`.
- Measured: all four ISOBMFF flavours are `Trusted` here and `Valid` at
  c2patool 0.27.22, with the hard binding checked in each.
  `composer check` exit 0, 397 passed (7443 assertions). HEIC had to be
  made — no repository this project can reach holds one — with macOS
  `sips` from this repository's own `fixture-unsigned.png`, so nothing
  third-party enters; that it works at all was not known until it was
  tried.
- Reasoned: that the rule missing from AC9 is the one worth writing down,
  because the failure it prevents is not a bug in the verifier but a
  sentence in the README that outruns the fixtures beside it.
- Decided by Maurice: to record MOV and HEIC. Open for him: confirmation
  of the two amendments, and the fragmented case, which is all that stands
  between M8 and closed.

## 2026-09-22 — The two amendments confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en bevestig de twee amendementen".
- Produced: pushed `97b6985`; `notes/step-81-amendments-since-79.md`; the
  confirmation stamped into SPEC-026 #2 and SPEC-027 #2; `NOTES.md`,
  `docs/milestones.md`.
- Measured: the amendment count across every spec — 80, of which 78 were
  confirmed by step 79. Pre-push checks: attribution 0, private-key
  patterns 0, repository PRIVATE, tree clean.
- Reasoned: that SPEC-026 #2 is the first amendment here written because
  of something *said* rather than something built. Every earlier one
  corrected a criterion measurement showed wrong; this one corrects a
  criterion that was too narrow to stop a sentence. Its new rule is about
  the record rather than the code, which is the only kind that would have
  caught it, because what went wrong was not a bug.
- Decided by Maurice: both amendments confirmed. Open for him: the
  fragmented BMFF case, which is all that stands between M8 and closed.

## 2026-09-22 — CI on steps 80 and 81
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the push that carried step 80b and the confirmations.
- Produced: pushed `97b6985` and `3621e18` to `origin/main`.
- Measured: CI run 35769603105: success on PHP 8.3, 8.4 and 8.5, 397
  passed, now with four ISOBMFF flavours held by fixtures. Reasoned:
  nothing.
- Decided by Maurice: push, and both amendments confirmed. Open for him:
  the fragmented BMFF case, which is all that stands between M8 and
  closed; the version number of a first tag; and the visibility change.

## 2026-09-22 — Step 82, fragmented BMFF measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "doe het gefragmenteerde geval".
- Produced: `tests/Fixtures/bmff-fragmented/` (an init segment and five
  fragments, 40 kB, with the commands that made them),
  `notes/step-82-fragmented-bmff.md`, `NOTES.md`, `docs/milestones.md`.
  Nothing in `src/` changed; the stream is still refused by name.
- Measured: no reachable repository holds a fragmented C2PA stream, so
  two were built with `ffmpeg` 8.0 and `c2patool fragment` from this
  repository's own `fixture-unsigned.mp4` — one of five fragments, one of
  seven. A signed init carries `purpose: manifest` and an assertion with
  **no `hash`** but a `merkle` list
  (`{uniqueId, localId, count, alg, initHash, hashes}`); each fragment
  carries its own C2PA box with `purpose: merkle`
  (`{uniqueId, localId, location, hashes}`). `initHash` is the ordinary
  v3 digest of step 77 applied to the init segment — reproduced exactly.
  A fragment's leaf hash is that same digest applied to the fragment with
  the same exclusion list — reproduced exactly. The tree puts the largest
  power of two smaller than the leaf count on the left and the rest on
  the right, with `sha256(left ‖ right)`; all five proofs of the first
  stream and all seven of the second reach the recorded root.
- Reasoned: that the seven-fragment stream earned its cost. The obvious
  rule — consume the bits of `location` from the least significant end —
  verifies four of the five leaves in the first stream and fails the
  fifth, the lone leaf one level up. Measuring one stream would have
  produced a rule that is right four times out of five, which is the most
  dangerous kind of wrong. Also: a fragmented stream is **more than one
  file**, and nothing in this verifier's public API has anywhere to put
  that — a bigger question than the hash, and one for the spec's scope.
- Decided by Maurice: to do the fragmented case. Open for him: the spec.

## 2026-09-22 — SPEC-028 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en schrijf de spec".
- Produced: pushed `d972969`; `specs/SPEC-028-fragmented-bmff.md`
  (status `draft`, seven acceptance criteria, no API sketch), a line in
  `docs/milestones.md`.
- Measured: nothing new; the spec rests on step 82's reproduction and
  step 77's digest rule, both cited. `php bin/spec-check.php`: OK, 29
  specs, SPEC-028 `draft`.
- Reasoned: that the API sketch is deliberately absent, because the shape
  depends on the blocking question and sketching one would make a
  decision look like a detail. That AC4 — a fragment from the other
  stream, internally valid and belonging to a different tree — is the
  substitution a Merkle root exists to prevent, and c2patool's answer to
  it must be recorded before the criterion is asserted. And that AC6
  refuses more than one `merkle` map rather than guessing at rendition
  selection: the field is a list, what selects among them is unmeasured,
  and a guess would pick a tree and call the result a match.
- Decided by Maurice: to do the fragmented case and have the spec
  written. Open for him: approval, and the blocking question — how a
  caller offers an init segment and N fragments, which grows SPEC-025's
  recorded surface whichever shape is chosen.

## 2026-09-22 — SPEC-028's blocking question answered
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "leg de afweging naast elkaar", then "akkoord, doe B met streams
  per fragment".
- Produced: the decision recorded in place in
  `specs/SPEC-028-fragmented-bmff.md`, with the API sketch that was
  deliberately absent now written; `docs/milestones.md`.
- Measured: `php bin/spec-check.php` OK, 29 specs, SPEC-028 still
  `draft`. Reasoned: nothing new beyond the weighing itself.
- Decided by Maurice: a `FragmentedVerifier` of its own — a tenth class
  in the contract — rather than a method on `Verifier` or a union on
  `verify()`. `Verifier` is untouched, the new class holds one rather
  than repeating it, and the report is the same `VerificationReport`.
  Fragments arrive **one open stream at a time**, named, so fifty
  fragments never mean fifty open handles; the verifier reads each to its
  end before asking for the next and closes nothing it did not open. The
  cost is written into the spec rather than waved away: a caller who
  finds `Verifier` and not this class concludes fragmented streams are
  unsupported, which the README's Public API table and
  `docs/comparison.md` have to prevent. Still open: approval of the spec.

## 2026-09-22 — SPEC-028 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-028-fragmented-bmff.md` status `draft` →
  `approved`, the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 29 specs, SPEC-028 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-028. Implementation may now begin,
  tests first — and the red phase owes one measurement before AC4 can be
  asserted: what c2patool says about a fragment of one stream offered
  inside another.

## 2026-09-22 — Step 83a, the SPEC-028 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 83a".
- Produced: `tests/Unit/Verifier/FragmentedVerifierTest.php` (7 tests,
  group SPEC-028), `bin/make-fragmented-variants.php` with two broken
  streams, `tests/Fixtures/bmff-fragmented/foreign-seg_3.m4s`, four
  recorded c2patool answers under
  `tests/Fixtures/c2patool/bmff-fragmented/`, two fixture READMEs,
  `notes/step-83-fragmented-tests.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: c2patool 0.27.22 on the whole five-fragment stream with the
  test trust settings is `Trusted` with no failures. On a changed init,
  a changed fragment and a foreign fragment it answers
  `assertion.bmffHash.mismatch` — in **text rather than JSON** ("Error
  validating segments: … / 0 Init manifests validated"), with **the same
  code for all three and no indication of which file failed**. Pest 6
  failed / 398 passed; Pint and `bin/spec-check.php` (29 specs, 34 test
  files) clean.
- Reasoned: that our AC2, AC3 and AC4 each requiring the failing file to
  be named makes this verifier more specific than its oracle, which
  belongs in `docs/comparison.md` when the spec is implemented. And that
  AC5 is the first criterion here whose input is the shape of the call
  rather than the content of a file — a withheld or repeated fragment is
  a different set, not a broken file, which is what taking an iterable
  buys.
- Decided by Maurice: approval of SPEC-028 and the `FragmentedVerifier`
  shape. Open for him: nothing new; 83b implements, and the contract
  snapshot grows from nine classes to ten by design.

## 2026-09-22 — Step 83b, SPEC-028 implemented and M8 closed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 83b".
- Produced: `src/Verifier/FragmentedVerifier.php`; the merkle path in
  `src/Hash/BmffHashCheck.php` (`checkMerkle()`, `checkFragment()`,
  `merkleMapOf()`, `path()`); `merklePayload()` on the ISOBMFF
  extractor; SPEC-025 amendment 2 and the contract widened to ten
  classes in the test, the checker, the snapshot and the README;
  SPEC-028's Traceability filled and status `implemented`;
  `docs/comparison.md`; the second half of
  `notes/step-83-fragmented-tests.md`; `NOTES.md`, `docs/milestones.md`.
- Measured: `composer check` exit 0, 404 passed (7474 assertions). The
  five-fragment stream verifies whole; a changed init segment, a changed
  fragment, a foreign fragment, a withheld one and a repeated one each
  fail with the file named.
- Reasoned: that the shape kept this small — `Verifier` already takes its
  `BmffHashCheck` as an argument, so handing it one that knows this
  call's fragments leaves the dispatch, the report and every other rule
  untouched, and nothing is patched after the fact. PHPStan caught the
  first draft's injected `Verifier` that was never read: an ignored
  dependency reads as a seam, and there was none. Removing it shrank the
  contract from 96 symbols to 95.
- Decided by Maurice: approval of SPEC-028 and the `FragmentedVerifier`
  shape. Open for him: confirmation of SPEC-025 amendment 2, and what
  comes after M8 — the version number of a first tag, and the visibility
  change.

## 2026-09-22 — The SPEC-025 amendment confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en bevestig het amendement".
- Produced: pushed `27bc9ed` and `02af041`;
  `notes/step-84-amendment-since-81.md`; the confirmation stamped into
  SPEC-025 #2; `NOTES.md`, `docs/milestones.md`.
- Measured: 81 amendments across the specs, of which 80 were confirmed by
  step 81. Pre-push checks: attribution 0, private-key patterns 0,
  repository PRIVATE, tree clean.
- Reasoned: that nothing a caller already had changed — `Verifier` keeps
  its signature and every whole-file answer, which SPEC-028 AC7 asserts
  on every run — and that both contract alarms fired without anybody
  having to remember them, which is what step 75's note asked for after
  SPEC-024 #1 slipped past an alarm that only enumerated what it knew.
- Decided by Maurice: the amendment confirmed. Open for him: the version
  number of a first tag, and the visibility change.

## 2026-09-22 — CI on steps 83 and 84
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the push that carried step 83b and the confirmation.
- Produced: pushed `02af041` and `e24d145` to `origin/main`.
- Measured: CI run 35772279106: success on PHP 8.3, 8.4 and 8.5, 404
  passed — the first CI run in which this verifier checks a fragmented
  stream, and the run that closes M8. Reasoned: nothing.
- Decided by Maurice: push, and the amendment confirmed. Open for him:
  the version number of a first tag, and the visibility change.

## 2026-09-22 — Step 85, the public fixtures re-checked
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Is alles nu geimplementeerd. Kan je nog testen met publieke
  fixtures."
- Produced: `tests/Fixtures/c2pa-rs/video1.mp4` with
  `tests/Fixtures/c2patool/c2pa-rs/video1.json`, a paragraph in that
  corpus's README, `notes/step-85-public-fixtures.md`,
  `docs/comparison.md`, `NOTES.md`, `docs/milestones.md`. Nothing in
  `src/` changed; 404 tests still pass.
- Measured: `c2pa-org/public-testfiles` re-cloned — its `2.2` tree still
  holds only `.gitkeep` and READMEs, as step 43 found, and all 25 legacy
  JPEG assets here are byte-identical to upstream. `c2pa-rs`'s fixture
  directory holds nine ISOBMFF files this project never had, because step
  39 predated M8: eight agree with c2patool (`legacy.mp4` expired,
  `dashinit.mp4` deliberately broken upstream, `nested_moov_1000.mp4`
  which c2patool cannot parse at all, and five with no manifest), and
  `video1.mp4` does not — `Valid` at c2patool, `Invalid` here.
- Reasoned: the reason is that `video1.mp4` carries
  **`c2pa.hash.bmff.v2`**, which SPEC-027 put out of scope "if a file
  with one ever turns up". One has, in the reference implementation's own
  fixtures, and it is not exotic — it also carries the only trusted RFC
  3161 timestamp of any ISOBMFF file here, and an ingredient. The refusal
  is the right shape, but "M8 is closed" and "every ISOBMFF file verifies"
  are different sentences and only the first is true. What v2 would take
  is unmeasured, and the route is the one step 77 used: instrument
  c2pa-rs rather than reason about it.
- Decided by Maurice: to ask. Open for him: whether v2 is worth a spec,
  and the version and visibility decisions.

## 2026-09-22 — Step 86, bmff v2 measured
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en zoek v2 uit".
- Produced: pushed `eee4650`; `notes/step-86-bmff-v2.md`, `NOTES.md`,
  `docs/milestones.md`. Nothing in `src/` changed.
- Measured: `video1.mp4`'s `c2pa.hash.bmff.v2` assertion holds eight
  exclusions, of which six use **nested** paths (`/meta/iloc`,
  `/mfra/tfra`, `/moov/trak/mdia/minf/stbl/stco` and `co64`,
  `/moof/traf/tfhd` and `trun`), four carry a **`subset`** and two carry
  **`flags`** — the three filters SPEC-027 refuses by name. c2pa-rs
  instrumented and run on the file prints four markers for four included
  top-level boxes and **no marker before the continuation ranges**, so
  the digest is exactly SPEC-027's. The two holes inside `moov` are the
  two `stco` boxes (40 bytes each, excluded from offset 16), which pins
  `subset` length 0 as "to the end of the box".
- Reasoned: that v2 and v3 are not the same instruction with a different
  number — v2's exclusion list is the precise one and v3's is five whole
  top-level boxes, so no single default list serves both. Two details
  carried forward: `video1.mp4` has a **second `uuid` box that is
  hashed**, so the data filter does real work rather than ceremony; and
  `free` is hashed under v2 while v3 excludes it. And one thing a v2 spec
  cannot measure with what is on hand: whether `/moof/traf/tfhd` and
  `trun` ever match in a non-fragmented file, so `flags` would be
  implemented against nothing — a fragmented **v2** stream would settle
  it and this project has only a fragmented v3 one.
- Decided by Maurice: to investigate v2. Open for him: whether it becomes
  a spec.

## 2026-09-22 — SPEC-029 drafted
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf de spec".
- Produced: `specs/SPEC-029-bmff-v2-exclusions.md` (status `draft`, seven
  acceptance criteria), a line in `docs/milestones.md`.
- Measured: nothing new; the spec rests on steps 85 and 86, cited with
  their ranges and offsets. `php bin/spec-check.php`: OK, 30 specs,
  SPEC-029 `draft`.
- Reasoned: that `flags` and `exact` stay out of scope even though
  `video1.mp4` carries two of them, because both sit on `/moof/...` paths
  and `moof` exists only in a fragmented file — so no test could reach
  the branch, and this project has refused writing one three times
  already. AC6 makes that precise in a way worth noting: the refusal must
  come from *resolving a path that exists*, not from reading the list,
  or this very fixture would fail on its own unreachable exclusions. And
  that AC2 uses `php://memory` rather than a fixture: the file is 828 kB
  and a copy to change one byte would cost that again in every clone.
- Decided by Maurice: to have the spec written. Open for him: approval,
  and the blocking question — whether SPEC-026's extractor grows a
  depth-bounded child walk or this check does its own, which would be a
  second truth about box parsing.

## 2026-09-22 — SPEC-029's blocking question answered
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, doe de extractor met een grens".
- Produced: both open questions recorded in place in
  `specs/SPEC-029-bmff-v2-exclusions.md`, with the API sketch rewritten
  around `boxTree()`; `docs/milestones.md`.
- Measured: `php bin/spec-check.php` OK, 30 specs, SPEC-029 still
  `draft`. Reasoned: nothing new.
- Decided by Maurice: the depth-bounded child walk lives in
  `IsobmffManifestStoreExtractor`, not in the hash check — so nothing
  parses a box in two places, and the walk stays in `Container`, which
  `Hash` already depends on. The bound is **eight**: six is what the
  deepest real path (`/moov/trak/mdia/minf/stbl/stco`) needs, eight
  leaves room for a container this project has not met, and a file
  nested deeper is refused by name rather than read short — a walk that
  stops early and reports what it found would hash bytes the signer
  excluded and call the result a match. Still open: approval.

## 2026-09-22 — SPEC-029 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: `specs/SPEC-029-bmff-v2-exclusions.md` status `draft` →
  `approved`, the Approved field filled; `docs/milestones.md` updated.
- Measured: `php bin/spec-check.php` OK, 30 specs, SPEC-029 reported
  `approved`. Reasoned: nothing.
- Decided by Maurice: approval of SPEC-029. Implementation may now begin,
  tests first — AC3 first, because it carries the exact ranges the
  instrumented c2pa-rs printed and is therefore the sharpest test of the
  nested resolution.

## 2026-09-22 — Step 87a, the SPEC-029 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 87a".
- Produced: `tests/Unit/Hash/BmffV2ExclusionsTest.php` (7 tests, group
  SPEC-029), `notes/step-87-bmff-v2-tests.md`, `NOTES.md`,
  `docs/milestones.md`. No new fixture.
- Measured: Pest 6 failed on the missing `IsobmffManifestStoreExtractor::boxTree()`
  and `BmffHashCheck::plan()`, AC7 passes; 404 other tests unaffected;
  Pint and `bin/spec-check.php` (30 specs, 35 test files) clean.
- Reasoned: that AC3's expected value is a transcript of step 86's
  instrumented run rather than a construction, so a misplaced box appears
  as a number that moved; that the plan is a list of ranges with a
  `marker` flag rather than a list of boxes, because a nested exclusion
  splits one box into ranges that share one marker and SPEC-027's
  `included()` could not say that; and that AC2 uses `php://memory`
  because an 828 kB second copy in every clone is a real cost for one
  flipped byte. AC6 pulls against itself on purpose: `flags` must be
  refused while `video1.mp4`'s own `flags` exclusions on `/moof` paths
  must not make it fail, so a mistake shows up as AC1 failing rather
  than AC6 passing — the right way round.
- Also recorded: the variadic `toContain($needle, $message)` trap, hit
  for the **eleventh** time and for the first time disguising a green
  test as a red one. Eleven is no longer bad luck and is worth a rule
  rather than another comment.
- Decided by Maurice: approval of SPEC-029 and both its questions. Open
  for him: nothing new; 87b implements.

## 2026-09-22 — Step 87b, SPEC-029 implemented: `c2pa.hash.bmff.v2` verifies
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, ga door met 87b" — make the seven SPEC-029 tests green.
- Produced: `Container/IsobmffManifestStoreExtractor::boxTree()` with
  `DEFAULT_MAX_BOX_DEPTH = 8` and the container list; `Hash/BmffHashCheck`
  grew `LABELS`, `labelOf()`, `plan()`, `ranges()`, `remaining()`, and
  `matches()` was turned inside out to resolve a path before refusing a
  filter; `digest()` now emits a marker only where the plan says so;
  `Verifier` routes on `labelOf()` and `DataHashCheck` stopped claiming
  the two labels; three amendments (SPEC-029 #1, SPEC-027 #3,
  SPEC-012 #6); a new oracle
  `tests/Fixtures/c2patool/timestamp/video1-full-plus-digicert-g4.json`;
  `bin/api-check.php`'s contract list fixed; SPEC-029 `implemented` with
  Traceability; `docs/comparison.md`, `NOTES.md`, `docs/milestones.md` and
  the 87b half of `notes/step-87-bmff-v2-tests.md`.
- Measured: `composer check` green — 411 tests, 7494 assertions; PHPStan
  max, Pint, Deptrac 0 violations; `bin/api-check.php` 10 classes,
  95 symbols, recorded surface matches. `video1.mp4` under
  `trust/full-plus-digicert-g4.settings.json`: `Valid`, one failure
  (`signingCredential.untrusted`, on the ingredient), identical to
  c2patool 0.27.22 under the same settings across the active manifest and
  the ingredient deltas. Without settings the two differ by
  `signingCredential.expired`, and c2patool no-settings was re-run to
  confirm it is the operating system's trust store doing it.
- Reasoned: that the divergence is the design decision, not a fault —
  this verifier has no system trust store, so an old file needs its TSA
  anchor named before the comparison is between equals; that
  `DataHashCheck` should still answer `general.error` when asked about a
  binding it does not own, because silence is the one thing this project
  refuses; and that `xpath-nested.mp4` failing through a digest mismatch
  rather than a refusal keeps the criterion's purpose intact.
- Decided by Maurice: none this step. Open for him: the three amendments
  above, all pending confirmation.

## 2026-09-22 — Step 88, the three amendments since step 84, confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar, en bevestig alle drie de amendementen".
- Produced: step 87b pushed (`eee4650..9e2913c`); SPEC-029 #1, SPEC-027 #3
  and SPEC-012 #6 stamped confirmed; `notes/step-88-amendments-since-84.md`,
  `NOTES.md`, `docs/milestones.md`.
- Measured: 84 numbered amendments across the specs, none outstanding;
  `git log --format=%B | grep -i "claude\|anthropic"` empty before the
  push; the repository is still PRIVATE.
- Reasoned: that all three are weight C — no rule of the verifier changed
  in either direction. SPEC-029 #1 is the one worth reading: it replaced a
  comparison between two verifiers with different trust anchors by one
  where both sides are asked the same question, rather than relaxing the
  criterion to whatever this verifier happens to produce.
- Decided by Maurice: confirmation of all three amendments. Open for him:
  whether `bin/api-check.php` joins `composer check`; the version of a
  first tag; the visibility change; the announcement.

## 2026-09-22 — CI on steps 87 and 88
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the push that carried step 87b and the confirmation of its three
  amendments.
- Produced: pushed `9e2913c` and `36d8c90` to `origin/main`.
- Measured: CI runs 35776024432,35775903551: success on PHP 8.3, 8.4 and 8.5,
  411 passed — the first CI run in which this verifier reads
  `c2pa.hash.bmff.v2`, so every ISOBMFF hard binding c2pa-rs writes today
  or wrote in 2022 is covered. Reasoned: nothing.
- Decided by Maurice: push, and all three amendments confirmed. Open for
  him: whether `bin/api-check.php` joins `composer check`; the version
  number of a first tag; the visibility change; the announcement.

## 2026-09-22 — Step 89, api-check joins `composer check`
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, doe api-check in composer check".
- Produced: `composer.json` (`api-check` script, second step of `check`);
  `apiContract()` and `apiClass()` in `bin/api-check.php` with
  `tests/Unit/ApiSurfaceTest.php` reading both instead of keeping copies;
  README, CONTRIBUTING, `notes/step-89-api-check-in-green.md`, `NOTES.md`,
  `docs/milestones.md`.
- Measured: `composer check` green — 411 passed, PHPStan max, Deptrac 0,
  api-check 10 classes / 95 symbols / surface matches. The new step
  falsified rather than assumed: an extra line appended to
  `public-surface.txt` makes `composer api-check` exit 1 with the symbol
  named, and the file was restored.
- Reasoned: that running the script fixes the smaller half of the problem.
  The contract list existed twice — nine names in the script, ten in the
  test — and two definitions of a promise are none; with one list the
  script is a second copy of a test that already runs, and what it adds is
  that the command the README tells a reader to run is one CI proves works.
  Said so in the note rather than calling it a new alarm.
- Decided by Maurice: api-check in `composer check`. Open for him: the
  version of a first tag; the visibility change; the announcement.

## 2026-09-22 — Step 90, the 111 conformance obligations mapped
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, leg de 101 predicaten naast onze checks".
- Produced: `docs/conformance.md` (every applicable predicate with a
  verdict and an anchor, plus what the gaps would cost),
  `notes/step-90-conformance-mapping.md`, `NOTES.md`,
  `docs/milestones.md`, and a README Status paragraph that had gone stale
  ("Not yet: ISOBMFF video (M8)" — M8 closed in step 83b).
- Measured: the suite's catalogue re-read from a fresh clone — 150
  predicates, of which **111** apply now, not step 71's 101: closing M8
  added `video_bmff` (8) and `streaming_bmff` (2). Verdicts: 49 yes, 12
  partial, 7 closed, 21 by design, 22 gaps. And the one finding that
  needed measuring rather than reasoning: `tests/Fixtures/c2pa-rs/
  ocsp.jpg` carries `rVals.ocspVals[0]`, 2264 bytes of DER, which
  `openssl ocsp -respin` reads as `Cert Status: good`, produced
  2025-08-11, next update 2025-08-18. `CoseSign1` parses that header into
  `$otherHeaders['rVals']` and nothing reads it.
- Reasoned: the table itself, and it says so — a reading of 111 rules
  against this code by the same hands that wrote it, not a measurement.
  The suite was not used as a judge; step 71 measured why it cannot be.
  The gaps are sorted by consequence rather than by number: one can yield
  a wrong `Trusted` (stapled OCSP, four predicates, one cause), eight are
  laxer than 2.4 about what a manifest may say without letting a changed
  byte through, and the rest are stricter or differently named.
- Decided by Maurice: to do this step before going public. Open for him:
  whether stapled OCSP becomes SPEC-030; the version of a first tag; the
  visibility change; the announcement.

## 2026-09-22 — Step 91, SPEC-030 drafted: stapled OCSP
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, schrijf SPEC-030 als draft".
- Produced: `specs/SPEC-030-stapled-ocsp.md` (status `draft`, ten
  acceptance criteria, three open questions), `NOTES.md`,
  `docs/milestones.md`.
- Measured, before a line of the spec was written: `rVals` sits in the
  **unprotected** COSE bucket (`protected: 1, 33` / `unprotected: sigTst,
  rVals, pad`) — it is not covered by the signature. And: c2patool
  0.27.22 emits **no** OCSP status code of its own on `ocsp.jpg` or
  `ocsp_with_assertion.jpg`; the single `signingCredential.ocsp.skipped`
  in this repository's oracles was written by a claim *generator* into an
  ingredient's recorded `validationResults`, not by the verifier. The
  conformance catalogue names RFC 5019 §3.2 requirements 1–4 as the
  acceptance rules, which is the citation the spec uses rather than a
  guessed section number.
- Reasoned: the four consequences of that unsigned header, which are the
  spec's spine — a stapled response may lower trust but never raise it;
  an unverifiable one may never fail the file, because otherwise editing
  one unsigned byte is a denial vector against any valid asset; only a
  verified `revoked` counts; and absence proves nothing, so what was not
  checked must be said out loud.
- Decided by Maurice: to draft it. Open for him: the three questions in
  the spec (stale responses, the AC3 fixture, whether `notRevoked` should
  be recorded at all), then approval; the version of a first tag; the
  visibility change; the announcement.

## 2026-09-22 — SPEC-030 approved
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, zet hem op approved".
- Produced: SPEC-030 status `approved`, with all four open questions
  resolved in place rather than deleted; `NOTES.md`, `docs/milestones.md`.
- Measured: nothing new this step; `bin/spec-check.php` reads 31 specs.
- Reasoned: the approval came without separate answers to the three open
  questions, so the draft's recommendations are recorded as the decisions
  — a stale `good` expires while a stale `revoked` does not; AC3's fixture
  is constructed with throw-away keys the script deletes; `notRevoked` is
  recorded with its caveat in the explanation a reader actually meets.
  Written into the spec so that a different answer is one amendment away
  rather than a memory.
- Decided by Maurice: approval. Open for him: correcting any of those four
  resolutions before 92a begins; the version of a first tag; the
  visibility change; the announcement.

## 2026-09-22 — Step 92a, the SPEC-030 tests, seen red
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, begin met 92a".
- Produced: `tests/Unit/Trust/OcspCheckTest.php` (ten criteria),
  `bin/make-ocsp-variants.php` and the seven files it writes under
  `tests/Fixtures/ocsp/`, SPEC-030 amendment 1 (AC1, AC2, AC3, AC6),
  `notes/step-92-ocsp-tests.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: `ocsp.jpg` without settings is `Invalid` — `timeStamp.
  untrusted`, `signingCredential.expired` — and `Valid` under
  `full-plus-digicert-g4.settings.json`, where the timestamp is trusted
  and attests 2025-08-13. The stapled response runs 2025-08-11 to
  2025-08-18, so it is fresh at that attested time and stale at now. The
  twelve baseline verdicts of AC9 were measured before any code. Pest: 9
  failed, 412 passed; Pint and `bin/spec-check.php` (31 specs, 36 test
  files) clean.
- Reasoned: that the same file being both AC1 and AC6 is a better pair of
  criteria than the spec originally asked for; that AC3 at the seam is
  worth its cost because this verifier has no CBOR writer and building a
  whole asset would need one — with the loss (no end-to-end revoked path,
  and no second implementation to check against) written into the note in
  those words rather than left as an assumption.
- Decided by Maurice: none this step. Open for him: amendment 1, pending
  confirmation.

## 2026-09-22 — Step 92b, SPEC-030 implemented: stapled OCSP
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bevestig het amendement en ga door met 92b".
- Produced: `src/Trust/OcspCheck.php`; four `StatusCode` cases; the call
  in `Verifier` after the chain and the timestamp, with `revocation` in
  `checksPerformed`; `deptrac.yaml` (Trust may read Asn1 and Cbor);
  SPEC-030 amendment 1 confirmed and amendment 2 written; SPEC-025
  amendment 3; the recorded surface 95 → 99; 24 test literals updated;
  `docs/conformance.md`, `docs/comparison.md`, README, the 92b half of
  `notes/step-92-ocsp-tests.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: `composer check` green — 421 passed (7584 assertions),
  PHPStan max, Deptrac 0 violations, api-check 10 classes / 99 symbols.
  The four fixtures answer at the seam: `revoked.der` →
  `signingCredential.ocsp.revoked` (serial 1, keyCompromise),
  `good.der` → `notRevoked`, `removed.der` → `skipped` (removeFromCRL is
  not a revocation), `other-good.der` → `skipped` (another certificate).
  `ocsp.jpg` gives `notRevoked` under the DigiCert anchor, where the
  judged time is the attested 2025-08-13, and `skipped` without it, where
  the judged time is now and the response expired on 2025-08-18.
- Reasoned: nothing load-bearing. The three corrections were all found by
  something that runs — the fixture caught the wrong OID
  (`1.3.6.1.5.5.7.48.1` is id-pkix-ocsp, not id-pkix-ocsp-basic; following
  the spec would have left the feature silently inert while ten criteria
  passed), PHPStan's always-true ternary led to an empty CBOR map being
  read as a list, and Deptrac refused the build until both new layer
  edges were written down. Two tests were green *because* of the OID bug
  and went red when it was fixed; that is recorded in the note.
- Decided by Maurice: SPEC-030 amendment 1 confirmed. Open for him:
  SPEC-030 amendment 2 and SPEC-025 amendment 3; the version of a first
  tag; the visibility change; the announcement.

## 2026-09-22 — Step 93, the three amendments since step 88, confirmed
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord, bevestig beide amendementen".
- Produced: SPEC-030 #2 and SPEC-025 #3 stamped confirmed (SPEC-030 #1
  was confirmed with 92b); `notes/step-93-amendments-since-88.md`,
  `NOTES.md`, `docs/milestones.md`.
- Measured: 87 numbered amendments across the specs, none outstanding.
- Reasoned: that SPEC-030 #2 is worth more than its weight suggests. Had
  the implementation followed the specification's wrong OID, all ten
  acceptance criteria would still have passed — six of them expect
  `skipped`, two test a seam that would have answered the same way — while
  the feature did nothing at all. What caught it was a fixture that had to
  be built rather than downloaded, from `openssl`, an independent source
  of the same number; and AC7 and AC8, green while the bug was there and
  red when it went, are the same lesson from the other side.
- Decided by Maurice: both amendments confirmed. Open for him: the version
  of a first tag; the visibility change; the announcement.

## 2026-09-23 — Step 94, the first-version notice
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "misschien wil ik het wel public maken maar nog heel even niet,
  want ik wil heel duidelijk aangeven dat dit een eerste opzet is en nog
  getest moet worden door mensen als ze ermee willen werken" — then
  "akkoord, schrijf de README-tekst".
- Produced: a notice directly under the README's one-line description; a
  new section *Trying it, and what to send back*; the old caveat reworded
  to say a first tag will be a `0.x`; the stale "22 gaps" corrected to 17
  (SPEC-030 closed five in step 92b);
  `notes/step-94-first-version-notice.md`, `NOTES.md`,
  `docs/milestones.md`.
- Measured: `composer check` green — 421 passed, PHPStan max, Deptrac 0,
  the recorded surface matches. `ApiSurfaceTest` AC3/AC5, which read the
  README, still pass.
- Reasoned: that the notice has to hold two true things at once — the work
  is thorough and the code has never been used — and that letting either
  swallow the other misleads. Two wordings were tightened for accuracy
  after a first draft: "checked against" a third implementation in Python
  became "compared with", because step 71 measured that suite disagreeing
  with three others on files all three accept; and "tell me" became "open
  an issue", which is what the README says everywhere else.
- Decided by Maurice: public later, not now; the notice first. Open for
  him: the visibility change; the first tag, for which the advice is now
  `0.1.0` rather than step 88's `0.2.0`; the announcement.

## 2026-09-23 — Step 95, the disclosure says how, not only that
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "er mag wel duidelijk in staan dat het gemaakt is met Claude Code
  maar op een [ge]controleerde manier".
- Produced: the README's *How this is built* rewritten from a paragraph
  into the controls themselves, each checkable in the repository; one line
  in the top notice linking to it; `notes/step-95-disclosure.md`,
  `NOTES.md`, `docs/milestones.md`.
- Measured: the three numbers in the new text against the repository —
  31 spec files, 87 numbered amendments, 17 rows marked as gaps in
  `docs/conformance.md` (a first count said 19 and was wrong: it included
  the legend and the tally rows). `composer check` green: 421 passed,
  PHPStan max, Deptrac 0.
- Reasoned: that "reviewed" is not information, and that a list of virtues
  nobody can verify is worth less than none — so every control names the
  file or the command that proves it. And that the section needed the
  sentence it is easiest to leave out: none of this is an independent
  security audit, nobody outside the project has reviewed the code. Without
  it the section would have quietly contradicted step 94's notice.
- Decided by Maurice: that the disclosure may be explicit, provided it says
  the work was controlled. Open for him: the visibility change; the first
  tag (`0.1.0`); the announcement.

## 2026-09-23 — Step 96, the pre-public audit
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "doe eerst die vier controles, en kijk of alle teksten die naar
  buiten gaan kloppen" — after "akkoord, zet hem dan maar public", which
  was then held ("wacht maar even").
- Produced: `SECURITY.md` scope lists corrected; `CHANGELOG.md` extended
  with SPEC-023, SPEC-024, SPEC-025, M8's four specs and SPEC-030;
  `composer.json`'s description; the README's corpus figure; the corpus
  paragraph in `docs/comparison.md`; a forward pointer in ADR-0001;
  `notes/step-96-pre-public-audit.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: private keys in the whole history — `git log --all -S` over
  276 commits, five PEM header variants, **0 commits**; the 29 tracked
  certificate files hold `CERTIFICATE` blocks only. Attribution in every
  commit message on every branch: empty. Local paths: one hit, the row in
  step 62's note that names the pattern being searched for. The untracked
  instruction file: `.gitignore` plus two notes recording this audit.
  Counts against the repository: 31 specs, 87 amendments, 99 symbols, 111
  obligations (54 met, 17 open), 421 tests, 93 corpus files in five
  alarms. And mechanically, in `src/`: no network call, no process call,
  no temporary file — the only writes are the CLI's stdout and stderr.
- Reasoned: that a security document understating what is verified is
  still wrong in the one file a reader opens to learn what is checked;
  that "nine writers" should be replaced rather than recounted, because a
  number nobody can verify is worse than none; and that the two remaining
  mentions of the untracked instruction file are the record of this very
  check and should stay, since removing them would make the record
  unreadable. That last one is a judgement call and is flagged rather
  than taken as settled.
- Decided by Maurice: to run this audit before the visibility change, and
  to hold the switch. Open for him: **the commit e-mail address**, which
  publishing makes public for all 276 commits and which GitHub can only
  mask beforehand; then visibility, branch protection, the `0.1.0` tag,
  Packagist and the announcement, in that order.

## 2026-09-23 — Step 97, the author address rewritten out of the history
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: whether to make a public copy of the repository with less history
  — advised against, and "akkoord, doe b", then "doe het zo veilig
  mogelijk" and "push maar".
- Produced: `git filter-repo 2.47.0` with a `mailmap`, run in a throwaway
  clone; the force-push (run by the maintainer, twice blocked for me by
  the permission classifier, correctly); the working repository reset to
  the rewritten history; **199 commit references in 13 tracked documents
  translated** through `filter-repo`'s commit-map;
  `notes/step-97-history-rewrite.md`, `NOTES.md`, `docs/milestones.md`.
  `user.email` set repository-locally to the noreply address, leaving the
  global configuration alone.
- Measured, before anything left the machine: 277 commits kept; the tree
  of `HEAD` is `c48c331a…`, identical; all 277 commits compared on tree,
  author date, commit date, name and subject — identical, line for line;
  554 of 554 e-mail fields rewritten; 0 traces of the old address. After
  the push: the same on the remote, and CI green on 8.3, 8.4 and 8.5.
  Also measured, and it confirms what was said beforehand: the old
  `4fdea57` is **still reachable through the GitHub API by full SHA**,
  because unreachable objects linger until GitHub collects them.
- Reasoned: that a scrubbed copy would trade the evidence for the
  README's own claim against a problem with a targeted fix; that
  translating 199 references is preserving a record rather than altering
  it, since each points at the same commit with the same content, date and
  message — but that editing a log meant to be faithful is worth saying
  plainly, so the note says it; and that the lingering old objects do not
  matter here only because the repository has never been public and no
  document in the new history names an old SHA.
- Decided by Maurice: no copy; rewrite; as safely as possible; push. Open
  for him: the visibility change, then branch protection, then `0.1.0`,
  then Packagist and the announcement.

## 2026-09-23 — Step 98, the repository is public
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "zet hem nu maar public".
- Produced: `gh repo edit --visibility public`; `NOTES.md`,
  `docs/milestones.md`. Nothing in `src/` or the texts changed.
- Measured, immediately before: working tree clean, nothing unpushed,
  local and remote both `ceb5aa9`, 0 occurrences of the old address
  anywhere in the history, CI green on 8.3, 8.4 and 8.5. Immediately
  after: visibility `PUBLIC` at
  https://github.com/provemark/c2pa-verifier.
- Reasoned: nothing. The judgement work was done in the two steps before
  this one — the audit that found what could not be undone afterwards,
  and the rewrite that removed the one thing it found.
- Decided by Maurice: the visibility change. Open for him, in this order:
  branch protection on `main`, a first tag (`0.1.0`), Packagist, and any
  announcement — so that nothing can be installed before the protections
  exist.

## 2026-09-23 — Step 99, branch protection on `main`
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "akkoord" to the proposal that followed the visibility change.
- Produced: branch protection on `main` — `allow_force_pushes: false`,
  `allow_deletions: false`, `enforce_admins: true`; `NOTES.md`,
  `docs/milestones.md`.
- Measured: the API confirms all three; an ordinary push still reaches the
  protected branch (tested, and the test is the empty commit `fe3e6d2`).
- Reasoned, and it changed the proposal *before* it was applied: required
  status checks block **direct pushes** as well as merges. This project
  pushes straight to `main`, so enabling them would have rejected every
  push until CI had run on that commit — a lock on the maintainer's own
  door. Left off, and said so rather than quietly dropped.
- Worth recording as a mistake: the push test used an empty commit, which
  is now permanently in a public history that had just been curated with
  care — and unremovable, because the protection it was testing forbids
  the force-push that would take it out. A real commit would have tested
  the same thing and left no litter. The protection worked exactly as
  intended; the test did not.
- Decided by Maurice: the protection. Open for him: a first tag
  (`0.1.0`), Packagist, and any announcement.

## 2026-09-23 — Step 100, the first release prepared
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "Ik wil zo min mogelijk taggen. Is tag 0.1.0 wel een goed begin
  en niet 0.0.1 of zo?" — then "akkoord".
- Produced: `CHANGELOG.md`'s `Unreleased` section becomes `0.1.0 —
  2026-09-23` with a paragraph saying why it is a `0.x`; the README's
  caveat replaced by the tag and a `repositories` snippet a tester can use
  before Packagist exists; `NOTES.md`, `docs/milestones.md`. The annotated
  tag itself is made but **not pushed**.
- Measured: `composer check` green (421 passed), `bin/package-check.php`
  reports 13 shipped paths, 9 export-ignored, 245 files and 2.5 MB in the
  distributed archive. Both sister projects tag with a `v` prefix
  (`v0.15.1`, `v0.3.0`), so this one does too.
- Reasoned, and it decided the number: Composer reads `^0.0.1` as
  `>=0.0.1 <0.0.2` — exactly that release. Under `0.0.x` every fix is a
  breaking boundary that reaches nobody and forces every user to edit
  their `composer.json`, which is the opposite of the maintainer's wish to
  tag as little as possible. `^0.1` receives every 0.1.x fix, so a new tag
  is only needed when the API breaks. `1.0.0` was rejected for a different
  reason: the API contract is guarded, but stability is earned by use, and
  nobody has used this yet.
- Decided by Maurice: `0.1.0`. Open for him: pushing the tag, then
  Packagist, then the announcement.

## 2026-09-23 — Step 101, v0.1.0 pushed, and a line of my own corrected
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "push maar".
- Produced: `main` at `785e1d3` and the annotated tag `v0.1.0` pushed; a
  corrected CHANGELOG line about SPEC-023; `NOTES.md`,
  `docs/milestones.md`.
- Measured: GitHub reports the tag on `785e1d3`; its zipball holds **245
  files**, exactly what `bin/package-check.php` measures locally, with the
  same top-level paths — so what a user downloads is what the check
  bounds. The archive ships `bin`, `docs`, `notes`, `specs`, `src` and the
  seven root markdown files; `.gitattributes` keeps out `tests` (63 MB of
  fixtures), `tools`, `.github` and the tool configuration.
- The correction: the changelog said SPEC-023 "keeps the fixtures, notes,
  specs and tooling out of the distributed archive". The notes and specs
  are **in** it, on purpose — SPEC-023 requires every relative link in
  shipped markdown to resolve to something also shipped, and the README
  and the log link to them. That sentence was written in step 96, whose
  whole purpose was checking the outward texts for accuracy; it was caught
  here only because the tag's archive was verified rather than assumed.
- Decided by Maurice: the push. Open for him: Packagist, then the
  announcement.

## 2026-09-23 — Step 102, on Packagist
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the maintainer submitted the package and set the webhook after
  `github.com/apps/packagist` turned out not to be installable and
  Packagist's "manual account sync" could not be found.
- Produced: the README's `repositories` workaround replaced by
  `composer require provemark/c2pa-verifier`, and the install line added
  where a tester reads it; `NOTES.md`, `docs/milestones.md`.
- Measured: the package holds `v0.1.0` and `dev-main`; the webhook on the
  repository is `https://packagist.org/api/github?username=…`, JSON, push
  only, SSL on, secret set, last delivery `OK` — the same shape as the one
  on `content-credentials`, read from its configuration rather than
  guessed. Packagist's last update before this push: 11:36:29 UTC.
- Reasoned: nothing. This push is itself the test of the webhook, which is
  why the change was made as a real one rather than an empty commit — the
  lesson of step 99, where a push test left litter in a public history.
- Decided by Maurice: submitting to Packagist, and the webhook over the
  GitHub App. Open for him: the CAI listing, and an article.

## 2026-09-23 — Step 103, the gaps become an on-ramp
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: the maintainer wants to invite other developers to look at the
  library and possibly build on it, before promoting it as complete —
  "maak ze aan" for the issues that would give them somewhere to start.
- Produced: twelve issues on `provemark/c2pa-verifier` covering the 17
  gaps of `docs/conformance.md`, a `conformance` label, and Discussions
  enabled; `NOTES.md`, `docs/milestones.md`.
- Measured: the twelve issues carry no assistant attribution; the three
  relative links each issue uses resolve from an issue URL to a file that
  exists (`../blob/main/CONTRIBUTING.md` returns 200 from `/issues/1`);
  the repository reports issues and discussions both open, twelve open.
- Reasoned: that seventeen issues would have been noise, so related
  predicates were grouped by feature — the three
  `alternative-content-representation` rules into one, the three BMFF
  shape checks into one, the two timestamp-assertion rules into one. That
  each issue should say **what it costs that the rule is missing**, using
  the three groups `docs/conformance.md` already sorts the gaps into, so
  a reader can tell a laxer-than-the-specification rule from a stricter
  one and choose accordingly. And that every issue needed the closing
  paragraph about how a change lands here: this project cannot merge a
  code-first pull request, and a contributor who learns that from a
  rejection has been badly served.
- Decided by Maurice: to open the issues. Open for him: the reply on
  WordPress/ai #459, the CAI listing, an article.

## 2026-09-23 — Step 104, a red CI that should have been caught sooner
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "er faalt een pipeline".
- Produced: `tests/Unit/Cli/CommandTest.php` masks both wall-clock
  timestamps rather than one; `NOTES.md`, `docs/milestones.md`.
- Measured: the failure is `every corpus file, with and without settings:
  stdout equals the API`, on **PHP 8.4 only** while 8.3 and 8.5 passed —
  the shape of a race, not a regression. The differing line is the
  `signingCredential.ocsp.skipped` explanation on `ocsp.jpg`, which names
  the judged time to the second. The same failure is in run on `785e1d3`
  at 07:51, **the commit tag `v0.1.0` points at**. `composer check` green
  locally after the fix: 421 passed.
- Reasoned: that SPEC-030 introduced a second *now* into an explanation
  and the existing guard covered only the first (`expired at …`, added
  after CI run 35714422755). The mask now covers `the judged time is …`
  too, and masks only the digits after a named phrase, so a real change to
  the wording still fails the test.
- The miss worth recording: CI was checked after every push on 2026-09-23
  except the one that carried the release. The tag's commit has a red run
  for this reason, and the tag is not being moved — a published tag that
  someone may already hold is not worth rewriting for a flaky test, and
  the record says what happened instead.
- Decided by Maurice: none this step.

## 2026-09-23 — Step 105, the suite runs in parallel
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "doe punt 1" — make `pest --parallel` work, the first of the five
  open items on the code.
- Produced: `tests/Shared.php` holding the nine declarations more than one
  test file needs, required from `tests/Pest.php`; the nine removed from
  the four files that held them; `composer test:parallel`;
  `notes/step-105-parallel-tests.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: 190 functions are declared across the test suite and **eight**
  were used from another file; 45 constants and **one** was. Parallel went
  from 24 failures to green: 421 passed both ways, 7.35s serial against
  2.62s parallel. `composer check` green.
- Reasoned: that `composer check` stays serial. It is the definition of
  green, the drift alarms print in order there, and five seconds does not
  buy back the determinism; `composer test:parallel` is the fast loop
  while working.
- Two mistakes, both recorded in the note: the analysis that found the
  eight was written to find *functions*, so the constant survived it and
  four tests still failed — it answered the question it was asked rather
  than the one that mattered. And the first cut took any comment lines
  above a function with it, which walked into a file header and left a
  dangling fragment in two files; restored from git and the rule narrowed
  to a complete docblock or nothing.
- Named rather than fixed: nothing runs `--parallel` automatically, so the
  next cross-file helper will break it silently, exactly as this one did.
  A CI job costs 2.6 seconds. Left as a decision for the maintainer.
- Decided by Maurice: to do this first.

## 2026-09-23 — Step 106, the parallel run is in CI
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "zet die CI-job erbij".
- Produced: a `composer test:parallel` step in `.github/workflows/ci.yml`,
  after `composer check` on each of the three PHP versions; `NOTES.md`,
  `docs/milestones.md`.
- Reasoned: a step in the existing matrix rather than a job of its own, so
  that `all green` covers it without new wiring, and on all three versions
  rather than one — three seconds a version is not worth reasoning about
  which version would be enough.
- Honest about the falsification: the *property* was seen failing in step
  105 (24 errors, then four after the functions moved and before the
  constant did), so what this step catches is known to be catchable. The
  CI step itself has not been seen red; it is the same command that was
  red locally an hour ago.
- Decided by Maurice: to add it.

## 2026-09-23 — Step 106b, a rule for the thirteen-times mistake
- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: "doe punt 5" — a lint against the variadic `toContain` trap.
- Produced: `it('every toContain in the suite takes exactly one needle')`
  in `tests/Unit/SpecCheckTest.php` (SPEC-000), with
  `spec000TestFiles()`, `spec000ToContainCalls()` in `tests/Shared.php`;
  the one live violation fixed in `HashedUriCheckTest`;
  `notes/step-106-tocontain-rule.md`, `NOTES.md`, `docs/milestones.md`.
- Measured: 191 `toContain` calls in the suite; **one** passes more than
  one needle. The rule was red on it when written. Then falsified on a
  case it was not written against: a second needle added to
  `ReportTest.php:160` made it report that file and line, and removing it
  made it green again. 422 passed, serial and parallel.
- Reasoned, and it changed the implementation: the first version searched
  the text and reported five, of which three were the comments that warn
  about this trap — two of them written by me — because they quote the
  wrong form in order to show it. **A rule that flags its own
  documentation is worse than no rule**: the next person deletes the
  warnings to make the build pass. It now reads the file with
  `token_get_all()`, which knows a comment from a call and a comma inside
  `'OK: 1 spec(s), 1 test file(s)'` from an argument boundary.
- The violation it found was harmless in verdict and not in use: a list of
  `StatusCode` enums cannot contain a string, so the check still held —
  what was lost was the name of the fixture a failing loop would have
  printed.
- Decided by Maurice: to do this fifth item.

## 2026-09-24 — The M7 row marked done
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "M7-rij bijwerken, daarna issue #10 oppakken" — after a status
  overview showed the milestone table still left M7 without its stamp.
- Produced: the M7 row of `docs/milestones.md`.
- Reasoned: M7 closed at step 57b on 2026-09-22 (`notes/step-57-update-manifests.md`,
  "M7 is complete"); the row's figures are copied from
  `notes/step-56-ingredient-validation.md` ("What this closes"), not
  re-measured. Every other milestone row carried its stamp; this one was
  missed when M8 followed the same day.
- Decided by Maurice: to update the row.

## 2026-09-24 — Issue #10 (`iat`) measured and parked
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "daarna issue #10 oppakken"; after the measurements below, "A,
  parkeren met de metingen in het issue".
- Produced: a comment on issue #10 with the measurements and what would
  reopen it; a new label `waiting for a file`, applied to #10. No
  specification, test or code.
- Measured: the COSE headers of the active manifest in all 164 signed
  JPEG/PNG/WebP fixtures (a throwaway script over `Corpus::cose()`): 0
  carry `iat` or a CWT Claims header (label 15). `contentauth/c2pa-rs` at
  `6c92bc3` (2026-09-23): `CertificateInfo::iat` is only ever `None`,
  `timeOfSigning.insideValidity` is defined and never emitted,
  `timeOfSigning.outsideValidity` is not defined. So `c2patool` gives no
  oracle.
- Reasoned: C2PA 2.4 §13.2.4 and VAL-CRYP-0029…0031 (via
  `encypherai/c2pa-knowledge-graph`, 2.4) make the check a *may* with
  informational codes only. Without a writer or a second implementation,
  a spec would rest on a fixture signed here and on nothing else. Which
  label carries `iat` (text `"iat"` or CWT claim 6) is left open and is
  named in the issue as the first question.
- Decided by Maurice: park the issue (option A) rather than draft a spec.

## 2026-09-24 — Issue #9 (time-gated anchors) measured and parked
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "#9 oppakken"; after the measurements, "akkoord met allebei"
  (park #9, then measure the settings format of c2patool 0.28.0).
- Produced: a comment on issue #9 with the measurements; a new label
  `waiting upstream`, applied to #9. No specification, test or code.
- Measured: `c2pa-org/conformance-public` `trust-list/` at `99927ca`
  (2026-08-14). The PEM carries no dates. The two ETSI TS 119 602 JSON lists
  hold 30 + 22 services, all `trusted`, each with only a
  `StatusStartingTime` (2025-05-08 … 2026-08-10); no `untrusted`, no
  `ServiceHistory`, no `notBefore`/`notAfter`. `contentauth/c2pa-rs`
  0.91.0: no date field on either `TrustAnchor`.
- Reasoned: VAL-CRYP-0010/0011 are conditional on an anchor configuration
  carrying a date. Neither this verifier's settings nor c2patool's can
  carry one, so nothing is ignored today. Reading `StatusStartingTime` as a
  `notBefore` would be an interpretation of our own that disagrees with
  c2patool. The issue's claim that the official list is time-gated was half
  right, and the comment corrects it.
- Found on the way: c2pa-rs 0.91.0 (2026-09-21) deprecates
  `trust.trust_anchors` in favour of `trust.anchors`, with removal
  announced for 0.92.0 (mid-November 2026); c2patool 0.28.0 was released
  2026-09-22. That is the next step.
- Decided by Maurice: park #9 and leave `docs/conformance.md` as it is;
  measure the settings format next.

## 2026-09-24 — Step 107, c2patool 0.28.0 and the shared settings file
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "akkoord met allebei", which included measuring whether c2patool
  0.28.0 still reads this project's settings files.
- Produced: `notes/step-107-c2patool-0.28.md`, a `NOTES.md` row. No
  specification, test or code.
- Measured: c2patool 0.28.0 (SBOM: `c2pa` 0.91.0) and 0.27.22 against each
  other, and against `bin/c2pa-verify`, on `fixture-signed.jpg` and
  `fixture-signed.mp4` with no settings and with each of the 15
  `tests/Fixtures/trust/*.settings.json` (throwaway comparison scripts in
  the session scratchpad). Anchors: every verdict unchanged.
  `allowed_list`: Trusted → Valid in 0.28.0 on both files, while this
  verifier stays Trusted. The cause was read in `c2pa` 0.91.0
  `settings/mod.rs`: `allowed_list` moved into `TrustAnchor`, and the
  top-level field is dropped without error.
- Reasoned: when 0.92.0 removes `trust_anchors`, the anchor half of the
  shared-file rule will probably break silently in the same way. Not
  measured.
- Decided by Maurice: none yet. What to do about it is his call.

## 2026-09-24 — SPEC-031 drafted: `trust.anchors`, and a loose `allowed_list` refused
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "SPEC-031 als draft, met weigeren voor allowed_list".
- Produced: `specs/SPEC-031-trust-anchors-list.md` (draft, seven criteria,
  five open questions); an addendum to `notes/step-107-c2patool-0.28.md`;
  rows in `docs/milestones.md` and `NOTES.md`. No test or code.
- Measured: 13 constructed `trust.anchors` settings (N1–N13) and four on a
  Truepic file (T1–T4) against c2patool 0.28.0; the table is in the note.
  Also 281 signed corpus files, 0.27.22 against 0.28.0 without settings:
  14 changed, 6 `Valid` → `Invalid`. The file list is in the note.
- Reasoned: from `c2pa` 0.91.0 `settings/mod.rs` and
  `certificate_trust_policy.rs`: the entry fields, `TrustListKind`'s
  lowercase names, the legacy merge. §14.4.1's number comes from the 2.3
  text quoted in c2pa-rs and is flagged in the spec to be checked against
  2.4.
- Decided by Maurice: SPEC-031 as a draft; a top-level `allowed_list` is
  refused, not ignored and not kept.

## 2026-09-24 — Step 108, an exclusion wider than the store
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "B eerst, de vijf data-hash-bestanden onderzoeken".
- Produced: `notes/step-108-exclusion-wider-than-store.md`, rows in
  `NOTES.md` and `docs/milestones.md`. No specification, test or code.
- Measured:
  - The five files under this verifier: two already `Invalid`, and the
    three Truepic files `Trusted` with `truepic-root.settings.json`.
  - A JPEG segment walk of `truepic-20230212-camera.jpg`: the exclusion
    `[0, 206316]` holds SOI + a 13613-byte EXIF APP1 + the store.
  - A copy with the EXIF date changed (6 bytes, `cmp -l`): `Trusted` here
    and in c2patool 0.27.22, `Invalid` in 0.28.0.
  - All 165 corpus files with a store and `c2pa.hash.data`: 128 exact,
    23 uncovered, 14 wider, of which 11 are own negative variants that
    are already `Invalid`.
- Reasoned: `c2pa` 0.91.0 `claim.rs` `data_hash_exclusions_match_manifest()`
  (exact equality) read. C2PA 2.4 VAL-ASSE-0043/0044/0045/0077 read through
  `encypherai/c2pa-knowledge-graph` 2.4; the section number is still to be
  confirmed. SPEC-012 amendment 5 (step 38) is where the hole came in, and
  `docs/conformance.md`'s `PRED-IMG-004` row is wrong.
- Decided by Maurice: to examine these files before SPEC-031 goes further.

## 2026-09-24 — PRED-IMG-004 corrected in the public texts
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "akkoord met 1 en 2". Item 1: correct the false `PRED-IMG-004`
  claim now. Item 2: SPEC-012 amendment 7, which follows separately.
- Produced: `docs/conformance.md` (the row becomes **gap**, the counts go
  from 54/17 to 53/18, a new section 0 explains that this gap can produce
  a wrong `Trusted`, and the header records the correction); `README.md`
  (17 → 18, plus a pointer to the open gap); `SECURITY.md` (the per-gap
  claim corrected, a third finding added, marked open and present in
  0.1.0); `CHANGELOG.md` (an `Unreleased` "known, not yet fixed" entry).
- Measured: `composer check` gives 422 passed; every changed number
  counted against `docs/conformance.md` after the edit.
- Reasoned: the facts come from step 108. No code changed, so the hole is
  still open, and the texts now say so.
- Decided by Maurice: correct the public claim now; fix it under SPEC-012
  amendment 7.

## 2026-09-24 — SPEC-012 amendment 7, the test seen red
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "akkoord met 1 en 2". This entry is item 2's first half.
- Produced: SPEC-012 amendment 7 (scope item 5 and AC3 rewritten: an
  exclusion holding part of the store holds nothing else);
  `tests/Unit/Hash/DataHashCheckTest.php` AC3, where the Truepic file now
  expects `assertion.dataHash.mismatch` naming 13617 and no digest, plus
  the step-108 tamper rebuilt in `php://memory`.
- Measured: `vendor/bin/pest --filter="AC3: an exclusion must cover the
  store"` gives 1 failed: `assertion.dataHash.match` where
  `assertion.dataHash.mismatch` is expected, at the first Truepic
  assertion. The tamper half runs after that point and is proven by step
  108's measurement, not by this run.
- Decided by Maurice: reverse amendment 5 (item 2).

## 2026-09-24 — Step 109, SPEC-012 amendment 7 implemented
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "akkoord met 1 en 2". This entry is item 2's second half.
- Produced: `src/Hash/DataHashCheck.php` (an exclusion holding part of the
  store holds nothing else); SPEC-017 amendment 4 with its AC6 test; the
  AC3 test renamed after the new rule, with the traceability row to
  match; the rewind fix in the in-memory tamper;
  `notes/step-109-exclusion-holds-only-the-store.md`; `docs/conformance.md`,
  `docs/comparison.md`, `README.md`, `SECURITY.md`, `CHANGELOG.md`,
  `docs/milestones.md`, `NOTES.md`.
- Measured: `composer check` gives 422 passed, clean. 864 runs of
  `bin/c2pa-verify` (288 corpus files × no settings, full-plus-digicert-g4,
  truepic-root) with the old and the new `DataHashCheck`: 9 lines differ,
  all Truepic, and the verdict changes only under truepic-root
  (`Trusted` → `Invalid`). The step-108 tampered copy is `Invalid`.
- Reasoned: padding in JPEG/PNG/WebP lives inside the store, so the rule
  is written per exclusion (length = the sum of the pieces held), not as
  one span. Not measured: a writer that pads outside the store in these
  formats; none is in the corpus.
- Decided by Maurice: reverse amendment 5. Release and advisory are not
  decided.

## 2026-09-24 — SPEC-031 open question 1 answered: every anchor counts for its own kind
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "Kan je een advies geven over die open vraag", then "ja, pas
  SPEC-031 zo aan".
- Produced: `specs/SPEC-031-trust-anchors-list.md`. Open question 1
  answered with its reasons and its cost. AC6 extended to the
  time-stamping side (`c2pa-rs/C.jpg` with the DigiCert cross-certificate
  as `"tsa"` and as `"manifest"`) and to a `"tsa"` entry's
  `allowed_list`. The API sketch gains `$tsaAllowedList`. The
  `docs/milestones.md` row is updated. No test or code.
- Measured: nothing new; the answer rests on probes N3, N4 and T2 (step
  107) and on the two official list files.
- Reasoned: `c2pa` 0.91.0 `certificate_trust_policy.rs`: the per-kind
  filters are used by `ocsp.rs` (signing) and by tests only, while the
  chain check in `certificate_trust/openssl.rs` walks every anchor set.
  The settings documentation names the Mozilla S/MIME root store as the
  `"cawg"` list. That an S/MIME leaf would then be `Trusted` for C2PA is
  reasoned, not measured.
- Decided by Maurice: separate the kinds strictly, `"manifest"` not
  counting for TSAs; the legacy string counts for both.

## 2026-09-24 — Step 110, SPEC-031's two pre-approval questions settled
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, zoek die twee eerst uit" (the §14.4.1 number against 2.4, and
  per-entry `trust_config` with a leaf whose EKU is not built in).
- Produced: `notes/step-110-trust-lists-in-2.4.md`. `SPEC-031`: the 2.4
  references, AC6 (the `"tsa"` allowed list refused), a new AC8, the API
  sketch (`TrustAnchorSet`), open question 2 answered, new open questions 5
  and 6. SPEC-012 amendment 7 and `DataHashCheck` now cite §15.12.1.1/.2.
  Rows in `docs/milestones.md` and `NOTES.md`.
- Measured: the 2.4 HTML of `c2pa-org/specifications` at `4eb2c67`
  (§14.4.1–3, §15.12.1.1–2, the x5chain text). A throwaway
  root/intermediate/leaf (EKU 1.3.6.1.4.1.99999.1) made with `openssl` in
  the scratchpad, `fixture-unsigned.jpg` signed with c2patool 0.28.0, and
  nine settings E1–E9 run through c2patool 0.28.0 and, for the legacy
  shape, `bin/c2pa-verify`. The table is in the note. Also a
  one-certificate chain, which this verifier refuses and 0.28.0 trusts.
- Reasoned: `TimestampCheck.php:223` passes the loose allowed list to the
  TSA check, which §14.4.3 forbids. Not measured yet.
- Decided by Maurice: to settle these two before approving SPEC-031.

## 2026-09-24 — Step 111a, SPEC-031 approved and its tests seen red
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "SPEC-031 goedgekeurd, begin met de tests".
- Produced: SPEC-031 status `approved`; `bin/make-anchors-variants.php`;
  `tests/Fixtures/trust/anchors/` (20 settings, `eku-probe.jpg`,
  `eku-probe-root.pem`); `tests/Fixtures/c2patool/anchors/` (25 c2patool
  0.28.0 reports); `tests/Unit/Trust/TrustAnchorsTest.php` (AC1–AC8);
  `notes/step-111-trust-anchors-tests.md`; rows in `docs/milestones.md`
  and `NOTES.md`.
- Measured: `vendor/bin/pest --group=SPEC-031` gives 8 failed, each reason
  read (see the note). `composer check` gives 8 failed, 422 passed; PHPStan,
  Deptrac and Pint clean. No `PRIVATE` PEM header in the new fixture
  directories.
- Reasoned: AC5's first needle matched today's unknown-key message and was
  tightened to `not a list` before commit.
- Decided by Maurice: SPEC-031 approved. Committed locally, not pushed,
  so that `main` does not go red.

## 2026-09-24 — Step 111b, SPEC-031 implemented
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, begin met 111b".
- Produced: `src/Trust/TrustAnchorSet.php` (new); `src/Trust/TrustSettings.php`,
  `src/Trust/ChainCheck.php`, `src/Trust/CertificateProfileCheck.php`,
  `src/Timestamp/TimestampCheck.php`; `bin/api-check.php` (the eleventh
  contract class); `tests/Fixtures/api/public-surface.txt` (+12);
  `tests/Unit/ApiSurfaceTest.php` (111); `tests/Unit/Trust/ChainCheckTest.php`
  (SPEC-014 AC3 on entries); SPEC-031 `implemented` with traceability and
  amendments 1–2; SPEC-014 amendment 3; SPEC-025 amendment 4; `README.md`
  (settings, contract table); `docs/comparison.md` (four rows);
  `CHANGELOG.md` (`Unreleased`: Added/Changed, "this will be 0.2.0"); the
  111b half of `notes/step-111-trust-anchors-tests.md`; rows in
  `docs/milestones.md` and `NOTES.md`.
- Measured: `vendor/bin/pest --group=SPEC-031` gives 8 passed.
  `composer check` gives 430 passed, and PHPStan, Deptrac, Pint and the API
  check are clean. `composer test:parallel` gives 430 passed.
  `bin/spec-check.php`: 32 specs, 37 test files.
- Reasoned: refusing a loose `allowed_list` breaks settings files that
  worked in 0.1.0, so under the changelog's own rule the next tag is
  0.2.0.
- Decided by Maurice: none in this step beyond the approval of SPEC-031.
  Tagging stays his call ("nog even niet taggen").

## 2026-09-24 — Step 112, eku-c2pa.png examined
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "onderzoek eku-c2pa.png eerst".
- Produced: `notes/step-112-claim-signing-eku.md`, a `docs/comparison.md`
  row, rows in `docs/milestones.md` and `NOTES.md`. No specification, test
  or code.
- Measured:
  - `eku-c2pa.png`, `good.png`, `eku-mixed.png` and
    `eku-outside-list.png` under 0.27.22, 0.28.0 and `bin/c2pa-verify`.
  - `eku-c2pa.png` under five `trust_config`s.
  - Three throwaway leaves (C2PA only, documentSigning only, C2PA +
    emailProtection) under step 110's scratchpad CA, each under four
    `trust_config`s and without settings.
  - The leaf EKUs of 47 signed corpus files.
  - The table is in the note.
- Reasoned: from the C2PA 2.4 text (§14.4.1, the 2.4 change list,
  §14.5.1) and `c2pa` 0.91.0 (`has_allowed_eku()` unchanged,
  `valid_eku_oids.cfg` unchanged, the default not applied on the
  settings path; the exact code path was not pinned down). Not a wrong
  `Valid` here; a probable upstream regression.
- Decided by Maurice: to examine this file first.

## 2026-09-24 — Step 113, anchors tied to EKUs measured
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, meet eerst de koppeling tussen anchors en EKU's".
- Produced: `notes/step-113-anchors-and-ekus.md`, rows in
  `docs/milestones.md` and `NOTES.md`. No specification, test or code.
- Measured: the official `C2PA-TRUST-LIST.pem` + `C2PA-TSA-TRUST-LIST.pem`
  as one settings file over every signed corpus file (`bin/c2pa-verify`):
  two reach an official anchor. The EKUs along both chains were read with
  the own `Certificate` class. c2patool 0.28.0 gives the same verdict on
  both. `good.png` (an emailProtection-only leaf) is Trusted in all three.
- Reasoned: C2PA 2.4 §14.4.1, §14.5.1.2 and the change list; the C2PA
  Certificate Policy's EKU rows (conformance-public `docs/v0.2`); `c2pa`
  0.91.0's EKU and trust functions (step 110); the settings format cannot
  express the association.
- Decided by Maurice: to measure this before anything else.

## 2026-09-24 — §14.5.1 named, not built
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "route 1, benoemen en stoppen".
- Produced: `docs/conformance.md` gains a section *Outside the catalogue*
  with §14.5.1.2 as a gap by decision; a `docs/comparison.md` row; the
  step-113 note and the milestone row record the decision.
- Measured: nothing new (step 113).
- Decided by Maurice: route 1. Name the gap and build nothing; no new
  setting and no upstream question for now.

## 2026-09-24 — Step 114, the allowed list and time-stamps measured
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, meet eerst punt 2" (SPEC-031 open question 6).
- Produced: `notes/step-114-allowed-list-and-time-stamps.md`, rows in
  `docs/milestones.md` and `NOTES.md`. No specification, test or code.
- Measured: two throwaway scripts on the public API. Three files (C.jpg,
  adobe C, truepic camera) were verified with and without their own TSA
  leaf on the constructor's allowed list: the TSA goes from untrusted to
  trusted, and on the Truepic file `signingCredential.expired` disappears.
- Reasoned: C2PA 2.4 §14.4.3 and §14.5.1.2. `c2pa` 0.91.0
  `time_stamp/verify.rs` sends the TSA chain to `check_certificate_trust()`,
  which checks the end-entity set first. c2patool cannot serve as an
  oracle here, because it trusts these TSAs with no anchor.
- Decided by Maurice: to measure this first.

## 2026-09-24 — Step 115, the allowed list kept away from time-stamps
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, akkoord, doe het zo" (the one-argument fix from step 114).
- Produced: `tests/Unit/Timestamp/TimestampCheckTest.php` (SPEC-017 AC13);
  `src/Timestamp/TimestampCheck.php` (`tsaSettings()` passes no allowed
  list); SPEC-017 amendment 5 (scope, API sketch, AC13, traceability, the
  orphan AC12 recorded); SPEC-031 open question 6 marked answered;
  `docs/comparison.md` row; `CHANGELOG.md` (`Unreleased`, Changed); the
  step-114 note's addendum; rows in `docs/milestones.md` and `NOTES.md`.
- Measured: `vendor/bin/pest --filter="SPEC-017 AC13"` gives 1 failed on
  `tsaSettings()->allowedList`, then 1 passed after the fix.
  `composer check` gives 431 passed, clean after Pint formatted the new
  test.
- Reasoned: none new; the evidence is step 114's.
- Decided by Maurice: the fix as proposed. The orphan AC12 test is only
  recorded, not changed.

## 2026-09-24 — Step 116, spec-check reads named criteria
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, eerst de wees-AC12 en spec-check aanscherpen".
- Produced: `bin/spec-check.php` (`specCheckNamedCriteria()`,
  `specCheckTracedCriteria()`, the AC11 finding);
  `tests/Fixtures/spec-check/orphan-criterion/`; `tests/Unit/SpecCheckTest.php`
  AC11; SPEC-000 AC11, amendment 1 and its row; Traceability rows for
  SPEC-013 AC16–AC18 and SPEC-015 AC11; SPEC-017 AC12 and amendment 6;
  `notes/step-116-named-criteria.md`; rows in `docs/milestones.md` and
  `NOTES.md`.
- Measured: a throwaway tokenizer script over the suite gives 394
  declarations, 376 naming a criterion, and 5 orphans. Its first run
  miscounted braces on `"{$var}"`, which was corrected and is recorded.
  AC11 was red on the fixture (no findings), then green. The repository
  self-check then named 6 findings, and those were repaired.
  `composer check` gives 432 passed. Falsified: without SPEC-017's AC12
  row, spec-check reports `FAIL: 1 finding(s)` at line 627.
- Reasoned: rows only (not a bold `**ACn`) is the standard, because the
  Traceability table is what a reader follows.
- Decided by Maurice: to do the orphan and the spec-check rule first.

## 2026-09-24 — Step 117, a one-certificate x5chain accepted
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "eerst punt 1, de x5chain meenemen vóór de release".
- Produced: `bin/make-x5chain-variants.php`; `tests/Fixtures/cose/x5chain-single.jpg`,
  `x5chain-single-root.pem`, `x5chain-single-root.settings.json`;
  `tests/Fixtures/c2patool/x5chain/` (four reports);
  `tests/Unit/Cose/CoseSign1Test.php` AC13; `src/Cose/CoseSign1.php`
  (`chain()`); SPEC-008 scope, AC13, amendment 2 and its row;
  `CHANGELOG.md` (Fixed); `notes/step-117-one-certificate-chain.md`; rows
  in `docs/milestones.md` and `NOTES.md`.
- Measured: c2patool 0.28.0 and 0.27.22 on the fixture give Valid (bare)
  and Trusted (root). AC13 was red on the refusal, then green after the
  fix; one message was corrected in between. spec-check refused the
  commit until AC13 had its row. `composer check` gives 433 passed. 166
  corpus signatures read: only the new fixture has a byte-string x5chain.
- Reasoned: RFC 9360 as quoted in C2PA 2.4 §14.5.
- Decided by Maurice: take this in before the release.

## 2026-09-24 — No security advisory for 0.1.0
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: to show a draft GitHub security advisory for the step-108 hole
  in 0.1.0. After reading it: "Niemand gebruikt het nog dus zo'n advisory
  hoeft niet".
- Produced: a draft in the session scratchpad only; nothing was created on
  GitHub. The finding stays public where it already is: `SECURITY.md`
  (*Findings so far*), the `CHANGELOG.md` security entry, and notes 108
  and 109.
- Measured / Reasoned: none new.
- Decided by Maurice: no advisory, because the package has no known users.

## 2026-09-24 — Step 118, a hard binding only gathered is missing
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, akkoord met die volgorde maar nog niet taggen" (first the
  hard-binding-in-gathered fix, then the oracle texts; no tag).
- Produced: `tests/Unit/Verifier/VerifierTest.php` AC19;
  `src/Verifier/Verifier.php` (`hardBindingGatheredOnly()` and the branch
  that uses it); SPEC-013 AC19, amendment 13 and its row;
  `tests/Fixtures/c2patool/absence/hash-data-gathered.0.28.0.stderr.txt`;
  the absence README row; `CHANGELOG.md`; `docs/comparison.md`;
  `notes/step-118-hard-binding-in-gathered.md`; rows in
  `docs/milestones.md` and `NOTES.md`.
- Measured: the eight remaining step-107 files, ours against 0.28.0 (only
  this one was ours-Valid / theirs-not). AC19 was red (Valid), then green.
  spec-check refused the build until the row existed. `composer check`
  gives 434 passed. 870 runs before and after: only this file changes, on
  3 lines.
- Reasoned: C2PA 2.4 §10.2.2 and §15.10.1.2, read in the 2.4 HTML.
- Decided by Maurice: fix before the release; do not tag.

## 2026-09-24 — Step 119, the oracle texts before 0.2.0
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: the second item of the agreed order (the texts about the oracle),
  still without tagging.
- Produced: `docs/comparison.md` (a paragraph on c2patool 0.28.0; two
  outdated bullets corrected); `README.md` (0.28.0 named; the amendment
  count split into 87 confirmed and 11 awaiting confirmation);
  `docs/conformance.md` (0.28.0 named); rows in `docs/milestones.md` and
  `NOTES.md`.
- Measured: 98 amendments counted across all specs, 87 of them confirmed
  up to step 93. `composer check` gives 434 passed.
- Reasoned: the two corrected bullets were contradicted by steps 61 and
  113.
- Decided by Maurice: no tag yet.

## 2026-09-24 — Step 120, the eleven amendments for confirmation
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, zet ze op één pagina".
- Produced: `notes/step-120-amendments-since-93.md` (the eleven by weight,
  each with what, why and which verdicts moved); rows in
  `docs/milestones.md` and `NOTES.md`.
- Measured: 98 amendments across the specs, 87 confirmed through step 93.
- Reasoned: the weights as each amendment states them; SPEC-017 #4 is
  listed under A because its verdict change is carried by SPEC-012 #7.
- Decided by Maurice: none yet; the page awaits his confirmation.

## 2026-09-24 — Step 120b, the eleven amendments confirmed
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, alle elf bevestigd".
- Produced: a stamp "Confirmed by Maurice van Loon, 2026-09-24 (step 120)"
  under each of the eleven amendments (SPEC-000 #1, SPEC-008 #2, SPEC-012
  #7, SPEC-013 #13, SPEC-014 #3, SPEC-017 #4–6, SPEC-025 #4, SPEC-031 #1–2);
  the step-120 note marked confirmed row by row; `README.md` (98, every
  one confirmed); rows in `docs/milestones.md` and `NOTES.md`.
- Measured: `bin/spec-check.php` OK after stamping; 11 stamps placed, 11
  table rows updated.
- Decided by Maurice: all eleven amendments confirmed.

## 2026-09-24 — Step 121, fuzzing and four gaps measured
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, begin met fuzzen en dan de meting".
- Produced: `notes/step-121-fuzz-and-four-gaps.md`, rows in
  `docs/milestones.md` and `NOTES.md`. No specification, test or code.
- Measured:
  - `php bin/fuzz.php 20260924 60 <out> <corpora + today's fixtures>`:
    7,722 runs, 0 faults, 204 Valid survivors, all Valid in c2patool
    0.27.22 and 0.28.0.
  - A throwaway settings fuzzer (seed 20260924, 300 rounds × 35 files):
    10,500 runs, 0 faults.
  - Six probe manifests signed with c2patool 0.28.0 under step 110's
    scratchpad CA, verified in three tools. The table is in the note.
    One probe (#11) could not be built.
  - The first three-tool loop reported NOREPORT for this verifier, because
    zsh does not word-split a variable. It was rerun in Python and the
    slip is recorded.
- Reasoned: C2PA 2.4 §15.10.3.2.2, §15.10.3.2.3 and §18's actions and
  metadata text; SPEC-018's named out-of-scope content family.
- Decided by Maurice: to fuzz and then measure before tagging 0.2.0.

## 2026-09-24 — SPEC-032 drafted
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, schrijf SPEC-032 als draft".
- Produced: `specs/SPEC-032-created-source-type-and-external-references.md`
  (draft, seven criteria, four open questions); rows in
  `docs/milestones.md` and `NOTES.md`. No test or code.
- Measured: a corpus scan (throwaway script) found 151 `c2pa.created`
  actions, 19 without `digitalSourceType`, 18 of them in v1 claims, and no
  external-reference assertion. c2patool 0.27.22 and 0.28.0 on
  `adobe-20220124-C.jpg` (Valid, no actions fault) and
  `c2pa-rs/no_alg.jpg` (refused: unknown algorithm).
- Reasoned: `c2pa` 0.91.0 `verify_actions()` (rule 2.b.v; v1 claims
  skipped unless `strict_v1_validation`), `verify_external_reference()`
  and `ExternalReference::validate()` (fourteen forbidden labels). C2PA
  2.4 §15.10.3.2.2, §15.10.3.2.3, §18.15.2 and §18.24.
- Decided by Maurice: SPEC-032 as a draft.

## 2026-09-24 — Step 122a, SPEC-032 approved and its tests seen red
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, volg c2patool bij vraag 2, SPEC-032 goedgekeurd".
- Produced: SPEC-032 `approved` (the answers recorded);
  `bin/make-spec032-variants.php`; `tests/Fixtures/assertion-rules/` (11
  probes, the root, its settings); `tests/Fixtures/c2patool/assertion-rules/`
  (44 reports); `tests/Unit/Manifest/AssertionRulesTest.php` (AC1–AC7);
  `notes/step-122-spec032.md`.
- Measured: both c2patool versions on every probe (the table is in the
  note). c2patool's builder wrote every malformed shape. `alg` or `hash`
  alone is Trusted in both oracles. `vendor/bin/pest --group=SPEC-032`
  gives 5 failed, 2 passed; the two passes are guards (AC2, AC3). No
  `PRIVATE` PEM header in the new fixture directories.
- Reasoned: C2PA 2.4 §15.10.3.2.2 on a lone `alg`/`hash`; AC5 follows
  the text and is stricter than both oracles there.
- Decided by Maurice: approve SPEC-032 and follow c2patool on rule A.
  Committed locally, not pushed.

## 2026-09-24 — Step 122b, SPEC-032 implemented
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: continuing the approved SPEC-032 (tests seen red in 122a).
- Produced: `src/Manifest/ExternalReferenceCheck.php` (new);
  `src/Manifest/ActionsCheck.php` (rule A); `src/Report/StatusCode.php`
  (one case); `src/Verifier/Verifier.php` and `IngredientManifestCheck.php`
  (the call); `tests/Fixtures/api/public-surface.txt` (+1); test updates in
  `AssertionRulesTest`, `ActionsCheckTest`, `ApiSurfaceTest`,
  `CertificateProfileCheckTest`; SPEC-032 `implemented` with traceability
  and amendment 1; SPEC-018 amendment 4; SPEC-025 amendment 5;
  `docs/conformance.md`, `docs/comparison.md`, `README.md`, `SECURITY.md`,
  `CHANGELOG.md`; the 122b half of the note; rows in `docs/milestones.md`
  and `NOTES.md`.
- Measured: `vendor/bin/pest --group=SPEC-032` gives 7 passed.
  `composer check` gives 441 passed, clean. 903 runs with the old code in a
  worktree (own vendor copy) against the new: only the probes change, and
  `no_alg.jpg` gains one code with its verdict unchanged.
- Reasoned: none new beyond the spec.
- Decided by Maurice: none in this step beyond the approval.

## 2026-09-24 — README amendment count corrected
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: nothing new; this is a correction found after step 122b.
- Produced: `README.md` now says 101 amendments, 98 confirmed and three
  (SPEC-032 #1, SPEC-018 #4, SPEC-025 #5) awaiting confirmation.
- Measured: 101 numbered amendments counted across the specs.
- Decided by Maurice: none.

## 2026-09-24 — The three SPEC-032 amendments confirmed; issue #2 answered
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, alle drie bevestigd, en nog geen tag en reageer op #2".
- Produced: confirmation stamps under SPEC-032 #1, SPEC-018 #4 and
  SPEC-025 #5; `README.md` (101 amendments, every one confirmed); a
  comment on issue #2 saying the `c2pa.created` case is enforced and the
  general case stays open, with the measurement and the 2.4 reading. Issue
  #3 was not touched, and no tag was made.
- Measured: `bin/spec-check.php` OK.
- Decided by Maurice: the three amendments confirmed; no tag yet; reply
  on #2 only.

## 2026-09-24 — Issue #3 closed
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, sluit #3 met een korte toelichting, geen tag".
- Produced: a closing comment on issue #3 (what SPEC-032 checks, the new
  status code, what both oracles say, where the details are); the issue is
  closed. No tag.
- Decided by Maurice: close #3; no tag.

## 2026-09-24 — Step 123, the actions content family measured
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "eerst die meting, nog geen tag".
- Produced: `notes/step-123-actions-content-family.md`, rows in
  `docs/milestones.md` and `NOTES.md`. No specification, test or code; no
  tag.
- Measured: ten probe manifests signed with c2patool 0.28.0 under step
  110's scratchpad CA (one could not be embedded), verified under the root
  by c2patool 0.27.22, 0.28.0 and `bin/c2pa-verify`. The table is in the
  note. `StatusCode` checked for the two codes involved.
- Reasoned: `c2pa` 0.91.0 `verify_actions()` read for its rule list; the
  size (one spec, about eight criteria, two codes) is an estimate.
- Decided by Maurice: measure before tagging.

## 2026-09-24 — Release 0.2.0 prepared and tagged
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, tag 0.2.0".
- Produced: `CHANGELOG.md` (`Unreleased` becomes `0.2.0 — 2026-09-24`, with
  a release paragraph); `README.md` (the current tag, and what broke
  coming from 0.1.0); an annotated tag `v0.2.0` on the release commit,
  pushed after CI was green on that exact commit.
- Measured: `composer check` gives 441 passed; `bin/package-check.php` gives
  269 files, 2.8 MB. CI on the release commit, and the GitHub archive
  of the tag against the local count, are recorded in the next entry.
- Decided by Maurice: tag 0.2.0.

## 2026-09-24 — v0.2.0 verified after the push
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: the check after tagging, as for 0.1.0 (step 101).
- Produced: nothing in the repository beyond this entry and the rows below.
- Measured: CI green on the release commit `4093bbb` before tagging. The
  tag `v0.2.0` pushed. GitHub's zipball of the tag holds 269 files, equal
  to `git archive v0.2.0` and to `bin/package-check.php`. Packagist lists
  `v0.2.0` (the webhook fired). `composer require
  provemark/c2pa-verifier:^0.2` in an empty project installs v0.2.0, and
  its `vendor/bin/c2pa-verify` gives `Valid` on `fixture-signed.jpg`.
- Decided by Maurice: tag 0.2.0.

## 2026-09-24 — SPEC-033 drafted
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "schrijf SPEC-033 als draft voor de actie-regels".
- Produced: `specs/SPEC-033-actions-content-rules.md` (draft, nine
  criteria, four open questions); rows in `docs/milestones.md` and
  `NOTES.md`. No test or code.
- Measured: a corpus scan of v2 claims (throwaway script): only four
  `c2pa.opened` actions (in `c2pa-rs/CACA.jpg` and
  `ingredient-manifest/ingredient-signature-broken.jpg`), each naming one
  ingredient; none of the other shapes.
- Reasoned: `c2pa` 0.91.0 `verify_actions()` read in detail (the inception
  count and its bare-label url, 2.a, 2.b.i–iv with resolution by label,
  2.c, translated, 2.f, the soft-binding check); C2PA 2.4 §15.10.3.2.3 and
  §18.15.4.7.
- Decided by Maurice: SPEC-033 as a draft.

## 2026-09-24 — Step 125a, SPEC-033 approved and its tests seen red
- Model: Claude Opus 5.5 (1M context), Claude Code CLI
- Asked: "ja, volg c2pa-rs bij vraag 2, SPEC-033 goedgekeurd".
- Produced: SPEC-033 `approved` (answers recorded) and amendment 1 (written
  before the tests); `bin/make-spec033-variants.php`;
  `tests/Fixtures/actions-rules/` (21 probes, root, settings);
  `tests/Fixtures/c2patool/actions-rules/` (42 reports);
  `tests/Unit/Manifest/ActionsContentTest.php` (AC1–AC9);
  `notes/step-125-spec033.md`.
- Measured: how c2patool's builder links an action to an ingredient
  (`ingredientIds`). Both c2patool versions on every probe (the table is
  in the note). The first soft-binding control was malformed (`value` as
  text), and was rebuilt with bytes. `vendor/bin/pest --group=SPEC-033`
  gives 8 failed, 1 passed (AC8 is a guard). No `PRIVATE` PEM header in
  the new fixture directories.
- Reasoned: `c2pa-rs` returns after the opening fault, hence amendment 1.
- Decided by Maurice: approve SPEC-033 and follow c2pa-rs on question 2.
  Committed locally, not pushed.
