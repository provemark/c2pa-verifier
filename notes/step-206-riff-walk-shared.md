# Step 206 — The RIFF walk shared, nothing else changed

*2026-10-05. SPEC-055 approved by Maurice van Loon the same day; this is
the refactor its open question 3 proposed before the tests.*

## Why

SPEC-055 reads WAV with SPEC-003's rules, criterion by criterion. Copying
the WebP walk would leave two copies to keep equal by hand. Moving it once,
before any WAV code, keeps this step to one idea, and shows the move on its
own: if a verdict changed now, the move would be the only cause.

## What changed

- `src/Container/RiffManifestStoreExtractor.php`: the walk, taken from
  `WebpManifestStoreExtractor` unchanged except for two constructor
  parameters: the form type (`WEBP`) and the name used in messages
  (`WebP`). With those values every message is the same text as before.
  `git show HEAD:…Webp… | diff - …Riff…` shows only those parameters,
  the class name, the docblock and four comments that now say "SPEC-003
  AC…".
- `src/Container/WebpManifestStoreExtractor.php`: the same name,
  constant, constructor and public `$maxChunkLength`; `extract()` delegates
  to a `RiffManifestStoreExtractor('WEBP', 'WebP', …)`.
- SPEC-004 AC1's test grepped the WebP file for `new StreamReader(`. The
  walk is no longer there, so that test went **red** (1 failed, 620
  passed). It now greps `{Jpeg,Png,Riff}ManifestStoreExtractor.php` and
  checks that the WebP class delegates to the RIFF class: SPEC-004
  amendment 2, **awaiting confirmation**. The criterion's substance is the
  same.
- SPEC-003's Traceability points at the new file; Traceability may change
  without a new approval.

## Measured

- `composer check`: exit 0, 621 tests, SPEC-003's sixteen criteria
  unchanged.
- The corpus: every file under `tests/Fixtures/` (606, the WAV files
  included), with no settings and with `trust/full.settings.json`, each
  report's `toArray()` hashed with today's date masked: 1,212 runs. Run
  twice before the change (identical), once after: **identical**.

## Next

Step 207: SPEC-055's tests, red.
