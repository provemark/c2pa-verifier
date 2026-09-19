# AI log

This project is built with Claude Code (Anthropic), driven and reviewed by
Maurice van Loon. Every contribution the assistant produces is logged here,
newest at the bottom, in the same commit as the work it describes. What is
*measured* (a command was run) is kept apart from what is *reasoned* (a
conclusion from reading). Decisions are Maurice's; the assistant proposes.

The assistant is not listed as an author in commit metadata; this log and the
README are where the disclosure lives.

## 2026-09-19 — Repository created

- Model: Claude Opus 5 (1M context), Claude Code CLI
- Asked: start a new repository in its own folder for a pure-PHP C2PA verifier
  and set it up so a later session can continue; everything in English; keep a
  clear log of everything the assistant makes; no author attribution.
- Produced: `.gitignore` (derived from the sister project's, without the
  signing-service entries and with `*.key` ignored unconditionally — no
  private key belongs here, test key or otherwise); this `AI-LOG.md`; a local,
  gitignored project brief (landscape, design decisions, milestones, way of
  working) that the next session reads first. Two commits, no remote.
- Measured, in the session that wrote the brief: GitHub repository search
  (`c2pa language:php`), the wordpress.org plugin API, eight plugin zips
  grepped for `openssl_verify` / COSE / CBOR (none verifies a signature),
  WordPress/ai PRs #459 and #294 and issue #421 read via the GitHub API,
  WordPress core Trac ticket #65952 read in a browser, Packagist searches for
  `c2pa`, `jumbf`, `cose`, `cbor`. Reasoned: that a pure-PHP verifier is
  buildable from existing parts, and the milestone order.
- Decided by Maurice: the project exists; it is spec-driven and deliberately
  slower than the sister project, with every addition explained first; the
  repository starts private; English throughout; disclosure through this log,
  no author attribution.
