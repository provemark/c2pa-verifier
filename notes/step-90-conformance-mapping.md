# Step 90 — 111 named obligations, one by one

*2026-09-22.* Step 71 found the conformance suite's real value: not its
verdicts, which disagree with three other implementations on files all
three accept, but its **catalogue** — 237 normative rules of C2PA 2.4
formalised as 150 predicates, each with a title, a severity and a condition.
This step lays every applicable predicate next to what this verifier does.
The result is `docs/conformance.md`.

## First correction: it is 111, not 101

Step 71 counted 101 applicable predicates — 97 cross-cutting plus 4
image-specific. That was before M8. Closing it added the `video_bmff` (8)
and `streaming_bmff` (2) families, so the applicable set is **111**. The
remaining 39 belong to PDF, WAV, fonts, JPEG XL, ZIP collections,
multi-asset, plain text and SVG: containers this verifier does not read.

## The count

| verdict | n | what it means |
|---|---|---|
| yes | 49 | enforced, or satisfied by construction |
| partial | 12 | part of the rule; the table says which part is missing |
| closed | 7 | the feature is refused wholesale, so such a file fails |
| by design | 21 | deliberately not done, recorded in a spec |
| **gap** | 22 | applies, not done, and until now not written down |

## The finding that matters: stapled OCSP

Four of the 22 gaps have one cause. **OCSP responses stapled into the
manifest are not read.**

Revocation has been out of scope since SPEC-014, with a stated reason: an
OCSP query is a network call and there is no network in the verification
path. That reasoning is sound for the *online* predicates, and they are
marked *by design*. It does not cover a response the signer put **inside the
file**, which needs no network at all.

This is not theoretical, and it was measured rather than argued:

```
ocsp.jpg  rVals: ocspVals[0] = 2264 bytes of DER
$ openssl ocsp -respin ocsp.der -resp_text -noverify
  Cert Status:  good
  Produced At:  Aug 11 21:51:18 2025 GMT
  Next Update:  Aug 18 21:51:18 2025 GMT
  Responder:    CN=Adobe Product Services G3 OCSP Responder …
```

`CoseSign1` already parses that header into `$otherHeaders['rVals']` and
nothing reads it. On this file the response says `good`, so no verdict in
this repository is wrong today. A response saying `revoked` would be ignored
just as completely, and this verifier would answer `signingCredential.
trusted` about a certificate whose own manifest carries the evidence
against it.

`PRED-STRU-015` is the same story's quieter half: a validator that skips
OCSP **must say so**. This one says nothing, in a project where
`checksPerformed` exists precisely so that a caller can see what ran.

## The second group: laxer than 2.4, but not about bytes

Eight gaps are assertion rules that a strict validator would reject a file
over while this one accepts it — `reviewRatings` beside a `humanEntry`
`dataSource`, a missing `digitalSourceType` on an action, forbidden labels
in an external reference, `c2pa.alternative-content-representation`'s
internal consistency, `c2pa.session-keys`, an anchor with `notBefore`
gating, a generator icon.

None of them lets a changed byte through. The hash binding, the hashed
URIs, the signature and the chain are all in the `yes` column; what these
rules govern is what a manifest may *say*, and the signer signed every one
of those bytes.

## The third group: stricter, or named differently

Five gaps and several partials make this verifier refuse where the rule
offers a second chance (no multi-asset fallback), or judge at `now` where a
feature it does not read would have supplied an attested time (the
`c2pa.time-stamp` assertion), or report the right failure under a code the
spec spells differently (`assertion.missing` where 2.4 says
`assertion.outsideManifest`; `general.error` where it says
`assertion.cbor.invalid`; `signingCredential.expired` where it says
`claimSignature.outsideValidity`).

Those cost nothing in safety. They are in the table so that a divergence
from c2patool is never a surprise to somebody reading a status list.

## What this step is not

**The table is reasoned, not measured**, except where an entry names a test
or a measurement. It is a reading of 111 rules against this code by the
same hands that wrote the code, and it should be read as that. The suite
was not used as a judge: step 71 measured why it cannot be.

What is measured, and lives elsewhere, is the fixture-by-fixture comparison
against c2patool 0.27.22 (`docs/comparison.md` and the recorded oracles
under `tests/Fixtures/c2patool/`) and the Go verifier as a second reading.

## Nothing in `src/` changed

This is a measurement step. The gaps are named, sorted by consequence, and
left for the maintainer to decide on. The one that deserves a spec of its
own is the stapled OCSP: the DER reader (SPEC-016), the certificate model
(SPEC-015), the chain walk (SPEC-014) and two fixtures are all already
here.
