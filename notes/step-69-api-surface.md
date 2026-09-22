# Step 69 — What a tag would freeze

*2026-09-22.* There is no tagged release and the README says the public
API may still move. Once a version number exists, every public symbol in
`src/` is a promise: removing one, renaming one, or changing a parameter
is a breaking change, whatever the README says about it. So the question
before a tag is not "is the API good" but **"how much of it did we mean to
promise"**.

Measured with reflection over every class in `src/`.

## The surface today

| | |
|---|---|
| public classes, interfaces and enums | **69** |
| public methods | 192 |
| public constants | 137 |
| public properties | 202 |
| **total public symbols** | **600** |
| marked `@internal` | **0** |

| layer | classes | methods | const | props |
|---|---|---|---|---|
| Asn1 | 4 | 26 | 18 | 12 |
| Cbor | 4 | 4 | 2 | 5 |
| Cli | 1 | 2 | 1 | 0 |
| Container | 8 | 17 | 7 | 6 |
| Cose | 8 | 16 | 18 | 17 |
| Hash | 2 | 4 | 3 | 0 |
| Jumbf | 7 | 16 | 19 | 24 |
| Manifest | 12 | 33 | 10 | 54 |
| Report | 4 | 13 | 42 | 11 |
| Support | 2 | 8 | 1 | 0 |
| Timestamp | 9 | 24 | 12 | 45 |
| Trust | 5 | 18 | 3 | 22 |
| Verifier | 3 | 11 | 1 | 6 |

## The surface we actually documented

The README's example names **two** classes — `Verifier` and
`TrustSettings` — and four accessors: `$report->result`,
`->hasManifest`, `->remoteManifestUrl`, `->toArray()`. The CLI shim uses
five classes in all. Everything else is reachable, unmarked, and would be
frozen by a tag: the DER reader, the CBOR decoder's item limits, every
JUMBF box type, `MemoryBudget::parseLimit()`, the ASN.1 tag constants.

None of that is wrong to *have* public — this is a library whose layers
are testable on their own, and the tests reach into them by design. What is
wrong is that nothing says which of them a stranger may build on.

## What the contract would be, if we drew it

Taking the README, the CLI and the sister library's adapter as the
definition of what a caller needs:

| class | methods | constants | properties |
|---|---|---|---|
| `Verifier\Verifier` | 2 | 1 | 0 |
| `Verifier\VerificationReport` | 3 | 0 | 6 |
| `Report\ValidationResult` | 2 | 0 | 3 |
| `Report\ValidationStatus` | 2 | 0 | 4 |
| `Report\ValidationState` | 3 | 3 | 2 |
| `Report\StatusCode` | 6 | 39 | 2 |
| `Trust\TrustSettings` | 5 | 1 | 4 |
| `Cli\Command` | 2 | 1 | 0 |

**99 symbols of 600 — sixteen per cent.** The other eighty-four per cent
is machinery that happens to be reachable.

## One property undoes most of that

`VerificationReport::$store` is a `?ManifestStore`, and `ManifestStore`
exposes `$manifests` and `$active`, which are `Manifest` objects, which
expose their claim, their assertions, their signature — the entire parsed
model, through one property on the report. Draw the contract at 99 symbols
and that property quietly re-admits the `Manifest`, `Jumbf` and `Cbor`
layers to it.

That is the decision this step exists to surface. Either `$store` is part
of the promise — and then the parse model is frozen too, which is a real
cost on a library still gaining formats — or it is an escape hatch marked
`@internal`, useful and unsupported, which is what it has been used as so
far: the tests reach through it, and the README never mentions it.

## The eight exception types

`Asn1Exception`, `CborException`, `ContainerException`, `CoseException`,
`JumbfException`, `ManifestException`, `TimestampException`,
`TrustException`. SPEC-013 turns every one of them into a status in the
report before the public boundary, so a caller has no reason to catch
seven of them; only `TrustException` escapes, from `TrustSettings::fromJson()`
on settings that are not settings, and the CLI catches exactly that one.
Whether the other seven are part of the promise is the same question as
`$store`, in a smaller shape.

## What is not decided here

Nothing was changed. Three things follow, and they belong together in one
spec rather than as scattered edits:

1. **Name the contract** — a list, in the README and enforced by nothing
   else, of the classes and members a caller may build on.
2. **Mark the rest `@internal`** — a docblock tag, honoured by PHPStan and
   by every IDE, which costs nothing and says plainly "this may change".
   It is documentation, not enforcement; that is the right weight here.
3. **A snapshot test** — the public surface written down as a golden list,
   so that adding a public symbol is a deliberate act with a diff, the way
   `.gitattributes` made adding a top-level directory deliberate in
   SPEC-023. This is the part that keeps the answer true a year from now.

And one question that is the maintainer's alone: whether a first tag is
`0.1.0` — no promise, the API may move — or `1.0.0`. The measurements above
argue for the former until the contract has survived a format it did not
have when it was drawn.
