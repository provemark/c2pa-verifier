# SPEC-005: JUMBF — the manifest store as a tree of boxes

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

M1 ends with the manifest store as bytes. Everything after it — the claim,
the signature, every assertion, every ingredient — is found by walking a
tree of JUMBF boxes (ISO 19566-5; C2PA 2.4 §11.1 "Use of JUMBF") and
following label paths such as `c2pa.assertions/c2pa.hash.data`. And the
hash binding of M4 hashes an assertion as "its superbox without the
superbox's own header" (§8.4.2.3), so the parser must know every box's
exact byte range, not only its contents.

This spec is that parser. It turns the bytes into value objects —
superboxes, description boxes, content boxes — with offsets and lengths,
and it interprets nothing inside a content box: a `cbor` box yields its
bytes. Decoding them is SPEC-006. `notes/step-09-manifest-store-inside.md`
holds the measurement this spec rests on: the tree of the three M1 stores
(identical in shape), the description-box fields, the `c2sh` salt, the
content types seen, and one foreign store (Adobe, 2022) with a `json`
box and v1 labels.

Where the C2PA text says *shall skip over and ignore* (unknown box type
UUIDs, §11.1.2) this spec keeps the box in the tree as an `UnknownBox`
rather than dropping it — skipping is not forgetting; M4 must still hash
it. Where the text names a box we cannot read (`brob`, compressed
manifests, §11.1.3.2; update manifests `c2um`) the answer is an error:
a verifier that cannot read a manifest must not say anything about it.

## Scope

**In scope**

- The box frame: 4-byte big-endian LBox, 4-byte TBox, contents. LBox 0
  ("to the end") and 1 (64-bit XLBox) are errors; LBox below 8 is an
  error; a box that overruns its parent is an error; a superbox whose
  children do not end exactly on its LBox is an error.
- The superbox `jumb`: its first child is a description box `jumd`, the
  rest are content boxes, superboxes, or unknown boxes, in file order.
- The description box `jumd` (ISO 19566-5 A.3 as C2PA §11.1.4.1 restates
  it): 16-byte type UUID; one toggles byte — bit 0 *Requestable*, bit 1
  *Label Present*, bit 2 *ID Present*, bit 3 *Signature Present*, bit 4
  *Private Present*; then, as announced, a NUL-terminated label, a 4-byte
  id, a 32-byte signature, a private box. Bits 5–7 set are an error. The
  label rules of §11.1.4.1.1: UTF-8; U+0000–U+001F, U+007F–U+009F, `/`,
  `;`, `?`, `#`, U+FEFF, U+FFFF and U+D800–U+DFFF are errors. Within a
  C2PA manifest every description box shall have Label Present and
  Requestable set (§11.1.4.1.2); a box that has not is an error.
- The private box: only `c2sh` is defined (§8.4.2.3), with 16 or 32
  bytes of salt; any other private box type, or another salt length, is
  an error.
- Content boxes `cbor`, `json`, `bfdb`, `bidb`, `uuid` (§11.1.4.3):
  their bytes, offset and length, uninterpreted. `bfdb` must be followed
  by `bidb` in the same superbox.
- The C2PA type UUIDs (§11.1.4.2–4): `c2pa` manifest store, `c2ma`
  manifest, `c2as` assertion store, `c2cl` claim, `c2cs` claim signature;
  the content-type UUIDs for `cbor`, `json`, the embedded file
  (`40cb0c32-bb8a-489d-a70b-2ad6f47f4369`) and `uuid`. The tree carries
  the UUID of every box; the parser requires only the root to be a
  `c2pa` superbox labelled `c2pa`.
- An `UnknownBox` for every box or superbox whose TBox or type UUID this
  spec does not name: type, UUID if it has a description box, label if
  that description parses, offset, length, raw bytes. Its contents are not
  walked.
- Errors for `brob` content boxes and for manifest superboxes of type
  `c2cm` (compressed) or `c2um` (update): "not supported", never a silent
  skip.
- Label-path lookup on a superbox: `child('c2pa.assertions')` → the child
  superbox with that label, or `null`; the caller decides whether absence
  is an error.
- The bytes M4 hashes: `Superbox::payload()`, the superbox's contents
  without its own 8-byte header (§8.4.2.3).
- Limits, checked before memory is spent: depth (default 16, measured 4),
  box count (default 4,096, measured 22), and the 64 MiB the containers
  already enforce.

**Out of scope** (each needs its own spec before it may be built)

- Decoding CBOR (SPEC-006) or JSON; anything about what a claim or an
  assertion means (SPEC-007), including which manifest is active and
  which labels are valid assertion labels.
- Resolving JUMBF URIs (`self#jumbf=…`) across manifests; that needs
  the claim (SPEC-007).
- The `uuid` content box's inner UUID; the JUMBF protection box (ISO
  19566-4); padding boxes (ISO 19566-5 A.4) — an error if seen, with a
  fixture and an amendment if a real file ever carries one.
- Compressed (`brob`, `c2cm`) and update (`c2um`) manifests: an error
  here; their own spec later.
- Reading the store from a file: the Container layer's job. Deptrac keeps
  `Jumbf` a leaf; the parser takes a `string`, and the caller passes
  `ManifestStoreBytes::$bytes`.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-005')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The stores are the ones M1 extracts from `tests/Fixtures/fixture-signed.*`
(the tests extract them with the M1 extractors; the hashes are those of
steps 02/04/06). Every offset below is measured in step 09.

- **AC1 — the PNG store parses to the measured tree**
  - Given the 46,025-byte store of `fixture-signed.png`
  - When the parser runs
  - Then the root is a superbox at offset 0, length 46,025, type UUID
    `63327061-0011-0010-8000-00aa00389b71`, label `c2pa`, toggles 3, with
    one child: a superbox at offset 38, length 45,987, UUID `63326d61-…`
    (`c2ma`), label `urn:c2pa:488bf983-c973-465d-a0eb-1597392cc5d0`, whose
    children are, in order: a superbox at 117 (label `c2pa.assertions`,
    UUID `c2as`, three superbox children), a superbox at 33,026 (label
    `c2pa.claim.v2`, UUID `c2cl`, one `cbor` content box of 591 bytes at
    data offset 33,081), a superbox at 33,672 (label `c2pa.signature`,
    UUID `c2cs`, one `cbor` content box of 12,297 bytes at data offset
    33,728); and the tree holds 8 superboxes, 8 description boxes, 4
    `cbor`, 1 `bfdb`, 1 `bidb`, no unknown boxes, depth 4

- **AC2 — the JPEG and WebP stores have the same shape**
  - Given the stores of `fixture-signed.jpg` (94,740 bytes) and
    `fixture-signed.webp` (100,635 bytes)
  - When the parser runs
  - Then each tree has the same box counts, depth, labels and UUIDs in the
    same order as AC1, and the `bidb` data is 81,079 and 86,972 bytes
    respectively

- **AC3 — description boxes carry their fields**
  - Given the PNG store
  - When the `c2pa.hash.data` assertion superbox (offset 32,831, length
    195) and the claim superbox are inspected
  - Then the first's description has toggles 19, `requestable()` true, a
    16-byte salt `e15885c19cc788b35f6233d5312997b9`, no id, no signature;
    the second's has toggles 3, `requestable()` true, salt `null`
  - And given `tests/Fixtures/jumbf/salt-32.bin` (the same store with that
    salt grown to 32 bytes, every enclosing LBox adjusted; §8.4.2.3 allows
    16 or 32)
  - Then it parses, and the salt is the 32 bytes
    `e15885c1…97b9` followed by sixteen `ab` bytes

- **AC4 — a label path finds a box, and a missing label is `null`**
  - Given the PNG store
  - When `child('c2pa.assertions')` is asked of the manifest superbox and
    `child('c2pa.hash.data')` of the result
  - Then the second call returns the superbox at offset 32,831 whose
    single content box's data begins `a5 6a 65 78`; and
    `child('c2pa.no.such.label')` returns `null` and throws nothing

- **AC5 — `payload()` is exactly what the claim hashes** *(oracle: the
  hashed URIs in the claim, as `c2patool --detailed` prints them)*
  - Given the PNG store
  - When the SHA-256 of `payload()` is taken for the three assertion
    superboxes
  - Then, base64-encoded, they are `Cxd9XpB7zgi4c3qUdT2c4DM6BpUCH16Yyn43iCeZ5LI=`
    (`c2pa.hash.data`, 187 bytes), `ECufvnM+XDu9kkr+8gORHq3qpBNJ/+ehOVOhPzFeTR0=`
    (`c2pa.thumbnail.claim`) and `O1ACO/r/WGO/TG5sR8iEz54Tf1uiS5Vk8rjhNvWGYXA=`
    (`c2pa.actions.v2`) — the values in the claim's `created_assertions`
    and `gathered_assertions`

- **AC6 — a foreign, v1 store parses, `json` box included**
  - Given the 51,118-byte store our JPEG extractor takes from
    `tests/Fixtures/public-testfiles/adobe-20220124-C.jpg`
  - When the parser runs
  - Then the tree has 9 superboxes; the manifest is labelled
    `contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc` with UUID
    `c2ma`; the assertion store holds four superboxes labelled
    `c2pa.thumbnail.claim.jpeg`, `stds.schema-org.CreativeWork` (one `json`
    content box of 111 bytes, salt present), `c2pa.actions`,
    `c2pa.hash.data`; the claim superbox is labelled `c2pa.claim` (no
    `.v2`); no unknown boxes

- **AC7 — an unknown type UUID is kept, not walked, not an error**
  *(C2PA 2.4 §11.1.2: "shall skip over (and ignore) its contents". Oracle:
  `c2patool` → `Error: could not create valid JUMBF for claim` — not for
  the unknown box itself but because the claim references it; that error
  belongs to SPEC-007, where the reference is resolved)*
  - Given the PNG store with the thumbnail assertion's type UUID replaced
    by `ffffffff-ffff-ffff-ffff-ffffffffffff`
  - When the parser runs
  - Then the assertion store's first child is an `UnknownBox` at offset
    166, length 32,470, with that UUID and the label
    `c2pa.thumbnail.claim`; its `bfdb`/`bidb` are not in the tree; the
    other two assertions, the claim and the signature parse as in AC1

- **AC8 — LBox 0, 1, or below 8 is an error** *(required: error /
  malformed input)*
  - Given the PNG store with the claim superbox's LBox set to 0; or to 1;
    or to 7
  - When the parser runs
  - Then, in each case, it throws `JumbfException` naming the offset
    (33,026) and the LBox, and returns no tree

- **AC9 — a box that overruns its parent is an error**
  - Given the PNG store with the `c2pa.hash.data` superbox's LBox 195 →
    205
  - When the parser runs
  - Then it throws `JumbfException` naming the child's offset (32,831),
    where it would end (33,036) and where its parent ends (33,026)

- **AC10 — children that do not end on their parent's LBox are an error**
  *(stricter than the oracle: `c2patool` → `Valid`; it does not check the
  root's LBox against its children)*
  - Given the PNG store with the root's LBox 46,025 → 46,026 (the bytes
    unchanged)
  - When the parser runs
  - Then it throws `JumbfException` naming the parent's offset, where its
    children end (46,025) and where it claims to end (46,026)

- **AC11 — a superbox whose first child is not a description box is an
  error**
  - Given the PNG store with the claim's `jumd` TBox changed to `jumx`
  - When the parser runs
  - Then it throws `JumbfException` naming the superbox's offset (33,026)
    and the type found, shown as text when printable

- **AC12 — description-box faults are errors** *(oracle, per variant in
  `tests/Fixtures/jumbf/README.md`: bit 5 → `c2patool` **`Valid`**, the bit
  is ignored; the 20-byte salt → `Invalid` only because the salt bytes
  changed, its length is not checked; `/` and U+0001 in the label →
  `Invalid`, `claim.multiple`; the rest → errors. Stricter than the oracle
  on the first three, in the safe direction)*
  - Given the PNG store with, separately: the claim's toggles 3 → 35 (bit
    5 set); toggles 3 → 1 (Label Present cleared); the label's NUL
    terminator overwritten so no NUL remains inside the box; a `/` in the
    label; a U+0001 in the label; the hash.data salt grown to 20 bytes
    (every enclosing LBox adjusted); the salt box's TBox `c2sh` → `c2sx`
  - When the parser runs
  - Then, in each case, it throws `JumbfException` naming the description
    box's offset and the fault, with any label bytes shown as hex, never
    raw

- **AC13 — compressed and update manifests are errors, not skips**
  - Given the PNG store with the manifest's UUID `c2ma` → `c2cm`; or →
    `c2um`; or with the claim's `cbor` content box TBox → `brob`
  - When the parser runs
  - Then, in each case, it throws `JumbfException` saying that compressed
    or update manifests are not supported, naming the offset

- **AC14 — `bfdb` without `bidb` is an error**
  - Given the PNG store with the thumbnail's `bidb` TBox changed to `bxdb`
  - When the parser runs
  - Then it throws `JumbfException` naming the `bfdb` box's offset (244)

- **AC15 — the root must be a `c2pa` superbox** *(oracle: a `cbor` root
  and a `c2ma` root are errors for `c2patool` too; the label `c2pb` is
  **`Valid`** for it — stricter here, safe direction)*
  - Given bytes whose first box is a `cbor` content box; and separately the
    PNG store with the root's UUID `c2pa` → `c2ma`; and with the root's
    label `c2pa` → `c2pb`
  - When the parser runs
  - Then, in each case, it throws `JumbfException` naming what was expected
    and what was found

- **AC16 — limits are enforced before memory is spent**
  - Given a synthetic store of 17 nested superboxes (each with a valid
    description box) and a parser with the default depth limit of 16; and
    a parser constructed with a box limit of 10 run on the PNG store
  - When the parser runs
  - Then it throws `JumbfException` naming the limit and the depth or count
    reached, at the box that exceeds it

- **AC17 — the default limits are stated and sufficient**
  - Given a parser constructed with no arguments
  - When it runs on the three M1 stores and the Adobe store
  - Then it succeeds, and its limits are readable as 16 and 4,096

## References

- Specification: C2PA 2.4 §11.1 "Use of JUMBF" — §11.1.2 Processing Rules
  (unknown UUIDs: skip; Requestable + Label Present boxes must be kept),
  §11.1.3.2 Compressed boxes (`brob`), §11.1.4.1.1 Labels, §11.1.4.1.2
  Toggles, §11.1.4.2 Manifest Store (`c2pa`, `c2ma` / `c2cm` / `c2um`, the
  last manifest is the active one), §11.1.4.3 Assertion Store (`c2as`;
  content types `cbor`, `json`, `bfdb` & `bidb`, `uuid`), §11.1.4.4 Claim
  and Claim Signature (`c2cl`, `c2cs`, one `cbor` box each); §8.4.2.3
  Hashing JUMBF Boxes (the payload rule; the `c2sh` salt, 16 or 32
  bytes). Read 2026-09-21 from the published HTML. ISO 19566-5:2023 (the
  JUMBF text itself) was not read (paywalled, CHF 135); the box and
  description-box layout is measured in step 09 and matches C2PA's
  restatement.
- Oracle: the box tree of the three M1 stores and the Adobe store,
  measured in step 09 with a probe (offsets, lengths, UUIDs, toggles,
  labels, salts); the three assertion hashes in the claim of the PNG
  fixture (AC5), printed by `c2patool 0.27.22 --detailed` and reproduced
  by hashing the measured byte ranges; c2patool's behaviour on the
  23 variants of AC3 and AC7–AC16, measured 2026-09-21 by re-embedding
  each variant store in the PNG (CRC recomputed) and running `c2patool`
  (`bin/make-jumbf-variants.php`, `tests/Fixtures/jumbf/README.md`,
  `notes/step-10-jumbf-variants.md`); where c2pa-rs is more lenient —
  the root label, the root LBox, an unknown toggle bit, the salt length
  — the divergence is written next to the criterion.
- Reasoned: toggle bits 2 and 3 (id, signature) from the C2PA text, not
  seen in any store; the default limits; that a padding box (ISO
  19566-5 A.4) never occurs in a C2PA store from a known writer.

## API sketch

```php
// namespace Provemark\C2paVerifier\Jumbf;

declare(strict_types=1);

final class JumbfException extends \RuntimeException {}

final readonly class JumbfParser
{
    public const DEFAULT_MAX_DEPTH = 16;
    public const DEFAULT_MAX_BOXES = 4096;

    public function __construct(
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxBoxes = self::DEFAULT_MAX_BOXES,
    ) {}

    /** @throws JumbfException */
    public function parse(string $bytes): Superbox;   // the c2pa manifest store
}

final readonly class Superbox
{
    public function __construct(
        public int $offset, public int $length,
        public DescriptionBox $description,
        /** @var list<Superbox|ContentBox|UnknownBox> */ public array $children,
    ) {}

    public function child(string $label): ?Superbox;
    /** @return list<Superbox> */ public function superboxes(): array;
    /** @return list<ContentBox> */ public function contentBoxes(): array;
    public function payload(): string;   // contents without the 8-byte header — what §8.4.2.3 hashes
}

final readonly class DescriptionBox
{
    public function __construct(
        public int $offset, public int $length,
        public string $uuid,          // lower-case, hyphenated
        public int $toggles,
        public string $label,
        public ?int $id,
        public ?string $signature,    // 32 bytes or null
        public ?string $salt,         // 16 or 32 bytes or null
    ) {}

    public function requestable(): bool;
}

final readonly class ContentBox
{
    public function __construct(
        public int $offset, public int $length,
        public string $type,          // 'cbor' | 'json' | 'bfdb' | 'bidb' | 'uuid'
        public string $data,
    ) {}
}

final readonly class UnknownBox
{
    public function __construct(
        public int $offset, public int $length,
        public string $type,          // TBox, as hex when not printable
        public ?string $uuid,
        public ?string $label,
        public string $bytes,         // the whole box, header included
    ) {}
}
```

The parser walks recursively with the bytes in memory (the containers
have already bounded them at 64 MiB); every box is validated before its
children are visited, and the depth and box counters are checked before a
child is created. Labels and type bytes go into messages only through the
hex/printable formatters of SPEC-004's `StreamReader` — or a copy of them
here, since `Jumbf` is a leaf layer and may not depend on `Container`
(Open questions).

## Open questions

- **Where the hex formatter lives.** `StreamReader::hex()` is in the
  `Container` layer; `Jumbf` may not depend on it. Proposal: a tiny
  `Bytes` helper in a layer both may use, added by amendment to SPEC-004
  when this spec is implemented. Non-blocker for approval.
- Resolved before approval (step 10, 2026-09-21): the 23 variants are
  built by `bin/make-jumbf-variants.php` and measured; the oracle column
  of `tests/Fixtures/jumbf/README.md` is filled.
- **Whether `UnknownBox` should also wrap unknown *content* box types
  inside a known superbox** (a `zzzz` box next to a `cbor` in a claim).
  Proposal: yes for assertion superboxes (§11.1.4.3 allows any JUMBF
  content type), error for claim and signature superboxes (§11.1.4.4 says
  "a single CBOR content type box"). Non-blocker.

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
| AC16                 | —                           | —                    |
| AC17                 | —                           | —                    |
