# Step 548 — 0.11.3 released

*2026-11-14.* Maurice: "tag en release v0.11.3 maar"; recorded at "leg de
0.11.3-release vast".

## What happened

- `main` was pushed up to `3ef897d` (steps 546–547).
  - CI on `56659da` was red (step 546: a test helper in the wrong file).
  - CI was green on `c2fde5e` (run 43598540343) and on `3ef897d` (run
    43602079589), 8 of 8 each.
- Tag `v0.11.3` (annotated, `fa83d6e`) on `3ef897d`, pushed.
- **CI on the tag** (run 43602456579): 8 of 8 jobs `success` on the first
  attempt.
- GitHub release `v0.11.3`, its text the CHANGELOG section:
  https://github.com/provemark/c2pa-verifier/releases/tag/v0.11.3
- Packagist lists `v0.11.3`, source `3ef897d`. It was there at the first
  check, right after the release.

## Measured on a fresh install

`composer require provemark/c2pa-verifier:^0.11` in an empty directory:

- installed `v0.11.3` from `3ef897d`; `Verifier::VERSION` is `0.11.3`;
- 688 files, equal to `git archive v0.11.3` and to step 547's count with
  its note committed;
- its `bin/c2pa-verify --crjson` on
  `public-testfiles/adobe-20220124-E-clm-CAICAI.jpg`: `jsonGenerator`
  `0.11.3`, and `manifests[0]` failure codes `signingCredential.untrusted`,
  `assertion.hashedURI.mismatch`, `ingredient.manifest.missing` — the last
  one is SPEC-091's.

No advisory: no verdict moves.

## Left

- Downstream, nothing has to move. The library (`^0.11`, conflict
  `>=0.12`) and the Drupal module (`^0.11`) receive 0.11.3 on their next
  `composer update`.
- The module's conformance evidence is run again on 0.11.3. That run and
  its integrity rubric are the check that SPEC-091 closes the gap the
  programme would see.
