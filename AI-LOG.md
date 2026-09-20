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
