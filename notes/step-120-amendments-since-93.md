# Step 120 — The eleven amendments since step 93, confirmed

*2026-09-24. Confirmed by Maurice van Loon the same day, all eleven.* 87 + 11 = **98**, which is what the specs hold (counted over
every `## Amendments` list).

Legend for **weight**: **A** = a rule of the verifier changed; **B** = the
report's shape, the API or the project's own rules changed, verdicts
unchanged; **C** = a criterion's wording, a message, a record catching up.

All eleven were written on 2026-09-24, in steps 108–118, and every one
traces back to the same event: `c2patool` 0.28.0 was measured against this
verifier for the first time (step 107).

## A — a rule of the verifier

| spec | # | what | why | verdicts that moved | confirmed |
|---|---|---|---|---|---|
| SPEC-012 | 7 | **Reverses amendment 5**: an exclusion that holds any part of the manifest store holds nothing else, else `assertion.dataHash.mismatch` | C2PA 2.4 §15.12.1.1 (and §15.12.1.2 for JPEG). The Truepic files exclude their EXIF segment together with the store, and a copy with a changed EXIF date stayed `Trusted` (step 108) | the three `truepic-20230212-*` files: `Trusted` → `Invalid` under their root; nothing else over 864 runs | confirmed 2026-09-24 |
| SPEC-017 | 4 | AC6's Truepic files expect the oracle's failures **plus** `assertion.dataHash.mismatch`, state `Invalid` | follows from SPEC-012 #7; the timestamp half of AC6 (trusted, not expired) is unchanged | the same three | confirmed 2026-09-24 |
| SPEC-017 | 5 | `tsaSettings()` carries **no allowed list**; new AC13 | C2PA 2.4 §14.4.3: the private credential store *"shall not apply to validating time-stamps"*. Through the PHP constructor, a TSA on the allowed list could excuse an expired signer (step 114) | none in the corpus (only the constructor could reach it) | confirmed 2026-09-24 |
| SPEC-014 | 3 | A top-level `trust.allowed_list` is **refused** (AC7); AC3's allowed-list test moves its certificates into a `"manifest"` entry | `c2patool` 0.28.0 drops a loose `allowed_list` without a word, so the same settings file meant `Trusted` here and `Valid` there (step 107; your decision) | a settings file with a loose `allowed_list` now exits 2. **This is why the next tag is `0.2.0`** | confirmed 2026-09-24 |
| SPEC-008 | 2 | A one-certificate `x5chain` written as a bare byte string is a chain of one; new AC13 | RFC 9360, quoted by C2PA 2.4 §14.5. `c2pa-rs` writes it that way for a signer directly under a root. It was refused here since step 16 (step 110, step 117) | none in the corpus (no real signer lacks an intermediate) | confirmed 2026-09-24 |
| SPEC-013 | 13 | A hard binding referenced only from `gathered_assertions` is `claim.hardBindings.missing`; new AC19 | C2PA 2.4 §10.2.2: `created_assertions` *"shall contain … a reference to an assertion that represents a hard binding"*. `c2patool` 0.28.0 refuses it too (step 118) | `absence/hash-data-gathered.png`: `Valid` → `Invalid`; nothing else over 870 runs | confirmed 2026-09-24 |

## B — the report's shape, the API, the project's rules

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-025 | 4 | The contract grows to **eleven classes** (`Trust\TrustAnchorSet`); `TrustSettings` gains `$anchorSets` and `MAX_ANCHOR_ENTRIES`; the recorded surface goes 99 → **111** symbols. Four helpers were kept *off* the contract, on the `@internal` `ChainCheck` | SPEC-031 reads `trust.anchors`; every public member of a contract class is a promise | confirmed 2026-09-24 |
| SPEC-000 | 1 | New AC11: a test whose name claims a criterion must find a row for it in its spec's Traceability table | a test called "SPEC-017 AC12" had existed for two days without a criterion. Measured: five orphans in 376 named tests (step 116) | confirmed 2026-09-24 |

## C — wording, a message, the record

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-031 | 1 | An untrusted outcome names an entry of another kind **only if the chain would have reached it** | AC7's twin settings (the same PEM as `"manifest"` and `"tsa"`) otherwise got a sentence about an entry that changed nothing (step 111b) | confirmed 2026-09-24 |
| SPEC-031 | 2 | The API sketch as built: kinds as three constants, not an enum; the helpers on `ChainCheck`; the total-certificates message | recorded so that nobody looks for what is not there | confirmed 2026-09-24 |
| SPEC-017 | 6 | AC12 (Pixel 10, step 46) written down as a criterion, read off the test as it stands | the test had no criterion and no row; nothing in the test or the code changed (step 116) | confirmed 2026-09-24 |

## The one worth reading twice: SPEC-012 #7

This is the third wrong `Valid` this project has found in itself, and
the first one that shipped: it is in `0.1.0`.

It did not come from a slip in the code. It came from a **decision**:
amendment 5 (2026-09-21) relaxed the rule from *equal* to *cover*, on two
grounds. The first was that `c2patool` 0.27.22 accepted the Truepic files.
The second was that the signer had chosen the wide exclusion and vouched
for it. The first ground was the oracle agreeing, not the
specification. The second is exactly what §15.12.1.1 forbids, and what
§15.12.1.2 makes explicit for JPEG. The amendment was checked against the
tool and not against the text.

Two things changed in how the project works because of it:

- **Every divergence from a new oracle version is examined before
  anything else** (steps 107, 108, 112, 118), and the question each time
  is the one the design rules put first: is this a wrong `Valid` here?
- **`docs/conformance.md` had said `PRED-IMG-004` was enforced** since
  step 90, because the mapping read the code's *intent*. It now records
  when the rule actually became true.

## What confirming means

Confirming says that the reason written in each amendment is the reason
you accept, and that its weight is right. It changes no code: all eleven
are already built, tested and pushed (CI green up to `bb29a10`). The
column above gets *"confirmed by Maurice van Loon, <date>"* per row, each
spec's amendment gets the same stamp, and the README's count becomes 98.
