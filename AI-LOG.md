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
