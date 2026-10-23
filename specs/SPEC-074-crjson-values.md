# SPEC-074: CBOR values as crJSON values

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-23                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The C2PA Conformance Program's test harness answers in crJSON (step 367).
crJSON writes every claim and every CBOR assertion as JSON. C2PA 2.4
crJSON §3.5.1 and §3.6.3 say how, in Table 1, "Mapping from CBOR to
JSON-LD":

| CBOR | crJSON |
|---|---|
| unsigned and negative integer | number |
| byte string | string, Base64 (RFC 4648), "prefixed by `b64'`" |
| text string | string |
| array | array |
| map | object; keys other than UTF-8 strings "converted to UTF-8 strings, as defined by the implementation" |
| false, true, null | boolean, null |
| half, single, double float | float |
| date-time (tag 0) | string, "direct copy from the text item inside the CBOR tag" |

This verifier already turns decoded CBOR into JSON for its report
(`ManifestStore::plain()`). That rendering is `c2patool`'s *reader* view:
byte strings as bare Base64, every tag as its content, a non-finite
float as the text `NaN`. It is not Table 1, and its contract must not
change. crJSON needs a mapping of its own.

Measured in step 370 and in this step (2026-10-23):

- `c2pa-rs` 0.91.1 (`src/crjson.rs`) does not apply Table 1 generically.
  It renders CBOR through its own JSON conversion and then rewrites the
  fields named `hash`, `pad`, `pad2` and `signature`. Other byte strings
  stay arrays of integers. In `c2patool` 0.28.2's crJSON of the 62
  fixture files: the BMFF exclusions' `data/value`, `cawg.identity`'s
  `pad1`, `c2pa.certificate-status`'s `ocspVals` and a time-stamp
  assertion's tokens. It also adds `"pad": "b64''"` to an object with a
  `hash` and no `pad`, and it rewrites an *array of integers* named
  `hash` into a byte string (old Adobe `c2pa.actions` hold their
  ingredient hash that way: 38 assertions).
- `c2pa-rs` prints a single-precision float by its shortest single
  form (`2.32` in a `stds.exif` of `nikon-20221019-building.jpeg`); PHP
  holds it as a double and prints `2.319999933242798`. Both denote the
  same single-precision value.
- `c2pa-rs` writes **`b64'<base64>'`, with a closing quote**: 1,405 of
  1,405 prefixed strings in that output. The crJSON page writes the
  prefix only: in Table 1, in §3.6.3.1 ("starting with the `b64'`
  prefix") and in its example (`"b64'<base64>"`). The schema has no
  pattern for it.
- The CBOR this verifier decodes from the fixture corpus (556 manifest
  stores, every claim and assertion): 1,688 byte strings, tag 0 only (in
  `c2pa.actions.v2`), no map with an integer key, 49 floats, none of them
  NaN or infinite.

## Scope

**In scope**

- One function from a decoded CBOR value (`CborDecoder`'s output:
  int, string, `CborBytes`, list, map, `CborTag`, bool, null, float) to a
  JSON-encodable PHP value, by Table 1.
- A refusal, by name, for a value Table 1 cannot express. §3.6.1 says an
  assertion nothing can be derived from is written as `{}`; that use is
  the next spec's.

**Out of scope** (each needs its own spec before it may be built)

- The crJSON document: which assertions, the claim, `{}` for a refused
  one, embedded files (thumbnails, icons), JSON assertions, `uuid` boxes.
- The signature object (`certificateInfo`, `timeStampInfo`).
- `c2pa-rs`'s extras: the added `pad`, the `operating_system` rename,
  decoding a CAWG signature. They are not in Table 1.
- The existing report's rendering. `plain()` is not touched.

## Behavior

- **AC1 — a byte string is Base64 between `b64'` and `'`**
  - Given byte strings `h''`, `h'00'`, `h'0102'`, `h'fbff'` and a 32-byte
    SHA-256
  - When mapped
  - Then each is `b64'`, its RFC 4648 §4 Base64 with padding, and a
    closing `'`, as CBOR diagnostic notation writes it (RFC 8610
    Appendix G.2): `b64'AA=='`, `b64'AQI='`, `b64'+/8='`, and `b64''`
    for `h''` (open question 1).

- **AC2 — the JSON-native types pass unchanged**
  - Given text (including non-ASCII and control characters), 0, 23, 24,
    `2^63-1`, -1, `-2^63`, false, true, null, an empty array, an empty
    map, and nested arrays and maps
  - When mapped and `json_encode`d
  - Then each has its own value and type: a CBOR map is a JSON object
    (`{}` even when empty), a CBOR array a JSON array (`[]` even when
    empty), and a map keeps its key order. The values are decoded with
    `keepMaps: true` (SPEC-006 amendment 3), without which an empty map
    cannot be told from an empty array *(amendment 1)*.

- **AC3 — tag 0 is its text**
  - Given `0("2026-10-09T05:31:00Z")` and the `when` field of a fixture's
    `c2pa.actions.v2`
  - When mapped
  - Then the value is the text, unchanged.

- **AC4 — a float stays a float**
  - Given half, single and double floats: `1.0`, `1.5`, `-0.0`,
    `65504.0`, `1.0e-7`
  - When mapped and `json_encode`d
  - Then each is a JSON number with a fraction or exponent (`1.0`, not
    `1`), so that a reader can tell it from an integer.

- **AC5 — an integer map key becomes its decimal string**
  - Given `{1: "a", -2: "b"}`
  - When mapped
  - Then the object is `{"1": "a", "-2": "b"}`, in that order.

- **AC6 — what Table 1 cannot express is refused** *(required: error path)*
  - Given a tag other than 0 (`1(1700000000)`, `32("https://x")`), tag 0
    over something that is not text (`0(1)`), NaN, +∞ and -∞, a PHP
    array that is not a list (a map decoded without `keepMaps`), at the
    top and nested three levels deep. *(Amendment 1: the key collision
    `{1: "a", "1": "b"}` left this list; the decoder refuses it, SPEC-006
    AC13.)*
  - When mapped
  - Then a `CrJsonValueException` is thrown, naming the reason and the
    path to the value (e.g. `actions[0].when`). No partial value is
    returned.

- **AC7 — against `c2patool` on real data**
  - Given the fixture files of step 370 whose store this verifier reads
    (59 of the 62; `c2pa-rs/cloud.jpg` and the two remote-manifest
    writer files have none here), and `c2patool` 0.28.2's crJSON of
    each, stored under `tests/Fixtures/c2patool/crjson/`
  - When every assertion in a `cbor` content box of every manifest is
    mapped, `cawg.identity` excepted (`c2pa-rs` decodes its signature)
  - Then each equals `c2patool`'s value for the same manifest and label,
    after three named normalisations applied to both sides: a byte string
    and an array of integers 0–255 compare as the bytes they hold; an
    added `"pad": "b64''"` that the CBOR does not hold is ignored; floats
    compare at single precision. Any
    other difference fails the test, with the file, the label and the
    path. Measured with a prototype outside the repository: 298 of 298
    equal, 147 of them only after the `pad` normalisation.

## References

- Specification: C2PA 2.4, Content Credentials JSON (crJSON) §3.5.1,
  §3.6.1, §3.6.3 (Table 1), §3.6.3.1; RFC 4648 §4; RFC 8949 §3.4.1
  (tag 0); RFC 8259 §6 (no NaN or infinity in JSON).
- Oracle: `c2patool` 0.28.2 `--crjson` (c2pa-rs 0.91.2) over the 62
  files named in `notes/step-370-crjson-measured.md`, with the
  divergences of AC7 named.
- Measured: `c2pa-rs` 0.91.1 `src/crjson.rs` (`Base64Bytes`,
  `fix_hash_encoding()`); the counts in Problem.
- Reasoned: that a refusal is better than a guess for a tag Table 1 does
  not name, since a crJSON reader would take the content for the value.

## API sketch

```php
// src/Report/CrJsonValue.php — @internal (SPEC-025)
final class CrJsonValue
{
    /** @throws CrJsonValueException naming the reason and the path */
    public static function of(mixed $cbor): mixed;
}

// src/Report/CrJsonValueException.php — @internal
final class CrJsonValueException extends \RuntimeException {}
```

Maps come back as `stdClass` (or `ArrayObject`) so that `json_encode`
writes an empty map as `{}` and an integer-keyed map as an object.
`json_encode` is then called with `JSON_PRESERVE_ZERO_FRACTION`. The
public contract does not change.

## Open questions

1. **The closing quote.** The crJSON page writes `b64'` as a prefix only
   (`b64'AQI=`). `c2pa-rs`, whose crJSON the programme's own tool
   produces, writes `b64'AQI='`. The schema allows both. *Proposal:* the
   page's form, prefix only, and the difference named in
   `docs/comparison.md`. *Against:* if the programme compares whole
   documents rather than codes, the `c2pa-rs` form matches what it
   expects. Blocking: AC1 depends on it.
   *Status 2026-10-23 (step 372):* settled, the other way: **with the
   closing quote**. The prefix comes from CBOR diagnostic notation, where
   a byte string is *"enclosed in single quotes, prefixed by … >b64<"*
   (RFC 8610 Appendix G.2); `c2pa-rs` writes it so; and the programme's
   34 rubric files at `conformance-public` `da71a72` read no byte value
   (no `b64`, no `pad`). The page's shorter form is named in
   `docs/comparison.md` when this spec is built.
2. **A tag other than 0.** *Proposal:* refuse (AC6), so the next spec
   writes `{}` for that assertion. *Alternative:* the content, as the
   report's `plain()` does, which is lossy and silent. No such tag
   occurs in the corpus. Not blocking.
   *Status 2026-10-23 (step 372):* settled as proposed: refused.
3. **Single-precision floats.** *Proposal:* written as the double PHP
   holds (`2.319999933242798`); AC7 compares at single precision. Writing
   `2.32` would need the decoder to keep each float's width, a change to
   SPEC-006's value model. Not blocking.
   *Status 2026-10-23 (step 372):* settled as proposed: the double.

## Amendments

1. **2026-10-23, step 372, decided by Maurice van Loon ("kies 1")** *(confirmed by Maurice van Loon, 2026-10-23: "ja akkoord")* — the input is decoded with `keepMaps: true`. Building AC2 showed that the decoder returns an empty map and an empty array alike (`[]`), and a map keyed 0, 1, 2… as a list, so this mapping could not write `{}` for an empty map. SPEC-006 amendment 3 adds the mode. AC2 now names it; AC6 loses the key collision, which never reaches this mapping because the decoder refuses a text key that reads as an integer (SPEC-006 AC13), and gains a guard: a PHP array with keys that are not a list was decoded without the mode and is refused. AC7 decodes each assertion's `cbor` content box again with the mode.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Cbor/CrJsonValueTest.php :: AC1: a byte string is Base64 between b64' and ' (5 datasets) / SPEC-074 | src/Cbor/CrJsonValue.php :: map() (CborBytes) |
| AC2 | tests/Unit/Cbor/CrJsonValueTest.php :: AC2: the JSON-native types pass unchanged (11 datasets) / SPEC-074 | src/Cbor/CrJsonValue.php :: map() (CborMap as stdClass, lists, scalars); src/Cbor/CborDecoder.php :: `keepMaps` (SPEC-006 amendment 3) |
| AC3 | tests/Unit/Cbor/CrJsonValueTest.php :: AC3: tag 0 is its text; AC3: the when of a real c2pa.actions.v2 is its text / SPEC-074 | src/Cbor/CrJsonValue.php :: map() (CborTag 0) |
| AC4 | tests/Unit/Cbor/CrJsonValueTest.php :: AC4: a float stays a float (5 datasets) / SPEC-074 | src/Cbor/CrJsonValue.php :: map() (finite floats) |
| AC5 | tests/Unit/Cbor/CrJsonValueTest.php :: AC5: an integer map key becomes its decimal string / SPEC-074 | src/Cbor/CrJsonValue.php :: map() (CborMap keys) |
| AC6 | tests/Unit/Cbor/CrJsonValueTest.php :: AC6: what Table 1 cannot express is refused, naming why and where (7 datasets); AC6: a map decoded without keepMaps is refused, not guessed at / SPEC-074 | src/Cbor/CrJsonValue.php :: map(); src/Cbor/CrJsonValueException.php |
| AC7 | tests/Unit/Cbor/CrJsonValueTest.php :: AC7: every CBOR assertion of 59 real files equals c2patool --crjson, under three named normalisations / SPEC-074 | src/Cbor/CrJsonValue.php :: of(); tests/Fixtures/c2patool/crjson/ (59 answers, README) |

`CrJsonValue` lives in `src/Cbor/`, not `src/Report/` as the API sketch
had it: `Report` is a leaf layer, and the mapping needs only CBOR's own
types, so no Deptrac arrow was added.

AC7 also sorts an object's keys on both sides before comparing: JSON
objects are unordered (RFC 8259 §4), and `c2pa-rs` writes ingredient
fields in its own order. That is equality of JSON, not a fourth
normalisation of content. It compared 298 assertions, as measured.

AC3's real file is `writers/openai-20260826-c2pa_2x.png`: the first
choice, `c2pa-rs/CA.jpg`, has no `when` in its actions.

The tests were run red first: all 33 failed on the missing class. Four
mutations were watched failing: no closing quote (6 failed), every tag
read as tag 0 (3), maps as PHP arrays (5), a keyed array let through
(1). `composer check` is green: 1,167 passed, 1 skipped. The divergences
from `c2pa-rs` are in `docs/comparison.md`, "crJSON values".
