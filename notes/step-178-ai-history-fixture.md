# Step 178 — a fixture with AI origin in a parent ingredient

## Why

The WordPress plugin built on this verifier labels images that a verified
manifest says were made by generative AI. On 2026-09-28, two real files
showed that the label cannot stop at the active manifest. One was an AI
image that a second service watermarked and converted. Its
`trainedAlgorithmicMedia` statement sits two `parentOf` steps down, and
the active manifest only says `composite`. The files are not in any
repository, because their licence is unknown. So the plugin needed a file
it may ship that has the same shape.

## What

`bin/make-ai-history-variants.php` makes
`tests/Fixtures/ai-history/parent-chain.png`. It follows step 60's
`two-manifests` recipe and changes one thing: the copy's `c2pa.created`
action says `trainedAlgorithmicMedia`. The actions box and its hashed URI
are recomputed, and both claims are re-signed with a throw-away key. The
key was deleted at the end of the run ("keys deleted" in the script's
output).

## Measured

| | c2patool 0.27.22 | this verifier |
|---|---|---|
| with `both-roots.settings.json` | `Trusted` | `Trusted` |
| without settings | `Valid`, ingredient delta `signingCredential.untrusted` | the same |

A second finding, which matters to callers: an ingredient's
`validation_results` in `toArray()` hold what the ingredient assertion's
signer **recorded**. In this file that is empty, because the script
records empty lists, as step 60's did. What the verifier found itself is
in `validation_results.ingredientDeltas`. Without settings the two differ:
the recorded failures are empty, and the delta says
`signingCredential.untrusted`. A caller deciding whether to trust an
ingredient must read the delta. The plugin's SPEC-027 amendment 2 does.

## Reasoned

That the active manifest may keep `c2pa.created` next to a `parentOf`
ingredient for this purpose, as step 60's control does. Both
implementations accept it. A stricter fixture would replace it with
`c2pa.opened`; nothing here depends on that.

## Tests

`tests/Unit/Verifier/AiHistoryTest.php`, grouped under SPEC-021. It checks
the file's two verdicts against the oracle and where the source types
sit. The tests passed at once: they pin existing behaviour on a new file
and add nothing to the verifier.
