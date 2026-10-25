# Step 442 — "2 assertion storees": each refusal its own plural

*2026-10-25.* Found in step 437 and left for a step of its own.

## What

A manifest holds one assertion store and one claim signature box (C2PA
2.4 §11.1). `Manifest::theOne()` refuses a manifest with two, and wrote
the count with the noun plus `es`: right for "2 signature boxes", wrong
for "2 assertion storees". The test of step 437 matched only the count
for that reason.

The callers now pass the plural (`assertion stores`, `signature boxes`).
The status code, the URL and the verdict are unchanged; only the
explanation's wording moves.

## Measured

`ManifestStoreTest` "AC12: a manifest holds one assertion store and one
claim signature, never two" now asks for "2 assertion stores
(c2pa.assertions), expected one" and "2 signature boxes (c2pa.signature),
expected one". Red before the fix (`2 assertion storees`), green after.
`composer check`: 1,375 passed, 1 skipped.

## For the next release

A wording change in a refusal's explanation; for the 0.8.3 changelog
next to steps 427, 429 and 430.
