# Step 456 — Which trust lists a verdict was judged against

*2026-10-27.* SPEC-081 built. No verdict changes; the public contract grows
by one class and two members, so this waits for the next minor release.

## Why

A `Trusted` verdict means something only together with the lists it was
judged against, and the report did not say which. The obvious identifier
does not help: every version of the conformance programme's JSON lists in
`c2pa-org/conformance-public` carries `LoTESequenceNumber` 1 and the same
`ListIssueDateTime`, while the content changed six times (signer list) and
five times (TSA list) between 5 August and 17 October 2026. Only the bytes
tell the versions apart (the measurement is in the spec).

## What changed

- `Trust\TrustListIdentity`, a `readonly` value object: the list's kind
  (`manifest` or `tsa`), the form read (`pem` or `lote`), the SHA-256 of
  the string exactly as given (a byte order mark included), and for a LoTE
  list its sequence number and issue date.
- `TrustSettings::fromTrustLists()` puts one on each `TrustAnchorSet` it
  builds (`$identity`; null for a set built any other way).
- `VerificationReport::trustLists()` returns them, in the order given.
  Without settings, or with a settings document in `c2patool`'s shape, it
  is empty (open question 1, decided: not now).
- A sequence number that is not an integer of at least 1, or a date that is
  not an RFC 3339 date-time, becomes null. The list is read exactly as
  before; nothing new is refused (open question 2, decided).
- `toArray()`, `toJson()`, `toCrJson()` and the CLI are unchanged. C2PA 2.5
  adds `trustListUris` to crJSON, but `c2patool` 0.28.1–0.28.3 do not write
  it (step 454), so it waits for an oracle.

## Measured

- **Red first.** The 23 new tests all failed on the missing
  `trustLists()`; `ApiSurfaceTest` AC1 failed on the ten symbols added to
  `tests/Fixtures/api/public-surface.txt`. Then green: `composer check`,
  1,432 passed, 1 skipped (the same skip as before).
- **Mutations.** Four changes to the new code, each caught: the digest taken
  after the byte order mark is removed (AC4), a sequence number of 0
  accepted, the date check dropped, a numeric string accepted as a number
  (AC7).
- **The corpus (AC6).** Every file under `tests/Fixtures/` (1,020) verified
  under the programme's two lists, once as PEM and once as LoTE, at a fixed
  validation time, before (a worktree at `3dbbe28` with its own copy of
  `vendor/`) and after: the SHA-256 of `toJson()` and `toCrJson()` is the
  same in all 2,040 runs. The worktree was checked to load its own `src/`.

## For the next release

A contract change: 152 symbols, twelve classes. The CHANGELOG entry of the
next minor names `TrustListIdentity`, `TrustAnchorSet::$identity` and
`VerificationReport::trustLists()`.
