# Step 10 — Twenty-three malformed manifest stores, measured before SPEC-005 is approved

*2026-09-21.* SPEC-005 (the JUMBF box tree) was drafted from step 09's
measurement of well-formed stores. Its error criteria needed the same
footing: one variant per fault, and c2patool's verdict on each, before the
maintainer approves the spec. This step is that measurement. No verifier
code; the draft was updated with the results.

## How the variants are made

`bin/make-jumbf-variants.php` takes the store out of
`tests/Fixtures/fixture-signed.png` with the SPEC-002 extractor (it checks
the 46,025 bytes and the step-09 hash first), changes **one field** at an
offset measured in step 09, and writes `tests/Fixtures/jumbf/<name>.bin`.
Two variants grow a box instead: four or sixteen bytes are inserted into
the `c2pa.hash.data` salt and every enclosing LBox — root, manifest,
assertion store, assertion superbox, description box, salt box — is
raised by the same amount, so the fault is *only* the salt length. One
variant is synthetic: seventeen nested superboxes, each with a minimal
description box, a CBOR box innermost.

c2patool cannot be given a bare store, so next to every `.bin` the script
writes a `.png`: the fixture with the variant store in its `caBX` chunk,
CRC recomputed (SPEC-002 AC6 would otherwise refuse it). Because the
fixture's hash binding excludes exactly the `caBX` chunk (step 09), a
same-length variant leaves the binding intact and c2patool's verdict is
about the store alone; a grown variant also moves the bytes after the
chunk, so those two report `assertion.dataHash.mismatch` as well.

The probe of step 09 was run on the grown variants and on `unknown-uuid`
to confirm the edits landed where intended (20- and 32-byte salts read
back, every superbox still ending on its LBox).

## What c2patool 0.27.22 said

The full table is `tests/Fixtures/jumbf/README.md`. Grouped:

**Errors, as expected (14).** LBox 0, 1 and 7 (`invalid JUMB box`,
`invalid JUMBF header`); a child overrunning its parent; a description box
typed `jumx` (`expected JUMD`); Label Present cleared, a label without a
NUL, and a private box that is not `c2sh` (all `unexpected end of file` —
c2pa-rs reads on past the fault and runs out); `c2cm` and `c2um` manifest
UUIDs; a `brob` claim box (`claim cbor box not valid`); `bfdb` without
`bidb`; a root whose UUID is `c2ma` (`"c2pa" block not found`) or whose
TBox is `cbor`; seventeen levels of nesting (`provenance not found`).

**`Invalid` rather than an error (4).** A `/` or a U+0001 in the claim's
label: c2patool no longer finds the claim under its label and reports
`claim.multiple`. The two salt variants: `assertion.hashedURI.mismatch`
because the salt bytes changed — the 20-byte salt is not rejected for its
length. SPEC-005 makes the label faults and the salt length errors: an
invalid label is malformed input, not a verdict.

**`Valid` (3) — the divergences that count.** The root's label `c2pb`,
the root's LBox one too large, and toggles with bit 5 set are all
accepted by c2patool; it checks none of the three. SPEC-005 rejects all
three. The direction is the safe one, as with SPEC-001 AC7, SPEC-002 AC6/7
and SPEC-003 AC4/5/7: an error where the oracle says `Valid`, never the
reverse.

**One that needed thinking (1).** `unknown-uuid` — the thumbnail
assertion's type UUID replaced — is an **error** for c2patool (`could not
create valid JUMBF for claim`), although §11.1.2 says an unknown UUID
"shall be skipped and ignored". Reading the message: it is not the box
that fails, it is the claim, whose `gathered_assertions` names
`c2pa.thumbnail.claim` and cannot resolve it to an assertion. That is the
division SPEC-005 proposes: the parser keeps the box as `UnknownBox`
(AC7); the layer that resolves the claim's references (SPEC-007) finds an
`UnknownBox` where an assertion should be and errors. The whole verifier
then agrees with c2patool; the layers disagree only about *where* the
error is raised, which is what layers are for.

## What changed in the draft

- AC3 gained the 32-byte salt as a positive case (`salt-32.bin`).
- AC7, AC10, AC12 and AC15 carry the oracle's behaviour next to the
  criterion; AC12's salt case is now a real 20-byte salt, not a length
  field that lies.
- The References name the measurement; the open question that blocked
  approval is resolved.

## Measured, in numbers

23 `.bin` files (21 of 46,025 bytes, two of 46,029 and 46,041, one of
604) and 23 `.png` carriers; 23 c2patool runs; `composer check` exit 0
with the script under PHPStan level max and Pint (seven findings fixed:
`hex2bin` returns `string|false`, so a checked `jumbfHex()` wraps it).

## Reasoned, not measured

- That c2patool's `unexpected end of file` on the toggle and label faults
  means "read past the fault", not a specific check — from the message
  alone; `jumbf_io.rs` was not read for this step.
- That a variant with a *content*-box type unknown inside an assertion
  (`zzzz` next to `cbor`) would be tolerated by c2patool — not built; it
  is SPEC-005's third open question and gets its fixture if the answer
  is "keep as UnknownBox".
