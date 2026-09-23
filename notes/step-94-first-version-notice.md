# Step 94 — Saying, at the top, that nobody has used this

*2026-09-23.* The maintainer's decision, in his words: he may make the
repository public, but not quite yet, and when he does he wants it said
very clearly that this is a first version and that people who want to work
with it will have to test it themselves.

Nothing about visibility changed in this step. What changed is the text
that has to be ready before that button is pressed.

## Where the warning was, and why that was not enough

The repository already carried a caveat:

> Nothing is published to Packagist yet and there is no tagged release.
> The public API below may still move.

Line 48, well below the fold, and about the *API*. It answers "will this
break when I upgrade?" and says nothing about the question a first reader
actually has, which is "has anyone run this?"

The notice now sits directly under the one-line description, before the
paragraph that describes what the verifier does — before anything that
reads like a promise.

## The sentence the whole notice turns on

> This code is thoroughly tested and has never been used. Those are two
> different things and both are true.

Both halves matter and neither may swallow the other. 421 tests, three
independent implementations compared, a mutation score of 98.06 %, 111
named obligations walked one by one — that is real work and hiding it
behind modesty would be its own kind of dishonesty. What is missing is not
rigour, it is **use**: no one has pointed this at their own files, their
own trust list, or their own hosting.

A warning that does not draw that line reads either as false modesty or as
a legal disclaimer, and neither helps a reader decide anything.

## What it asks for, and why that specific thing

The notice asks for one thing: run `c2patool` on the same file with the
same settings, and report where the two verdicts differ. That is the only
measurement this project genuinely cannot make for itself — every oracle
here runs on fixtures chosen by the same person who wrote the code.

A new section, *Trying it, and what to send back*, makes it concrete: run
both, send the difference rather than the file, and say what the host is.
The third point is the one that matters most and is easiest to forget —
**this library exists for hosts nobody tests on**, so a report from cheap
shared hosting is worth more than one from a laptop.

It also says which divergences are already known and deliberate, so that a
reporter does not spend an evening on something `docs/comparison.md`
already explains: the timestamp authority this verifier will not trust
without a configured anchor, and the revocation line it prints on every
file where `c2patool` prints none.

## A correction to earlier advice

Step 88's advice for a first tag was `0.2.0`. With this framing that is
wrong: `0.2.0` implies a `0.1` that people used, and no such thing exists.
**`0.1.0`**, whenever the maintainer decides to tag, says what is true. The
README's caveat now says a first tag will be a `0.x` and why.

Also corrected: the Status section still said "the 22 gaps", a number that
stopped being right when SPEC-030 closed five of them in step 92b. It is
17.

## What was deliberately not done

No visibility change, no tag, no Packagist entry, no announcement. The
repository stays private until the maintainer says otherwise; this step
only makes sure the first paragraph a stranger reads is an honest one.
