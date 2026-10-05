# Step 217 — The second review's code-only findings fixed

*2026-10-05.*

A second review ran over `840d992..HEAD`, the RIFF walk of steps 214–216.
It found no input that yields a wrong `Valid` or `Trusted` and no exception
that escapes. Its findings were measured with both `c2patool` versions on
the reviewer's seven files; every measurement held. They split in two:

- **A — behaviour, needing an amendment** (findings 1–4: a header size
  larger than the file, a short tail or an overrunning chunk outside the
  store, a chunk that swallows the store, a header size below 4). Maurice
  decided on the advice the same day; they are the next steps.
- **B — code and text only** (findings 5–10), fixed here.

## Fixed

- **Finding 5.** `StreamReader::end()` fails on a stream that cannot seek,
  before any chunk is read, and that fault carried the default
  `storeReached: true`. It now says `false`. A new test (SPEC-003 AC18)
  builds such a stream with a small stream wrapper. Seen **red** (*Failed
  asserting that true is false*, with the message *cannot seek*), then
  green.
- **Finding 6.** The RIFF extractor's docblock described the strict walk of
  before amendment 3. It now describes strict-about-the-store,
  lenient-about-the-rest, and `storeReached`.
- **Finding 7.** SPEC-003's Traceability named AC5's and AC16's tests by
  their old names; AC12 and AC18 now name their new tests too.
- **Finding 8.** The rewrap of a fault to set `storeReached` stays, in one
  place, with a comment saying why: `StreamReader`'s faults inside the walk
  carry the default flag. A helper that throws with the flag directly
  would still need this for them.
- **Finding 9** (two messages for "the RIFF chunk ends inside a chunk") is
  left for the next steps, where that code changes for finding 2.
- **Finding 10.** `ContainerException`'s constructor takes
  `RuntimeException`'s parameters in their order (`$message`, `$code`,
  `$previous`), with `$storeReached` last, so a positional
  `new ContainerException($msg, 0, $prev)` works as it always did.

## Measured

`composer check`: exit 0, 679 tests. The corpus (1,268 runs) is
**identical** to step 216's.
