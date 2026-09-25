# Step 154 — An INTEGER read as a number is bounded, and converted faster (SPEC-016 amendment 5, SPEC-015 amendment 6)

*2026-09-25. The second crash/DoS item of the security review of the
same day. No key needed. Present in 0.1.0 to 0.2.1.*

## The denial of service

`Bytes::hexToDecimal` turns an INTEGER into decimal text: serial
numbers, nonces, versions. It took one hex digit at a time over every
decimal digit so far, which is quadratic in the length:

| INTEGER | time |
|---|---|
| 2,000 octets | 0.54 s |
| 4,000 octets | 2.1 s |
| 8,000 octets | 8.6 s |

The review's 52 KB file kept `bin/c2pa-verify` busy for 35.8 s; both
`c2patool` versions answer `timeStamp.malformed` in 0.01 s. A token may
be 1 MiB and a header may hold eight. A certificate serial goes through
the same function, and it is read before any signature is checked.

## 154a — what real files need, what c2patool accepts

- **Real files.** Every conversion was logged in a scratch worktree:
  over all signed fixtures under every settings file (150,540
  conversions) and the 78 current-writer files of step 141 (1,414). The
  longest INTEGER is **20 octets**, RFC 5280's limit for a serial;
  nonces reach 16.
- **c2patool.** A throw-away hierarchy with leaf serials of 20, 21, 64,
  65 and 200 octets, signed by `c2patool` 0.28.0: both versions say
  `Trusted` for all five. A bound at 20 or 64 would therefore have made
  genuine, if non-conforming, files `Invalid` here.
- **A faster conversion.** Seven hex digits at a time over limbs of
  10^9. It gave the same result on 3,008 comparisons with the old one,
  leading zeros and powers of two included, and is about 60 times
  faster: 1,024 octets in 2.3 ms instead of 145 ms. It is still
  quadratic (8,000 octets: 139 ms), so a bound is still needed.

Maurice chose a bound of 256 octets together with the faster
conversion.

`tests/Unit/Asn1/IntegerBoundTest.php` was red on the bound: a 257-octet
INTEGER was converted, and `serial-257.jpg` was `Trusted`. The parts
that check exact values were green before the change and are there to
hold the new conversion to the old results.

## 154b — built

- `Bytes::hexToDecimal` uses the limb conversion. `Bytes::MAX_DECIMAL_OCTETS`
  is 256, and `Bytes::decimalOctets()` counts magnitude. As a last guard,
  the function throws `LengthException` past the bound.
- `Der::integer()` refuses past the bound with `Asn1Exception`, before
  converting. That becomes `timeStamp.malformed` or
  `signingCredential.ocsp.skipped`.
- `Certificate` refuses past the bound with `TrustException`, which
  becomes `signingCredential.invalid`. The profile check and the chain
  walk each read the leaf, so that status appears twice, as for any
  unreadable certificate.
- `docs/comparison.md`: the 257-octet serial is a row under *Where
  `c2patool` can do more*. It is a resource bound, not a protection of
  the verdict (ADR-0005).

Measured:

- The review's file: 0.058 s, `Invalid`, `timeStamp.malformed`
  *"INTEGER at offset 23 has 16384 octets; at most 256 are read as a
  number"*.
- At the bound: 1,000 INTEGERs of 256 octets in 0.16 s, and 1,000
  certificates with such a serial in 0.22 s. The DER reader's element
  limit keeps a token to about 1,000 certificates.
- `composer check`: exit 0, 519 passed.
- 19,686 runs over every signed fixture and settings file, before and
  after: 51 moved, all `serial-257.jpg`, to `Invalid`.
- `php bin/fuzz.php 20260925 60`: 0 faults; the same 34 suspects as in
  step 153.

**Weight B**: a denial of service becomes a status. The one verdict
that changes is a stated resource bound.

## Disclosure

Local until the review's other findings are fixed; released together as
the security release.
