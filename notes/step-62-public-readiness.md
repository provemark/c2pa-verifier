# Step 62 — What a public repository would have shown

*2026-09-22.* The repository has been private since it was created. Before
that changes, the question is not "is the code good" — `composer check`
answers that on every commit — but "does what a stranger sees match what
is here". Three things did not, and one of them mattered.

The audit was read-only. Everything below was measured with the command
beside it; nothing was taken on trust from a previous step.

## What was clean

| what | command | result |
|---|---|---|
| secrets across the whole history, six patterns (PEM private keys, `ghp_`, `sk-`, `AKIA`, `xox*`, Bearer) | `git log -p --all \| grep -c …` | 0 for each |
| local paths in tracked files (`/Users/`, `/home/`, `C:\`) | `git grep -E …` | 0 files |
| references to the untracked instruction file | `git grep CLAUDE.md` | one, in `.gitignore`, where it belongs |
| dead relative links in tracked markdown | link walk over `git ls-files '*.md'` | 0 |
| `TODO` / `FIXME` / `XXX` / `HACK` | `git grep -E` over `src bin tests specs docs` | 0 |
| secrets in the CI workflow | `grep -E 'secrets\.|token'` | none; the workflow needs none |
| provenance and licence of every third-party fixture | the README in each fixture directory | present, per file, including the CC BY-SA 4.0 attribution the one Commons photo requires and the public-domain origin of the other |
| the spec/test traceability | `php bin/spec-check.php` | OK, 23 specs, 28 test files |
| author attribution in commit messages | `git log --format=%B \| grep -i …` | 0; the disclosure is in the README and `AI-LOG.md`, which is where this project puts it |

## 1. The README described a verifier that no longer exists

It said milestones M0–M6 were done and that *"a store with more than one
manifest is refused, on purpose"*. That has not been true since SPEC-021:
M7 is complete, the manifests an ingredient assertion names are validated
in their own right, update manifests included. The first paragraph a
stranger reads understated the package by a whole milestone.

`SECURITY.md` carried the same sentence in its "not verified" list. Both
are corrected, and the correction is the substance of this step: ingredient
manifests moved from the *not verified* list to the *verified* list, and
what genuinely remains unverified about them is named instead — a manifest
the walk never reaches is **ignored**, as C2PA 2.4 §15.11.3.3 directs, and
an ingredient naming a manifest the store does not hold gets
`ingredient.unknownProvenance`. Ignored and reported, not silently accepted;
that distinction is the whole point of the list.

Two numbers in the README were also stale, and are now measured rather
than remembered: **five** fixture corpora, not four (the matrix became the
fifth in step 59), and **96** distinct files, not 75 — counted by path,
because two names occur in more than one corpus. The second oracle of step
61 is named there now too: agreeing with one implementation can hide a
shared misreading, and saying so is more honest than the bare count.

## 2. A `composer require` would have downloaded 62.9 MB

Composer fetches the dist archive, which is `git archive`. Measured:

```
git archive --format=tar HEAD | wc -c        →  62.9 MB
```

Of that, `tests/` is 63 440 kB and `src/` is 500 kB. Every user of this
package would have pulled the entire fixture corpus — signed photographs
from nine writers — into their `vendor/` directory to run none of it.

`.gitattributes` now marks `tests/`, `.github/`, `tools/` and the four
tool configs `export-ignore`:

```
git archive --format=tar --worktree-attributes HEAD | wc -c   →  1.9 MB
```

What still ships is deliberate: `src/`, `bin/`, `docs/`, `specs/`,
`notes/` and `AI-LOG.md`. They are what the README links to, so every
link in the package still resolves inside the package, and a library
whose subject is provenance ought to carry its own. Together they are
under 2 MB — a thirty-third of what it was.

## 3. The repository had no description and no topics

Invisible while private; the first thing anyone sees once it is not. Both
are set now: the description is the README's first sentence plus the
three things this verifier does *not* do, and nine topics (`c2pa`,
`content-credentials`, `provenance`, `php`, `content-authenticity`,
`jumbf`, `cbor`, `cose`, `digital-signatures`).

GitHub does not report a detected licence for the repository
(`licenseInfo` is empty) although `LICENSE` is the unmodified MIT text.
That may be an artefact of the private state; it is to be re-checked
after the visibility change, not guessed at now.

## Not fixed here, and why

- **`CODE_OF_CONDUCT.md` is absent.** GitHub's community checklist wants
  one. Whether this project adopts one, and which, is the maintainer's
  decision, not a hygiene repair.
- **Two fixture directories have no README** (`c2patool/`, `spec-check/`).
  Both hold this project's own generated output — recorded oracle JSON and
  the checker's test trees — not third-party material, so there is no
  provenance to document.
- **No guard was added for any of this.** A test asserting that
  `.gitattributes` still covers every top-level path would be a real drift
  alarm: the failure mode is a future directory added without a thought
  for the dist. It is not here because every test file in this repository
  must carry a `->group('SPEC-###')` that names an existing spec, and no
  spec covers the published package. Adding the test means writing that
  spec first. Named here rather than skipped quietly.

## What this step does not do

Nothing outward. The repository is still `PRIVATE`; no tag, no Packagist,
no announcement. The order when that changes is: visibility, then branch
protection on `main` immediately after — the gap between the two is the
only moment the branch is public and unprotected.
