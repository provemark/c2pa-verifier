# Step 314 — Two timestamp tokens, measured (L3)

*2026-10-09. Measurement only; no change to `src/` or to the generators.*

Reading §15.8 against `c2pa-rs` (step 312, P05-1) found that a
`sigTst2` header with more than one token is dropped there as
`timeStamp.malformed` (`crypto/cose/sigtst.rs`). This verifier judges the
first token and uses its time (SPEC-017 AC8, by design: "1 of 2 tokens
judged").

## Probes

`bin/make-tsa-matrix.php` in its scratch mode, with an option to put two
tokens into `tstTokens`. Both come from the same trusted TSA, the second
made right after the first. There were two probes: one with a valid
signer, and one with a signer valid for 2.5 minutes, judged after it had
expired. The change to the generator is not committed in this step
(SPEC-062 AC7 counts the probes); it comes with the fix.

| probe | `c2patool` 0.27.22 | 0.28.1 | `openssl ts -verify` (first token) | this verifier |
|---|---|---|---|---|
| control | `Valid` (no TSA anchors read) | `Trusted` | OK | `Trusted` |
| `two-tokens` | `Valid`, `timeStamp.malformed` | `Trusted`, `timeStamp.malformed` | OK | `Trusted`, `timeStamp.trusted` |
| `expired-signer-two-tokens` | `Invalid`, `timeStamp.malformed`, `signingCredential.expired` | `Invalid`, the same | OK | **`Trusted`** |
| `expired-signer-trusted-tsa` (one token) | `Invalid` (no TSA anchors read) | `Trusted` | OK | `Trusted` |

**Confirmed: a wrong `Trusted` against `c2patool`.** An expired signer
with two valid tokens stays `Trusted` here because the first token's time
is used. Both `c2patool` versions drop the timestamp and judge the signer
at the current time: `Invalid`. With a valid signer the state agrees and
only the timestamp codes differ.

## Checked

No code changed. `composer check`: 955 passed.
