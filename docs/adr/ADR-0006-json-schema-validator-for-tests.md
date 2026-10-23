# ADR-0006: A JSON Schema validator for the tests only

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-10-23                     |
| Decided  | Maurice van Loon               |

## Context

The crJSON this verifier will write (SPEC-075, SPEC-076) has a schema:
C2PA 2.4's crJSON page embeds a JSON Schema, draft 2020-12, `$id`
`https://c2pa.org/crjson/crJSON.schema.json`. Step 370 measured that the
reference implementation's own crJSON fails it on every file. A test that
checks every crJSON this verifier writes against the schema is the
cheapest way not to repeat that.

ADR-0001 keeps the runtime free of dependencies beyond PHP's own
extensions. A schema validator is not needed at runtime: the verifier
writes crJSON, it never reads or checks it.

Writing a validator for draft 2020-12 here would be a project of its own,
and an unproven one. Two maintained PHP libraries exist (Packagist,
2026-10-23):

| package | version | drafts | licence | downloads |
|---|---|---|---|---|
| `opis/json-schema` | 2.6.0 (2025-10-17) | up to 2020-12 | Apache-2.0 | 57.7 million |
| `justinrainbow/json-schema` | 6.13.1 (2026-09-30) | up to draft-07 | MIT | — |

The crJSON schema declares 2020-12.

## Decision

- `opis/json-schema` `^2.6` is added to **`require-dev` only**. Nothing
  in `src/` uses it; Deptrac and the package check keep it that way, and
  the installed package does not pull it in.
- The schema is copied byte for byte from the crJSON page into
  `tests/Fixtures/crjson/crJSON.schema.json`, with its source URL, the
  date and its SHA-256 in a README there, and the page's licence
  (CC BY 4.0) named.
- Before it is trusted, the validator is measured against the one already
  used (step 370, Python `jsonschema` 4.26.0): on `c2patool`'s 59 crJSON
  answers it must find the same files invalid for the same reasons (the
  two extra manifest keys on every manifest; then six missing
  `timestamp`s and one v1 `metadata` array).

## Consequences

- A contributor runs `composer install` with one more dev package and its
  two dependencies (`opis/string`, `opis/uri`).
- A schema change upstream is a new copy and a visible diff, not a
  silent drift.
- If the schema itself is wrong where the prose is right (step 370 named
  three places where they disagree), the test will say the schema's
  answer. Where this verifier follows the prose instead, the spec names
  it and the test excepts it by name.
