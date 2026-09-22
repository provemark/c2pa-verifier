# Step 68 — The six amendments since step 58, for the maintainer's confirmation

*2026-09-22.* This page replaces
[`step-64-amendments-since-58.md`](step-64-amendments-since-58.md), which
listed three of these and was never confirmed; three more have been written
since, in step 67b. Confirming this page confirms all six.

The spec template allows an approved spec to be amended when a measurement
made before or during its tests-first step shows the criterion wrong; the
amendment is written into the spec at once, so that the tests are never
green against a text they contradict. Step 51 put the first fifty-one on
one page and step 58 the next seventeen; Maurice van Loon confirmed all
sixty-eight. The arithmetic checks out: 51 + 17 + 6 = **74**, which is the
number of amendments the specs now hold.

Legend for **weight**: **A** = a rule of the verifier changed (what is
`Valid`, `Invalid`, `Trusted`, which code, or what is read at all);
**B** = the report's shape or the API changed, verdicts unchanged;
**C** = a test literal, a count, a message, a seam, or a layer line —
nothing a user of the verifier could notice.

## A — rules of the verifier

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-001, SPEC-002, SPEC-003 | 4, 2, 2 | **The bound on the manifest store falls from 64 MiB to 16 MiB in all three containers**, and a store that fits the bound but not the host's remaining memory is refused before it is read | step 66 measured a 63 MiB store — *inside* the old bound — needing 132 MB and ending a 128 MB host with a PHP fatal error instead of returning `Invalid`. A fatal cannot be caught, so the caller got no report and no status code at all. Across 212 corpus stores the median is 45 kB and the largest ever met 3.36 MB, so the old figure was nineteen times anything real | — |
| SPEC-015 | 5 | **A key's kind is read from the SubjectPublicKeyInfo algorithm OID** where PHP's own key type does not say it; Ed25519 (1.3.101.112) joins the RSASSA-PSS OID that was already read this way | before PHP 8.4 an Ed25519 key has no `ed25519` details and `openssl_pkey_get_details()` reports type "other", so the profile said `signingCredential.invalid` — **every Ed25519-signed file was `Invalid` on PHP 8.3 and `Trusted` on 8.4 and 8.5**. A verdict that depended on the runtime. §14.5 has always allowed Ed25519; the bug could not be seen before the coverage matrix, because no fixture carried such a signature | — |

## B — the report's shape, the API

None.

## C — literals, counts, seams, layers

| spec | # | what | confirmed |
|---|---|---|---|
| SPEC-013 | 12 | The coverage matrix (`tests/Fixtures/matrix/`) joins the four corpora as a **fifth drift alarm**, with three new criteria: AC16 (every algorithm in every format), AC17 (state, failure codes and `signature_info` against c2patool's JSON with and without the test roots), AC18 (the two trust answers). No rule of the spec changed | — |
| SPEC-023 | 1 | **AC6 rewritten** before a line of the checker was written: it asked for the archive in an empty directory "with no `vendor/`", which no PHP library can satisfy — `bin/c2pa-verify` is a shim that requires an autoloader. The criterion now describes the layout Composer creates, and the autoloader is built from the `autoload.psr-4` map in the archive's **own** `composer.json` | — |

## Three things worth a second look before confirming

1. **The bound is the first amendment that refuses files this verifier used
   to read.** Every earlier "stricter" entry tightened a verdict on a file
   that was already being examined; this one declines to examine at all.
   Measured: **no file in this repository is affected** — the largest store
   in 212 is 3.36 MB against the new 16 MiB bound — so nothing in the
   corpus changes, and that is exactly why the change is invisible to the
   test suite until you look for it.

2. **And it is the first rule whose answer depends on the host.** A store
   of 15 MiB is read on a 512 MB host (36 MB peak) and refused on a 32 MB
   one. That was weighed and accepted on 2026-09-22 against the
   alternative, which is a fatal error on small hosts; the condition was
   that a refusal must never read as a judgement, so the wording says *"The
   file was not examined, so this is not a judgement about it"* and AC2
   asserts on that sentence. If you would rather have one absolute bound
   and accept the fatal on hosts below it, say so: it is the removal of one
   call and one criterion.

3. **SPEC-015 #5 is the third wrong verdict this project has found in
   itself**, and the first that depended on the PHP version rather than on
   the file. The two earlier ones were wrong `Valid`s, the dangerous
   direction; this one was a wrong `Invalid`, the safe direction but wrong
   all the same. What is worth your eye is its shape: the verifier agreed
   with `c2patool` on every corpus and was still wrong, because the corpora
   and the oracle shared a blind spot.

## Confirmation

Awaiting Maurice van Loon.

## How to confirm (the procedure, as in steps 51 and 58)

Per group, or per line: "bevestigd" (all of A and C), or a question or a
"no" naming the spec and number. A "no" on an A-line reverses a rule and
gets its own step: the spec text back, a test that shows the reversed
rule, and the note. Confirmations are dated on this page and, for group
A, in the spec's amendment line itself.
