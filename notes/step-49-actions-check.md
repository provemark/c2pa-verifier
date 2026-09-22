# Step 49 — The SPEC-018 tests seen red (49a), then `ActionsCheck` until they are green (49b)

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

---

# 49b — Green: `ActionsCheck`, and the second wrong `Valid` closed

*2026-09-22, the same day.* `composer check` green: 19 specs, Pint,
PHPStan 0, Deptrac 0, `Tests: 301 passed (3618 assertions)`; a fuzz
replay (seed 100 ×24, 2 334 runs) 0 faults.

## What was written

- `Manifest\ActionsCheck` — `check()` collects the actions assertions in
  the claim's order (created list, then gathered; `c2pa.actions.v2`,
  `c2pa.actions` and their `__n` duplicates), leaves out any whose
  hashed URI mismatched or that is not in the store, and hands them to
  `checkAssertions()`: v1 → at most one; v2 → every one well-formed
  (`checkData()`: a map, `actions` a non-empty list of maps with a
  non-empty text `action`, at most 10 000) and the first one opening
  with `c2pa.created` or `c2pa.opened`. `assertion.action.malformed` on
  the assertion's url for the shape, on the manifest for the opening.
- `StatusCode::AssertionActionMalformed` (a failure).
- `Verifier::check()`: `actions` between `hashedUris` and `dataHash`,
  the mismatched hashed-URI urls passed as unreadable.

## What the first green run showed (SPEC-018 amendment 2)

- **The url.** c2patool prints the **bare manifest label** for this
  rule's manifest-level fault — `urn:c2pa:488bf983-…` — where its
  hard-binding faults carry `self#jumbf=/c2pa/urn:c2pa:…` (SPEC-012). The
  drift alarms compare code *and* url, so this verifier prints the same
  bare label here; the assertion-level faults keep the JUMBF form. A
  c2pa-rs inconsistency, copied on purpose and written down.
- Pest's variadic `toContain($needle, $message)` twice more (the fifth
  and sixth time); a `null` array key in a test loop. Fixed in the
  tests.
- Nine older tests list `checks_performed`: `actions` inserted; the enum
  count 31.

## Measured, front door

| file | before | after | c2patool |
|---|---|---|---|
| `absence/no-actions.png` | `Valid` (`Trusted` with its root) | `Invalid`, "the manifest has no actions assertion", url the label | `Invalid`, the same url |
| `absence/actions-first-edited.png` | `Valid` | `Invalid`, "the first action is c2pa.edited" | `Invalid`, the same |
| `absence/actions-empty.png` | `Valid` | `Invalid`, "actions is empty" on the assertion's url | exit 1, no report |
| `fixture-signed.png`, the corpora | as before | unchanged — AC2 over the eight v2 and seven odd v1 manifests | unchanged |

## Where this leaves the audit

Two wrong `Valid`s in two days, both found by asking a question no
corpus file asks — and both closed with a signed variant that showed
them first. The remaining, named leniency is c2pa-rs's content family
for actions (ingredient parameters, icons, templates) and the rules of
`SPEC013_NOT_YET`; M7 is where they come up. The absence audit's method
— a signed variant for every "X when Y" — is now tooling
(`bin/make-absence-variants.php`) and a habit.

