# Step 202 — The C2PA conformance programme: not now

*2026-10-04. A decision, not a build. Nothing in the code changes.*

## What was asked, and what came back

The maintainer asked the C2PA conformance programme how a verifier like
this one could take part. The answer named three requirements:

- **A legal entity** applies, not a private person.
- **The trust lists are fetched online at every validation.** This
  verifier works offline by design: it reads trust settings the caller
  passes or the bundled lists, and never fetches anything, a remote
  manifest included (README, "What it does not do").
- **A complete product** is assessed, not a library on its own.

## The options weighed

- **Not taking part.** Nothing changes. What stands in its place is the
  verifier's own account against the specification: every one of the
  111 applicable obligations laid out in
  [`docs/conformance.md`](../docs/conformance.md), and every verdict
  measured against `c2patool` ([`docs/comparison.md`](../docs/comparison.md)).
- **An optional online mode**, off by default, that fetches the trust
  lists. It would need its own specification and would turn "no network"
  into "no network unless asked".
- **A separate product** for the programme, leaving this library and the
  WordPress plugin built on it as they are.

## Decided

**Not now** (decided by the maintainer, 2026-10-04). The first
requirement is not met today, so the other two need no decision yet.
"No network" stays a rule of this verifier. The question can be opened
again when the circumstances change; this note is where it starts.
