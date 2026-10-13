# Step 353 — Two more from the literature: SPEC-069 amendment 1 and SPEC-070, drafted

*2026-10-13.*

After SPEC-068 and SPEC-069, Maurice asked whether others had published
similar findings about C2PA, so that they could be taken into the same
fix. A search turned up five sources: Golaszewski, Krawetz, Sherman et al.
(arXiv 2604.24890 / IACR ePrint 2026/804), Krawetz's "C2PA Issues and
Evaluation" (2025-06-09), Buchanan's Pixel post (August 2026), Hacker
Factor's self-signed certificate post (2024), and the Nikon Z6 III case.
Two `c2pa-rs` changes are related as well: PR #2743 and its CVEs. Laid next
to this verifier (read, not measured):

| finding | here |
|---|---|
| self-signed root under a false name | `Trusted` only with a chain to an anchor; else `signingCredential.untrusted` |
| two tokens, or `sigTst` and `sigTst2` | refused (SPEC-016 AC8, SPEC-017 amendment 9) |
| TSA outside the trust list | `timeStamp.untrusted`, its time unused |
| sidecar swap / dual manifest | not exposed: a remote manifest is never fetched |
| thumbnail in an update manifest (`c2pa-rs` PR #2743) | already `manifest.update.invalid` |
| a revoked leaf | only when the file staples OCSP (SPEC-030, SPEC-066); no network |
| a timestamp removed or replaced | not detectable by any validator: it lies outside the signature |
| Pixel root, Nikon multiple exposure | not detectable: the signature is genuine |
| `thumbnail.primaryMismatch` | needs image decoding (GD is an extension); `c2pa-rs` does not check it either |
| **Pixel 10 Pro GPS in an exclusion** | **the range SPEC-069 allows**: SPEC-069 amendment 1 |
| **acknowledged ingredient failures** | **dropped, by SPEC-021 AC4**: SPEC-070 |

Maurice chose to take the two marked rows (candidates 1 and 2). A third
candidate, a list of known-revoked certificates, stays a separate
conversation: c2patool's settings format has no field for it.

## Drafted, not built

- SPEC-069 amendment 1 (proposed). The informational for an allowed
  exclusion says that those bytes are not signed, so Exif or XMP values
  such as GPS or a capture time are not verified. The verdict does not
  change.
- SPEC-070 (draft). The statuses an ingredient assertion recorded are kept
  as informational under that ingredient's deltas, marked as acknowledged
  by the signer, instead of being dropped. The verdict does not change.
  It adds one property to `ValidationStatus`, a contract class, so its
  open question asks whether the release is 0.7.0.

A grep of the WordPress plugin repository found Exif mentioned only in its
spec on EXIF rotation, not in anything it displays. No code changes in
this step.
