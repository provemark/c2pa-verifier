# Step 344 — The release commit for 0.6.1

*2026-10-10.*

0.6.1 carries SPEC-067 and its amendment 1 (steps 341–343): icons in the
data boxes of earlier C2PA versions are read and their hash checked.

- The CHANGELOG's *Unreleased* becomes **0.6.1 — 2026-10-10**; the README
  names the new tag and what it changes.
- **A patch:** `bin/api-check.php` reports the contract unchanged (135
  symbols); the new methods are internal. `provemark/content-credentials`
  0.19 (`<0.6 || >=0.7`) and the Drupal module (`^0.6`) take it unchanged.
- **The corpus against `e4a59e4` (0.6.0 plus nothing):** 1,792 runs, 10
  move, all on the five data-box fixtures (steps 342 and 343).
- `composer check`: 1,028 passed. `bin/package-check.php`: 460 files, 4.7 MB in the dist; 1.6 MB as a zip.

Maurice gave the word to push and release 0.6.1.
