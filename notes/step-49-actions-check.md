# Step 49a — The SPEC-018 tests, seen red: six tests, two more signed variants, and c2patool's two answers

*2026-09-22.* SPEC-018 approved; the tests-first half of step 49. Nothing
under `src/`.

## The variants (measured)

`bin/make-absence-variants.php` extended with two edits of the actions
assertion's *content* — the first `action` rewritten `c2pa.created` →
`c2pa.edited`; the `actions` list emptied — each with the assertion's
hashed URI, the data hash and the claim re-signed as the other absence
variants are. Six variants now, a new throw-away hierarchy per run.

| variant | c2patool 0.27.22 (no settings / root as anchor) | this verifier, before |
|---|---|---|
| `actions-first-edited` | `Invalid` — `assertion.action.malformed` "first action must be created or opened", url the manifest / the same | `Valid` / `Trusted` |
| `actions-empty` | **exit 1**, "validation rule was violated: No Action array in Actions", no report — c2pa-rs's "failure full stop" branch | `Valid` / `Trusted` |

The second answers the spec's first open question: c2patool puts the
empty-list fault on no url at all. This verifier will put it on the
assertion's (SPEC-018 amendment 1a). The second open question — a
signed v1 two-actions variant — is answered no: the PNG fixture's claim
is v2 and rewriting it to v1 is a claim rewrite, not an edit; AC4's v1
half runs through the seam `checkAssertions()` (amendment 1b).

## Red, for the right reason

```
$ vendor/bin/pest --group=SPEC-018
Tests:    6 failed (3 assertions)
```

AC1's first assertion is the verdict itself — `no-actions.png`:
`'Invalid'` expected, `'Valid'` given — put first on purpose, so that the
red line is the wrong `Valid` and not a missing class. The other five
fail on `StatusCode::AssertionActionMalformed` and
`Manifest\ActionsCheck` not existing. The rest of the suite: 295 passed.

## What the tests pin

- AC1 — `no-actions.png` with and without its root: `Invalid`, one
  `assertion.action.malformed` on the manifest url equal to c2patool's,
  "no actions assertion", `signingCredential.trusted` *and* `Invalid`
  under the root, `checks_performed` with `actions` between
  `hashedUris` and `dataHash`.
- AC2 — the eight v2 and seven "odd" v1 manifests of the corpora: no
  `assertion.action.malformed`, `actions` in `checks_performed`, the
  claim versions as measured in step 48.
- AC3 — `actions-first-edited` (manifest url, naming `c2pa.edited`, equal
  to c2patool's url), `actions-empty` (the assertion's url, "empty", the
  oracle's stderr quoted).
- AC4 — the fixture's own gathered actions assertion passes; through
  `checkAssertions()`: two v1 actions assertions → one fault on the
  manifest url naming v1; one v1 opening with `c2pa.edited` → nothing;
  none in v1 → nothing; the same non-opening one as v2 → the manifest
  url.
- AC5 — `hashed-uris-two-changed`: the actions assertion's hashed URI
  mismatches, no `assertion.action.malformed`, `actions` still listed.
- AC6 — six malformed shapes through `checkData()` as v2 name the field
  (`actions`, `actions[0]`, `action`); the same six as v1 return nothing;
  10 000 actions pass, 10 001 do not.
