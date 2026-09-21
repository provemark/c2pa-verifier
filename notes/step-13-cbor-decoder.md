# Step 13 — The CBOR decoder (SPEC-006 implemented)

*2026-09-21. Oracle: the sixteen recorded values of step 12, RFC 8949
Appendix A and F.*

## What was built

`src/Cbor/`, the third layer, a leaf that uses only `Support\Bytes`:

- `CborDecoder::decode(string): mixed` — recursive descent over the string
  with an offset passed by reference. The head is read and classified
  before anything is allocated (`item()`); additional information 28–30
  and 31 are refused there, major type 7 is handled before any argument
  is read (`simple()`), and the argument's 1/2/4/8 bytes are taken only
  if they are there (`take()`). A string's declared length is checked
  against the remaining input before `substr` (`string()`); an array's or
  map's declared count against the item limit before the loop
  (`countable()`); the depth on entering an array, map or tag (`enter()`).
  Maps are built key by key: a key that is not int or string is refused
  with its kind named; a text key that PHP would silently turn into an
  int (`"1"`) is refused; a duplicate is refused after its value has been
  read, so a truncated map is reported as truncation (RFC 8949 Appendix F
  lists `a2 00 00 00` that way).
- `CborBytes`, `CborTag`, `CborException`.

Integers beyond PHP's signed 64-bit range read back negative from
`unpack('J')`; `fits()` turns that into the error rather than a float or
a wrap-around. Text strings go through `mb_check_encoding`; invalid ones
are shown as hex, at most 32 bytes.

## Measured

- Red: 16 tests on the missing classes (`c9eec06`).
- First run: 13 passed, 3 failed — all three in the *test file*: PHP had
  turned hex keys like `'17'` and `'81'` into ints (a cast in the loops),
  and the expected offset of the duplicate key in `a2 61 61 01 61 61 02`
  was miscounted (4, not 5; the spec's hex had a stray space too, fixed).
- Second run: 1 failed — `a2 00 00 00`, where the decoder reported the
  duplicate key 0 before the truncation; the duplicate check moved after
  the value.
- PHPStan: 29 findings in the test file (the decoder returns `mixed`;
  the tests now narrow with `assert`s that are themselves checks) and one
  in the decoder (a return docblock PHPStan could not prove; replaced by
  prose). Then `composer check` → exit 0: spec-check `OK: 7 spec(s), 7
  test file(s)`, Pint passed, PHPStan `No errors`, Deptrac 0, Pest
  **106 passed (528 assertions)**.

## What the sixteen blobs and the RFC vectors now prove

The decoder reproduces every recorded value of the four stores — claims,
actions, hash-data assertions and COSE_Sign1 signatures from two writers
— and every supported row of RFC 8949 Appendix A; it refuses every
indefinite-length row of Appendix A, every float row, the two 2⁶⁴ rows,
and every truncation and syntax example of Appendix F that the spec
lists, each with the offset the test expects. Nothing in `src/` decodes
a float or an indefinite length; if a real file ever needs one, that is
an amendment with a fixture, not a silent extension.

## Reasoned, not measured

- The `"1"`-vs-`1` key collision check: no fixture; PHP's array
  semantics make it a silent merge, which is exactly the kind of quiet
  wrong value this spec exists to prevent.
- The 32-byte cap on the hex shown for an invalid text string: a message,
  not a limit on the input.
