# Step 561 — Prepare 0.11.5

*2026-11-23.* Maurice asked for it: "bereid patch 0.11.5 voor". It is a
patch, as he decided when the status-code moves began. It carries steps
555–558: four moves that follow `c2patool` 0.29.0's codes beside a refused
or expired signing credential. No verdict changes. No contract symbol
changes either (217). A caller on `^0.11` receives it without a change of
constraint.

## What changes

- **`CHANGELOG.md`.** *Unreleased* becomes **0.11.5 — 2026-11-23**. The
  introduction names the one visible change outside the codes:
  `ManifestEvidence::$signer` is null where the report withholds
  `signingCredential.trusted` (step 557, decided by Maurice).
- **The version.** `Verifier::VERSION` is `0.11.5`.
- **`README.md`.** The sentence on the current tag names 0.11.5 and what
  it changes.
- **`SECURITY.md`.** Unchanged. Nothing is more lenient. Every move takes
  a success code away or adds `untrusted` beside a failure that is
  already there.

Steps 559 and 560 changed documentation only. They ship in the archive but
change no behaviour.

## Measured against `v0.11.4`

**Setup.** A worktree at `v0.11.4` with a copied `vendor/`, checked to
load its own `src/` (`VERSION` 0.11.4).

**Files.** The 1,230 under `tests/Fixtures/`, except `.md`, `.json`,
`.pem` and `.php`.

**Configurations**, all at 2026-11-23T12:00:00Z:

- none;
- the programme's official lists as `--trust-list` / `--tsa-trust-list`,
  the same local copies as step 551 (SHA-256 `709555a0…` and
  `6f409f08…`);
- the conformance test root
  (`tests/Fixtures/conformance-test/root.settings.json`);
- the file's own `<name>.settings.json`, where one exists.

For each file and side: the ordinary report with its exit status, and
`--crjson`. That is 3,817 runs a side.

**Results:**

- No exit 2 on either side.
- **No verdict moves**, in any configuration.
- **Reports.** 75 files differ, in 240 runs (none 68, official lists 67,
  conformance root 68, own settings 37). Every difference is one of the
  four moves:

  | change | active manifest | ingredient deltas | step |
  |---|---|---|---|
  | `claimSignature.validated` withheld | 230 | 12 | 556 |
  | `claimSignature.insideValidity` withheld | 94 | 12 | 555 |
  | `signingCredential.trusted` withheld | 28 | 6 | 557, 558 |
  | `signingCredential.untrusted` added | 8 | 6 | 558 |

  `trusted` withheld and `untrusted` added are status counts. They do not
  add up to the 240 runs, because one run can carry several.
- **crJSON.** The same files differ, and no other. Every other document
  differs only in `jsonGenerator.version` (`0.11.4` → `0.11.5`).

## Checks

- `composer check` exit 0: 217 symbols, 92 specs.
- `composer test:parallel`, as CI runs it: 1,790 passed, 1 skipped.
- `bin/package-check.php`: 701 files, 6.6 MB, 2.3 MB as a zip, counted on
  the uncommitted tree. The note adds one file once committed.
- CI on `f391ed9` (step 560): run 45131126618, 8 of 8 `success`.

## Left

Each on Maurice's word:

1. tag `v0.11.5`, the GitHub release, then Packagist;
2. downstream, nothing has to change. The library, the Drupal module and
   the WordPress plugin take or bundle it in their own sessions.
