# Step 71 — A third implementation, and a checklist of 237 rules

*2026-09-22.* The brief named `encypherai/c2pa-conformance-suite` as an
oracle to assess. Step 43 looked at it from the outside and judged it "possibly
a second *tool* to compare with later, not a corpus", with one detail
wrong. This step ran it.

## Two corrections to step 43

**It has a licence: Apache-2.0**, with a NOTICE naming Encypher
Corporation. Step 43's table says "no licence declared", which was either
wrong when written or overtaken by the repository; either way the note was
acted on as if reuse were unclear. It is not: reading, running and
comparing are all plainly allowed, and so is reuse with attribution.

**It is not merely a rubric — it is a verifier.** Its pipeline is its own
container extractor, its own JUMBF and CBOR parser, its own predicate
evaluator and its own crypto verification, in Python. That makes it a
**third independent implementation**, after c2pa-rs (through c2patool) and
the Go verifier of step 61.

## What it says about our files, and why we do not believe it

Run without a trust store on six corpus files:

| file | its signature verdict | pass / fail / skip |
|---|---|---|
| `fixture-signed.png` | `claimSignature.validated` | 23 / 2 / 121 |
| `fixture-signed.webp` | `claimSignature.validated` | 23 / 2 / 121 |
| `public-testfiles/adobe-20220124-C.jpg` | `claimSignature.validated` | 24 / 4 / 117 |
| `writers/openai-20260826-c2pa_2x.png` | `claimSignature.validated` | 26 / 2 / 117 |
| **`fixture-signed.jpg`** | **`claimSignature.missing`** | 16 / 3 / 128 |
| **`c2pa-rs/CA.jpg`** | **`claimSignature.missing`** | 16 / 3 / 128 |

On `fixture-signed.jpg` it reports `assertion.dataHash.mismatch` and
`claimSignature.mismatch` for a file that **c2patool 0.27.22, this verifier
and the Go implementation all accept**. Three independent readings against
one.

The decisive measurement is the extraction, because everything downstream
depends on it:

```
ours:   94740 bytes, sha256 f47af93e8afe0f71…
theirs: 94740 bytes
```

Byte counts agree, so the store is not where they diverge — and two of
their own container paths (PNG, WebP) validate the same claims happily.
The fault is container-specific and on their side of the line. It is not
worth chasing further here: their failure messages come back empty, which
makes their JPEG path a debugging job for its authors rather than a
question about ours.

What this does *not* give us is a third opinion on JPEG. Their own coverage
on these files is thin in any case: 128 of 150 predicates were skipped on
our JPEG, 121 on the PNG.

## The part that is worth the whole step

The suite carries a catalogue: **150 predicates formalising 237 normative
rules of C2PA 2.4**, each with a title, a spec section, a severity and a
condition. That is a checklist of named obligations, and it does not depend
on their crypto being right.

Of the 150, **101 apply to the containers this verifier reads** — 97
cross-cutting plus 4 image-specific:

| group | count | what it covers |
|---|---|---|
| ASSE | 27 | assertions: hashed URIs, redaction, ingredient fields, action rules |
| CRYP | 25 | signatures, certificates, algorithms |
| STRU | 19 | structure, claim fields, OCSP and revocation |
| INGR | 8 | ingredients |
| CONT | 7 | container |
| CROSS | 6 | cross-format |
| IMG | 4 | the data hash for images |
| TIME | 4 | timestamps |
| TRUS | 1 | trust |

Reading the ASSE and STRU titles against what this verifier does turns up
obligations we have never named, among them:

- `PRED-ASSE-009` self-redaction prohibition
- `PRED-ASSE-023` inception action position
- `PRED-ASSE-024` `reviewRatings` absent when `dataSource` is human entry
- `PRED-ASSE-025` mandatory `digitalSourceType` for editorial and created actions
- `PRED-ASSE-027` forbidden labels in external references
- `PRED-STRU-004` stop the manifest search after the first store
- `PRED-STRU-008` claim required fields and `claim_generator_info` name presence
- `PRED-STRU-019` JPEG APP11 exclusion range total length matches the store length
- `PRED-STRU-010` … `016` OCSP and revocation — which this project refuses on purpose, having no network

Some of those we satisfy by refusing something wholesale, some we do not
check at all, and telling the two apart is a step of its own. The catalogue
is the thing: **a list of 237 obligations, named and sectioned, against
which this verifier can be measured instead of measured against one
oracle's verdict.**

## And one finding in our own code, found by reading their list

`PRED-ASSE-009` is about redaction, so the redaction path was read. This is
in `HashedUriCheck`, and it is what a user is told today:

> the claim declares N redacted_assertions; redactions (C2PA 2.4 §6.7) are
> **not supported before M7**, and a claim that says "redacted" is not
> passed on trust

M7 was completed in step 57. The message points a user at a milestone that
has already passed, and the docblock above it says the same. The refusal
itself is defensible — refusing a redaction is failing closed — but the
reason given is no longer true, and a message that dates itself is worse
than one that simply states the rule.

Worse, it raises a question the code does not answer: `ManifestGraph` has a
`$redactedAssertions` field, added with SPEC-020, and a claim declaring
redactions is refused before the graph is walked. Either that field is
unreachable in practice, or the two paths disagree about what a redaction
means. Nothing was changed here; it is named for the maintainer.

## What was not done

Nothing in `src/` changed. The suite was run from a clone in a scratch
directory and is not vendored: it is Apache-2.0, so vendoring would be
allowed, but a second implementation is worth more as something we *run*
than as something we carry.
