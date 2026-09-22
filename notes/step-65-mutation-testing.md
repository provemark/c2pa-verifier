# Step 65 — Are the tests strict? Mutation testing, and what it found

*2026-09-22.* Three hundred and sixty-six tests were green, five drift
alarms agreed with `c2patool` on ninety-six files, a second implementation
in another language agreed on two hundred and fifty-seven, and seventy
thousand mutated files had gone through the verifier without an exception
escaping. None of that answers the question underneath: **would the tests
notice if the code were wrong?** A suite can be large and still slack.

Mutation testing answers it. The tool changes the source one place at a
time — `<` becomes `<=`, a `return false` disappears, a method call is
deleted — and runs the suite against each change. A change that makes a
test fail is *killed*: the tests were watching. A change that leaves
everything green is *untested*: that line could be wrong and nobody here
would know.

## The number

```
Mutations: 90 untested, 3 timeout, 4547 tested
Score:     98.06%
Duration:  935.39s
```

Sixty-eight classes, just under eight thousand lines, fifteen and a half
minutes. 98.06% is a good result and it is the first time this project can
say anything at all about the quality of its own tests rather than their
quantity.

## The tool is not the one we started with

**Infection does not work here**, and the reason is worth recording.
Infection decides which generation of PHPUnit it is writing a configuration
for by asking the test binary; the binary here is Pest, so it guesses
"before 9.3" and writes a `<filter><whitelist>` block. PHPUnit 12 rejects
that outright — *"Element 'filter': This element is not expected"* — and
the run dies before a single mutant is tried, reporting only that "project
tests must be in a passing state", which they were. Infection was removed
again, and with it the `allow-plugins` entry Composer wanted for
`infection/extension-installer`: that entry grants a third party the right
to execute code at install time, including in CI, and it was set to `false`
rather than granted.

Pest 4 has mutation testing built in, and it needs `--everything` here
because these tests carry no `covers()` annotations, `--covered-only` so
that untested code is not counted twice, and `-d memory_limit=6G` because
the default 128 MB runs out partway through.

## What the ninety are

Forty-one of the ninety are `IncrementInteger` / `DecrementInteger`: a
bound moved by one where no input can show the difference. `CoseSign1`'s
`$n < 4294967296` becoming `$n <= 4294967296` needs a four-gigabyte payload
to catch. Those are not gaps.

| file | untested |
|---|---|
| `src/Cbor/CborDecoder.php` | 29 |
| `src/Trust/TrustSettings.php` | 12 |
| `src/Asn1/Der.php` | 8 |
| `src/Trust/Certificate.php`, `src/Asn1/DerReader.php` | 7 each |
| `ActionsCheck`, `CoseSign1`, `WebpManifestStoreExtractor` | 5 each |
| `TimeStampToken`, `Support/Bytes` | 4 each |
| `ManifestGraph`, `HashedUriCheck`, `RsaPss`, `Cli/Command` | 1 each |

Three were worth acting on, and one of those overturned a reading of my
own.

## 1. The EMSA-PSS trailer byte — and a wrong first answer

`src/Cose/RsaPss.php:52` holds RFC 8017 §9.1.2 step 4: the last byte of
the encoded message must be `0xbc`. Deleting that check broke no test.

Reading the code, the first conclusion was that it did not matter: the
`hash_equals($h, $h2)` at the end would catch anything the trailer let
through. **That was wrong, and the test proves it.** Changing only the
trailer byte leaves `maskedDB` and `H` untouched, so every later step —
MGF1, the DB recovery, the salt, the final comparison — still agrees, and
`RsaPss::verify()` returns `true` for an encoding RFC 8017 says to reject:

```
--- with the check removed
Failed asserting that true is false.
```

It is not a forgery route: the signature is the RSA operation over the
whole encoded message, so a stranger cannot flip that byte in a signature
they did not make. It is malleability, and a verifier that fails closed has
no business accepting it. The check was right; the *tests* were not
watching it.

Producing a signature whose only defect is that byte needs the private key
that made it, so the test builds the EMSA-PSS encoding itself (RFC 8017
§9.1.1) and signs it with a 2048-bit key generated in memory for the run.
No key enters this repository, test key or otherwise; this one exists for
milliseconds and is never written anywhere.

## 2. A cycle stopped the whole walk, not just its branch

`src/Manifest/ManifestGraph.php:173` records a cyclic ingredient reference
and then `continue`s to the next assertion. Turning that into `break` broke
no test — because every cyclic fixture had its cycle in the *last*
assertion, so there was nothing behind it to lose. A manifest that names a
cycle and then names something real would have had the something real
silently dropped from the walk.

The new case is synthetic, through the `fromIngredients()` seam: B names A,
which is in the path, and then names C, which is not. C must still be
reached.

## 3. A branch made redundant by its own repair

`Certificate::keyFacts()` read an Ed25519 key's kind from PHP's
`ed25519` details, which exist only from PHP 8.4. SPEC-015 amendment 5
added a read of the SubjectPublicKeyInfo algorithm OID because on 8.3 those
details are absent and every Ed25519 file came out `Invalid`. That OID read
is version-independent — it answers identically on 8.3, 8.4 and 8.5 — which
is exactly what the mutation showed: deleting the older branch changed
nothing any test could see. It is gone. One path for one question, and CI
on 8.4 is what proves it, since that is where the details exist.

## Both tests were seen red against their own mutant

A regression test written after the fact is green from birth, which proves
nothing. Each of the two was checked by applying by hand the exact mutation
that had escaped, watching the new test fail, and restoring:

```
RsaPss      : 1 failed  ->  1 passed
ManifestGraph: 1 failed, 1 passed  ->  2 passed
```

Re-measured afterwards, `RsaPss` and `ManifestGraph` are at **100%**.

## What is not fixed here

`pest --mutate --parallel` cannot be used: eight helper functions are
defined in one test file and called from others (`spec020Oracle` lives in
`IngredientAssertionTest.php` and four other files call it). Serially PHP
loads every file and it works; in parallel each process loads a subset and
twenty-four tests die with "Call to undefined function". It is not a fault
in the verifier, but it is a trap for anyone who reaches for `--parallel`,
and it is the difference between a fifteen-minute measurement and a
two-minute one. Moving those eight into `tests/Pest.php` is its own step,
by hand: an attempt to do it with a script inside this step missed two
functions and cut a third in the wrong place, and was reverted.

The remaining eighty-eight untested mutations stay as the baseline. Most
are message text (`opensslError()`'s wording) and bounds no input reaches.
The number to compare against next time is **98.06%**.
