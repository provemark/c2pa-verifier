# Step 37 — Floats decode (SPEC-006 amendment 2), and what the four camera files then say

*2026-09-21.* The first of step 36's two findings. SPEC-006 refused CBOR
floats by design; the C2PA's own Nikon and Truepic test files carry them
in `stds.exif` and `com.truepic.custom.odometry`. Maurice's decision:
decode them. Tests red first, then the decoder.

## What changed

- SPEC-006 AC7 turned around: the RFC 8949 Appendix A float vectors
  (half, single, double; the largest half 65504.0; a subnormal half;
  the three infinities; NaN; tag 1 over a double) decode to PHP floats;
  a float whose bytes are missing is still a `CborException` naming the
  offset. Seen red: `float at offset 0 is not supported`.
- `CborDecoder::float()`: single and double through `unpack('G')` /
  `unpack('E')`; half by hand — 1 sign bit, 5 exponent bits, 10 mantissa
  bits, with subnormals (no implicit leading one, ×2⁻²⁴), ±Infinity
  (exponent 31, mantissa 0) and NaN. Fourteen lines. No verification
  touches a float; every hash is over bytes.
- `composer check` → `203 passed (2102 assertions)`; the four camera
  files parse to a `ManifestStore`.

## Measured: the four camera files through the front door, full settings

| file | ours | c2patool |
|---|---|---|
| `nikon-20221019-building.jpeg` | `Invalid`: `signingCredential.expired` (valid 2022-10-04 → 2023-10-05, checked at now), `.untrusted` (the chain ends at GlobalSign's CA, no anchor) | `Invalid`: the same two codes |
| `truepic-20230212-{camera,landscape,library}.jpg` | `Invalid`: `signingCredential.expired`, `.invalid`, `.untrusted`, `assertion.dataHash.mismatch` | `Valid`: `signingCredential.untrusted` only |

The Nikon file agrees code for code. The three Truepic files do not, for
three reasons, each a finding:

1. **`signingCredential.invalid`: my reading of c2pa-rs was short by two
   lines.** The leaves are signed with `sha384WithRSAEncryption`;
   `certificate_profile.rs:181–188` allows SHA-384 and SHA-512 with RSA
   as well as SHA-256, and step 30 recorded only the first. SPEC-015
   amendment 3, fixed in this step with a test; the Truepic profile is
   clean now.
2. **`signingCredential.expired`: the certificates lived one day.**
   Truepic's Lens SDK issues a leaf per capture, valid for 24 hours, and
   timestamps the signature (`signature_info.time` at c2patool:
   `2023-02-12T18:44:26+00:00`). c2patool judges validity at that time;
   this verifier judges at *now* until M6. Expected, and the reason M6
   is next: the three Truepic files and the Nikon file are the
   timestamped fixtures it needs.
3. **`assertion.dataHash.mismatch`: the exact-range rule met a writer
   that excludes more than the store.** Truepic's `c2pa.hash.data`
   exclusion is `[0, 206316]` — from the first byte of the file to the
   end of the store, which occupies `[13617, 192699]`; everything before
   the store (the JPEG's own headers, EXIF, XMP) is excluded too.
   SPEC-012 AC3 requires the exclusion to *equal* the store's range;
   c2patool takes the range as written. The exclusion is inside the
   signed claim's hashed URI, so a writer that excludes more than the
   store hides bytes from its own binding — a choice the signer made and
   vouched for, not an attack surface for anyone else; the invariant a
   verifier needs is that the store lies *inside* the excluded region
   (otherwise its own bytes would be hashed, which no file can satisfy).
   Proposed as SPEC-012 amendment 5: the exclusion must **cover** the
   store's range, not equal it; every step-23 variant still fails
   (`bytes-inserted-before-store`, `exclusion-shifted` and the gap JPEG
   all leave part of the store uncovered). Maurice decides.

## Next

Step 38: the multi-manifest rule (SPEC-013 amendment 5, decided) and
the drift alarm over the official corpus; the exclusion rule with it if
Maurice agrees. Then M6.
