# ADR-0005: Stricter than `c2patool` only where the strictness protects a verdict

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-09-25                     |
| Decided  | Maurice van Loon               |

## Context

Two rules of this project pull in opposite directions.

- **The verdict means what `c2patool`'s means.** A verifier with its own
  vocabulary is a second truth. The status codes, the states and the
  drift alarms against `c2patool` all rest on this.
- **Fail closed.** A wrong `Valid` is the one outcome that really costs
  something, so an unknown case is refused, never guessed.

`docs/comparison.md` already allowed this verifier to be stricter than
`c2patool` *"only for a named reason"*. What counts as a reason was never
written down, and step 143 showed that it matters. The Bing Image Creator
files write their JUMBF URIs as `self#jumbf=c2pa/…`, without the leading
slash:

- C2PA 2.4 §8.4.2.1 reads that as relative to the manifest, and §15.7
  then makes the signature `claimSignature.missing`;
- the spec's own examples wrote exactly this form from 1.0 to 2.1;
- `c2pa-rs` treats it as absolute.

Step 143 recorded the strict reading as a difference *by design*, with
the normative text as the reason. That was a reason, but it protected
nothing. What the URI means is unambiguous: its label must still be the
claim's own manifest, and everything it leads to is checked afterwards.
The only effect of the strictness is an `Invalid` that `c2patool` and the
spec's authors would not give.

A false `Invalid` is not harmless. A verifier that often says "invalid"
for no reason teaches its users to ignore "invalid", and then the true
refusals lose their force too.

## Decision

**This verifier is stricter than `c2patool` only where the strictness
prevents a wrong `Valid`, or trust in something that was not checked.**
Everywhere else it follows `c2pa-rs`'s reading, including where that
reading is more lenient than the normative text of the C2PA
specification.

Concretely, a difference may be recorded as *by design* in
`docs/comparison.md` only when its row names what the strictness
prevents. Examples that pass this test:

- A timestamp authority is trusted only through configured anchors. The
  alternative is trust by observation (ADR-0004).
- A CAWG identity assertion is refused until its credential is validated.
  The alternative is a verdict about a credential nobody examined.
- A malformed structure `c2patool` skips over is refused. The alternative
  is a verdict about bytes that were not read as the spec defines them.

A difference that fails the test is a gap, not a design. It belongs under
*"Where `c2patool` can do more"*, with the spec that would close it or
the reason it waits.

Being stricter than the spec's *text* is never needed to be safe. Being
more lenient than it is allowed when `c2pa-rs` is, and when nothing a
verdict depends on goes unchecked.

## Consequences

- The Bing URI row in `docs/comparison.md` moves from *"differs by
  design"* to *"`c2patool` can do more"*. It is deferred, not declined.
  Reading the URI changes no verdict while the files' hard binding,
  `c2pa.hash.boxes`, is refused by name (`PRED-CONT-006`). SPEC-042, the
  URI reading, is the first step if box hashes are ever built.
- SPEC-041 (a first APP11 piece with Z = 0) was already a case of
  following `c2pa-rs` where the norm is probably stricter. It passes this
  ADR as written.
- **The existing *by design* rows have not yet been checked against this
  test.** Each needs its protection named, or it moves. That review is a
  step of its own.
- The introduction of `docs/comparison.md` points here for what a
  *"named reason"* must be.

## Alternatives rejected

- **Follow the normative text wherever it is clear.** That keeps the
  verifier defensible on paper. It also makes it disagree with the
  reference implementation, and with the specification's own examples, on
  files that are not wrong in any way that matters. The users of this
  verifier compare it with `c2patool`, not with the text.
- **Follow `c2patool` everywhere.** That gives up the project's reason to
  exist. Several `c2pa-rs` leniencies do protect nothing, and this
  verifier refuses them on purpose. ADR-0004 already said it: *"copying
  an unexplained leniency is trust by observation"*.
