# Step 496 — The command line writes U+FFFD, as promised

*2026-10-28.* Found while building SPEC-089 (step 495); Maurice: "akkoord,
bouw stap 496". A correction under SPEC-019 amendment 4; no verdict and no
report changes.

## Why

SPEC-019 amendment 4 (step 398) made the command's error lines on stderr
terminal-safe and promised that bytes that are not UTF-8 are shown as
U+FFFD; `Command::shown()`'s comment says the same. It used `mb_scrub()`,
which writes `mb_substitute_character()`, a `?` by default
(`php -r 'echo bin2hex(mb_scrub("a\xffb","UTF-8"));'` prints `613f62`).
AC15's tests looked only for control characters, so nothing caught it. A
`?` can also be a real character of a path or an option, so it says less
than U+FFFD.

Step 495's amendment 2 also said that a report's explanations disagree
with `mb_scrub()`. They do not: SPEC-043 AC13 (step 249) asks for `?`
there, and they keep it. That sentence of SPEC-089 is corrected.

## What changed

- `Command::shown()` writes U+FFFD for each ill-formed sequence, through
  `JSON_INVALID_UTF8_SUBSTITUTE`, as `EvidenceDocument` does.
- SPEC-019: a correction note under amendment 4 and the AC15 row of its
  traceability; SPEC-089 amendment 2's last sentence; the CHANGELOG under
  Unreleased.

## Measured

- `tests/Unit/Cli/CommandTest.php`, "AC15: bytes that are not UTF-8 are
  shown as U+FFFD, not as a question mark" (`--at`, an unknown option, a
  path that cannot be opened): 3 failed before the correction. Before:
  `Error: --at: 'caf??x' is not an RFC 3339 date-time`; after, the two
  bytes are two U+FFFD.
- No corpus run: the change is in the command only, which the corpus
  comparison does not go through.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
