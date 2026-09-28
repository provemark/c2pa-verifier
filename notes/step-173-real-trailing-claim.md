# Step 173 — A real writer's claim with trailing bytes

*2026-09-28. A regression fixture under SPEC-013 AC7; no change to `src/`.*

## Where the file comes from

The signing service of `provemark/content-credentials` tried the bump to
`@contentauth/c2pa-node` 0.9.8, which carries c2pa-rs 0.91.0. Every
signature it made was unreadable. The trigger is `specVersion` in
`claim_generator_info`, where C2PA 2.4 places it. c2pa-rs 0.91.0 then
writes a claim map with one entry more than its header declares. That is
upstream c2pa-rs #2731, still open on this date. The service signs with
HTTP 200, and no reader opens the result: not c2patool 0.27.22, and not
c2pa-rs 0.91.0 itself. The bump was refused there; the file is kept here.

`tests/Fixtures/cbor/claim-trailing-bytes-c2pa-rs-0.91.0.png` is that
output, unchanged: the unsigned PNG fixture, the c2pa-rs `sample/` ES256
test certificate, the sync path, no timestamp. Provenance, the verdict of
each reader and the SHA-256 are in `tests/Fixtures/cbor/README.md`.

## Why it is worth a test

SPEC-006 already refuses trailing bytes, and a synthetic vector already
tests the decoder. What this adds is a file a released writer produced,
end to end through the front door. And the red run showed why it matters.

## Seen red

The check in `CborDecoder::decode()` (`$offset !== strlen($bytes)`) was
disabled for one run. The new test then failed. The verifier reported
`claimSignature.insideValidity`, `claimSignature.validated`,
`signingCredential.untrusted`, `signingCredential.ocsp.skipped`, three
`assertion.hashedURI.match` and `assertion.dataHash.match`. That is a
`Valid` verdict, because the signature and the hashes cover the bytes the
writer actually wrote. **That one check is the only thing between this
file and a wrong `Valid`.** With the check restored, the test passes:
`Invalid`, `claim.cbor.invalid`, "18 byte(s) remain after the value, which
ended at offset 584".

Measured: `vendor/bin/pest --filter="trailing bytes is claim"` with the
check disabled (1 failed) and restored (1 passed); `composer check`.
