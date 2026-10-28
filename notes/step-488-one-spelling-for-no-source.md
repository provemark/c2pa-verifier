# Step 488 — One spelling for "no source"

*2026-10-28.* Finding 10 of the review of step 482, fixed before 0.9.0 is
tagged: SPEC-086 amendment 5, approved by Maurice. No verdict, status or
contract symbol changes.

## Why

The `Verifier`'s last parameter was `?RevocationSource $revocationSource
= null`, and `Verifier::source()` turned `NoRevocationSource` into null.
"No source" thus had two spellings, `null` and `new NoRevocationSource`,
and a caller had to know they mean the same. After the tag the contract
could not be narrowed to one without a breaking change; no caller uses
the parameter yet.

The review also noted that a source of the caller's that always answers
null gives other reports than `NoRevocationSource`. That stays, on
purpose: such a source was asked and got nothing, which is
`signingCredential.ocsp.inaccessible` (SPEC-086 AC4); the default is
never asked and changes nothing (AC9).

## What changed

- The parameter is `RevocationSource $revocationSource = new
  NoRevocationSource`: no argument works as before; `null` is a
  `TypeError`.
- Inside the verifier, null still means "not asked"; that is not part of
  the contract.
- `RevocationSource`'s docblock says how to give no source, and what a
  null answer from a source means. The CHANGELOG's 0.9.0 entry says the
  parameter is not nullable.
- The five test files that build a `Verifier` with an optional source
  now give no argument when they have none.

## Measured

- `RevocationSourceTest.php`: "no source has one spelling" (reflection on
  the parameter's type, nullability and default, and `null` throwing)
  failed before the build and passes after; the guard "a source of the
  caller's that answers null was asked; NoRevocationSource never is"
  passed before and after, as the amendment keeps that behaviour.
- Mutations (`RevocationSourceTest`): four, all caught — the parameter
  nullable again, a null default, the default asked, no source ever
  asked.
- The contract stays at 209 symbols (`bin/api-check.php` records names,
  not types).
- Corpus, 3,111 runs with the default `Verifier` (no settings,
  `trust/full.settings.json`, the programme's lists) at `6ef5017`
  (worktree with a copied `vendor/`) and now, reports and evidence
  outcomes compared: identical.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
