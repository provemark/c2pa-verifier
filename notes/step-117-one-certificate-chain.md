# Step 117 — a chain of one, as RFC 9360 writes it

*2026-09-24. SPEC-008 amendment 2 (AC13).*

## Why

Step 110 signed a probe with a leaf directly under a root. `c2pa-rs` wrote
the protected `x5chain` (label 33) as a **bare CBOR byte string**, and this
verifier refused the file (*"x5chain is not an array but a byte string"*,
`signingCredential.invalid`, `Invalid`), where `c2patool` 0.28.0 said
`Trusted`. RFC 9360, which C2PA 2.4 §14.5 quotes, allows exactly that:
*"If a single certificate is conveyed, it is placed in a CBOR byte
string."* SPEC-008 had required an array since step 16. That was
stricter than the RFC, never unsafe, and unseen, because every real
signer carries an intermediate.

## The fixture

`bin/make-x5chain-variants.php <c2patool-0.28.0> <c2patool-0.27.22>`:

- a throwaway P-256 root, and a leaf directly under it with
  `emailProtection`;
- `fixture-unsigned.jpg` signed by `c2patool` 0.28.0 as
  `tests/Fixtures/cose/x5chain-single.jpg`;
- the root as `x5chain-single-root.pem` and in
  `x5chain-single-root.settings.json` (with `store.cfg`);
- both `c2patool` versions' answers without settings and with the root,
  in `tests/Fixtures/c2patool/x5chain/`.

The keys are overwritten and deleted before the script ends, and it exits
non-zero if one survives. `grep -rl "BEGIN.*PRIVATE"` over both new
directories returns nothing.

Both oracles agree: **`Valid` without settings, `Trusted` with the root**,
in 0.27.22 and in 0.28.0.

## Red, then green

- `SPEC-008 AC13` was red on its first line: `CoseException: x5chain is
  not an array but a byte string`.
- The fix is in `CoseSign1::chain()`: a non-empty `CborBytes` becomes a
  list of one and goes through the same checks as an array element
  (non-empty, the 16,384-byte limit, the leaf parses as X.509). An empty
  byte string is *"x5chain is empty"*, as an empty array is. Anything
  else is *"neither a byte string nor an array but …"*.
- The first green run disagreed on one message: an empty byte string
  said *"x5chain[0] is empty"*. The code now says *"x5chain is empty"*,
  as the criterion asks.
- **The spec check from step 116 rang on its first real use.** Before
  SPEC-008's traceability row existed, `composer check` stopped at
  `FAIL: 1 finding(s)` for AC13.
- `composer check`: 433 passed, clean.

## What changed, and what did not

166 corpus signatures were read with the fixed parser, and the only one
whose `x5chain` is a byte string is the new fixture. So no existing
verdict moved. What changes is that a real 2.x signer directly under a
root, which RFC 9360 and both `c2patool` versions accept, is no longer
refused here.
