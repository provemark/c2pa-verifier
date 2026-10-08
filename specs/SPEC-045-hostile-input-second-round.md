# SPEC-045: Hostile input, the second round — JSON, repeated references, string chunks, an empty `bfdb`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-043 promised that hostile input ends in a report or a refusal (exit 2),
within bounded memory and time, and never in a crash. The review of step 157
found four inputs that still break that promise. It looked from the side of
the WordPress plugin, which runs `verify()` on every uploaded image inside
an admin request, typically with `memory_limit` 256M and 30–60 s.

The four were measured again on 2026-09-27 with the review's probes (PHP
8.3; `-d memory_limit` as stated):

1. **A JSON assertion has no bound.** `Manifest::decode()` calls
   `json_decode()` with only a depth limit, and SPEC-043's CBOR item budget
   does not apply to JSON. An unreferenced JSON assertion `[[0],[0],…]` in
   `fixture-signed.png`, at 256M:
   - 1 MB: `Invalid`, peak 72 MB;
   - 3 MB: `Invalid`, but the fatal error came later, while the report was
     converted to JSON (`ManifestStore.php:260`), because the decoded
     value is kept;
   - 4 MB: fatal "Allowed memory size … exhausted" in `json_decode()`
     (`Manifest.php:321`), exit 255.

   The largest JSON content box in the 524 fixture files is 2,031 bytes
   and 171 items (`c2pa-rs/exp-test1.png`), across 100 JSON boxes.
2. **One assertion referenced many times is hashed once per reference.**
   `HashedUriCheck::entry()` runs `hash($alg, $box->payload())` for every
   claim entry. One 8 MB assertion took 6.13 s with 300 references and
   20.85 s with 1,000. Step 157 measured 62.8 s with 3,000. Reasoned from
   the item budget: about 12,000 references fit, which is about four
   minutes. `c2pa-rs` computes each assertion's hash once when it loads the
   claim, and compares that hash for every reference (`claim.rs`,
   `verify_internal`, `ca.hash()`). A duplicate reference there gets its
   own `assertion.hashedURI.match`; it is not refused.
3. **The chunks of an indefinite-length string are free.**
   `CborDecoder::chunks()` never charges the budget, so an empty chunk
   (`0x40`) costs one byte of input and no item. Measured:
   - 14 million empty chunks in the COSE unprotected header, which is not
     signed: 47.05 s, peak 102 MB, `Invalid`. The COSE is decoded from the
     same bytes at seven places per manifest (`Verifier` ×3, `ChainCheck`,
     `CertificateProfileCheck`, `TimestampCheck`, `ClaimSignatureCheck`),
     each with a fresh budget.
   - The same number in one assertion, decoded once: 7.28 s.
4. **An empty `bfdb` box throws `ValueError`.** `Manifest::mediaType()`
   calls `strpos($bfdb, "\0", 1)` before its `strlen($bfdb) < 2` check.
   On an empty string PHP 8 throws `ValueError`. The README says only
   `TrustException` reaches a caller, and the CLI exits 255.

`c2patool` 0.27.22 and 0.28.0 refuse all four probes within a second.
Each probe also carries something else that `c2pa-rs` refuses first: an
assertion the claim does not list (*"assertion missing"*), a store whose
length changed (`assertion.dataHash.mismatch`), or *"invalid embedded file
box"*. So they are no oracle for the verdict. What they show is that the
reference tool stays bounded.

## Scope

**In scope**

1. **JSON is bounded and charged.** A JSON content box larger than
   `MAX_JSON_BYTES` (256 KiB, open question 1) is refused before it is
   decoded, with `assertion.json.invalid` and a message that names the
   limit. A JSON box within the limit is decoded, and its items (every
   key, value, array and object; amendment 1) are charged to the store's
   `CborBudget`, so that JSON and CBOR share the 65,536 items of SPEC-043
   AC1.
2. **An assertion is hashed once per algorithm.** `HashedUriCheck` keeps
   the digest per box and algorithm for the length of one check, and every
   reference compares against it. Duplicate references are not refused.
   Each still gets its own status, as `c2pa-rs` gives it (open question 2).
3. **Every chunk costs an item.** `CborDecoder::chunks()` charges the
   budget once per chunk, so an indefinite-length string of N chunks costs
   N + 1 items. The COSE decode is then bounded by the budget that the
   COSE parser already passes, however often it runs.
4. **An empty `bfdb` is a refusal.** The length check comes before the
   search. An empty or one-byte `bfdb` is the existing *"the embedded-file
   description has no media type"* `ManifestException`.

**Out of scope**

- Decoding the COSE once per manifest instead of seven times (open
  question 3). With item 3 each decode is bounded, so this is a speed-up,
  not a bound.
- The lower findings of step 157: linear scans over tiny container
  segments, DER copies per nesting level, colliding CBOR integer keys,
  duplicate manifest and assertion labels. Each is measured below a few
  seconds, or fails closed, or is capped by the budget. A later spec may
  take them.

## Behavior

The fixtures are made by `bin/make-hostile-input-2-variants.php` from
`fixture-signed.png`, as the probes of step 157 were. Where a probe
needs more than about 1 MB, the test builds it in memory instead of
committing it.

- **AC1 — a JSON assertion is bounded** *(required: malformed input)*
  - Given `fixture-signed.png` with an unreferenced JSON assertion of 4 MB
    (`[[0],[0],…]`); the same with a 200 KiB JSON object of one string;
    and the same with two JSON arrays of 150 KiB of `10,` each
    (amendment 1)
  - When each is verified under `memory_limit` 256M, and the report is
    converted with `toJson()`
  - Then none ends in a fatal error. The 4 MB assertion is refused before
    it is decoded, with `assertion.json.invalid` naming `MAX_JSON_BYTES`.
    The 200 KiB one is decoded, and the file is `Invalid` for the
    undeclared assertion, as today. The two of 150 KiB together exceed the
    store's 65,536 items and are refused with a status naming that limit,
    although neither exceeds it alone.

- **AC2 — one assertion referenced many times is hashed once** *(required: malformed input)*
  - Given `fixture-signed.png` with one 8 MB assertion and a claim that
    lists it 1,000 times (unsigned; the signature fails)
  - When it is verified
  - Then it ends within 2 s (20.85 s before). The report carries one
    hashed-URI status per reference, 1,000 in all, as before.

- **AC3 — the chunks of a string are charged** *(required: malformed input)*
  - Given a CBOR byte string of 70,000 empty chunks, decoded alone; and
    `fixture-signed.png` with 14 million empty chunks in the COSE
    unprotected header
  - When the first is decoded, and the second is verified
  - Then the decode is refused with `CborException` naming the item limit.
    The file is `Invalid` within 2 s (47.05 s before), with a status
    naming the limit. A string of 1,000 chunks still decodes to their
    concatenation.

- **AC4 — an empty `bfdb` is refused, not thrown** *(required: malformed input)*
  - Given `fixture-signed.png` with an embedded-file assertion whose `bfdb`
    box is empty
  - When it is verified, through the API and through the CLI
  - Then no `ValueError` escapes. The report is `Invalid` with the
    existing *"no media type"* refusal, and the CLI exits 1, not 255.

- **AC5 — nothing else moves**
  - Given every media fixture under no settings and under every readable
    settings file, before and after
  - Then no verdict and no status list changes outside the new fixtures.
    The fuzzer run of SPEC-043 still finds 0 faults.

## References

- Specification: RFC 8949 §3.2.3 (indefinite-length strings) and §5.1
  (a decoder's limits are the application's); RFC 8259 §9 (a JSON parser
  may limit size and depth); C2PA 2.4 §11.1.4.3 (the content boxes,
  `json` and `bfdb` among them) and §8.4.2.3 (hashed URIs), as SPEC-005
  and SPEC-011 cite them.
- Oracle: none for the verdict; see Problem. `c2pa` `claim.rs`
  (`verify_internal`, read at `main`, 2026-09-27) for the per-assertion
  hash.
- Measured: the review's probes, rerun on 2026-09-27 (numbers above); the
  JSON boxes of the 524 fixture files.
- Reasoned: the four-minute estimate for 12,000 references.

## API sketch

```php
// Provemark\C2paVerifier\Manifest\Manifest
public const int MAX_JSON_BYTES = 262144;   // open question 1

// CborBudget gains nothing new; Manifest::decode() charges JSON items through take()
// HashedUriCheck: array<string, string> $digests keyed by spl_object_id($box).':'.$alg, per check()
```

## Open questions

1. **The JSON limit** — *answered 2026-09-27 by Maurice van Loon: 256 KiB.*
   The proposal was 256 KiB, about 128 times
   the largest measured (2,031 bytes). At that size `json_decode()` peaks
   near 18 MB, measured by scaling from the 1 MB probe (72 MB), not run.
   1 MiB would peak near 72 MB. The item charge is what bounds the store
   either way.
   *Status 2026-10-08 (step 279):* answered by Maurice van Loon (2026-09-27).
2. **Duplicate references: cache or refuse** *(non-blocking; proposal:
   cache).* Caching keeps every status list as it is, and matches
   `c2pa-rs`. Refusing would be stricter than `c2patool` for no gain in
   safety, which ADR-0005 does not allow.
   *Status 2026-10-08 (step 279):* about a verdict, equal to `c2pa-rs`.
3. **Decoding the COSE once** *(non-blocking; out of scope).* It is worth
   doing on its own, as a refactor with no change in behaviour, after this
   spec.
   *Status 2026-10-08 (step 279):* done in step 255 (`CoseSign1::ofManifest()`).

## Amendments

1. **2026-09-27, step 161a, counted before the tests.** AC1's inputs do
   not measure what AC1 says. `[[0],[0],…]` costs two items per 4 bytes,
   so 200 KiB of it is about 102,400 items, over the store's budget on its
   own, not *"about 51,000"*. Two boxes of 150 KiB of it would each be
   over the budget alone. The inputs are now:
   - within the limit: a 200 KiB JSON object holding one string (two
     items), which is decoded;
   - over the budget together: two JSON arrays of 150 KiB of three-byte
     numbers (`10,`), about 51,200 items each. Each is within the budget
     on its own, and together they exceed it.

   Scope item 1 also says what an item is: every key, value, array and
   object, as a CBOR map's keys are items too (SPEC-043).

   **Weight C:** the evidence changes, not the rule.

   Confirmed by Maurice van Loon, 2026-09-27 (step 162).

2. **2026-09-27, step 161b, found by the whole suite.** AC3's file with
   14 million chunks is a 14 MB `caBX` chunk. After the other tests, the
   suite's process had 49 MB of memory left, so SPEC-013's memory check
   refused the chunk before the COSE was read (*"does not fit this
   host"*). That is `Invalid` and fast, but it is not the limit that AC3
   names. The test now uses 2 million chunks. Measured with the probe of
   step 157 at 2 million: 6.78 s before the change, with the claim
   signature valid, and 0.26 s after, with the item limit named. At 14
   million: 47.05 s before and 0.28 s after. The rule is unchanged.

   **Weight C:** the evidence changes, not the rule.

   Confirmed by Maurice van Loon, 2026-09-27 (step 162).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Verifier/HostileInputSecondRoundTest.php :: AC1: a JSON assertion is bounded / SPEC-045 | src/Manifest/Manifest.php :: MAX_JSON_BYTES, assertionData(), charge() |
| AC2 | tests/Unit/Verifier/HostileInputSecondRoundTest.php :: AC2: one assertion referenced many times is hashed once / SPEC-045 | src/Hash/HashedUriCheck.php :: check(), entry() (the digest per box and algorithm) |
| AC3 | tests/Unit/Verifier/HostileInputSecondRoundTest.php :: AC3: the chunks of a string are charged / SPEC-045 | src/Cbor/CborDecoder.php :: chunks() |
| AC4 | tests/Unit/Verifier/HostileInputSecondRoundTest.php :: AC4: an empty bfdb is refused, not thrown / SPEC-045 | src/Manifest/Manifest.php :: mediaType() |
| AC5 | tests/Unit/Verifier/VerifierTest.php :: AC10–AC13 / SPEC-013 (the drift alarms); bin/fuzz.php; the before/after run of step 161b | the whole verification path |
