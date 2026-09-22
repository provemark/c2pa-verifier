# Step 64 — The three amendments since step 58, for the maintainer's confirmation

*2026-09-22.* The spec template allows an approved spec to be amended when
a measurement made before or during its tests-first step shows the
criterion wrong; the amendment is written into the spec at once, so that
the tests are never green against a text they contradict. Step 51 put the
first fifty-one on one page, step 58 the next seventeen, and Maurice van
Loon confirmed all of them. **Three** have been written since — two in
step 59 (the coverage matrix) and one in step 63b (the published package).
The arithmetic checks out: 51 + 17 + 3 = **71**, which is the number of
amendments the specs now hold.

Legend for **weight**: **A** = a rule of the verifier changed (what is
`Valid`, `Invalid`, `Trusted`, which code, or what is read at all);
**B** = the report's shape or the API changed, verdicts unchanged;
**C** = a test literal, a count, a message, a seam, or a layer line —
nothing a user of the verifier could notice.

## A — rules of the verifier

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-015 | 5 | **A key's kind is read from the SubjectPublicKeyInfo algorithm OID** where PHP's own key type does not say it. Ed25519 (1.3.101.112) joins the RSASSA-PSS OID, which was already read this way | before PHP 8.4 an Ed25519 key has no `ed25519` details and `openssl_pkey_get_details()` reports type "other", so the profile said `signingCredential.invalid` — **every Ed25519-signed file was `Invalid` on PHP 8.3 and `Trusted` on 8.4 and 8.5**. A verdict that depended on the runtime. No criterion of the spec changed: §14.5 has always allowed Ed25519, and the bug could not be seen before the matrix because no fixture carried an Ed25519 signature | — |

## B — the report's shape, the API

None.

## C — literals, counts, seams, layers

| spec | # | what | confirmed |
|---|---|---|---|
| SPEC-013 | 12 | The coverage matrix (`tests/Fixtures/matrix/`) joins the four corpora as a **fifth drift alarm**, with three new criteria: AC16 (the coverage itself — every algorithm, every format), AC17 (state, failure codes and `signature_info` against c2patool's JSON, with and without the test roots), AC18 (the two trust answers). No rule of the spec changed | — |
| SPEC-023 | 1 | **AC6 rewritten** before a line of the checker was written: it asked for the archive in an empty directory "with no `vendor/`", which no PHP library can satisfy — `bin/c2pa-verify` is a shim that requires an autoloader. The criterion now describes the layout Composer creates, and the autoloader is built from the `autoload.psr-4` map in the **archive's own** `composer.json` | — |

## Two things worth a second look before confirming

1. **SPEC-015 #5 is the third wrong verdict this project has found in
   itself**, and the first that depended on the PHP version rather than on
   the file. The two earlier ones were wrong `Valid`s, which is the
   dangerous direction; this one was a wrong `Invalid`, which is the safe
   direction but still wrong — a correctly signed Ed25519 file was
   rejected on the oldest PHP this package supports, and CI could not see
   it because no fixture had such a signature. It is closed, and the
   matrix now makes the gap that hid it impossible to reopen quietly. What
   is worth your eye is the shape of it: the verifier agreed with
   `c2patool` on every corpus and was still wrong, because the corpora and
   the oracle shared the same blind spot.

2. **SPEC-023 #1 amends a criterion you approved the same day.** That is
   not a problem with the procedure — the amendment was written before the
   implementation, which is exactly when it should be — but it is worth
   saying plainly that the criterion as approved was not merely awkward:
   it could not pass at all. If you would rather the spec had been sent
   back for re-approval instead of amended in place, say so and that
   becomes the rule for the next one.

## Confirmation

Awaiting Maurice van Loon.

## How to confirm (the procedure, as in steps 51 and 58)

Per group, or per line: "bevestigd" (all of A and C), or a question or a
"no" naming the spec and number. A "no" on an A-line reverses a rule and
gets its own step: the spec text back, a test that shows the reversed
rule, and the note. Confirmations are dated on this page and, for group
A, in the spec's amendment line itself.
