# Step 357 — SPEC-071 drafted: which boxes a BMFF hash may exclude

*2026-10-13.*

Step 356 measured that a `c2pa.hash.bmff` exclusion of `/mdat` or `/moov`,
with the excluded box edited after signing, is `Trusted` here and in
`c2patool` 0.28.x. SPEC-071 (draft) closes it as SPEC-069 does for data
hashes, with an allow-list of what `c2pa-rs` writes:

- the C2PA `uuid` box (data filter);
- `/ftyp`, `/mfra`, `/free`, `/skip`, `/meta/iloc`, `/mfra/tfra`;
- the offset fields `stco`/`co64`/`tfhd`/`trun`, only with a subset from
  offset 16 on.

Every other path is `assertion.bmffHash.mismatch`, judged by path whether
or not it matches a box.

The blocking open question is Pixel video. A commit linking to `c2pa-rs`
PR #2434 says Pixel excludes `/moov[1]/trak[3]`, an indexed path this
verifier does not resolve. The proposal: build now, fail closed, and make
a real Pixel video the trigger for a spec that resolves indexed paths.

Spec only; no code changes.
