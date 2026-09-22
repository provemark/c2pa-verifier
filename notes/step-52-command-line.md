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
