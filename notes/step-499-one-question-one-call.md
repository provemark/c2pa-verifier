# Step 499 — One question, one call

*2026-10-28.* Finding 5 of the review of 0.9's code (`v0.8.3..HEAD`);
SPEC-086 amendment 6 and SPEC-087 amendment 2, approved by Maurice ("akkoord,
amendement approved, bouw 499"). No report changes.

## Why

The caller's revocation source (SPEC-086, SPEC-087) was asked once per
judged manifest: about each CA of the path, then about the signer. An
active manifest and its ingredient manifests signed under the same
certificates asked the same questions once each, and the Time-Stamping
second walk asked its CAs again. With a source that goes to the network,
each repeat is an outbound OCSP request, and a file can be built to repeat
it up to the graph's limit. Over the corpus, 70 of 448 calls repeated an
earlier one of the same run, in 23 runs; one file asked 12 times for 2
questions.

## What changed

- `Trust\RememberedRevocationSource` (internal): the caller's source,
  asked at most once for each subject, issuer and list of urls; a later
  call gets the first answer, the same bytes, the same null, or the same
  exception thrown again.
- `Verifier::check()` makes one per verification and hands it to the
  trust walk, the signer's revocation check and the ingredient checks.
  `Verifier` stays readonly; nothing is kept between two `verify()` calls.
- `RevocationSource`'s docblock says it. The contract keeps 211 symbols.

What the source is asked about, and when, does not change, nor what
becomes of the answer: each manifest judges it at its own time and lists it
in its own evidence as `fetched`.

## Measured

- Red first: AC10 of SPEC-086 and of SPEC-087 (now once per pair in a run),
  AC12 (`c2pa-rs/CACA.jpg`: 4 calls for 2 pairs; the CAIAII… file: 12) and
  AC13 (`revocation/cs-good.png`, a source whose first answer about the
  throw-away intermediate is null or an exception and whose second is the
  intermediate revoked: P's ingredient manifest came out
  `signingCredential.untrusted` on the second answer): 6 failed. Green
  after the build.
- Hand mutations against the SPEC-086 and SPEC-087 groups (run without
  `--parallel`, after a green baseline of 76 tests): not wrapping the
  source, asking every time, not remembering an exception, not throwing it
  again — 4 of 4 killed. Dropping the issuer from the key survived: the
  corpus never asks about one certificate under two issuers. A direct test
  of the class (another issuer, or other urls, is asked again) now kills
  it and the same mutation of the urls.
- The corpus against `69a7772`: 6,264 runs (1,044 files × none, full and
  lists, each without and with a source that records its calls and
  answers null). Every report identical (the hash of `toJson()`,
  `toCrJson()` and `evidenceToJson()`); calls 448 → 378, each run's calls
  exactly the first occurrences of its calls before.
- `composer check` exit 0 and `vendor/bin/pest --parallel` exit 0.
