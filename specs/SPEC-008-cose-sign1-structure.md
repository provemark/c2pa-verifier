# SPEC-008: COSE_Sign1 — the structure, the headers, and the bytes that were signed

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-21                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A manifest's signature box holds a `COSE_Sign1_Tagged` (RFC 8152 §4.2;
C2PA 2.4 §13.2). Everything M3, M5 and M6 need comes out of it: the
algorithm and the bytes that were signed (M3), the certificate chain
(M5), the timestamp token (M6). This spec reads that structure and
nothing more — no bit of cryptography — so that a structural fault has
its own name, separate from "the signature does not verify". c2patool
does not separate them: a wrong `alg` gives it `claimSignature.mismatch`
(step 16); this verifier says what is wrong.

The bytes that were signed are not the box: they are the `Sig_structure`
built in memory (§13.2.3, §13.2.6) — `["Signature1", the protected
header's bytes as stored, an empty external_aad, the claim box's
contents]`. Getting one byte of it wrong is silent: a mismatch, or, if a
test shares the mistake, a `Valid` over the wrong bytes. So its encoding
is the one place this verifier *writes* CBOR, defined here as a single
function, and the vectors measured in step 16 — 1,895 bytes for the PNG,
SHA-256 `065a22da…` — are criteria.

`notes/step-16-cose-signature.md` holds the measurement this spec rests
on: the four fixtures' structures, the header placement two writers use,
and three broken signatures through c2patool.

## Scope

**In scope**

- `CoseSign1::fromBytes(string $signatureBytes)`: SPEC-006 decodes it;
  it must be `CborTag` 18 over a list of exactly four items: protected
  header as `CborBytes`, unprotected header as a map, payload `null`,
  signature as `CborBytes`. Anything else is an error naming what was
  found. A payload that is a byte string — empty or not — is an error:
  §13.2.3 says detached is `nil` and "a byte array of length zero cannot
  be used to indicate detached content".
- The protected header: its bytes kept as stored (`protectedBytes`), and
  decoded (SPEC-006) as a map (`protected`); an empty byte string is an
  empty map (RFC 8152 §3). Not a map → error.
- `alg`: the protected map's value under the integer key 1, an `int`;
  missing, under the string key `"alg"`, or not an int → error (§13.2.3:
  "the literal string alg is never used"). Which ints are supported is
  SPEC-009's; this spec carries the value.
- `x5chain`: looked up in this order — protected 33, protected
  `"x5chain"`, unprotected 33, unprotected `"x5chain"`; when both labels
  are present in one bucket, 33 wins (§14.5). Its value must be a
  non-empty list of non-empty `CborBytes`; the first is the leaf and must
  parse as an X.509 certificate (`openssl_x509_read`), else an error.
  Absent everywhere → error: without a chain there is no key to verify
  against and nothing for M5. An unprotected chain is **accepted**, as
  c2patool accepts the 2022 corpus: the signature is verified *against*
  that chain, so a swapped chain fails the signature; RFC 9360's
  integrity requirement is met by the check, not by the bucket. The
  bucket is recorded (`chainProtected: bool`) for M5's report.
- The timestamp: `sigTst` or `sigTst2` in the unprotected header, kept
  as the decoded value for M6, not interpreted. `pad` (a zero-filled byte
  string, §10.3.2.5) and every other header key: kept in `otherHeaders`,
  never refused.
- `sigStructure(string $claimBytes): string` — the CBOR array
  `["Signature1", protectedBytes, h'', claimBytes]` with definite,
  shortest-form lengths (RFC 8949 §4.2.1): the only CBOR this verifier
  encodes, as one function.
- Limits, checked before allocation: at most 16 certificates in the chain
  and 16 KiB per certificate (measured: 3 and 1,716 bytes); the
  protected header at most 64 KiB.

**Out of scope** (each needs its own spec before it may be built)

- Verifying the signature; deciding which `alg` values are supported;
  checking that the key fits the algorithm (SPEC-009).
- Any verdict or status code (SPEC-010): this spec throws `CoseException`.
- Building or validating the chain beyond "the leaf parses" (M5);
  reading the timestamp token (M6).
- `COSE_Sign` (multiple signers), counter-signatures, `crit` headers, the
  `x5t`/`x5u`/`x5bag` parameters: an error if `alg` or `x5chain` cannot
  be found as above; their own spec if a real file ever needs them.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-008')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The signature bytes are `Manifest::signatureBytes()` of the four
fixtures (SPEC-007); the numbers are step 16's.

- **AC1 — the PNG signature parses to its four parts**
  - Given the PNG manifest's `signatureBytes()` (12,297 bytes)
  - When `CoseSign1::fromBytes()` runs
  - Then `protectedBytes` is 1,285 bytes and begins `a2 01 26 18 21`,
    `protected` has exactly the keys 1 and 33, `alg` is −7, the payload
    is detached, `signature` is 64 bytes, `unprotected` has exactly the key
    `pad` (10,932 zero bytes), `timestamp` is `null`, and `otherHeaders`
    holds `pad`

- **AC2 — the chain comes from the protected header, leaf first**
  - Given the PNG signature
  - When the chain is read
  - Then `chain` is a list of 2 `CborBytes` of 651 and 622 bytes,
    `chainProtected` is `true`, and `openssl_x509_parse` of the first
    gives subject CN `C2PA Signer`, of the second CN `Intermediate CA`

- **AC3 — the JPEG and WebP signatures have the same shape**
  - Given the JPEG and WebP signatures
  - When parsed
  - Then `alg`, the protected keys, the chain lengths, the unprotected
    keys and the signature length equal the PNG's

- **AC4 — a 2022 signature: PS256, the chain unprotected under the string label, a timestamp**
  - Given the Adobe manifest's `signatureBytes()` (18,048 bytes)
  - When parsed
  - Then `protectedBytes` is 4 bytes (`a1 01 38 24`), `alg` is −37, the
    chain has 3 certificates (1,716, 1,685, 1,663 bytes) found under the
    unprotected `"x5chain"` with `chainProtected` `false`, `timestamp` is
    the decoded `sigTst` map whose `tstTokens[0]['val']` is a
    `CborBytes` of 5,951 bytes, and `otherHeaders` holds `x5chain`,
    `sigTst` and `pad` (6,457 bytes)

- **AC5 — the Sig_structure is byte-exact** *(oracle: the vectors of step
  16, and — in SPEC-009 — the signatures themselves)*
  - Given each of the four parsed signatures and its manifest's
    `claimBytes()`
  - When `sigStructure()` is built
  - Then the PNG's is 1,895 bytes, begins
    `84 6a 5369676e617475726531 59 0505 a2 01 26`, and has SHA-256
    `065a22daa4ffad6e5b83a830c4760dbc163842152562593e58cb44c286408504`;
    the JPEG's 1,895 bytes, SHA-256 `c80e74eb…31c165`; the WebP's 1,896
    bytes, SHA-256 `f47dba5f…72e652`; the Adobe's 602 bytes, SHA-256
    `1a33b3e7…07932`; and the structure ends with the claim bytes
    themselves

- **AC6 — the encoder writes shortest-form lengths**
  - Given payloads of 0, 23, 24, 255, 256, 65,535, 65,536 and 70,000
    bytes with a 4-byte protected header
  - When `sigStructure()` is built
  - Then the payload's head is `40`, `57`, `58 18`, `58 ff`, `59 0100`,
    `59 ffff`, `5a 00010000`, `5a 00011170`, and the whole structure
    decodes (SPEC-006) back to the four items

- **AC7 — not a tagged COSE_Sign1 is an error** *(required: error /
  malformed input; oracle: `c2patool` errors on all three, with the
  message `could not generate a trusted time stamp` — its COSE parse
  failure surfaces through the timestamp path)*
  - Given the PNG signature with its tag `d2` (18) → `d3` (19); and
    separately with the tag byte removed (`84 …`); and with the array
    head `84` → `83` (three items)
  - When parsed
  - Then each throws `CoseException` naming what was expected and found
    (tag 18 / a tag / four items)

- **AC8 — a present payload is an error** *(stricter than the oracle:
  `c2patool` → **`Valid`**, `claimSignature.validated` — it ignores the
  payload field; C2PA 2.4 §13.2.3 forbids an empty byte string as
  "detached")*
  - Given the PNG signature with the payload `f6` (nil) → `40` (an empty
    byte string)
  - When parsed
  - Then it throws `CoseException` saying the payload must be detached
    (`nil`), citing that an empty byte string does not count

- **AC9 — the protected header must be a map with an integer alg**
  *(oracle: `c2patool` errors on all three — `could not generate a trusted
  time stamp` for the array and the missing alg, `could not find signing
  certificate chain` for the string label)*
  - Given the PNG signature with, separately: the protected map head `a2`
    → `82` (an array); the key `01` → `02` (no alg); the protected bytes
    replaced by the CBOR map `{"alg": -7}` (the string label)
  - When parsed
  - Then each throws `CoseException` naming the fault (not a map; alg
    missing; alg under the string label `"alg"`)

- **AC10 — a missing or malformed chain is an error** *(oracle:
  `c2patool` → `could not find signing certificate chain in COSE
  signature` for the missing and the empty chain, `COSE error parsing
  certificate` for the broken leaf)*
  - Given the PNG signature with, separately: the label `18 21` (33) →
    `18 22` (34) so no chain is present; the chain's first certificate
    with one byte of its DER flipped (`30 82` → `31 82`); the chain
    array emptied (`82` → `80`, the two certificates left as trailing
    items — which SPEC-006 refuses as bytes after the value, so the
    variant is built by replacing the whole protected header)
  - When parsed
  - Then each throws `CoseException` naming the fault (no x5chain in
    either bucket; the leaf is not an X.509 certificate; the chain is
    empty)

- **AC11 — 33 wins over the string label** *(oracle: not observable —
  any change to the protected header breaks the signature, so `c2patool`
  reports `claimSignature.mismatch` whichever chain it picked; the rule is
  §14.5's)*
  - Given a signature whose protected header carries `x5chain` under both
    33 and `"x5chain"`, with different chains (built synthetically from
    the PNG's header: the 33 chain as is, the string-labelled chain the
    same two certificates in reverse order)
  - When parsed
  - Then `chain` is the 33 chain, leaf CN `C2PA Signer`

- **AC12 — limits are enforced before allocation**
  - Given a parser with a chain limit of 2 given the Adobe signature (3
    certificates); and a synthetic protected header whose x5chain holds
    one certificate declared as 20,000 bytes
  - When parsed
  - Then each throws `CoseException` naming the limit

## References

- Specification: RFC 8152 §3 (header buckets; an empty protected bstr is
  an empty map), §3.1 (`alg`, label 1), §4.2 (`COSE_Sign1`), §4.4
  (`Sig_structure`); RFC 9360 (`x5chain`, label 33); C2PA 2.4 §13.2.2
  (detached payload), §13.2.3 (tag 18, `alg` in protected under 1, the
  `Sig_structure` rules, no empty-bstr detached), §13.2.6 (verification
  builds the structure from the stored protected bytes), §14.5 (`x5chain`
  contents and placement; both labels accepted, 33 wins), §10.3.2.5
  (`sigTst`/`sigTst2`, `pad`). Read 2026-09-21 from the published text.
- Oracle: the four fixtures' structures decoded in step 16 with SPEC-006/
  007; the `Sig_structure` vectors measured there (lengths, first bytes,
  SHA-256) and verified against the real signatures with `ext-openssl`;
  c2patool 0.27.22 on the eleven variants of AC7–AC11, measured
  2026-09-21 (step 17; `bin/make-cose-variants.php`,
  `tests/Fixtures/cose/README.md`).
- Reasoned: the limits (16 certificates, 16 KiB each, 64 KiB protected);
  accepting an unprotected chain (the step-16 argument); that `pad` and
  unknown headers are harmless to keep.

## API sketch

```php
// namespace Provemark\C2paVerifier\Cose;

declare(strict_types=1);

final class CoseException extends \RuntimeException {}

final readonly class CoseSign1
{
    public const DEFAULT_MAX_CHAIN = 16;
    public const DEFAULT_MAX_CERTIFICATE_BYTES = 16384;
    public const DEFAULT_MAX_PROTECTED_BYTES = 65536;

    public function __construct(
        public string $protectedBytes,            // as stored: what Sig_structure carries
        /** @var array<int|string, mixed> */ public array $protected,
        /** @var array<int|string, mixed> */ public array $unprotected,
        public string $signature,
        public int $alg,
        /** @var list<CborBytes> */ public array $chain,   // leaf first, DER
        public bool $chainProtected,
        public mixed $timestamp,                   // the sigTst / sigTst2 value, or null (M6)
        /** @var array<int|string, mixed> */ public array $otherHeaders,
    ) {}

    /** @throws CoseException */
    public static function fromBytes(string $bytes, int $maxChain = self::DEFAULT_MAX_CHAIN, …): self;

    /** ["Signature1", protectedBytes, h'', $claimBytes] — the only CBOR this verifier encodes. */
    public function sigStructure(string $claimBytes): string;
}
```

`Cose` sees `Cbor` (SPEC-006 decodes the structure) and `Support`; the
`Manifest` layer hands it `signatureBytes()` and `claimBytes()`, so
`Cose` does not need to see `Manifest` — Deptrac's arrow for `Cose` →
`Manifest` stays unused until SPEC-010 needs it.

## Open questions

- Resolved before approval (step 17, 2026-09-21): the eleven variants of
  AC7–AC11 are built by `bin/make-cose-variants.php` and measured; AC12's
  limit cases are synthetic in the test (a chain limit of 2 on the Adobe
  signature, a declared 20,000-byte certificate).
- Resolved at implementation: bytes in, decoded here; `Cose` sees `Cbor`
  and `Support` only.
- Clarified at implementation (the AC4/AC11 reading): `otherHeaders`
  holds every header of both buckets except the labels 1 and 33 — a
  deprecated `"x5chain"` stays visible there whether it was the chain
  used (AC4) or a duplicate (AC11).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | tests/Unit/Cose/CoseSign1Test.php :: AC1: the PNG signature parses to its four parts / SPEC-008 | src/Cose/CoseSign1.php :: fromBytes() |
| AC2 | tests/Unit/Cose/CoseSign1Test.php :: AC2: the chain comes from the protected header, leaf first / SPEC-008 | src/Cose/CoseSign1.php :: findChain(), chain() |
| AC3 | tests/Unit/Cose/CoseSign1Test.php :: AC3: the JPEG and WebP signatures have the same shape as the PNG signature / SPEC-008 | src/Cose/CoseSign1.php :: fromBytes() |
| AC4 | tests/Unit/Cose/CoseSign1Test.php :: AC4: a 2022 signature: PS256, the chain unprotected under the string label, a timestamp / SPEC-008 | src/Cose/CoseSign1.php :: findChain() (unprotected, deprecated label), fromBytes() (sigTst, otherHeaders) |
| AC5 | tests/Unit/Cose/CoseSign1Test.php :: AC5: the Sig_structure is byte-exact / SPEC-008 | src/Cose/CoseSign1.php :: sigStructure(), head() |
| AC6 | tests/Unit/Cose/CoseSign1Test.php :: AC6: the encoder writes shortest-form lengths and the structure decodes back to four items / SPEC-008 | src/Cose/CoseSign1.php :: head() |
| AC7 | tests/Unit/Cose/CoseSign1Test.php :: AC7: not a tagged COSE_Sign1 is an error / SPEC-008 | src/Cose/CoseSign1.php :: fromBytes() (tag and item checks) |
| AC8 | tests/Unit/Cose/CoseSign1Test.php :: AC8: a present payload is an error / SPEC-008 | src/Cose/CoseSign1.php :: fromBytes() (payload check) |
| AC9 | tests/Unit/Cose/CoseSign1Test.php :: AC9: the protected header must be a map with an integer alg / SPEC-008 | src/Cose/CoseSign1.php :: fromBytes() (protected header and alg checks) |
| AC10 | tests/Unit/Cose/CoseSign1Test.php :: AC10: a missing or malformed chain is an error / SPEC-008 | src/Cose/CoseSign1.php :: findChain(), chain(), isX509() |
| AC11 | tests/Unit/Cose/CoseSign1Test.php :: AC11: 33 wins over the string label / SPEC-008 | src/Cose/CoseSign1.php :: findChain() (label order) |
| AC12 | tests/Unit/Cose/CoseSign1Test.php :: AC12: limits are enforced before allocation / SPEC-008 | src/Cose/CoseSign1.php :: fromBytes() (protected limit), chain() (chain and certificate limits), DEFAULT_MAX_* |
