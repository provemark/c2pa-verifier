# Step 495 — The evidence as a document to keep

*2026-10-28.* SPEC-089, drafted and approved the same day (Maurice: the
open questions as proposed). Meant for the 0.10 minor, with group F and the
C2PA 2.5 additions, so that the library and the Drupal module follow once.

## Why

0.9.0 made a report show what its verdict rests on, as PHP objects only.
Maurice wants the Drupal module to store that evidence in an archive. A
format each caller invents is a second truth; the verifier writes it once.

## What changed

- `VerificationReport::evidenceToArray()` and `evidenceToJson()` (two
  contract symbols, 211), written by `Verifier\EvidenceDocument`
  (internal).
- `docs/evidence-format.md`: every key, its type, when it is null, and the
  rule for change (within version 1 keys are only added).
- README's contract table, the CHANGELOG under Unreleased.

## Measured

- `tests/Unit/Verifier/EvidenceDocumentTest.php`: 16 of 17 tests failed
  before the build, the guard on the constants passed. One more test (two
  manifests in order) was added when mutation M9 survived.
- Mutations: fourteen, all caught — an empty `manifests` as `[]`, url-safe
  base64, no scrubbing, codes by enum name (time-stamps and OCSP), no
  verdict, no trust lists, no anchor list, no `tsa`, no token certificates,
  unescaped unicode, manifests reversed, `?` instead of U+FFFD.
- AC7 writes the document for every corpus file under no settings,
  `trust/full.settings.json` and the programme's lists: no fault.
- Corpus, 3,132 runs at `0581010` and now: `toJson()`, `toCrJson()` and the
  evidence outcomes identical.
- Two amendments, confirmed by Maurice the same day. 1: the fixture AC1 described does not
  exist (`fixture-signed.png` carries no time-stamp), so AC1 uses
  `timestamp/tsa-matrix/control.png`; and a label reaches the evidence only
  as a C2PA or uuid URN, whose free part is printable ASCII without a colon,
  so a bidi override, a control character or text that is not UTF-8 never
  reaches the document from a file. 2: `mb_scrub()` writes `?`, not
  U+FFFD (`php -r 'echo bin2hex(mb_scrub("a\xffb","UTF-8"));'` prints
  `613f62`); the document writes U+FFFD.
- Found on the way, not changed: `ValidationStatus` scrubs an explanation
  with `mb_scrub()`, so it writes `?`, while `Cli\Command`'s comment says
  U+FFFD.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
