# Step 50 — Release hygiene: the README that said "M0: skeleton", SECURITY, CONTRIBUTING, CHANGELOG, and a measured comparison

*2026-09-22.* Before this verifier can be opened, its front matter has to
say what it is now, not what it was on the first day. Five documents,
no code:

- **`README.md`** — status M0–M6 with what is and is not there; a `Use`
  section with the three calls that matter (`TrustSettings::fromJson`,
  `Verifier::verify`, `toArray`), the three verdicts in `c2patool`'s
  meaning, the trust-settings shape, and the sentence that every value
  in the report is untrusted text; the design rules updated with what
  the project learned (no dependencies by ADR, the two absence
  findings, the fuzz count); licences of the fixtures pointed at.
- **`SECURITY.md`** — what a vulnerability is here (a wrong `Valid`, an
  escaping exception, unbounded resource use), what is not (stricter
  than `c2patool`, an unsupported format, `Valid` without your anchors),
  how to report, the exact scope of what is and is not verified, and —
  on purpose — the two wrong `Valid`s this project found in itself, dated
  and linked. A verifier that goes public should name its own worst
  finding; it makes "fail closed" a claim with evidence rather than a
  slogan.
- **`CONTRIBUTING.md`** — the way of working as rules for a stranger
  (spec first, red first, one concept, measured ≠ reasoned, the absence
  variant for every "X when Y"), the checklist before a pull request
  (the five tools, no keys, no `exec`/network/temp files, no dependency
  without an ADR, codes verbatim, fixtures with licences, stricter
  allowed / lenient a bug), the git rules including the no-attribution
  rule stated as itself, and how to work with an AI assistant here.
- **`CHANGELOG.md`** — per milestone, the specs that closed it and the
  day, the fixes of this week under M6, "Unreleased": no tag, no
  Packagist.
- **`docs/comparison.md`** — the three tables the question "does it work?"
  keeps getting: where `c2patool` can do more (with the milestone that
  closes each gap), where the verdicts are equal (measured), where this
  verifier differs by design (each with its reason and the spec or ADR
  that names it) — plus what "works" rests on and does not.

Measured for the documents: the drift-alarm lists in `tests/Pest.php`
(10 + 7 + 1 `_MULTI`, 4 + 1 `SPEC013_SUBSET_ONLY`), the milestone dates in
`docs/milestones.md`, a live report from `fixture-signed.jpg` under the
full settings for the README's example; `composer check` green, 301
tests; a search of the five files for local paths and the local
instruction file finds nothing.

Not in this step, still open before going public: a CLI (a spec of its
own — `bin/c2pa-verify <file> [--settings …]` printing `toJson()`), the
market scan of the brief as a dated note, and Maurice's confirmation of
the amendments made under the "measurement amends before tests" clause
(SPEC-013 #7 is the one with weight). And the decision itself: public
before or after the NLnet/Restack application.
