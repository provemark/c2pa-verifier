# Step 32 — `ChainCheck`: the first `Trusted`, ten tests red → green

*2026-09-21.* SPEC-014 implemented. The verifier can now say what
c2patool says with a trust file: `Trusted`. Not by the name on the
certificate — by a chain of signatures from the leaf to an anchor the
operator supplied, or by the leaf's own hash on the operator's allowed
list.

## What was built

`src/Trust/`, the layer reserved since M0, now four files:

- `TrustSettings` — the shared format, read whole or not at all.
  `fromJson()` → `fromArray()`: unknown keys at either level, a
  non-boolean `verify_trust`, a value that is not a string, a PEM block
  that is not a `CERTIFICATE` (a private key is refused by its BEGIN
  line, before its body is looked at, and the message names only the
  kind), invalid base64, more than `maxCertificates`, a certificate
  OpenSSL cannot read — every one a `TrustException` naming the field.
  `ekusFromConfig()` reads `store.cfg`'s shape (one OID per line, `//`
  comments) for SPEC-015.
- `Certificate` — DER in, and out: `sha256`, `subject` and `issuer` as
  OpenSSL renders them (keys typed to strings), `isCa`, `signedBy()` on
  `openssl_x509_verify() === 1`, `sameAs()` on `hash_equals` of the DER.
  OpenSSL reports a malformed certificate as a warning *and* a false
  return; the warning is silenced with a scoped error handler (the `@`
  operator does not keep it out of a test runner), the return is the
  answer.
- `ChainCheck` — `verify_trust` off → `[]`. The chain from `CoseSign1`
  (a `CoseException` → its own status). The allowed list first. Then
  the walk: at each depth, the current certificate is an anchor
  (`sameAs`) or is signed by one (issuer name equal *and* `signedBy`);
  else the next certificate must carry the issuer's name and verify the
  link. Every way out is a sentence: the chain ends and no anchor
  signs; the chain ends and an anchor *carries the issuer's name* but
  its key did not make the signature — "a name is not a proof"; the
  next certificate has the wrong name; the link does not verify.
  "Depth" is the number of links walked to the anchor: the leaf itself
  0, the leaf signed by an anchor 1.
- `Verifier::verify($stream, ?TrustSettings)` runs it after the
  signature; `checks_performed` gains `trust` only then.

Around it: `ValidationState::Trusted` and the three-state rule in
`fromStatuses()` — `Trusted` = a `signingCredential.trusted` success and
no failure; `Valid` = a success and no failure other than
`signingCredential.untrusted`; `Invalid` otherwise. `validation_status`
is omitted when empty, as every `Trusted` JSON of step 30 showed.
`StatusCode` is at 23.

## Measured

- Before: `10 failed (1 assertion)` — step 31b.
- First run of the implementation: `8 passed, 1 failed, 1 warning`. The
  failure was a wording: the test asked "depth 1" for a leaf signed by
  the anchor and the code said depth 0 — the code now counts links
  walked, as the spec's AC4 reads. The warning was OpenSSL's on the
  truncated certificate of AC7, through an `@` that PHPUnit ignores;
  fixed as above. Six older tests then failed on the two intended
  changes (the enum's new success; the omitted key) and were adjusted,
  each recorded as an amendment in its spec.
- After: `composer check` → Pint passed, PHPStan `[OK] No errors`,
  Deptrac `Violations 0`, Pest **`193 passed (1829 assertions)`**.
- AC8, the second oracle: on all four fixtures `openssl_x509_checkpurpose`
  with the anchors file says what `ChainCheck` says — trusted with both
  roots, and with the RSA root alone untrusted for the three EC
  fixtures and trusted for the Adobe file.
- AC10, the drift alarm with the full settings: all 22 files of the
  corpus — the four fixtures `Trusted`, every other file's state as
  c2patool recorded it without settings.
- AC5, on the certificates themselves: the RSA root's `subject` equals
  the EC root's, array for array; the intermediate's `issuer` equals
  that name; `signedBy($ecRoot)` true, `signedBy($rsaRoot)` false.

## What `Trusted` means now, and what it does not yet

A chain of signatures from the leaf to a supplied anchor, or a listed
leaf. Not yet: that the leaf is a *C2PA signing certificate* — X.509 v3,
valid at the signing time, an allowed algorithm and key, the right EKU
and KU (§14.5). Until SPEC-015 a chain of unsuitable certificates that
reaches an anchor is `Trusted` here; SPEC-015 closes that, and M5 is
done when it does. The verification time is *now* until M6.

## Next

SPEC-015: the certificate profile. The list c2pa-rs's
`certificate_profile.rs` checks, read in step 30, as criteria; the EKU
list as ADR-0003 item 4 decided; `signature_info` in the report;
`signingCredential.invalid` / `.expired`. Variants: a leaf without
`digitalSignature`, an expired leaf, a CA used as a leaf, an EKU outside
the list — each needs a certificate *made* for the test (public, no
key kept), signed by the test intermediate whose key this repository
does not have — so the variants will be self-contained chains with a
throw-away root, measured through c2patool with that root as the anchor.
