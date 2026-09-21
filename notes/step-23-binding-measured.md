# Step 23 — The hash binding measured, before M4 is specified

*2026-09-21.* M3 ends with a signature that verifies: the claim is what
the signer signed. It says nothing yet about the *asset* — a signed claim
can sit in a file whose pixels were changed afterwards. M4 closes that
gap with two checks, and this step measures both against the four
fixtures and thirteen variants before a spec says how they are done. No
verifier code, no spec.

## The two checks (C2PA 2.4, read 2026-09-21)

1. **Hashed URIs** (§15.10.3). Every entry of the claim's
   `created_assertions` / `gathered_assertions` (v1: `assertions`) is a
   `hashed_uri`: `{url, hash, ?alg}`. The validator resolves the url,
   hashes the assertion box per §8.4.2.3 and compares: `assertion.hashedURI.match`
   or `.mismatch`. An assertion in the store that no entry names is
   `assertion.undeclared`; an entry that resolves to nothing is
   `assertion.missing`. The algorithm comes from the URI's `alg`, else the
   claim's `alg`, else `algorithm.unsupported` (§15.4.2).
2. **The data hash** (§15.12.1, §18.5). `c2pa.hash.data` is
   `{exclusions: [{start, length}], name, alg, hash, pad}`. The hash runs
   over every byte of the asset except the exclusion ranges. Overlapping
   or negative ranges: `assertion.dataHash.malformed`; a range past the
   end of the file, or no `hash`: `assertion.dataHash.mismatch`; an
   algorithm outside §13.1's list (sha256/384/512): `algorithm.unsupported`;
   a mismatch: `assertion.dataHash.mismatch`, a match `.match`. The
   exclusion that holds the store may contain "only the C2PA Manifest
   Store and any appropriate padding"; anything else is `.mismatch`.
   Ranges beyond the store's earn the informational
   `assertion.dataHash.additionalExclusionsPresent`. Exactly one hard
   binding per standard manifest: none is `claim.hardBindings.missing`
   (§15.10.1.2).

## Measured: the four fixtures, with a throw-away probe

A probe on top of M2's classes (`Manifest::resolve()`, `Superbox::payload()`,
the decoded assertion) and PHP's `hash_init`/`hash_update` — the file read
in 64 KiB chunks, the exclusions skipped with `fseek`, never the whole
file in memory:

| fixture | hashed URIs | `alg` | exclusions | `pad` | streaming hash | non-store bytes in the exclusion |
|---|---|---|---|---|---|---|
| `fixture-signed.jpg` (96,939 B) | 3 × MATCH | `sha256` from the claim (none in the URIs) | `[{20, 94772}]` | 7 × `00` | MATCH over 2,167 bytes | 32 |
| `fixture-signed.png` (47,736 B) | 3 × MATCH | idem | `[{33, 46037}]` | 8 × `00` | MATCH over 1,699 | 12 |
| `fixture-signed.webp` (100,956 B) | 3 × MATCH | idem | `[{312, 100643}]` | 6 × `00` | MATCH over 313 | 8 |
| `adobe-20220124-C.jpg` (140,297 B) | 4 × MATCH (claim v1) | idem | `[{20, 51130}]` | 9 × `00` | MATCH over 89,167 | 12 |

No undeclared assertions in any of the four; `name` is `jumbf manifest`
everywhere. Two things worth knowing for the spec:

- **The exclusion is the store plus the format's framing**, one range
  per file even when the store is split: JPEG 32 bytes = two APP11
  segments × (marker 2 + length 2 + piece header 12); PNG 12 = chunk
  length + type + CRC; WebP 8 = the RIFF chunk header. The **WebP pad
  byte** (odd chunk size) is *not* in the exclusion: the range ends one
  byte before the file's end and that byte is hashed. Whether the bytes
  inside the exclusion are "only the store and padding" (§15.12.1) is
  therefore a per-format question: for these three, the non-store bytes
  are exactly the framing the M1 extractors already read.
- **The algorithm is nowhere in the assertion's own hashed URI** — every
  fixture relies on the claim-level `alg`, so §15.4.2's fallback is the
  normal path, not the exception.

## Measured: thirteen variants through c2patool 0.27.22

`bin/make-binding-variants.php` → `tests/Fixtures/binding/` (the README
there has the table with every code). The pattern:

- **The asset changed, the store untouched** — a flipped IDAT bit, a
  flipped scan byte, 16 bytes appended, a 16-byte chunk inserted before
  the store: every one `Invalid` with `assertion.dataHash.mismatch` and
  nothing else wrong. That is M4's "done when", both halves: one changed
  pixel byte → mismatch; the untouched file matches. The inserted-chunk
  case shows c2patool takes the exclusion *literally* from the assertion;
  it does not re-find the store in the container.
- **The assertion changed** — overlap, past-end, shifted, `sha1`,
  non-zero pad: always `assertion.hashedURI.mismatch` first (the claim
  still holds the old hash of the assertion), and c2patool *keeps going*
  and evaluates the data hash anyway. That yields `dataHash.mismatch` for
  overlap/past-end/shifted/sha1 and `dataHash.match` for the non-zero
  pad, which confirms that `pad` is filler outside the hash. Two
  divergences from the letter of §15.12.1: an overlap is reported as
  `.mismatch` (plus the informational `additionalExclusionsPresent`),
  not `.malformed`; an unknown `alg` as `.mismatch` ("type is
  unsupported"), not `algorithm.unsupported`.
- **Two cases where c2patool has no report at all**: `hash-missing`
  (`Error: could not decode assertion … missing field 'hash'`) and
  `assertion-undeclared` (`Error: assertion missing: url = c2pa.extraz.v2x`
  — the undeclared assertion is reported as *missing*, upside down). A
  hard error is fail-closed, so not wrong; but a verifier that returns a
  report with `assertion.dataHash.mismatch` / `assertion.undeclared`, the
  codes the specification names, is what the sister library can show.
- **`hashed-uri-changed`**: the claim's own bytes changed, so
  `claimSignature.mismatch` comes first, then `assertion.hashedURI.mismatch`
  on `c2pa.hash.data`, and the data hash still matches (the asset is
  untouched).

One variant was corrected by its own measurement. `exclusion-shifted`
first moved `start` from 33 to 34 and c2patool said `dataHash.match`. Not
a bug: the byte that enters the hash (offset 33, `00`, the high byte of
the caBX length) and the byte that leaves it (46,070, `00`, the high byte
of the next chunk's length) are the same byte value, so the hashed
sequence is identical. The committed variant shifts to 32, onto the IHDR
CRC. A test that "passes" for the wrong reason is the kind of thing this
project measures before it trusts.

## What this settles for the specs

- **SPEC-011, hashed URIs**: per declared entry resolve → hash
  `payload()` with the URI's `alg`, else the claim's, else
  `algorithm.unsupported` → `assertion.hashedURI.match`/`.mismatch` with
  the assertion's absolute URI (the url c2patool records); `assertion.missing`
  when the url resolves to nothing; `assertion.undeclared` for a box in
  the assertion store no entry names. Fail closed on every one.
- **SPEC-012, data hash**: exactly one `c2pa.hash.data` (else
  `claim.hardBindings.missing` / `assertion.multipleHardBindings`); the
  assertion's shape checked (`hash` present, `exclusions` a list of
  `{start ≥ 0, length ≥ 0}`, sorted and non-overlapping, else `.malformed`);
  `alg` from the assertion, else the claim, on the §13.1 list; ranges
  within the file; the hash streamed with the ranges skipped; the
  exclusion that holds the store checked to be the store plus the
  format's framing (measured above per format) — everything else
  `.mismatch`. Where c2patool is looser (`.mismatch` for an overlap, a
  report where the spec says `.malformed`) the spec's code is the stricter
  and more precise one; both are failures, so the verdict cannot be
  wrong in the dangerous direction either way.
- `StatusCode` grows by the codes above through those two specs.

## Reasoned, not measured

The §15.12.1 rule about update manifests (adjusting the store exclusion's
length) is out of scope until M7; the "only the store and padding"
check for formats beyond JPEG/PNG/WebP waits for those formats' specs.
