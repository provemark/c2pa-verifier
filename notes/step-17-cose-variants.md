# Step 17 — Eleven broken COSE structures through c2patool, before SPEC-008 is approved

*2026-09-21.* SPEC-008 (the COSE_Sign1 structure) was drafted from step
16. Its error criteria needed one variant each and c2patool's verdict.
This step extends `bin/make-cose-variants.php` with eleven structural
variants of the PNG fixture's signature box, checks each at the CBOR
level, and runs c2patool on the carriers. No verifier code; the draft
was updated.

## How the variants are made

Same-length edits where one byte does it (the tag, the payload `f6` →
`40`, the map head, the `alg` label, the `x5chain` label, the leaf's first
DER byte); a shrink for `no-tag` and `three-items` (the tag byte, the
signature item removed, every enclosing LBox adjusted); and three
replacements of the whole protected header, re-headed with a
shortest-form byte-string length: `{"alg": -7}`, `{1: -7, 33: []}`, and a
header with `x5chain` under both 33 and `"x5chain"` (the same two
certificates, reversed under the string). One variant was rebuilt after
the CBOR check: `a2` → `82` for "not a map" produced an array of two with
1,282 trailing bytes — a SPEC-006 error, not the fault meant; `a2` → `84`
gives a clean four-item array.

Every variant still parses as a JUMBF tree, and each carries the fault
intended, checked with SPEC-006: tag 19; an untagged array; a three-item
array; a `CborBytes` payload; a protected header that is a list; keys
`[2, 33]`; `[alg]`; `[1, 34]`; `[1, 33]` with a broken leaf; `[1, 33]`
with an empty chain; `[1, 33, "x5chain"]`.

## What c2patool 0.27.22 said

| variant | c2patool |
|---|---|
| `tag-19`, `no-tag`, `three-items`, `protected-not-map`, `alg-missing` | `Error: could not generate a trusted time stamp` |
| `alg-string-label`, `x5chain-missing`, `chain-empty` | `Error: could not find signing certificate chain in COSE signature` |
| `leaf-der-broken` | `Error: COSE error parsing certificate` |
| **`payload-present`** | **`Valid`**, `claimSignature.validated` |
| `double-label` | `Invalid`, `claimSignature.mismatch` + `assertion.dataHash.mismatch` |

Three readings:

- **The payload field is not checked by c2pa-rs.** An empty byte string
  where `nil` must be is accepted and the claim is read from the box
  regardless. C2PA 2.4 §13.2.3 is explicit that a zero-length byte array
  "cannot be used to indicate detached content". SPEC-008 AC8 refuses it
  — stricter than the oracle, with the text on its side, in the safe
  direction.
- **c2pa-rs's structural errors wear the wrong label.** Five different
  COSE faults come out as "could not generate a trusted time stamp": the
  COSE parse fails inside the code path that first reads the signature
  to look for a timestamp. An error is an error, but this is the kind of
  message the brief's §8.1 worries about in the other direction — a
  verifier that cannot say *why*. SPEC-008 names each fault.
- **`double-label` proves nothing about which chain c2pa-rs picks**: any
  change to the protected header breaks the signature, so the verdict is
  `mismatch` either way. AC11 rests on §14.5's rule, not on the oracle.

## What this settles

SPEC-008's twelve criteria all have their footing: AC1–AC6 the measured
structures and `Sig_structure` vectors, AC7–AC10 an error each with
c2patool's (mostly agreeing) answer next to it, AC8 the one place the
verifier is stricter, AC11 the spec's rule, AC12 synthetic limits. The
draft's blocking open question is resolved.

## Measured, in numbers

14 `.bin` and 14 `.png` under `tests/Fixtures/cose/` (three from step 16
plus eleven); 14 SPEC-005 parses; 11 CBOR-level checks; 11 c2patool runs;
the script under PHPStan level max (one finding: `chr()` of an
unbounded int, replaced by `pack('C')`) and Pint; outputs byte-identical
before and after the fix; `composer check` exit 0.

## Reasoned, not measured

- That "could not generate a trusted time stamp" is the timestamp code
  path reporting a COSE parse failure: from the message and the
  structure of the variants, not from reading `cose_validator.rs`.
