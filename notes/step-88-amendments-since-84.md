# Step 88 — The three amendments since step 84, confirmed

*2026-09-22.* 81 + 3 = **84**, which is what the specs hold.

Legend for **weight**: **A** = a rule of the verifier changed; **B** = the
report's shape or the API changed, verdicts unchanged; **C** = a test
literal, a count, a message, a seam, or a layer line.

All three come from one step — 87b, which made `c2pa.hash.bmff.v2` verify —
and none of them changes a rule. Two are criteria that stopped being true
when the feature they excluded became a feature; the third is a criterion
that was never right to begin with, and that is the one worth reading.

## A — a rule of the verifier

None.

## B — the report's shape, the API

None.

## C — a criterion, a message, a comparison

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-029 | 1 | AC1 compares against an oracle recorded under **the same trust anchors**, not against one recorded without settings | The two verifiers did not have the same anchors, so the same file got two honest but different answers | confirmed 2026-09-22 |
| SPEC-027 | 3 | AC5's refusal list drops `subset` and nested `xpath`s, keeps `length`, `version`, `flags`, `exact` | SPEC-029 implements the first two; `c2pa.hash.bmff.v2` cannot be verified without them | confirmed 2026-09-22 |
| SPEC-012 | 6 | AC8's message names `BmffHashCheck` instead of "M8" | M8 closed; that binding is verified now, by another check | confirmed 2026-09-22 |

## The one that matters: a comparison between unequals

SPEC-029 AC1 asked for `c2pa-rs`'s `video1.mp4` to be verified **without**
trust settings, and for the failure codes to equal the recorded
`c2patool/c2pa-rs/video1.json`. They differed by one code, on the
ingredient:

```
ours:   signingCredential.expired, signingCredential.untrusted
theirs: signingCredential.untrusted
```

Both are right about their own inputs. `c2patool` falls back to the
operating system's trust store for a timestamp authority — step 40 §5
measured that its `timeStamp.trusted` for the DigiCert 2023 responder does
not depend on the anchor configured — so without settings it still trusts
both of this file's DigiCert stamps, judges its 2022 signers at the moment
they were stamped, and reports `claimSignature.insideValidity`.

This verifier has no system trust store **by design**. Trust comes from the
settings file and from nowhere else: that is what makes a verdict here
reproducible on a machine whose keychain nobody controls. Without an anchor
for the responder it cannot trust the stamp, so it judges the ingredient's
certificate — valid 2022-04-04 to 2023-04-04 — at *now*. It has expired,
and saying so is correct.

The temptation was to relax the criterion to "the codes we happen to
produce". What it needed instead was to ask both sides the same question.
`trust/full-plus-digicert-g4.settings.json` has been a fixture since M6:
the C2PA test anchors plus the cross-certificate that signs those stamps.
Under it the two agree status for status, in both scopes, with one failure
each — the ingredient's chain ends at an intermediate no anchor signs.

**Why this is weight C and not A**: no rule changed, in either direction.
The verifier answered the same before and after; only the question put to
it did. A file that passes still passes and a file that fails still fails.

## What the other two cost

Nothing a caller can see. SPEC-027 #3 moves a synthetic fixture from one
refusal to another — `bmff/xpath-nested.mp4` now fails through
`assertion.bmffHash.mismatch` instead of an unsupported-filter error,
because its `/a/b` names no box, so the bytes it meant to exclude are
hashed and the digest says so. `Invalid` either way, which is what the
criterion exists to protect. SPEC-012 #6 changes one sentence in a message
that only appears when someone calls an internal check directly with a
binding it does not own; the status stays `general.error`, because
answering is the point.

## Confirmation

**Confirmed by Maurice van Loon on 2026-09-22** ("bevestig alle drie de
amendementen"). Each amendment line in SPEC-029, SPEC-027 and SPEC-012
carries the same stamp.
