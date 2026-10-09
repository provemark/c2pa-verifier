# Step 308 — Reading C2PA 2.4 §14.1 to §14.4 against the verifier

*2026-10-09. Reading and one measurement; no change to `src/`.*

The first step of a reading round. Chapter by chapter, every normative
sentence of the C2PA 2.4 specification is put beside the verifier, in
`docs/reading-c2pa-2.4.md`. A rule that is not met, or not known to be met,
becomes a candidate for a probe, not a change. This step covers the
signers' identity, the validation states and the trust lists (§14.1 to
§14.4). §14.5 (X.509 certificates) is the next step.

## Source

The 2.4 page was fetched once (SHA-256 `d55caebd…d26d`), and §14.1 to §14.4
were cut out by heading, about 1,300 words. 2.4 is the newest published
version: its version history begins with "2.4 - April 2026", and a 2.5 page
does not exist at the same place (step 307's search).

## Measured

One rule could be measured at once. §14.3.5 defines Valid by the presence
of `claimSignature.validated` and `claimSignature.insideValidity`; this
verifier, like `c2pa-rs`, defines it by the absence of failures. Over every
fixture under no settings and each settings file (103,796 runs, 29,915 of
them `Valid` or `Trusted`), no `Valid` or `Trusted` report lacks either
code, and no `Trusted` report lacks `signingCredential.trusted`.

Everything else in the table is read from the code and the specs, and is
marked as such, or points at the test that measures it.

## Result

Seventeen rules:

- 10 covered, among them 14.3.5 "in effect", and 14.4.2's separate TSA
  list, which is partial only for the legacy single anchor string;
- 1 partial: two credentials;
- 4 by design: the state vocabulary, anchors not tied per EKU, and no
  bundled signer list or TSA list;
- 1 n/a: anchor configurations with dates;
- 1 by construction: the private store is the caller's.

Three candidates:

- **C1 — two credentials.** Label 33 together with `"x5chain"` in the
  protected header, or label 33 in both headers, is not refused, though
  §14.2 rejects any second credential. The code says `c2pa-rs` does the
  same. That is read, not measured; the next step is two probes.
- **C2 — the state rule written positively.** Today it makes no difference;
  it would be defence in depth.
- **C3 — section numbers.** 22 places cite "C2PA 2.4 §14.6" or "§14.6.1",
  which 2.4 does not have; timestamps are §10.3.2.5 and §15.8 there.
  `docs/conformance.md` cites §14.5.1.2 for a rule that is §14.4.1.

## Checked

`composer check`: 953 passed.
