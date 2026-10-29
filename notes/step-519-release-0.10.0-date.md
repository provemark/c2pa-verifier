# Step 519 — 0.10.0 dated, its introduction and the security record brought up to date

*2026-10-29.* Maurice: "akkoord, vul de introductie aan en zet de datum".

Step 500 prepared 0.10.0 on 2026-10-28 (steps 495–499). Before it was
tagged, a security review of the whole code was made, and its findings
were fixed one per step in steps 502–518, all pushed (`8f0a133`, CI green).
The release text still described the release as step 500 wrote it.

What this commit does:

- **`CHANGELOG.md`.** The heading becomes **0.10.0 — 2026-10-29**, the
  day of the tag. The introduction now names the four groups the review
  brought: six kinds of wrongly `Trusted` file, five crashes, four costly
  inputs, and two deliberate new differences from `c2patool`. The entries
  under Added, Changed and Fixed were written step by step and are
  unchanged.
- **`README.md`.** The sentence on the current tag adds the review's
  fixes.
- **`SECURITY.md`.** Step 500 left it unchanged because that round had
  found no wrong `Valid` or `Trusted`. The review found six, so the count
  goes from 27 to 33. A new entry names each with the versions it was
  present in (found through `git tag --contains` on the commit that
  brought the code), whether it was confirmed or reasoned, and its
  amendment. The crashes, the costly inputs and the two low findings are
  named but not counted, as the record's rule is for wrong verdicts.
  - The CAWG refusal and the BMFF walk date from `0.1.0`.
  - Time Stamping written twice dates from SPEC-031 amendment 3
    (step 159, `0.2.4`).
  - The excluded-track handler dates from SPEC-072 (`0.7.0`).
  - The two OCSP findings could keep a revoked signer `Trusted` only
    through a caller's revocation source, which arrived in `0.9.0`.
- `Verifier::VERSION` was already `0.10.0` (step 500).

No code changes. The tag goes on this commit once it is pushed and CI is
green, on Maurice's word.
