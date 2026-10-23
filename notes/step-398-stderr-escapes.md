# Step 398 — SPEC-019 amendment 4: stderr is terminal-safe too

*2026-10-23.* A low item of the review of 2026-10-23.

## The finding

The report on stdout is JSON, which escapes control characters (AC12).
The error lines on stderr echoed their input raw: `--at` through
`var_export()`, an unknown option, and a path that cannot be opened or
read. `--at $'2026\e[31mRED'` wrote the ESC byte to the terminal.

Found while building it: the crJSON line for a file without a store
passed its explanation through `Bytes::printable()`, which is meant for
four-byte box types, so the line always came out as hex
(`general.error: 64 65 73 63 …`).

## The fix

`Command::shown()` writes C0 controls and DEL as `\xNN`, C1 controls and
the bidirectional overrides as `\u{…}`, and bytes that are not UTF-8 as
U+FFFD; every stderr line passes through it. The crJSON line reads as
text.

## Measured

- Red first: 7 new tests. Green after.
- Mutations, each failing: the C0/DEL escape dropped (5), the C1/bidi
  escape dropped (1).
- `composer check`: 1,310 passed, 1 skipped.
