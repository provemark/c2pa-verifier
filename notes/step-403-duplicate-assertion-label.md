# Step 403 — SPEC-007 amendment 9: one label, the box the reference resolves to

*2026-10-23.* Noted by a reviewer of 2026-10-23 as outside their scope;
taken up with the low items.

## The finding

With two assertion boxes carrying one label, `Manifest::resolve()`
returned the first, which the claim's hash covers, while
`$manifest->assertions` kept the last, which the data hash, actions,
ingredient and other checks read. Not a wrong `Valid`: `HashedUriCheck`
reports the second box as `assertion.undeclared`, a failure. But the
checks read bytes no hash covered.

## The fix

`Manifest::read()` keeps the first box with a label and neither decodes
nor uses a later one; it is still reported as undeclared.

## Measured

- `fixture-signed.png` with a second `c2pa.actions.v2` (`c2pa.created`
  changed to `c2pa.opened_`, lengths fixed): the kept assertion was the
  second box (red), and is the one `resolve()` returns after (green);
  `assertion.undeclared` still reported.
- The corpus before and after: 0 of 1,854 runs moved.
- `composer check`: 1,316 passed, 1 skipped.
