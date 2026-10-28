# Step 489 — 0.9.0 measured again before the tag

*2026-10-28.* Maurice: "akkoord, push 488 en doe 489". Docs only.

Steps 483–488 fixed the findings of the review of step 482 (1, 2, 3, 4, 6,
7 and 10) after the release commit. That commit's CHANGELOG entry carried
the date of step 482 and a corpus comparison made before those fixes.
This step measures again and brings the entry up to date. Findings 5, 8
and 9 (efficiency and tidying) wait until after 0.9.0.

## What changed

- `CHANGELOG.md`: **0.9.0 — 2026-10-28**; the introduction says twelve
  files differ from `0.8.3` (nine in a status code, three in an
  explanation only); the SPEC-088 bullet under *Changed* names the new
  explanation of a decoder limit.

## Measured

- The corpus against `v0.8.3` as in step 482: a worktree at `v0.8.3`
  with a copied `vendor/` (checked to load its own `src/`, version
  `0.8.3`), the current `tests/Fixtures/` copied once and read by both,
  no settings, `trust/full.settings.json` and the programme's lists, the
  validation time 2026-10-27T12:00:00Z, the version string removed from
  the reports before hashing. 3,111 runs, compared row by row: 36 differ,
  twelve files under each setting; no `validation_state` moved.
  - a status code, `general.error` → `assertion.cbor.invalid`:
    `claim/assertion-cbor-{break,reserved,trailing,duplicate,utf8}.png`;
  - a status code, `timeStamp.malformed` → `timeStamp.untrusted`:
    `timestamp/tsa-matrix/{imprint-sha1,expired-signer-imprint-sha1,tsa-cert-absent,tsa-cert-absent-chain-only}.png`;
  - an explanation only, `general.error` kept:
    `claim/assertion-cbor-{depth,undefined,bigint}.png`.
  The probes of steps 483, 484 and 486 do not differ: without a
  revocation source they are judged as `0.8.3` judged them.
- `bin/package-check.php`: 624 files, 6.0 MB in the dist, 2.1 MB as a
  zip; every top-level path classified. `bin/api-check.php`: the recorded
  surface matches (209 symbols).
- `composer check` and `vendor/bin/pest --parallel`: both exit 0.

Not done here: the tag, Packagist, and the library, Drupal, plugin and
demo updates wait for Maurice's word. The CHANGELOG date is today's; if
the tag falls on another day, the date follows it.
