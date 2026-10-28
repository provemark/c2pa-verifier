# Step 486 — A stored response never read is `notTried`

*2026-10-28.* Finding 6 of the review of step 482, fixed before 0.9.0 is
tagged: SPEC-085 amendment 2, approved by Maurice. No verdict, status or
contract symbol changes.

## Why

The evidence (SPEC-085) says what became of each OCSP response offered
for a signer. When the signer's issuer is not in `x5chain`, no stored
response can be matched to the signer, so none is read. SPEC-085 called
each of them `unusable` all the same — the word for a response that was
read and refused (unreadable, not successful, about another certificate,
or not verifying). A good response that simply could not be checked
looked like a broken one, to a reader who keeps the evidence.

## What changed

- `OcspCheck::stored()` passes no readings for that case, so each stored
  response is `notTried`.
- SPEC-085's *Design* defines `notTried` as "not read": after a revoked
  answer, or with the issuer missing from `x5chain`. AC6's third case and
  AC8's rule on `notTried` follow (amendment 2).
- `OcspEvidence`'s docblock says the same.

A response fetched from the caller's source (SPEC-086) is not a stored
one: since SPEC-086 amendment 2 the source may still be asked, with the
issuer the chain walk verified, and its answer chosen beside the
`notTried` staple.

## Measured

- No corpus file reached the case (a scan over every fixture under no
  settings and `trust/full.settings.json`: no manifest with stored
  responses and fewer than two certificates).
- `bin/make-revocation-notried-variants.php` (new): signer L issued by
  the throw-away root R, `x5chain` [L], L's `good` stapled; keys deleted
  after the run. `c2patool` 0.28.3 calls it `Trusted` with no OCSP code.
- `tests/Unit/Verifier/RevocationNotTriedTest.php` (3 tests) and
  `EvidenceOcspTest.php` (AC6's third case, AC8's three runs): 7 failed
  before the build, each on `unusable` where `notTried` was expected; all
  pass after.
- Mutations (`RevocationNotTriedTest`, `EvidenceOcspTest`): three, all
  caught — the old `unusable` readings, no entries at all, and the first
  entry marked chosen.
- Corpus, 3,111 runs (no settings, `trust/full.settings.json`, the
  programme's lists) at `3ad2dd9` (worktree with a copied `vendor/`) and
  now, with each manifest's evidence outcomes added to the comparison:
  the reports are byte-identical; only the new probe's staple moves from
  `unusable` to `notTried`, in each of its three runs.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.

## Read, not measured

That the staple stays unread when the walk's issuer is the anchor R
itself: `stored()` matches responses against `x5chain[1]` only. Reading
the staple against the walk's issuer would be a change of behaviour, not
of a label, and is not part of this step.
