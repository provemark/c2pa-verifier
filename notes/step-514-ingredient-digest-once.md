# Step 514 — Many references to one manifest cost one hash

*2026-10-29.* Finding V5 of the security review before 0.10.0, a resource
finding. SPEC-021 amendment 9, approved before the test was written.

## The finding

Since step 408 (SPEC-021 amendment 7) the hash of every ingredient
assertion that names a manifest is compared, each under that assertion
(C2PA 2.4 §15.11.3.3.2). Each comparison hashed the manifest's box again.
A store may hold 256 ingredient assertions (`ManifestGraph::MAX_ASSERTIONS`),
so one large ingredient manifest named many times was hashed that many
times.

## What was measured

- The review's probe, an ingredient manifest (claim v1, unsigned) with an
  assertion of 15 MB, named by 255 ingredient assertions with the right
  box hash: 9.4 s on `61cdf7b`, 521 statuses, `Invalid`; with one
  reference, 0.11 s. After: 0.14 s, the same 521 statuses.
- AC14 builds the same shape in memory with an assertion of 2 MB, so no
  key and no fixture file is needed: the ingredient check runs whatever
  the signatures say. On `61cdf7b`: the 255 `ingredient.manifest.validated`
  statuses each under their own assertion, as expected, and 1.22 s against
  about 0.03 s for one reference, so the time bound fails. After: it
  passes. The old source against the final test once more: failed on the
  time (1.21 s).
- SPEC-021's 24 tests, AC12 among them (a second reference with a wrong
  hash), green after.
- The corpus before (`61cdf7b`, worktree, `vendor/` copied) and after:
  2,188 runs, none differs.
- `composer check` (1,703 passed, 1 skipped), `vendor/bin/pest
  --parallel` and `bin/package-check.php` pass.

## What was done

`IngredientManifestCheck::check()` keeps the digests it computes, by
manifest, route (the box, the pre-1.3 claim bytes, the signature box) and
algorithm, and compares every reference against them. Each reference is
still compared and reported under its own assertion. The digests live for
one `check()` call: kept across calls, keyed by object id, one file's
digest could meet another file's manifest. The public `hash()` keeps its
meaning and starts with no digests. `ActionsCheck::claimLabels()` of the
store is computed once before the loop instead of once per manifest.
