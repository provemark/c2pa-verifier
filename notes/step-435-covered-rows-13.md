# Step 435 — The covered rows of §13, each broken once

*2026-10-25.* Step 3 of the audit "Before 2.5: is all of 2.4 there?",
continued from step 434: the 18 covered rows of §13 (hash algorithms,
signature algorithms and keys, the COSE structure). No code changes:
tests, one fixture and the reading.

## How

As in step 434: one exact replacement in `src/` per mutation, the cited
tests run, the whole suite when they stay green, the file restored and
`git status src` checked; for the survivors, the whole fixture corpus
with each directory's settings, with and without the mutation. 35
mutations covered 17 rows. The 18th (`iat` is the signer's word) names
no code: the field is never read.

## What was measured

| result | mutations |
|---|---|
| red in the test the row cites | 28 (three of them in rows that said "read": the SHA-1 timestamp imprint, which `TsaMatrixTest` has held since step 420, and a hash that is not a byte string in the claim and in the data hash) |
| red only in other tests: citation fixed | 1: a BMFF hash's alg taken from the claim before the assertion (`ClaimAlgTest` AC10) |
| green everywhere | 6 |

No survivor moved a single corpus file. What became of the six:

| rule | what was done |
|---|---|
| §13.1: BMFF hashes only SHA-256/384/512 | `BmffHashCheckTest` AC6 had the case, but its hash was text, so it was refused for the hash first. The case now carries a byte string and a named message; a `sha1` case beside it |
| §13.1: a hash value is a byte string (BMFF) | the same test, its own case: a text hash with `sha256` |
| §13.1: the nearest identifier, the merkle map's own `alg` | `bmff-fragmented/broken/init-merkle-alg-sha384.mp4` (`bin/make-fragmented-variants.php`; same length, not re-signed); `FragmentedVerifierTest` AC2: the init segment no longer matches its `initHash` |
| §13.2.1: RSA above 16,384 bits | `SignatureVerifierTest` AC5: a certificate made in memory, its public key swapped for a random 16,392-bit modulus (a public key only; nobody holds a private half). OpenSSL loads it; `requireFit()` refuses it |
| §13.2.1 (RFC 8017 §9.1.2): the PSS padding | `SignatureVerifierTest` AC7: the encoding built by hand with a key made in memory, once with a padding byte not zero, once with the separator 0x02; both refused. The key is never written |
| §13.2.3: `alg` an integer | `CoseSign1Test` AC9: `{1: "ES256"}` refused as "alg is not an integer" |

Each of the six was seen red under its mutation after the change, and
green without it. `composer check`: 1,363 passed, 1 skipped. One run before
that failed `HostileInputSecondRoundTest` AC2 (under two seconds) at a
load average above 5, as in step 433; the next two passed.

## What was reasoned

The padding mutation could only matter to the holder of the signing key:
anyone else cannot make an encoding that passes the hash comparison.
Tested anyway, since RFC 8017 says such an encoding is inconsistent and a
verifier that accepts it is not the one the rule describes. `c2patool`
was not asked about the hand-made cases: they test the encoding inside
the verifier, not a file.

## Not done

The covered rows outside §13, §14 and §15 (about 140).
