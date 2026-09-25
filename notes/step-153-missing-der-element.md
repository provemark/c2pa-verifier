# Step 153 — A DER element that is not there is a refusal, not a crash (SPEC-016 amendment 4, SPEC-030 amendment 3)

*2026-09-25. The first crash of the security review of the same day.
Anyone can write such a file, with no key needed. Present in 0.1.0 to
0.2.1.*

## The crash

The timestamp and OCSP parsers read some fields by position,
`->sequence()[0]`, without checking that the SEQUENCE has that element.
An empty SEQUENCE there, for example an AlgorithmIdentifier with nothing
in it, gave a PHP warning ("Undefined array key 0") and then
`Call to a member function oid() on null`. That is an `Error` no check
catches. `bin/c2pa-verify` ended with exit status 255 and printed no
report. In the OCSP parser the warning landed on standard output, ahead
of the JSON.

## 153a — finding every place, and the tests seen red

The review named one line. To avoid searching place by place, a probe
mutated real inputs systematically: every constructed element emptied,
and separately without its last child. Every PHP warning counted as an
escape. The repository version, `tests/Unit/Asn1/MissingElementTest.php`,
builds its patches with `DerPatch`, which does not use the reader under
test. `DerPatch` gained one walker for this, `constructed()`.

| input | mutations | escapes before |
|---|---|---|
| the tokens of `c2pa-rs/C.jpg` (RSA), `public-testfiles/truepic-20230212-camera.jpg` (RSA, SHA-384), `c2pa-rs/ocsp.jpg` (ECDSA) | over 300 | 48, at `SignedData` :91, :116, :120, `SignerInfo` :116, :165, `TstInfo` :86 |
| `tests/Fixtures/ocsp/good.der`, `revoked.der`, `removed.der` | 272 | 3, at `OcspCheck` :239 |

No removed last child escaped; the existing `count()` checks cover
those. Both AC11 tests were red on those escapes. The OCSP test was
first red on its own lower bound (300, where the fixtures give 272),
then on the escapes.

## 153b — built

`Der::element(int $i)` returns the i-th element of a SEQUENCE, or throws
`Asn1Exception` when there is no SEQUENCE or no such element. The seven
reads now go through it. The existing paths already turn `Asn1Exception`
into the parser's answer:

- `TimestampException`, then `timeStamp.malformed`;
- for OCSP, `signingCredential.ocsp.skipped`.

The review's probe, `crash-sigtst2.png`, now gets a report: exit 1,
nothing on standard error, `timeStamp.malformed` *"SEQUENCE at offset 22
has 0 element(s), no element 0"*.

Measured:

- AC11 green (2 tests); `--group=SPEC-016` 70 passed, `SPEC-017` 18,
  `SPEC-030` 11.
- `composer check`: exit 0, 517 passed.
- 19,150 runs over every signed fixture and settings file, before and
  after: none moved.
- `php bin/fuzz.php 20260925 60 <out>`: 5,922 runs, 0 faults, 34
  suspects. The same 34 on the commit before this one, so none is new.
  `c2patool` 0.27.22 and 0.28.0 both say `Valid` on all 34, as step 45
  found for its suspects.

**Weight B:** a crash becomes a status. No verdict that was reached
before changes.

## Disclosure

Local until the review's other findings are fixed; released together as
the security release.
