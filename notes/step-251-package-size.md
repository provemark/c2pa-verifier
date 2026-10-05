# Step 251 — The package's size, and a signal for when it matters

*2026-10-05. `bin/package-check.php` reported 3.8 MB against SPEC-023's
4 MB ceiling after 0.3.0; amendment 2 had foreseen that the question would
return.*

## Measured

On `v0.3.0` (`git archive`, export-ignore applied):

| part | size |
|---|---|
| `specs/` (59 files) | 1.06 MB |
| `notes/` (206 files) | 0.94 MB |
| `src/` | 0.58 MB |
| `AI-LOG.md` | 0.51 MB |
| `docs/` | 0.20 MB |
| `NOTES.md` | 0.15 MB |
| the rest | 0.06 MB |

376 files, 3.50 MB of files; the check measures the tar, with a 512-byte
header and padding per file: 3.79 MB. The zip Composer downloads
(`git archive --format=zip -9`) is 1.34 MB.

Per tag, tar and zip: `v0.2.0` 2.81 / 0.99 MB, `v0.2.5` 3.69 / 1.29,
`v0.2.9` 3.94 / 1.39, `v0.3.0` 3.79 / 1.34 MB (lower since the fixture
builders stopped shipping, SPEC-023 amendment 2). `AI-LOG.md` grew from 349
to 508 KB, `notes/` from 111 to 206 files in eleven days.

## Decided (Maurice van Loon)

Option 1 of two: raise the ceiling to 16 MB and keep open question 4's
answer (the package carries its own record). The ceiling exists to catch
the 62.9 MB of step 62 again, not to budget the prose; the consumers that
bundle the verifier (the WordPress plugin, the demo) take `src/` only.
Option 2, a lean package with the record left on GitHub, would reverse
that answer and rewrite every relative link (AC4, AC5).

So that the answer is asked again when it matters rather than by chance,
the check also shows the zip's size and makes a zip over 5 MB a finding
that names open question 4 (amendment 3, AC8).

## Tests, red

`vendor/bin/pest tests/Unit/PackageTest.php`: `Undefined constant
"PACKAGE_DIST_CEILING"` — the number now lives in `bin/package-check.php`
only, and the tests ask for it there, with `packageGitZipSize()` and
`packageZipFinding()`.
