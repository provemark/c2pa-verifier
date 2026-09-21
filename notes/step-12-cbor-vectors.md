# Step 12 — Recording what the CBOR says, and asking c2patool about four faults

*2026-09-21.* SPEC-006 (the CBOR decoder) was drafted from step 09's
inventory. Its positive criteria need recorded expected values that do not
depend on the library used to obtain them, and its two "stricter than the
oracle" claims — indefinite lengths, duplicate keys — and its one
"deliberately not stricter" choice — deterministic encoding — needed a
measurement. This step is both. No verifier code; the draft was updated.

## The sixteen recorded values

`tests/Fixtures/cbor/<store>--<label>.json`, one per `cbor` content box of
the four stores, written by a throw-away script: the box located with our
own SPEC-005 parser, the bytes decoded with `spomky-labs/cbor-php` 3.4.2
(scratch directory only), the value rendered in a JSON form that keeps
what JSON cannot — bytes as `{"$bytes": hex}`, maps as ordered key/value
pairs with int keys kept as ints, tags as `{"$tag", "$value"}`. Each file
also records the box's data offset, length and SHA-256, so a reader can
find the bytes again. Three checks on the way: the PNG signature is tag 18
over four items (1,285 bytes, `{pad: 10,932 bytes}`, null, 64 bytes); the
PNG claim has its seven keys in order; the Adobe unprotected header has
`x5chain`, `sigTst`, `pad`.

This is the oracle of AC2: the decoder must reproduce these files.
Committed as data, so no test ever installs cbor-php.

## Four faults inside a signed manifest

`bin/make-cbor-vectors.php` splices a few bytes into one CBOR box of the
PNG store, adjusts every enclosing LBox, and writes both the changed box
(`.cbor`) and a PNG carrier with the store re-embedded. Every change also
breaks the signature or the assertion hash — nothing can be changed in a
signed store without that — so the question to c2patool is not "valid or
not" but *how* it fails: a parse error (it refused the encoding) or a
signature/hash mismatch (it read the encoding and only the crypto
noticed).

| variant | c2patool 0.27.22 | so |
|---|---|---|
| `created_assertions` as an indefinite-length array | `Invalid`, `claimSignature.mismatch` | **read** — c2pa-rs accepts indefinite lengths |
| `version` as the float 0.0 | `Error: claim could not be converted from CBOR` | refused, as a type error on the field; whether a float elsewhere would pass is not shown |
| `alg` renamed to a second `dc:title` | `Error: unknown algorithm` | **read** — the duplicate is not noticed; the missing `alg` is |
| `start` 33 encoded as `19 0021` instead of `18 21` | `Invalid`, `assertion.hashedURI.mismatch` | **read** — deterministic encoding is not enforced on input |

cbor-php, for comparison, decodes the first, second and fourth and refuses
the duplicate key.

## What this settles

- SPEC-006 refuses indefinite lengths (AC6) and duplicate keys (AC13)
  where c2pa-rs reads them. Both are stricter in the safe direction, and
  both have the format on their side: C2PA 2.4 requires the claim and the
  standard assertions to follow RFC 8949 §4.2.1, which forbids the first
  and the second. The divergence is written next to each criterion.
- SPEC-006 does **not** enforce deterministic encoding on input — and now
  measurably neither does the oracle. The open question is closed the
  way the draft proposed.
- The float variant proves less than hoped: c2patool's error is about
  the field's type. The decoder's float refusal (AC7) rests on the
  inventory (zero floats in sixteen blobs from two writers) and on fail
  closed, not on this measurement; the note says so.

## Measured, in numbers

16 JSON files (the two signature files are the largest: the certificates
and the 10,932-byte `pad` in hex); 4 `.cbor` and 4 `.png`; 4 c2patool
runs; the script under PHPStan level max and Pint, `composer check` exit
0 (90 passed, no test uses the new files yet).

## Reasoned, not measured

- The JSON rendering's `$map` form is the test's own convention, chosen
  so that the decoder's output and the recorded value can be compared
  with one equality; nothing in the verifier uses it.
- Whether a float in a *custom* assertion would be tolerated by c2patool:
  not built. If a real file ever carries one, that is the fixture for the
  amendment SPEC-006 anticipates.
