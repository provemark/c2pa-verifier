# Step 428 — A claim `alg` outside §13.1 that nothing falls back on

*2026-10-25.* The second question of group A (step 426): a claim whose
`alg` is not one of C2PA 2.4 §13.1's, while every hashed-URI entry and
the hard binding name their own algorithm. §10.2.2 says the claim's `alg`,
when present, is one of the §13.1 algorithms. This verifier uses the
claim's `alg` only as a fallback, so it never looks at it here. Would
`c2patool` refuse the file?

## What was measured

`bin/make-claim-alg-variants.php` gained a fourth probe,
`entries-alg-claim-sha224.mp4`: `entries-alg.mp4` (step 427) with
`alg: sha224` added to the claim. SHA-224 is a real algorithm that §13.1
does not list. The 11 bytes come off the `instanceID`, now 23 characters
long, so its length moves into the initial byte and the claim keeps its
length. The script was run again, so all four probes have new throw-away
keys; the step 427 answers did not change.

| file | `c2patool` 0.28.1 | `c2patool` 0.28.3 | here |
|---|---|---|---|
| `entries-alg-claim-sha224.mp4` | `Trusted` | `Trusted` | `Trusted` |

Equal. Where something does fall back on such a claim `alg`, both say
`Invalid` already (`binding/claim-alg-sha1.png`, only the code differs:
`docs/comparison.md`).

## What changed

No code. A second test under SPEC-027 AC10 pins the equality:
`ClaimAlgTest`, *"a claim alg outside §13.1 that no entry and no binding
falls back on is not judged, as in c2patool"*. It pins behaviour that
already held, so it was green at once. To show it can fail, `assertionOf()`
was changed for one run to prefer the claim's algorithm over the
assertion's: the test went red (`Invalid`), and the line was put back.

The §10.2.2 row moves from *partial* to *by design (equal to `c2patool`,
measured)*. The letter is stricter than both implementations; refusing the
file would make this verifier differ from `c2patool` without preventing a
wrong `Valid`, since every byte is hashed with an algorithm the signer
named.

## Measured and reasoned

- Measured: the probe with both `c2patool` versions and this verifier; the
  mutation run; `composer check`.
- Reasoned: that the claim's `alg` matters only where something falls
  back on it.
