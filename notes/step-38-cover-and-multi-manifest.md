# Step 38 — Two rules from the official test files: an exclusion *covers* the store, and a store with more than one manifest is refused until M7

*2026-09-21.* The two decisions step 36 and 37 put to Maurice, done as
two red-then-green changes, and the official corpus of 26 files turned
into a second drift alarm.

## 38a — cover, not equal (SPEC-012 amendment 5)

SPEC-012 AC3 required the data hash's exclusion to *equal* the store's
file range. Truepic's writer excludes `[0, 206316]` for a store at
`[13617, 192699]` — the JPEG's whole head with it — and c2patool takes
the range as written. The exclusion sits inside the signed claim's
hashed URI: a writer that excludes more hides bytes from its own
binding, a choice the signer vouched for. What a verifier must require
is containment: every piece of the store inside an exclusion. The rule
changed to that; the covering exclusion is the store's, any other is
"additional". Seen red on `truepic-20230212-camera.jpg`, then green;
every step-23 variant still `.mismatch` (part of the store uncovered),
the Truepic file `assertion.dataHash.match` as c2patool's JSON.

## 38b — more than one manifest → `Invalid` until M7 (SPEC-013 amendment 5, AC11)

`E-uri-CIE-sig-CA.jpg` is tampered only in its ingredient manifest.
This verifier checks the active manifest and was saying `Trusted`
where c2patool says `Invalid` — the direction the brief calls the only
risk that counts. Maurice chose to fail closed: a store with more than
one manifest gets a `general.error` on `self#jumbf=/c2pa` naming the
count and M7, after the checks on the active manifest have run (the
reader still gets everything else), and the state is `Invalid`. Eight
files c2patool calls `Trusted` (`CACA`, `CACAICAICICA`,
`CAIAIIICAICIICAIICICA`, `CAICA`, `CAICAI`, `CICA`, `CICACACA`,
`CIE-sig-CA`) are `Invalid` here for the interim; the test names them
in `SPEC013_PUBLIC_MULTI` so that M7 has to bring them back to stay
green.

## The second drift alarm

`SPEC013_PUBLIC_CORPUS` in `tests/Pest.php`: the 24 official files with
a c2patool JSON. Per file, `state` equals c2patool's — except the ten
named in `SPEC013_PUBLIC_MULTI` (this rule) and the three in
`SPEC013_PUBLIC_NO_TIMESTAMP` (the Truepic leaves lived one day and are
judged at *now* until M6; the test asserts `signingCredential.expired`
is the reason, and M6 removes the name). `A` and `I` are asserted
`hasManifest` false.

After this step, on the files where c2patool gives a state:

| | files | ours vs c2patool |
|---|---|---|
| equal | `C`, `CA`, `CAI`, `CI`, `CII` (`Trusted`); `E-sig-CA`, `E-dat-CA`, `E-uri-CA`, `XCA`, `XCI`, `E-clm-CAICAI`, `E-uri-CIE-sig-CA`, Nikon (`Invalid`) | 13 of 24 |
| stricter on purpose, named | the eight multi-manifest `Trusted` files (M7); the three Truepic `Valid` files (M6) | 11 of 24 |
| we say Valid/Trusted where c2patool says Invalid | — | **0** |

## Measured

`composer check` → Pint passed, PHPStan `[OK] No errors`, Deptrac
`Violations 0`, Pest **`204 passed (2206 assertions)`** (203 + AC11).
One test-side stumble, the third time: Pest's variadic `toContain()`
read a file name as a second needle.

## Next

M6 — the timestamp. It now has four timestamped fixtures (Nikon and
the three Truepics, besides the Adobe file) and eleven named
expectations waiting to change: the Truepic three to `Valid` when
validity is judged at `genTime`, and — after M7 — the eight
multi-manifest files back to `Trusted`.
