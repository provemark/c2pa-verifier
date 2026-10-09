# Step 312 — Reading the whole of C2PA 2.4 against the verifier

*2026-10-09. Reading only; no change to `src/`.*

Steps 308 and 309 read §14 by hand. Maurice van Loon asked for every
chapter to be read before the next release (0.5.4), not §14 alone. This
step reads the rest and gathers it in `docs/reading-c2pa-2.4.md`.

## How it was done

The rest of the specification, about 81,000 words, was cut by heading into
ten packs of 2,600 to 15,200 words (§5–9, §10–11, §13/§16/§17/Appendix C,
§15.1–6, §15.7–9, §15.10–end, §18.1–9, §18.10–16, §18.17–end, Appendix
A). Each pack was read in parallel by an AI agent (`AI-LOG.md`) against
`src/`, the specs, the tests and `c2pa-rs` 0.91.1's source. Each agent
filled a table in the format of §14 and listed candidates with a risk
label. The agents could not change the repository. Some ran
`bin/c2pa-verify` or `c2patool` on probe files of their own in a scratch
directory.

The tables were checked before they went in:

- every test, file and method they cite exists: 334 references, 0
  missing;
- ten covered rows drawn at random were re-read in the code; five of them
  were followed to the line, and all held;
- every candidate marked "possibly more lenient than `c2patool`" was
  re-read by hand in this verifier and in `c2pa-rs`;
- one contradiction between two packs (a claim without `alg`) was
  settled in the code: here a hash without its own `alg` takes the claim's,
  and only when both are missing is it `algorithm.unsupported`;
  `c2pa-rs`'s `Claim::alg()` falls back to SHA-256.

## Result

562 rules: 280 covered, 65 partial, 53 by design, 101 n/a, 62 candidates.
Thirteen candidates may be more lenient than `c2patool` (L1 to L13 in the
document):

- a BMFF hash without `alg`;
- a data hash without `pad`;
- two timestamp tokens;
- a metadata assertion without `@context`;
- a malformed time-stamp or certificate-status assertion;
- cloud data not checked;
- soft bindings not decoded;
- `c2md` manifests not read;
- duplicate manifest labels;
- an unchecked version 2 manifest label;
- a merkle map on an unfragmented file;
- an empty `claim_generator_info` map in a version 2 claim.

All thirteen are read, none measured. Two more need a measurement first:
action field types, and OCSP on ingredient manifests.

## Next

A probe for each of the thirteen, judged by both `c2patool` versions.
Then a fix per spec, tests first, where a probe confirms the difference.
Then 0.5.4.

## Checked

`composer check`: 955 passed.
