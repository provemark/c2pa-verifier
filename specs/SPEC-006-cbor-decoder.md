# SPEC-006: CBOR — the measured subset, decoded; the rest refused

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-21                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Everything a manifest *says* — the claim, its assertion references and
hashes, the actions, the hash binding's exclusions, the COSE signature's
headers — is CBOR (RFC 8949) inside the `cbor` content boxes SPEC-005
yields. Without a decoder the box tree is a tree of opaque blobs.

A decoder that misreads a major type or a length form does not fail; it
returns a *different value* — a wrong hash, one assertion fewer, a wrong
exclusion range — and that is the error that runs silently into a `Valid`
that is not true. So this decoder decodes exactly what was measured in
`notes/step-09-manifest-store-inside.md` over sixteen real blobs from two
writers, and refuses everything else with an offset: indefinite lengths,
floats, reserved additional-information values, unknown simple values,
integers PHP cannot hold, duplicate map keys, bytes after the value. C2PA
2.4 requires the claim and every standard assertion to follow RFC 8949
§4.2.1 (Core Deterministic Encoding), which forbids indefinite lengths and
duplicate keys; refusing them is not stricter than the format, it is the
format.

It decodes only. Nothing in this verifier needs a CBOR encoder: the
signature is checked over the bytes as stored, the assertion hashes over
box payloads. Nothing here knows what a claim is (SPEC-007).

## Scope

**In scope**

- One entry point: decode a byte string as exactly one CBOR data item;
  bytes left over are an error.
- Major types 0–7 with additional information 0–27 (RFC 8949 §3.1, §3.2):
  unsigned and negative integers (within PHP's `int`, −2⁶³ … 2⁶³−1;
  beyond is an error, never a float or a wrap-around), byte strings,
  text strings (valid UTF-8, else an error — RFC 8949 §3.1 says a text
  string *shall* be valid UTF-8), arrays, maps, tags, and of major type 7
  only `false`, `true` and `null`.
- The PHP data model: `int`; text as `string`; **bytes as `CborBytes`**
  (a `readonly` value object with `->bytes`) so that a hash and a label
  can never be confused and a later JSON view knows what to base64;
  arrays as PHP lists; maps as PHP arrays whose keys are `int` or
  `string` (any other key type is an error; a duplicate key is an error,
  RFC 8949 §5.6); tags as `CborTag(int $number, mixed $value)`, **every
  tag number passed through** — a tag is annotation, and the layer that
  needs it (tag 18, COSE_Sign1, in M3) decides what it means.
- Errors, each with the byte offset: additional information 28–30
  (reserved), 31 (indefinite length — RFC 8949 §3.2.3, forbidden by
  §4.2.1), the `break` code `0xff` anywhere, floats (major type 7 with
  additional information 25, 26, 27), simple values other than 20–22
  (including `undefined`, 23, and the two-byte form with a value below
  32, §3.3), truncation, trailing bytes.
- Limits, checked before memory is spent: nesting depth (default 32;
  measured 7), the number of items in one array or map (default 65,536),
  and the length of one string (bounded by the input, which the
  containers bound at 64 MiB).

**Out of scope** (each needs its own spec before it may be built)

- Enforcing Core Deterministic Encoding on input — shortest integer
  form, key order. A writer's obligation; c2pa-rs's older versions were
  not strict about it, and refusing a validly signed file for two spare
  length bytes would diverge from c2patool in the direction that hurts
  the user without protecting anyone: the signature covers the bytes as
  they are. Open question: measure whether c2patool enforces it.
- Encoding. Not needed anywhere in the verifier.
- Floats, indefinite lengths, big numbers (tags 2 and 3 are passed
  through as tags over `CborBytes`; interpreting them is nobody's job
  yet). If a real file ever needs floats, that is a fixture and an
  amendment.
- What any decoded value means: SPEC-007 and later.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-006')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The blobs are the `cbor` content boxes SPEC-005 yields from the four
stores (three M1 fixtures and `adobe-20220124-C.jpg`); their expected
values are recorded once, from the step-09 cross-check with
`spomky-labs/cbor-php` 3.4.2, as JSON files under `tests/Fixtures/cbor/`
with byte strings as hex and tags as `{"tag": n, "value": …}` (see Open
questions). RFC 8949 Appendix A and Appendix F are quoted by their hex.

- **AC1 — the PNG claim decodes to its seven keys**
  - Given the 591-byte `cbor` box of the PNG store's `c2pa.claim.v2`
  - When it is decoded
  - Then the result is a PHP array with exactly the keys `instanceID`,
    `claim_generator_info`, `signature`, `created_assertions`,
    `gathered_assertions`, `dc:title`, `alg` in that order;
    `instanceID` is `xmp:iid:abc42c63-7d76-437d-b2bb-b8e473a93dba`;
    `claim_generator_info` is `{name: "c2pa-verifier fixtures", version:
    "0.0.0", "org.contentauth.c2pa_rs": "0.90.22"}`; `created_assertions`
    is a list of one map whose `url` is
    `self#jumbf=c2pa.assertions/c2pa.hash.data` and whose `hash` is a
    `CborBytes` of 32 bytes equal to base64
    `Cxd9XpB7zgi4c3qUdT2c4DM6BpUCH16Yyn43iCeZ5LI=`; `alg` is `sha256`;
    and there is no `claim_version` key

- **AC2 — the sixteen measured blobs decode to their recorded values**
  - Given every `cbor` content box of the four stores (four per store)
  - When each is decoded and rendered in the JSON form of
    `tests/Fixtures/cbor/`
  - Then each equals its recorded file, byte strings and tags included —
    in particular the PNG signature box is `CborTag(18, [CborBytes(1285),
    [map with key "pad" → CborBytes(10932)], null, CborBytes(64)])` and
    the Adobe signature box's unprotected map has the keys `x5chain`,
    `sigTst` and `pad`

- **AC3 — byte strings and text strings are different types**
  - Given the PNG `c2pa.hash.data` box
  - When it is decoded
  - Then `hash` and `pad` are `CborBytes` (32 and 8 bytes), `name` and
    `alg` are PHP strings (`jumbf manifest`, `sha256`), and
    `exclusions[0]` is `{start: 33, length: 46037}` with PHP ints

- **AC4 — RFC 8949 Appendix A, the supported rows, decode as printed**
  - Given the hex of these rows: `00`, `01`, `0a`, `17`, `1818`, `1819`,
    `1864`, `1903e8`, `1a000f4240`, `1b000000e8d4a51000`, `20`, `29`,
    `3863`, `3903e7`, `40`, `4401020304`, `60`, `6161`, `6449455446`,
    `62225c`, `62c3bc`, `63e6b0b4`, `64f0908591`, `80`, `83010203`,
    `8301820203820405`, `98190102…1819` (25 items), `a0`, `a201020304`,
    `a26161016162820203`, `826161a161626163`, `a56161614161626142…`
    (5 keys), `f4`, `f5`, `f6`, `c074323031332d30332d32315432303a30343a30305a`,
    `c11a514b67b0`, `d74401020304`, `d818456449455446`,
    `d82076687474703a2f2f7777772e6578616d706c652e636f6d`,
    `c249010000000000000000`, `c349010000000000000000`
  - When each is decoded
  - Then each yields the value the table prints: the ints, `CborBytes`
    for `h'…'`, strings, lists, maps with int keys `{1: 2, 3: 4}` and
    string keys, `false`/`true`/`null`, and `CborTag` for the tagged rows
    — tag 0 over the text, tag 1 over `1363896240`, tags 23, 24, 32, and
    tags 2 and 3 over the nine-byte `CborBytes` (bignums passed through,
    not interpreted)

- **AC5 — integers beyond PHP's range are an error** *(required: error /
  malformed input)*
  - Given `1bffffffffffffffff` (18446744073709551615) and
    `3bffffffffffffffff` (−18446744073709551616), both well-formed CBOR
  - When each is decoded
  - Then each throws `CborException` naming the offset (0) and that the
    integer does not fit a 64-bit signed integer

- **AC6 — indefinite lengths are an error naming the offset** *(stricter
  than the oracle: c2pa-rs parses `claim-indefinite-array` and fails only
  on the signature; RFC 8949 §4.2.1, which C2PA requires of claims,
  forbids them)*
  - Given the Appendix A rows `5f42010243030405ff`,
    `7f657374726561646d696e67ff`, `9fff`, `9f018202039f0405ffff`,
    `83018202039f0405ff`, `bf61610161629f0203ffff`,
    `bf6346756ef563416d7421ff`
  - When each is decoded
  - Then each throws `CborException` naming the offset of the byte whose
    additional information is 31 (0, 0, 0, 0, 5, 0, 0) and that indefinite
    lengths are not supported
  - And given `tests/Fixtures/cbor/claim-indefinite-array.cbor` (the PNG
    claim with `created_assertions` as `9f … ff`)
  - Then it throws `CborException` naming the offset of the `9f`

- **AC7 — floats are an error naming the offset** *(oracle: c2pa-rs →
  `claim could not be converted from CBOR` on `claim-float`, a type error
  on the field, not a float check)*
  - Given `f90000`, `f93c00`, `fb3ff199999999999a`, `fa47c35000`,
    `f97c00` (Infinity), `f97e00` (NaN), and `c1fb41d452d9ec200000`
    (tag 1 over a float)
  - When each is decoded
  - Then each throws `CborException` naming the float's offset (0 for the
    first six, 1 for the last) and that floats are not supported

- **AC8 — unknown simple values and `undefined` are an error**
  - Given `f7` (undefined), `f0` (simple 16), `f8ff` (simple 255), and
    `f81f` (the two-byte form for a value below 32 — not well-formed,
    Appendix F subkind 2)
  - When each is decoded
  - Then each throws `CborException` naming the offset and the simple
    value

- **AC9 — reserved additional information and a stray break are an error**
  - Given `1c`, `1d`, `1e`, `3c`, `5c`, `7c`, `9c`, `bc`, `dc`, `fc`
    (Appendix F subkind 1) and `ff` alone, and `8300ff02`
  - When each is decoded
  - Then each throws `CborException` naming the offset (0 for all but the
    last, 2 for the last) and the additional information value

- **AC10 — truncation is an error naming where the bytes ran out**
  - Given the Appendix F "end of input" examples `18`, `19`, `1a`, `1b`,
    `1901`, `1a0102`, `1b01020304050607`, `38`, `58`, `78`, `98`, `b8`,
    `d8`, `f8`; the short strings `41`, `61`, `5affffffff00`,
    `7affffffff00`; the unclosed containers `81`, `8200`, `a1`, `a20102`,
    `a100`, `a2000000`; and the bare tag `c0`
  - When each is decoded
  - Then each throws `CborException` naming the offset at which more
    bytes were needed and how many

- **AC11 — bytes after the value are an error**
  - Given `0000` and `a0f6`
  - When each is decoded
  - Then each throws `CborException` naming that 1 byte remains after the
    value, which ended at offset 1

- **AC12 — text strings must be UTF-8**
  - Given `62c328` (two bytes that are not valid UTF-8 under a text-string
    head)
  - When it is decoded
  - Then it throws `CborException` naming the offset and showing the bytes
    as hex, never raw

- **AC13 — map keys are int or string, and unique** *(stricter than the
  oracle on duplicates: c2pa-rs reads `claim-duplicate-key` and fails on
  the missing `alg`, not on the second `dc:title`; RFC 8949 §5.6)*
  - Given `a1400a` (a byte-string key), `a1800a` (an array key),
    `a201020103` (the key 1 twice), `a26161016161 02` (the key `"a"`
    twice)
  - When each is decoded
  - Then each throws `CborException` naming the offset of the offending
    key and the fault
  - And given `tests/Fixtures/cbor/claim-duplicate-key.cbor`
  - Then it throws `CborException` naming `dc:title` as the duplicate

- **AC14 — limits are enforced before memory is spent**
  - Given 33 nested arrays (`81` × 33 then `00`) with the default depth
    limit of 32; and a decoder constructed with an item limit of 10 given
    `98190102…1819` (25 items); and `5b0000000100000000` (a byte string
    claiming 4 GiB, with no bytes following)
  - When each is decoded
  - Then each throws `CborException` at the item that exceeds the limit or
    at the length that cannot be satisfied, without allocating the claimed
    size

- **AC15 — the default limits are stated and sufficient**
  - Given a decoder constructed with no arguments
  - When it decodes the sixteen measured blobs
  - Then it succeeds, and its limits are readable as 32 and 65,536

## References

- Specification: RFC 8949 (STD 94) §3 (the data model), §3.1 (major
  types), §3.2 (lengths; §3.2.3 indefinite), §3.3 (floats and simple
  values), §3.4 (tags), §4.2.1 (Core Deterministic Encoding Requirements),
  §5.6 (duplicate keys); Appendix A (examples) and Appendix F
  (well-formedness errors) for the vectors above — read 2026-09-21 from
  the RFC Editor's text. C2PA 2.4 §10.3 and the assertions clause: the
  claim and every standard assertion "shall comply with the Core
  Deterministic Encoding Requirements of CBOR (RFC 8949, clause 4.2.1)".
- Oracle: the sixteen blobs of the four stores, decoded in step 09 with
  `spomky-labs/cbor-php` 3.4.2 and inventoried over the raw encoding
  (major types 0/2/3/4/5/6/7, additional information ≤ 25, one tag, no
  indefinite lengths, floats or negatives); `c2patool 0.27.22 --detailed`
  for the claim's and assertions' values (AC1, AC3); RFC 8949 Appendix A
  and F for AC4–AC10; `c2patool 0.27.22` on the four claim-level variants of
  `bin/make-cbor-vectors.php`, measured 2026-09-21 (step 12): indefinite
  lengths and duplicate keys are tolerated by c2pa-rs, a float fails as
  a type error, a non-shortest integer parses.
- Reasoned: the default limits; that no writer emits floats or
  indefinite lengths in a C2PA store (two writers measured, the format
  forbids it for claims and standard assertions, custom assertions are
  not bound by it — a float there would make the store `Invalid` here;
  recorded as the price of fail closed).

## API sketch

```php
// namespace Provemark\C2paVerifier\Cbor;

declare(strict_types=1);

final class CborException extends \RuntimeException {}

final readonly class CborBytes
{
    public function __construct(public string $bytes) {}
}

final readonly class CborTag
{
    public function __construct(public int $number, public mixed $value) {}
}

final readonly class CborDecoder
{
    public const DEFAULT_MAX_DEPTH = 32;
    public const DEFAULT_MAX_ITEMS = 65536;

    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {}

    /**
     * Exactly one data item; bytes left over are an error.
     *
     * @return int|string|CborBytes|list<mixed>|array<int|string, mixed>|CborTag|bool|null
     *
     * @throws CborException
     */
    public function decode(string $bytes): mixed;
}
```

A recursive-descent decoder over the string with an offset; the head
(initial byte plus 0–8 bytes) is read and classified before anything is
allocated; a string's declared length is checked against the remaining
input before `substr`; the depth counter is incremented on entering an
array, map or tag and the item counter checked against the declared
count before the loop. Maps are built key by key with a duplicate check.
`Cbor` is a leaf layer (Deptrac) and may use `Support\Bytes` for
messages.

## Open questions

- Resolved before approval (step 12, 2026-09-21): the sixteen recorded
  values are in `tests/Fixtures/cbor/*.json`; `bin/make-cbor-vectors.php`
  builds four claim-level variants and their PNG carriers, all measured
  (`tests/Fixtures/cbor/README.md`). The Appendix A/F vectors are short
  and live in the test file as hex.
- Resolved by measurement (step 12): c2pa-rs does **not** enforce
  deterministic encoding on input — `hashdata-nonshortest-int` parses
  and fails only on the assertion's hash. Not enforcing it here is
  consistent with the oracle.
- **`CborTag` for tags 2/3 (bignums)**: passed through; whether a later
  layer should refuse them is that layer's question. Non-blocker.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
| AC11                 | —                           | —                    |
| AC12                 | —                           | —                    |
| AC13                 | —                           | —                    |
| AC14                 | —                           | —                    |
| AC15                 | —                           | —                    |
