# ADR-0003: X.509 chain and profile — written here, on `ext-openssl`; the EKU list as c2pa-rs keeps it

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-09-21                     |
| Decided  | Maurice van Loon               |

## Context

ADR-0001 left one layer open on purpose: "ASN.1 / X.509 / RFC 3161: open
until M5. Probably `phpseclib`, but deciding now would be an unmeasured
claim." M5 is here. It has to turn `Valid` into `Trusted`: the leaf
certificate in the COSE `x5chain` must chain to an anchor the operator
supplied, or be on the operator's allowed list, and must look like a C2PA
signing certificate (C2PA 2.4 §14.4–14.5). The measurement is step 30
(`notes/step-30-trust-measured.md`); what follows rests on it.

What M5 needs from X.509, exactly:

1. **Per-link signature verification** — is certificate *n* signed by the
   key in certificate *n+1*? — up to an anchor, with an intermediate on
   the anchor list allowed to end the walk (c2pa-rs: `PARTIAL_CHAIN`).
2. **Fields and extensions of the leaf**: version, validity, signature
   algorithm, key type / curve / size, EKU, KU, basicConstraints — the
   §14.5 profile as c2pa-rs's `certificate_profile.rs` checks it.
3. **A hash of the leaf's DER** for the allowed list (SHA-256, as c2pa-rs).
4. **Names and the serial** for `signature_info` (issuer = the leaf's O,
   CN, serial in decimal).

What it does *not* need: parsing ASN.1 by hand. Measured on PHP 8.4 with
OpenSSL 3.6.3:

- `openssl_x509_verify($cert, $issuerPublicKey)` answers 1 / 0 / −1 on PEM
  strings, no files: leaf ← intermediate 1, intermediate ← EC root 1,
  intermediate ← RSA root −1. That is item 1.
- `openssl_x509_parse()` and `openssl_pkey_get_details()` give every
  field of item 2 and item 4 (the serial in hex), with one exception: the
  RSA-PSS signature parameters (hash = MGF hash), which OpenSSL enforces
  itself when it verifies the link, so nothing is lost by not reading
  them.
- `hash('sha256', $der)` is item 3.
- `openssl_x509_checkpurpose()` would do the whole path building — but it
  wants **files** for the anchors and intermediates (the settings carry
  PEM strings; shared hosting is the target, temp files are I/O we did
  not ask for), verifies at **now** with no way to set the signing time,
  and its purposes are OpenSSL's, not §14's. Measured true only with the
  intermediate supplied as a file.
- `phpseclib` would parse everything and build chains, at the cost of a
  runtime dependency (`phpseclib/phpseclib` ≈ 1.5 MB, plus
  `paragonie/constant_time_encoding`, `paragonie/random_compat`) on hosts
  that want none, for functions `ext-openssl` — required since M0 —
  already provides.

The other decision M5 forces is the **EKU list**. §14.4.1 says a validator
"shall maintain … a list of accepted Extended Key Usage values"; the
settings format shared with the sister library carries it as
`trust.trust_config` (the contents of `store.cfg`). Measured in step 30:
c2patool 0.27.22 with a `trust_config` of documentSigning *only* still
says `Trusted` for a leaf with emailProtection, and c2pa-rs's source
(`certificate_trust_policy.rs:496–520`, `valid_eku_oids.cfg`) shows why —
emailProtection, timeStamping and ocspSigning are allowed unconditionally,
the built-in file adds documentSigning and the two C2PA-specific OIDs,
and `trust_config` can only *add*. Two readings were put to the
maintainer: mirror c2pa-rs (additive), or take `trust_config` as the list
when it is present (stricter, the letter of §14.4.1, and the first
divergence from the oracle on a *configured* file).

## Decision

1. **X.509 is written here, on `ext-openssl`.** A chain walk in own code:
   the leaf's issuer must be the next certificate's subject and the link
   must verify with `openssl_x509_verify`; the walk ends when a
   certificate is byte-equal (DER) to an anchor, or is *signed by* an
   anchor; depth bounded (`maxChain`, from SPEC-008's 16). No temp files,
   no `openssl_x509_checkpurpose`, no `phpseclib`. Validity is checked at
   the signing time when M6 provides a timestamp, else at *now* — the
   same fallback as c2pa-rs, and said so in the report.
2. **The allowed list first**, by SHA-256 of the leaf's DER: a hit is
   `Trusted` without a chain check, as c2pa-rs.
3. **The profile** (§14.5) as c2pa-rs's list, each fault
   `signingCredential.invalid` or `.expired` — the codes §15 has; the
   messages ours.
4. **The EKU list as c2pa-rs keeps it** (decided by Maurice van Loon,
   2026-09-21, "optie a"): the six built-in OIDs, `trust_config` adds and
   never removes. The verdict then equals the oracle's on any settings
   file that exists; the divergence from §14.4.1's letter is written into
   the spec next to the criterion, and a stricter mode is a later spec if
   anyone asks for it.
5. **RFC 3161 stays open until M6.** `TSTInfo` is ASN.1 that `ext-openssl`
   does not expose (`openssl_pkcs7_*` reads PKCS#7 signatures, not the
   timestamp token's content); that milestone measures whether a small
   own DER reader (the four or five types a `TSTInfo` needs) or
   `phpseclib` is the smaller risk, and amends this ADR or writes ADR-0004.

`composer.json` is unchanged: `php`, `ext-openssl`, `ext-mbstring`.

## Alternatives rejected

- **`openssl_x509_checkpurpose()` for the chain.** Path building for free,
  but files, `now`, OpenSSL's purposes — three things the spec would have
  to work around rather than state. Rejected; kept as a *second oracle*
  in the tests, where writing a temp file is fine: on every fixture the
  own walk and OpenSSL's must agree.
- **`phpseclib` for X.509.** The right tool when ASN.1 must be read; here
  nothing must be. A runtime dependency for convenience is what ADR-0001
  declined for CBOR and COSE; the same rule holds. Reconsidered at M6,
  where ASN.1 does have to be read.
- **`trust_config` as the exclusive EKU list.** Stricter and closer to
  §14.4.1's wording, but it makes this verifier say `untrusted` where
  c2patool says `Trusted` for the same file and the same settings — a
  divergence in the safe direction, yet one that every operator with a
  narrow `store.cfg` would meet as a bug report. Rejected for now;
  measured and documented so that it can be revisited.
- **Trust by name** (issuer string against a list). Not verification.
  Ruled out by the brief; listed here only because three of the plugins
  measured in §2 of the brief do exactly this.

## Consequences

- Two specs: **SPEC-014**, the trust settings read (the shared format:
  `trust.trust_anchors`, `trust.allowed_list`, `trust.trust_config`,
  `verify.verify_trust`), the allowed list and the chain walk —
  `signingCredential.trusted` / `.untrusted`, `Trusted` as a
  `ValidationState`; **SPEC-015**, the certificate profile —
  `signingCredential.invalid` / `.expired`. Each measured against
  `tests/Fixtures/c2patool/trusted/` and against
  `openssl_x509_checkpurpose` as the second oracle.
- `ValidationState` gains `Trusted`; SPEC-010's "Valid = no failure"
  becomes "Trusted = a `signingCredential.trusted` success and no
  failure; Valid = no failure otherwise" — c2patool's own nuance measured
  in step 14 (`untrusted` is a failure and the state is still `Valid`)
  needs its rule then.
- The report gains `signature_info` (alg, issuer, common_name,
  cert_serial_number) per manifest; the serial's hex → decimal without
  `gmp` or `bcmath` is a twenty-line function, measured against
  c2patool's string.
- `validation_status` is omitted from `toArray()` when empty, as c2patool
  does — SPEC-013 amendment.
- The verification time is *now* until M6. A file signed with a
  certificate that has since expired is `signingCredential.expired` here
  and, once the timestamp is read, may become `Trusted` — the report
  must say which time it used.
