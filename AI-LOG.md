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
