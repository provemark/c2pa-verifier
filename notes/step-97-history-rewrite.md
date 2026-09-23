# Step 97 — One address out of 277 commits, and everything else kept

*2026-09-23.* The pre-public audit (step 96) found one real exposure: the
maintainer's e-mail address, in the author and committer field of every
commit. Publishing the repository would publish all 554 of those fields.

The question was put as: make a scrubbed copy with less history?

## Why not a copy

The history is the evidence for the claim the README makes two steps
earlier — *judge the method, not the tool*. What backs that claim is 277
commits in which tests were seen failing before they passed, 87 numbered
amendments, a note per step and a log entry per session. Squash that and a
checkable claim becomes an assertion.

The audit had also already established there was nothing else to hide: no
private key in any commit, no assistant attribution in any message, no
local paths. And two repositories would be a drift machine — this project
has already been bitten once by two copies of one truth, four commits
apart, with nothing falling over.

So: one repository, and a targeted rewrite of the one field that was the
problem.

## What was done, and what was checked

`git filter-repo 2.47.0` with a `mailmap`, **in a throwaway clone** rather
than in the working repository, so that nothing could damage the original
before it had been verified:

| check | result |
|---|---|
| commits | 277, none lost |
| tree of `HEAD` | `c48c331a…`, **identical** — every file byte for byte |
| all 277 commits compared on tree, author date, commit date, name and subject | identical, line for line |
| e-mail fields | 554 of 554 are the GitHub noreply address |
| the old address | 0 hits anywhere in the history |

Only then was it force-pushed, and only then did the working repository
follow. CI is green on the rewritten history (PHP 8.3, 8.4, 8.5).

Three safety nets were in place throughout: a verified bundle of all refs
(37.7 MB, "records a complete history"), the old history still on the
remote until the force-push, and the working repository still holding the
old commits.

## The consequence nobody asks about: 199 dead pointers

Every commit SHA changes when an author field changes. `AI-LOG.md` alone
quoted **181** of them, and twelve other files the rest — 199 references,
129 distinct commits. Left alone, every one would point at nothing, in the
exact documents this project asks to be judged by.

`filter-repo` writes a `commit-map` of old → new, and all 199 were
translated through it. A candidate was only replaced when it was a prefix
of exactly one commit; the chance of a random hex run doing that by
accident is about one in a million per string.

**These were edits to a log that is meant to be a faithful record, and
that is worth saying plainly.** What changed is a pointer, not a fact: each
reference names the same commit, with the same message, the same date and
the same content as before. The alternative — a record full of identifiers
that resolve to nothing — would have been less honest, not more.

## What is still true about the old commits

Measured after the push: the old `4fdea57` is **still reachable through the
GitHub API by its full SHA**. Unreachable objects linger until GitHub
collects them; that is normal and known.

It does not matter here, and the reason is worth stating rather than
assuming: the repository has never been public, so those SHAs have never
been seen by anyone outside it — and no document in the new history names
one, because all 199 references were translated. Nobody can discover what
to ask for.

It is also the reason this had to happen **before** the visibility change
rather than after it.
