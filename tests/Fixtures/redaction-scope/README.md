# Redaction scope (step 158, SPEC-035 amendment 5)

Two files for SPEC-035 AC9, both made from `../fixture-signed.png` by
`bin/make-redaction-scope-variants.php`. Nothing is re-signed. The
`c2pa.thumbnail.claim` box is taken out of the active manifest's assertion
store. In front of the active manifest sits an unsigned manifest of exactly
the removed length that nothing references. The active claim, its signature
and the store's length are unchanged, so the claim signature and the data
hash still match.

| file | the unreferenced manifest's `redacted_assertions` | c2patool 0.27.22 and 0.28.0 (`--settings ../trust/full.settings.json`) |
|---|---|---|
| `unreferenced-redacts-thumbnail.png` | the active manifest's thumbnail | `Invalid` (`assertion.missing` on the thumbnail) |
| `unreferenced-no-redaction.png` | empty | `Invalid` (`assertion.missing` on the thumbnail) |

Under the same settings the untouched fixture is `Trusted` in both
versions. The answers are in `../c2patool/redaction-scope/`. The shape was
found by the review of step 157.
