# Step 161 — SPEC-045: hostile input, the second round

*2026-09-27. Findings 3–6 of step 157. SPEC-045 approved by Maurice van
Loon this day, with the JSON limit at 256 KiB.*

## 161a — the inputs and the tests seen red

Counting the items before writing the tests showed that AC1's inputs were
wrong (amendment 1). `[[0],[0],…]` costs two items per four bytes, so 200
KiB of it is over the store's budget on its own. The inputs are now a JSON
object of one 200 KiB string, and two arrays of 150 KiB of `10,`, about
51,200 items each.

`bin/make-hostile-input-2-variants.php` writes the three small files to
`tests/Fixtures/hostile-2/`. The larger inputs are built in memory in
`tests/Unit/Verifier/HostileInputSecondRoundTest.php`, the way the review's
probes built them.

`vendor/bin/pest --group=SPEC-045` before the change, run with
`memory_limit=1G` so that the red runs finish: 4 failed.

- AC1: no `assertion.json.invalid` for the 4 MB assertion.
- AC2: 20.59 s, where less than 2 s was expected.
- AC3: `CborException` was not thrown for 70,000 empty chunks.
- AC4: `ValueError`.

## 161b — the change

- **`Manifest`**: a JSON content box over `MAX_JSON_BYTES` (262,144) is
  refused before `json_decode()`, with `assertion.json.invalid` naming the
  limit. A JSON box within the limit is decoded, and `charge()` then takes
  one item of the store's `CborBudget` for every key, value, array and
  object. Going over the budget refuses the store with `general.error`,
  naming the limit of 65,536 items.
- **`HashedUriCheck`**: the digest is kept per box and algorithm for one
  `check()`, and every entry compares against it. Every entry still gets
  its own status.
- **`CborDecoder::chunks()`**: every chunk takes one item. The break does
  not.
- **`Manifest::mediaType()`**: the length is checked before `strpos()`.

In the whole suite, AC3 failed where it had passed alone. With 14 million
chunks the `caBX` chunk is 14 MB. After the other tests only 49 MB was
left, so SPEC-013's memory check refused the file before the COSE was
read, which is correct but is not AC3's limit (amendment 2). The test now
uses 2 million chunks. The probe was measured at that size with and
without the change to `CborDecoder`.

## Measured

| input | before | after |
|---|---|---|
| 4 MB JSON (`p1_json.php 4`, 256M) | fatal, exit 255 | refused, `assertion.json.invalid` |
| 1,000 references to one 8 MB assertion | 20.59 s | 0.08 s, 1,000 statuses |
| 2 million empty chunks in the COSE header (`p7_cose_chunks.php 2`) | 6.78 s, claim signature valid | 0.26 s, `general.error`, the limit named |
| 14 million, the same (`p7_cose_chunks.php 14`) | 47.05 s | 0.28 s |
| empty `bfdb` | `ValueError`, CLI exit 255 | `Invalid`, CLI exit 1 |

- `vendor/bin/pest --group=SPEC-045`: 4 passed. `composer check`: exit 0,
  536 passed.
- **All media fixtures under no settings and every readable settings
  file, 21,546 runs, before and after.** Only the new `hostile-2/`
  fixtures moved: `bfdb-empty.png` from a thrown `ValueError` to `Invalid`
  (54 runs), and `json-numbers-2x150k.png` to a status list naming the
  item limit, still `Invalid` (54 runs). The 100 JSON boxes of the corpus,
  2,031 bytes at most, are all decoded as before.
- `php bin/fuzz.php 20260925 60`: 5,922 runs, 0 faults, the same 34
  suspects as in steps 153–155. The slowest run took 0.04 s and the peak
  memory was 38 MiB.

Not done here (SPEC-045 open question 3): decoding the COSE once per
manifest. With the chunk charge every decode is bounded.

## Disclosure

The fix is local until the release that closes step 157. It goes out
together with steps 158 and 159.
