# Contributing

This project is built slowly on purpose. Every layer of it is meant to be
understood and explainable by its maintainer, and every claim in it is
meant to be measured. If that is how you like to work, welcome.

## The way of working

1. **Spec before code.** A feature starts as `specs/SPEC-###-slug.md` from
   `specs/TEMPLATE.md`, status `draft`, with acceptance criteria as
   Given/When/Then and at least one malformed-input criterion. No
   implementation while a spec is `draft`; the maintainer flips it to
   `approved`.
2. **Tests first, and seen red.** Pest tests tagged `->group('SPEC-###')`
   go in before the implementation, and the note of the step quotes the
   red run. A green test that was never red does not count. Assert that
   something is *present* or that two outcomes *differ*; a test that only
   says "does not contain" is suspect until it has been seen red.
3. **One concept per step.** A pull request introduces one idea. Ten small
   steps beat one large one.
4. **Measured ≠ reasoned.** Every note and every PR says which part was
   measured (with the command) and which was read from the specification
   or the reference implementation. A claim that licenses a decision gets
   a falsification attempt first.
5. **Fail closed.** An unknown case is an error with a status code, never
   an assumption. Where a rule says "check X when Y is present", add the
   signed variant in which Y is absent (`bin/make-absence-variants.php`
   shows how) — that is where this project's own worst bugs were.
6. **Log it.** `NOTES.md` is the index, `notes/step-NN-*.md` the record
   written for an outside reader, `docs/milestones.md` the plan, and
   `AI-LOG.md` the disclosure of every assistant contribution — all in the
   same commit as the work.

## Before you open a pull request

```bash
composer check   # spec-check, api-check, Pint (test mode), PHPStan level max, Deptrac, Pest
```

All five must pass on PHP 8.3, 8.4 and 8.5 (CI runs them). Also:

- No private key anywhere in the tree, test key or otherwise:
  `git ls-files | grep -i '\.key$'` must print nothing, and a search for
  a PEM private-key header over `tests/Fixtures/` must find nothing.
  Tooling that needs to sign a test variant uses throw-away keys outside
  the repository and deletes them before it ends.
- No `exec`, no network, no temporary files in the verification path.
- No new dependency without an ADR in `docs/adr/`.
- Every new status code is verbatim from C2PA 2.4 §15; there is no
  vocabulary of our own.
- Fixtures from elsewhere come with their licence file and a README row
  naming the origin, the commit or the sha1, and what the file brings.
  Public certificates are fine; anything that identifies a private
  person is not.
- Where the verifier's verdict differs from `c2patool`'s on a corpus file,
  the difference is named in `tests/Pest.php`'s exception lists and in
  `docs/comparison.md` — stricter is allowed, more lenient is a bug.

## Git

- Local commits are fine; squash-merge into `main`.
- Commit messages describe the step ("Step 47: …", "Implement SPEC-018:
  …"); no attribution trailers for AI assistants. Disclosure lives in
  `AI-LOG.md` and the README, not in commit metadata — please keep it
  that way.
- Do not push tags, change visibility or publish to Packagist; those are
  the maintainer's calls.

## Working with an AI assistant

You may. If you do, add an entry to `AI-LOG.md` in the same commit — model,
what you asked, what was produced, what was measured, what was reasoned,
what you decided yourself — and do not list the assistant as an author.
The log exists so that a reader can tell, for every line, who thought
what and what was checked.

## Questions

Open an issue for anything about the specifications, the corpora or the
reference implementation. For a suspected wrong `Valid`, see
[`SECURITY.md`](SECURITY.md) first.
