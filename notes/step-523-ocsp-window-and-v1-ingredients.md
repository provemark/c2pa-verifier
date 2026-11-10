# Step 523 — A stale OCSP `good`, and redacted Claim-v1 ingredients

*2026-11-10.* Step 521 named two upstream changes in `c2pa` 0.91.11 that no
corpus file covers, so their not moving showed nothing:

- CAI-13263 (upstream #3003, from #2921): an OCSP `good` outside its
  validity window no longer proves non-revocation when no trusted
  time-stamp gives a time;
- upstream #3006 and #3021: a redacted ingredient needs a matching
  `claimSignature` when its ingredient assertion is v3, also for a
  Claim-v1 child; v1 and v2 ingredient assertions are accepted without
  that binding.

The question for both: is this verifier more lenient anywhere? Maurice
asked for probes ("akkoord, begin met de probes"), then for this record
("akkoord, documenteer stap 523").

## Measured: a stapled `good` past its `nextUpdate`

A scratch probe, not a fixture: its window lasts one minute. A throw-away
P-256 root, intermediate and signer (keys overwritten and deleted at the
end). `c2patool` 0.28.6 signed `fixture-unsigned.png` with no time-stamp.
`openssl ocsp`, as the intermediate and without certificates
(`-resp_no_certs`), answered `good` about the signer with `-nmin 1`:
*This Update* 08:42:43, *Next Update* 08:43:43 (UTC). The `pad` of the
COSE unprotected header was replaced by `rVals {ocspVals: [good]}` and a
shorter pad of the same total length; that header is not signed, so
nothing was signed again. Judged with the root as anchor:

| | 08:42:43Z, inside the window | 08:45:17Z, after it |
|---|---|---|
| `c2patool` 0.28.6 | `Trusted`, `signingCredential.ocsp.skipped` | `Trusted`, `signingCredential.ocsp.skipped` |
| `c2patool` 0.28.3 | `Trusted`, no OCSP code | `Trusted`, no OCSP code |
| this verifier | `Trusted`, `signingCredential.ocsp.notRevoked` | `Trusted`, `signingCredential.ocsp.skipped`: *"the stapled response was valid from …08:42:43Z to …08:43:43Z and the judged time is …08:45:17Z, so it is not current"* |

The script's own run of the second half, a few seconds later, gave the
same.

## What it means (reasoned)

- This verifier already applies CAI-13263's rule. Without a trusted
  time-stamp the judged time is now, and a `good` counts only inside its
  window (SPEC-030; without `nextUpdate`, until `producedAt` + 24 hours,
  amendment 7). After the window it is `skipped`; the verdict stays
  `Trusted`, as an unchecked revocation does in `c2patool`.
- Neither `c2patool` version uses this staple, inside the window or
  after. The probe cannot show the old upstream fault (a stale `good`
  counted), so it compares nothing on that point. That `c2pa-rs` sets
  aside a response that carries no certificates is a guess, not traced;
  `docs/comparison.md` already records the same silence on the
  `revocation-*` fixtures, made the same way.
- A stale `good` matters only where it keeps a revocation source from
  being asked. SPEC-030 amendment 7 (step 504) closed that, and its AC12
  and AC13 test it on fixed responses.

## Read: redacted Claim-v1 ingredients

`IngredientManifestCheck` takes the claim-signature route when the
ingredient manifest has redactions **and its claim is v2**
(`$manifest->claim->version >= 2`); otherwise the box hash decides. `c2pa`
0.91.11 decides by the **ingredient assertion's** version. The four
cases of a redacted ingredient manifest:

| ingredient assertion | child claim | `c2pa` 0.91.11 | here |
|---|---|---|---|
| v3 | v2 | `claimSignature` must match | the same |
| v3 | v1 | `claimSignature` must match | box hash, which redaction broke: `ingredient.manifest.mismatch` |
| v1 or v2 | v2 | accepted, no binding | `ingredient.claimSignature.missing` |
| v1 or v2 | v1 | accepted, no binding | `ingredient.manifest.mismatch` |

In no case is this verifier more lenient. Where it differs it says
`Invalid` where `c2pa-rs` may say `Valid`: a possible false `Invalid`, not
a false `Valid`. No probe: this project cannot sign a Claim-v1 manifest
(SPEC-018 amendment 1), and no corpus file has a redacted v1 child.

## What changed

- `docs/comparison.md`: a paragraph under the 0.28.6 comparison.
- No code, no tests, no fixtures.

## Next

Nothing here asks for a spec. Open from step 521: whether to follow the
three status-code changes of 0.28.6, and moving the drift alarms to it.
