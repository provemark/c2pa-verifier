# Step 14 — c2patool's JSON recorded; fifteen wrong claims through c2patool

*2026-09-21.* SPEC-007 (the claim and the manifest) was drafted from the
measurements of steps 09 and 12. Before approval it needed two things:
the JSON c2patool prints for the four fixtures — the oracle for the JSON
view and for the sister-library equivalence (AC6, AC7) — and one variant
per error criterion with c2patool's verdict on it. This step is both. No
verifier code; the draft was updated.

## c2patool's JSON, recorded

`tests/Fixtures/c2patool/{jpg,png,webp,adobe-20220124-C}.json`: the
default (non-`--detailed`) output of `c2patool 0.27.22` on each fixture,
unchanged. What it shows, and what SPEC-007's JSON view copies:

- top level: `active_manifest`, `manifests`, `validation_status`,
  `validation_results`, `validation_state`;
- per manifest: `claim_generator_info` (**always a list**, the v2 map
  wrapped), `title`, `instance_id`, `thumbnail` (`format`, `identifier`
  — an absolute JUMBF URI), `assertions`, `signature_info`, `label`,
  `claim_version`; for the v1 Adobe file `claim_generator` (a string) and
  `format` instead of `claim_generator_info`;
- `assertions` lists **neither the hard-binding assertion nor the
  thumbnail** — one entry for our fixtures (`c2pa.actions.v2`), two for
  Adobe (`stds.schema-org.CreativeWork`, and `c2pa.actions` rendered as
  **`c2pa.actions.v2`**). SPEC-007 keeps labels as stored; the sister
  parser matches actions on the prefix `c2pa.actions`, so the accessors
  agree either way.

`signature_info` and `validation_*` come from the crypto layers; the
JSON view of M2 leaves them out, and the sister parser's docblock says
missing fields degrade to null/false/[].

## Fifteen variants

`bin/make-claim-variants.php` takes the PNG store (and, for one variant,
the Adobe store) and changes one thing: a label; a pair cut from the
claim map, or from the hash-data entry, with a thirty-line
definite-length CBOR walker to find the pair's bytes and the map's count
lowered by one; a value re-typed; a box duplicated. Every enclosing LBox
is adjusted, and every variant was run through the SPEC-005 parser first:
all fifteen parse as trees — only the meaning is wrong, which is the
layer SPEC-007 tests. A PNG carrier per variant, as in steps 10 and 12.

The table is `tests/Fixtures/claim/README.md`. Grouped:

**Errors, as expected (11).** A claim labelled `.v3` (`claim version is
too new`); `signature`, `created_assertions`, `instanceID`,
`claim_generator_info` or `name` missing, and a hash-data entry without
`hash` (all `claim could not be converted from CBOR` — serde's one
message for every schema fault); a URI to a box that does not exist and
one to the claim box (`assertion missing: url = c2pa.hash.data`); a
second claim superbox and a claim with two `cbor` boxes (two distinct
messages); no manifest (`provenance not found`).

**`Invalid` rather than an error (3).** The assertion store labelled
`c2pa.assertionz` (`claim.multiple`); the hash re-typed as the text
`"abc"` — c2pa-rs **reads it** and fails only at `hashedURI.mismatch`,
the type is not checked; and the broken JSON, which c2patool does not
treat as a parse error at all: it emits the status codes
`assertion.json.invalid` and `assertion.required.missing` and the verdict
`Invalid`.

**What this settles.** SPEC-007 is stricter than the oracle on two: a
non-byte-string `hash` (AC11) and a mislabelled assertion store (AC12) —
errors where c2pa-rs reads on. Safe direction, written next to the
criterion. The JSON case (AC13) is different in kind: c2patool has a
*status code* for it. SPEC-007, a parse layer, errs; the Verifier layer
(its own spec, M3+) must map that error onto `assertion.json.invalid`
so that the verdicts agree — recorded in the spec so it is not forgotten.

## Measured, in numbers

4 recorded JSON files; 15 `.bin` and 15 `.png`; 15 SPEC-005 parses, 15
c2patool runs; the script under PHPStan level max (three findings fixed:
a docblock, and `chr()` of a count that PHPStan could not prove
non-negative) and Pint; hashes unchanged after the fixes; `composer
check` exit 0 (106 passed, no test uses the new files yet).

## Reasoned, not measured

- That serde's `claim could not be converted from CBOR` means "schema
  violation" in each of the six cases: from the variants, not from
  reading c2pa-rs's claim struct.
- The `uri-wrong-place` message naming `c2pa.hash.data` (the URI now
  says `c2pa.claim.v2`): c2pa-rs evidently looks for the hard binding by
  label and reports that; not investigated further.
