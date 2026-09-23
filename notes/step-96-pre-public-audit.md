# Step 96 — Read as a stranger would read it, before the switch

*2026-09-23.* Asked for before making the repository public: run the four
checks that cannot be undone once the history is visible, and see whether
the texts that go out are still true.

**Nothing was made public in this step.** The repository is still private.

## The four checks

| what | how | result |
|---|---|---|
| private keys, anywhere in the history | `git log --all -S"BEGIN … PRIVATE KEY"` over 276 commits, five header variants | **0 commits**; every one of the 29 tracked `.pem`/`.crt` files holds `CERTIFICATE` blocks and nothing else |
| assistant attribution in commit metadata | `git log --all --format=%B \| grep -i "claude\|anthropic"` | **empty** |
| local paths in tracked files | `git grep -lI "/Users/"`, and `-S` over the history | one hit, and it is the row in `notes/step-62-*.md` that *names the pattern being searched for* |
| references to the untracked instruction file | `git grep -lI`, and `-S` over the history | three hits: `.gitignore`, where it belongs, and two notes recording this same audit |

Both remaining hits are the record of the check itself. Removing them would
make the record unreadable — "we searched for references to a file we will
not name" — so they stay, and this note says why.

## Four things the texts got wrong

The checks were clean. The texts were not, and one of them mattered.

**`SECURITY.md` said this verifier does not do two things it does.** Its
scope list still read "ISOBMFF/BMFF hashes (refused)" and "OCSP /
certificate revocation of any kind (no network)". Both stopped being true
in M8 and SPEC-030. A security document that understates what is verified
is not dangerous in the way overstating is, but it is wrong in the file
where a reader goes precisely to find out what is and is not checked. Both
lists now match the code, and the "not verified" side names what stays out
and why: revocation that needs the network, and nothing else.

**The changelog stopped at M7.** Eight specs and a whole milestone —
SPEC-023, SPEC-024, SPEC-025, the four of M8 and SPEC-030 — had never been
written up in the one document a reader consults to see what changed.
Added, newest first: revocation, the conformance table, M8, the assurance
work, the published package. All 31 specs are now named there.

**`composer.json`'s description** — the sentence Packagist shows — named
neither ISOBMFF nor revocation. It does now.

**Two numbers were stale and one was unverifiable.** The README said the
drift alarms run "96 files from nine writers"; counted, the five corpora
hold **93**, and "nine writers" is a figure nobody can check. It now names
the five corpora instead. `docs/comparison.md` said *four* corpora where
the README said five — the fifth alarm lives in `MatrixTest.php` rather
than `tests/Pest.php` — and both now say the same thing.

## What was verified rather than assumed

Every number that survives in an outward text was counted against the
repository in this step: 31 specs, 87 amendments, 99 recorded symbols, 111
obligations with 54 met and 17 open, 421 tests, `c2patool` 0.27.22, a
mutation score of 98.06 %, ten contract classes.

And the two strongest claims in the README, mechanically:

```
no network in src/      curl_, fsockopen, stream_socket_client, socket_create,
                        file_get_contents('http…)      → 0 hits
no process in src/      exec, shell_exec, proc_open, passthru, system, popen
                                                       → 0 hits
no temporary files      tmpfile, tempnam, sys_get_temp_dir → 0 hits
```

The only writes anywhere in `src/` are the command line writing to stdout
and stderr.

## Still open, and not for this note to decide

- **The commit e-mail address.** Every commit carries the maintainer's
  address, and going public publishes all 276 of them. GitHub can mask it,
  but that has to be arranged *before* the history becomes visible.
- The order after the switch: visibility, then branch protection on
  `main`, then a first tag — `0.1.0` — and only then Packagist and any
  announcement, so that nobody can install a version before the
  protections exist.
