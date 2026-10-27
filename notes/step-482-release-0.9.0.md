# Step 482 — The release commit for 0.9.0

*2026-10-27.* Maurice: "akkoord, push 481 en bereid daarna de minor voor".

0.9.0 carries steps 453–481: `signingCredential.expired` named as a code
of older tables (453), which trust lists a verdict was judged against
(SPEC-081, 454–456), the evidence behind each verdict (SPEC-082 to
SPEC-085, 457–470, with two CI fixes in 468 and 469), a revocation source
the caller injects for the signer and the CAs of its path (ADR-0007,
SPEC-086 and SPEC-087, 471–478), and three status codes brought to the
letter of C2PA 2.4 (SPEC-088, 479–481).

**A minor, as decided.** The contract changes, so this is the one minor
that gathers those changes (step 451: the Drupal module should not have to
follow every change). `bin/api-check.php` reports 209 symbols in eighteen
classes, against 142 in `0.8.3`: the 67 new lines of
`tests/Fixtures/api/public-surface.txt`, each named in the CHANGELOG. The
`Verifier` constructor takes `revocationSource` as its optional last
argument. Nothing was removed or renamed. A caller on `^0.8` keeps 0.8.3.

What the release commit does:

- The CHANGELOG gains **0.9.0 — 2026-10-27** (Added, Changed,
  Documentation). The README names the new tag. `Verifier::VERSION` is
  `0.9.0`.
- `SECURITY.md` is unchanged: no default verdict moved, and no wrong
  `Valid` or `Trusted` was found or fixed in this round.

Measured:

- The corpus against `v0.8.3` (a worktree with a copied `vendor/`, checked
  to load its own `src/`, the current `tests/Fixtures/` copied once and
  read by both; no settings, `trust/full.settings.json` and the programme's
  lists, a fixed validation time): 3,042 runs, 18 differ — six files under
  each setting, every one a fixture of SPEC-088 or a SHA-1 imprint probe
  whose code SPEC-088 changed. No `validation_state` moved.
- `bin/package-check.php`: 617 files, 5.9 MB in the dist, 2.1 MB as a
  zip; every top-level path classified.
- `composer check` and `vendor/bin/pest --parallel`: both exit 0, 1,545 passed, 1 skipped. `bin/api-check.php`: the recorded surface matches; `bin/spec-check.php`: 88 specs.

Not done here: the push, the tag, Packagist, and the library, Drupal,
plugin and demo updates wait for Maurice's word.
