# SPEC-019: The command line — `bin/c2pa-verify <file> [--settings <path>]`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Everything the verifier can say is reachable from PHP only:
`Verifier::verify($stream, ?TrustSettings)` returns a `VerificationReport`,
and `toJson()` renders it as c2patool's JSON (SPEC-010, SPEC-013). Anyone who
wants a verdict without writing PHP — a reviewer comparing this verifier with
c2patool file by file, a shell script, a CI job, the maintainer answering a
bug report — has to write the same six lines every time, and every one of
those six lines is a place to get the settings, the stream or the exit
status wrong. The drift-alarm tests compare `VerificationReport` with
c2patool's recorded JSON in-process; nothing yet lets a person make that
comparison on a file of their own with one command each.

This spec adds one executable, `bin/c2pa-verify`, that does exactly what the
README's usage example does and nothing more: open the file, read the
settings if given, print `toJson()` to standard output, and say the verdict
in the exit status. It is a *thin* shell around the public API — the same
report, byte for byte, that a PHP caller gets — so that the command can never
know something the library does not, and the library never learns anything
from the command.

**What goes wrong without it.** Nothing in the verifier; everything around
it. The first outside reader of this repository cannot run it without
reading `src/`. And the one contract a shell cares about, the exit status,
would be invented by every caller separately.

**What c2patool's exit status means (measured, c2patool 0.27.22, 2026-09-22).**
The proposal that led to this spec said "an exit code as c2patool's". That
was reasoned, and it is wrong: c2patool's exit status says whether a report
was produced, not what the report says.

| file | stdout | stderr | exit |
|---|---|---|---|
| `fixture-signed.png` (Valid) | JSON | — | 0 |
| `binding/pixel-changed.png` (**Invalid**, `assertion.dataHash.mismatch`) | JSON | — | **0** |
| `fixture-unsigned.png` (no manifest) | — | `Error: No claim found` | 1 |
| `binding/no-hard-binding.png` | — | `Error: claim missing hard binding` | 1 |
| `README.md` (not an image) | — | `Error: Unsupported file type` | 1 |
| a path that does not exist | — | `Error: No such file or directory (os error 2)` | 1 |
| `--settings README.md` (not JSON) | — | `Error: Could not configure c2pa-rs … type is unsupported` | 1 |
| `--settings /nonexistent.json` | JSON | — | **0** (the missing file is ignored) |
| no arguments | — | usage | 2 |
| `--bogus` | — | `error: unexpected argument '--bogus'` | 2 |

Two of those rows this verifier will not copy. An `Invalid` report with exit
0 makes `c2pa-verify "$f" && publish "$f"` publish a tampered file; and a
settings file that cannot be read, silently ignored, turns every `Trusted`
the caller expected into a `Valid` they did not look at — the caller asked
for trust and got none, with no word said. Both are the opposite of *fail
closed*. The exit status here carries the verdict; a settings file that
cannot be read or parsed is a refusal.

## Scope

**In scope**

- One executable, `bin/c2pa-verify`, registered in `composer.json` under
  `bin` so that a Composer install places it in `vendor/bin/`. It runs on
  the same PHP the library requires (`^8.3`, `ext-openssl`), with no
  dependency the library does not have.
- The logic in a class under `src/Cli/` — `Cli\Command::run(array $arguments,
  $stdout, $stderr): int` — so that every criterion below is tested
  in-process on `php://memory` streams, and the executable is a five-line
  shim around it. `Cli` is a new Deptrac layer that may depend on `Verifier`,
  `Trust` and `Support` only; nothing may depend on `Cli`.
- Arguments: exactly one file path; optionally `--settings <path>` (also
  `--settings=<path>`); `--help`; `--` ends the options so that a file whose
  name starts with `-` can be named. Nothing else. Unknown options, a missing
  path, two paths, `--settings` without a value: a usage error.
- Output: `VerificationReport::toJson()` followed by one newline on standard
  output, nothing else on standard output, ever. Diagnostics — usage, a file
  that cannot be opened, settings that cannot be read — on standard error,
  as one line beginning with `Error: `, as c2patool's do.
- Exit status: **0** when `validation_state` is `Trusted` or `Valid`; **1**
  when it is `Invalid` (the report is still printed — a report, not a
  guess; `docs/comparison.md`); **2** when no report could be made (usage
  error, the file cannot be opened, the settings cannot be read or parsed).
  Every path through the command ends in one of these three; an exception
  escaping the command is a bug, not a fourth status.
- The file is opened read-only as a stream (`rb`) and handed to
  `Verifier::verify()` unchanged; the command never reads the file into a
  string. The settings file is read whole (it is a small JSON document with
  PEM blocks; SPEC-014 bounds the certificate count) and passed to
  `TrustSettings::fromJson()`.
- No network, no `exec`, no temporary file, no environment variable, no
  configuration file other than the one named on the command line (the
  verifier's fixed design decisions apply to the command as to the
  library). The command does not look for a settings file anywhere on its
  own.

**Out of scope** (each needs its own spec before it may be built)

- Reading the asset from standard input (`-`): the verifier needs a seekable
  stream for the data hash (SPEC-012) and the container probes (SPEC-001
  AC14); buffering stdin to a temporary file is exactly what the design
  rules forbid in the verification path. A spec for it would have to say
  where the bytes go.
- A `--detailed` or any second output shape, `--version`, colour, a
  human-readable summary. There is one report and one rendering of it
  (SPEC-010); a second one is a second truth.
- Verifying more than one file per invocation, directories, globbing. The
  shell does that: `for f in *.jpg; do c2pa-verify "$f"; done`.
- Fetching a remote manifest named in `remote_manifest` (SPEC-013
  amendment 9: reported, never fetched).
- A `--trust` shortcut that assembles settings from PEM files. The settings
  file is the one format shared with c2patool and the sister library
  (design decision "trust settings in the same file format"); the command
  does not add a second one.
- Distribution as a PHAR, a Docker image, a Homebrew formula.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-019')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle for the *report* is the library itself: on every file, the
command's standard output must be `VerificationReport::toJson()` of the same
call plus one newline — the report's own equality with c2patool is
SPEC-010/SPEC-013's business and stays there. The oracle for the *exit
status* is the table under Problem, with the two named departures. AC1–AC9
run `Cli\Command::run()` in-process on `php://memory` streams; AC10 runs the
executable as a process (allowed in tests, never in `src/`).

- **AC1 — a valid file: the report on stdout, exit 0, stderr empty**
  - Given `tests/Fixtures/fixture-signed.png` and no `--settings`
  - When `Command::run(['tests/Fixtures/fixture-signed.png'], $out, $err)` runs
  - Then it returns `0`; `$out` holds exactly
    `Verifier::verify(fopen(...,'rb'))->toJson() . "\n"` (byte-equal; the
    JSON says `"validation_state": "Valid"` and carries
    `signingCredential.untrusted`, SPEC-014 amendment 1); `$err` is empty.

- **AC2 — a trusted file: `--settings` reaches the verifier**
  - Given the same file and
    `--settings tests/Fixtures/trust/full-plus-digicert-g4.settings.json`
    (in both spellings, `--settings <path>` and `--settings=<path>`, and
    in either order relative to the file path)
  - When the command runs
  - Then it returns `0` and the JSON says `"validation_state": "Trusted"`,
    byte-equal to `Verifier::verify($stream, TrustSettings::fromJson(...))->toJson() . "\n"`.

- **AC3 — an invalid file: the report on stdout, exit 1**
  - Given `tests/Fixtures/binding/pixel-changed.png`
  - When the command runs
  - Then it returns `1`; `$out` holds the report (`"validation_state":
    "Invalid"`, `assertion.dataHash.mismatch` in `validation_status`),
    byte-equal to `toJson() . "\n"`; `$err` is empty. This is the first
    named departure from c2patool (exit 0 there).

- **AC4 — no manifest: a report, exit 1**
  - Given `tests/Fixtures/fixture-unsigned.png`
  - When the command runs
  - Then it returns `1` and `$out` holds the report with `"has_manifest":
    false` and `"validation_state": "Invalid"`; `$err` is empty (c2patool
    prints `Error: No claim found` and no JSON; here the JSON *is* the
    answer, and the exit status agrees with c2patool's).

- **AC5 — not an image: a report, exit 1**
  - Given `README.md` as the file
  - When the command runs
  - Then it returns `1` and `$out` holds the report the verifier makes for
    it (`general.error`, "unsupported file type", SPEC-013); `$err` is
    empty. The command does not decide what is an image; the verifier does.

- **AC6 — the file cannot be opened: no report, exit 2** *(error path)*
  - Given a path that does not exist, and a path that is a directory
  - When the command runs
  - Then it returns `2`; `$out` is empty; `$err` is one line
    `Error: cannot open <path>: <reason>` ending in a newline, the reason
    being PHP's own message for the failed `fopen`, not a guess. No PHP
    warning reaches either stream (how the warning becomes the reason is
    an open question below; that it must is not).

- **AC7 — the settings cannot be read: no report, exit 2** *(error path; the second departure from c2patool)*
  - Given a valid file and `--settings /path/that/does/not/exist.json`
  - When the command runs
  - Then it returns `2`; `$out` is empty (the verifier is **not** run
    without the settings the caller asked for); `$err` is
    `Error: cannot read settings <path>: <reason>`.

- **AC8 — the settings are not trust settings: no report, exit 2** *(malformed input)*
  - Given a valid file and `--settings README.md`, and separately a JSON
    file with an unknown top-level key (`{"foo": 1}`)
  - When the command runs
  - Then it returns `2`; `$out` is empty; `$err` is
    `Error: settings <path>: <TrustException message>` — the message
    SPEC-014 gives ("not valid JSON: …", "unknown top-level key foo …"),
    verbatim, so that the settings fault is named once, in one place.

- **AC9 — usage** *(error path)*
  - Given each of: no arguments; two paths; `--settings` as the last word;
    `--bogus`; `-x`
  - When the command runs
  - Then it returns `2`; `$out` is empty; `$err` starts with
    `Error: ` naming the fault in one line, followed by the usage text
    (`Usage: c2pa-verify [--settings <path>] [--] <file>`).
  - And given `--help` (alone or with other words)
  - Then it returns `0`, `$out` is the usage text, `$err` is empty.
  - And given `-- --settings` as the arguments (a file literally named
    `--settings`, which does not exist)
  - Then the path is taken as a file name: `2`, `Error: cannot open --settings: …`.

- **AC10 — the executable is the command** *(the shim, run as a process)*
  - Given `bin/c2pa-verify` executed by `proc_open` with the PHP binary
    that runs the tests (`PHP_BINARY`), on `fixture-signed.png`,
    `pixel-changed.png` with the SPEC-014 settings, and a missing path
  - When the three processes run
  - Then their exit statuses are `0`, `1`, `2`; the first two standard
    outputs are byte-equal to AC1's and AC3's `$out`; the third standard
    output is empty and its standard error is AC6's line. The shim has no
    logic of its own to get wrong: `require` the autoloader (either the
    project's or, installed under `vendor/bin`, the consumer's — both
    candidates tried, the first that exists), construct the verifier, call
    `Command::run(array_slice($argv, 1), STDOUT, STDERR)`, `exit` with the
    result.

- **AC11 — every corpus file: stdout equals the API, the exit status follows the state** *(the drift alarm for the command)*
  - Given every file of the four corpora (`SPEC013_CORPUS`,
    `SPEC013_PUBLIC_CORPUS`, `SPEC013_RS_CORPUS`, `SPEC013_WRITERS_CORPUS`
    in `tests/Pest.php`), each run without settings and with
    `full-plus-digicert-g4.settings.json`
  - When `Command::run()` runs in-process on each
  - Then `$out` is byte-equal to the corresponding `toJson() . "\n"`, the
    return value is `0` exactly when the JSON's `validation_state` is
    `Trusted` or `Valid` and `1` otherwise, and `$err` is empty for every
    file. No file is skipped and no exception escapes: the exception lists
    (`_MULTI`, `_REMOTE`, `_CAWG`, `_TSA_NOT_CONFIGURED`) are the library's
    verdicts, and the command reports whatever the library says.

- **AC12 — nothing but the report on stdout; the report is terminal-safe**
  - Given `tests/Fixtures/binding/` variants whose status messages carry
    bytes from the file (an unknown box type, a wrong signature; see
    SPEC-010 AC on message text)
  - When the command runs
  - Then `$out` decodes as one JSON document with `json_decode(..., flags:
    JSON_THROW_ON_ERROR)`, contains no byte below 0x20 other than `\n`,
    and ends in exactly one `\n` — `json_encode` escapes control
    characters and non-ASCII (`toJson()` sets neither
    `JSON_UNESCAPED_UNICODE` nor `JSON_INVALID_UTF8_*`), so a manifest value
    cannot reach the terminal unescaped (the design rule "terminal output
    is untrusted").

## References

- Specification: none of C2PA's — this spec adds no rule of the verifier.
  The report is SPEC-010 and SPEC-013; the settings are SPEC-014
  (`TrustSettings::fromJson()`, its `TrustException` messages); the
  design rules are `README.md` ("How this is built") and ADR-0001 (no
  dependencies: the command parses its two options by hand rather than
  through `symfony/console`).
- Oracle: c2patool 0.27.22 (`c2pa/0.90.22`), the exit-status table under
  Problem, measured 2026-09-22 with
  `c2patool <file> >/dev/null 2>err; echo $?` on `fixture-signed.png`,
  `binding/pixel-changed.png`, `fixture-unsigned.png`,
  `binding/no-hard-binding.png`, `README.md`, a missing path,
  `--settings README.md`, `--settings /nonexistent.json`, no arguments,
  `--bogus`. (A first measurement read `$?` after a command substitution
  and got 0 everywhere; it was thrown away and redone with `rc=$?` on the
  line after the call.)
- Oracle for the report: the library itself, byte for byte (AC1, AC11) —
  the report's equality with c2patool is measured in SPEC-013's drift
  alarms and not repeated here.
- Reasoned: that `0 / 1 / 2` is the contract shells expect (`grep`, `diff`
  and `cmp` use it in this sense: 0 found/same, 1 not, 2 trouble). That
  c2patool's exit 0 on an `Invalid` report is a choice of the tool and not
  of the C2PA specification, which says nothing about processes.

## API sketch

Illustrative only — not binding implementation.

```php
// namespace Provemark\C2paVerifier\Cli;

final readonly class Command
{
    public const USAGE = "Usage: c2pa-verify [--settings <path>] [--] <file>\n"
        . "  Prints the verification report as JSON on standard output.\n"
        . "  Exit status: 0 Trusted or Valid, 1 Invalid, 2 no report (usage, unreadable file or settings).\n";

    public function __construct(private Verifier $verifier) {}

    /**
     * @param  list<string>  $arguments  argv without the program name
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @return int  0, 1 or 2 — never anything else, never an exception
     */
    public function run(array $arguments, $stdout, $stderr): int;
}

// bin/c2pa-verify — the shim, no logic of its own
#!/usr/bin/env php
<?php
declare(strict_types=1);
foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) { require $autoload; break; }
}
exit((new Command(new Verifier))->run(array_slice($argv, 1), STDOUT, STDERR));
```

`composer.json` gains `"bin": ["bin/c2pa-verify"]`. `deptrac.yaml` gains the
`Cli` layer (`src/Cli/.*`) with the ruleset `Cli: [Verifier, Trust,
Support]`; no other layer names `Cli`. The argument parser is a loop over
`$arguments` with four cases (`--help`, `--settings`/`--settings=`, `--`,
anything else); it has no notion of short options, option bundling or
repeated options — a repeated `--settings` is a usage error, since the two
files could disagree and the command would have to pick one.

## Open questions

- **Which `Verifier` does the shim construct?** `Verifier` has a
  constructor with defaults for every collaborator (SPEC-013); `new
  Verifier` is the whole answer, and the shim stays five lines.
  *Non-blocker.*
- **`fopen` failures without `@`.** AC6 wants PHP's own reason in the
  message and no warning on stderr. Two ways: `@fopen` plus
  `error_get_last()`, or a temporary `set_error_handler` that turns the
  warning into the message. The second is cleaner (no `@` anywhere in
  `src/`, as today) and is the proposal; a test asserts the warning text
  ("No such file or directory", "Is a directory") lands in `$err` as part
  of the `Error:` line. *Non-blocker, decided in the tests-first step.*
- **Exit 1 for "no manifest".** A file without a manifest is `Invalid` in
  the report (SPEC-013) and c2patool exits 1 on it; both point at 1. A
  caller who wants to tell "no manifest" from "broken manifest" reads
  `has_manifest` in the JSON, which is why the JSON is printed. *Decided
  by the report; noted so that it is not reopened.*
- **`--settings` before or after the file.** Both allowed (AC2); the
  parser does not care about order. *Decided.*

## Amendments

(none yet)

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
