# Step 52 — The command line (SPEC-019): tests first

*2026-09-22.* SPEC-019 adds one executable, `bin/c2pa-verify <file>
[--settings <path>]`, around the public API: `VerificationReport::toJson()`
plus one newline on standard output, `Error: …` on standard error, and the
verdict in the exit status. This note records the tests-first step (52a);
the implementation (52b) is appended below when it exists.

## What the tests pin down

`tests/Unit/Cli/CommandTest.php`, twelve tests in group `SPEC-019`, one per
acceptance criterion. Every test but one calls `Cli\Command::run($arguments,
$stdout, $stderr)` in-process on `php://memory` streams; the expectation for
standard output is always the library's own answer, built in the test with
the same `Verifier::verify()` call (`spec019Expected()`), so that the
command can never say something the library does not.

| AC | the assertion that bites |
|---|---|
| 1 | `fixture-signed.png`: exit 0, stdout byte-equal to `toJson()."\n"`, `Valid`, stderr empty |
| 2 | with `full-plus-digicert-g4.settings.json` in four spellings/orders: exit 0, `Trusted` |
| 3 | `binding/pixel-changed.png`: **exit 1** with the report — c2patool exits 0 here |
| 4 | `fixture-unsigned.png`: exit 1, `"has_manifest": false` in the report |
| 5 | `README.md`: exit 1, the verifier's "unsupported file type" report |
| 6 | a missing path: exit 2, stdout empty, one stderr line `Error: cannot open <path>: … No such file or directory`; a directory: `Error: cannot open <path>: Is a directory` |
| 7 | `--settings` naming a missing file: **exit 2, no report** — c2patool ignores the file and prints an untrusted report with exit 0 |
| 8 | `--settings README.md` and `--settings trust/unknown-key.settings.json` (`{"foo": 1}`, new fixture): exit 2, SPEC-014's `TrustException` message verbatim after `Error: settings <path>: ` |
| 9 | no arguments, two paths, `--settings` last, `--bogus`, `-x`, `--settings` twice: exit 2, `Error: …` plus the usage on stderr; `--help`: exit 0, usage on stdout; `-- --settings`: a file name |
| 10 | `bin/c2pa-verify` as a process (`proc_open` with `PHP_BINARY`): exit 0 / 1 / 2 and the same bytes as AC1, AC3-with-settings, AC6 |
| 11 | all 70 corpus files × {no settings, settings}: stdout equal to the API, exit 0 iff `Trusted`/`Valid`, stderr empty; all three states seen |
| 12 | every `jumbf/`, `cose/`, `binding/`, `claim/` PNG variant: one JSON document, one trailing newline, no control byte on stdout (`label-control.png` among them, whose message carries file bytes) |

## Measured

- `vendor/bin/pest --group=SPEC-019`: **12 failed** (5 assertions), every
  one `Class "Provemark\C2paVerifier\Cli\Command" not found` at
  `CommandTest.php:31` — the red run. PHPStan on the file reports three
  findings, all the missing class (the third, a `mixed` return, follows
  from it); Pint passes.
- `fopen($dir, 'rb')` **succeeds** on macOS (PHP 8.5) for a directory; the
  verifier then reads nothing (`fread(): Read of 8192 bytes failed with
  errno=21 Is a directory`, a notice) and reports "unsupported file type".
  AC6's directory case therefore has to be refused by the command itself,
  before the verifier, and the test says so with the exact stderr line. The
  spec's AC6 named the directory case without saying who refuses it; this
  is the answer, not an amendment.
- The fixture `tests/Fixtures/trust/unknown-key.settings.json` is new
  (`{"foo": 1}`, README row added): AC8 wanted an unknown top-level key
  and the alternative was a temporary file in the test.

## Reasoned

- The tests call `new Command(new Verifier)` — the shape of the API sketch.
  Should 52b find a better shape, the tests change first and the note says
  why.
- AC11 does not consult c2patool's JSON: the report's equality with
  c2patool is SPEC-013's drift alarm, and repeating it here would make two
  tests fail for one cause.

## 52b — the implementation

*2026-09-22, same day.*

- `src/Cli/Command.php` — `run(array $arguments, $stdout, $stderr): int` in
  four steps: the argument loop (four cases: `--help`, `--`,
  `--settings`/`--settings=`, anything else; a repeated `--settings`, a
  second path, an unknown option or a dangling `--settings` is a usage
  fault), the settings *before* the file (the caller asked for trust and
  gets it or a refusal), the file as a stream (`is_dir()` first, then
  `fopen()` under a temporary error handler that keeps PHP's reason —
  "No such file or directory" — for the `Error:` line; no `@`), the
  report and the exit status (`ValidationState::Invalid` → 1, else 0).
  Standard output is written once, `toJson()."\n"`.
- `bin/c2pa-verify` — the shim: the autoloader (the project's, or the
  consumer's three levels up when installed under `vendor/bin`), `new
  Command(new Verifier)`, `run(array_slice($argv, 1), STDOUT, STDERR)`,
  `exit`. `composer.json` `bin`. Note: Composer links a package's `bin`
  into a *consumer's* `vendor/bin/`, not into the package's own — in this
  repository the command is `bin/c2pa-verify`.
- `deptrac.yaml` — the `Cli` layer → Verifier, Trust, Report, Support
  (`Report` for `ValidationState`, an addition to the API sketch; SPEC-019
  amendment 1). Nothing depends on `Cli`.

### Measured

- `vendor/bin/pest --group=SPEC-019`: first run **11 passed, 1 failed** —
  AC11 counted 68 files against 70 names: `adobe-20220124-C` is both an
  own-corpus name and an official file (one path), and
  `adobe-20220124-E-clm-CAICAI` is an official file *and* a c2pa-rs
  fixture (two paths, same name). The corpora are now keyed by path, 69
  files, both copies tested.
- Second run: AC11 threw **`JsonException`: Malformed UTF-8 characters**
  out of `VerificationReport::toJson()` on
  `writers/openai-20260826-c2pa_2x.png`, with and without settings. The
  cause, walked with a script over `toArray()`: a `Cbor\CborBytes` object
  at `manifests/<label>/claim_generator_info/0/icon/hash` — OpenAI's
  generator entry carries an `icon` that is a hashed URI (`url`, 32-byte
  `hash`), and `ManifestStore::manifestArray()` passed
  `claim_generator_info` through as decoded while every other value goes
  through `plain()` (bytes → base64). **An exception escaping the public
  API on a real writer's file**, unseen for four days because the writers
  drift alarm compares states and codes, not the rendering. Fixed in
  `ManifestStore` (SPEC-007 amendment 5) with a regression test in the
  SPEC-007 file, seen red first ("Failed asserting that CborBytes Object
  …"). Not a verdict fault — the state was `Valid` before and after — but
  a library that throws on `toJson()` is one a CLI cannot be built on.
- One test-writing gotcha on the way: Pest's `toHaveLength()` counts
  UTF-8 *characters* on a string (`mb_strlen`), so a 32-byte binary hash
  "has length 31"; `strlen()` and `toBe(32)` instead.
- `composer check`: exit 0 — spec-check OK, Pint passed, PHPStan 0
  errors, Deptrac 0 violations, **314 passed** (301 + 12 + 1).
- From the shell: `bin/c2pa-verify fixture-signed.png --settings
  full.settings.json` → `"validation_state": "Trusted"`, exit 0;
  `pixel-changed.png` → exit 1; `/nope.png` → `Error: cannot open
  /nope.png: No such file or directory`, exit 2; `--settings /nope.json`
  → `Error: cannot read settings /nope.json: No such file or directory`,
  exit 2; `--help` → the usage, exit 0; no arguments → `Error: no file
  given` plus the usage, exit 2; `exp-test1.png` (5.6 MB) in 0.08 s.

### Reasoned

- `is_dir()` before `fopen()`: measured in 52a that `fopen()` on a
  directory succeeds; the check is a guard, not a policy — a FIFO or a
  device would open and be read like any stream, and the verifier's own
  bounds apply.
- The settings are read before the file is opened so that a usage-level
  fault (a bad settings file) never leaves a stream open, and so that the
  order of the two errors is fixed whatever the argument order.

## Where this leaves the project

SPEC-019 `implemented`. The CLI makes every later measurement (M7's
ingredient files, a reader's own file) one command; the JSON-rendering
hole it found is the first fault of that kind in the writers corpus and
argues for a rendering drift alarm — `toJson()` on every corpus file —
which AC11 now is, for as long as the command exists.

### CI, and one flaky comparison

Run 35714422755 on `ded2fcd`: PHP 8.3 and 8.4 green, **PHP 8.5 red** on
AC11 — `truepic-20230212-camera` without settings: the expectation and
the command's run straddled a second, and the `signingCredential.expired`
message names the second of the check ("expired at 2026-09-22T10:10:09Z"
against "…:10Z", "checked at now"). Not a fault of the command; a fault of
the test's byte-equality on a message that carries the clock. AC11 now
masks that one timestamp (`expired at <now>: `) on both sides before
comparing; everything else stays byte-exact.
