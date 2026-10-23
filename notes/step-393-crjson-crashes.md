# Step 393 — SPEC-074 and SPEC-075 amendment 2: crJSON no longer crashes on odd values

*2026-10-23.* The sixth finding of the review of 2026-10-23. Both
amendments approved by Maurice. crJSON output is new since 0.7.1 and not
in any release.

## The finding

Two values in a manifest stopped `--crjson`:

- a CBOR map key starting with a NUL byte: PHP cannot hold it as an
  object property, and threw an uncaught `Error` (exit 255);
- a JSON assertion with a number beyond a double (`1e99999999`): PHP
  reads it as infinity and then cannot encode it, so the whole document
  was refused (exit 2). An integer beyond 64 bits was written back as a
  float, a different number.

## The fix

- `CrJsonValue::map()` refuses a key starting with NUL with
  `CrJsonValueException`, naming the path; the assertion becomes `{}`, as
  for every value crJSON cannot express (SPEC-074 AC6). A NUL elsewhere
  in a key is kept.
- `CrJsonValue::fromJson()` returns a JSON assertion as it is only where
  it can be written back as the same JSON: decoded once normally and once
  with big integers as strings, the two must match, and the value must
  encode. Otherwise `{}`.

## Measured

- Red first: AC8 (the uncaught `Error`) and AC10 (`fromJson()` did not
  exist).
- Mutations, each failing: the NUL key let through (1), big integers let
  through (2), an infinity let through (1).
- The reviewer's probes `nul.jpg` and `inf.jpg` (altered files whose
  making is not recorded, so not kept as fixtures): `--crjson` exited 255
  and 2 before; both exit 1, their report's status, with a whole document
  after.
- `composer check`: 1,290 passed, 1 skipped.
