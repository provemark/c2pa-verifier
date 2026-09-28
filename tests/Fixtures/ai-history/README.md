# AI origin in a parent ingredient (step 178)

`bin/make-ai-history-variants.php <scratch>` builds this store the same way
as step 60's `two-manifests` control (`../m7-absence/README.md`): the PNG
fixture's manifest box copied under a second label, placed first so the
active manifest is the last in the store (C2PA 2.4 §11.1.4.2), and named
by a v3 `parentOf` ingredient assertion in the active claim. The hard
binding is re-bound, and both claims are re-signed with a throw-away P-256
hierarchy. The keys stay outside the repository and are deleted at the end
of the run.

There is one change from the control. In the copy, the `c2pa.created`
action's `digitalSourceType` is
`http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
instead of the fixture's `algorithmicMedia`, with the actions box and its
hashed URI in the copy's claim recomputed. The active manifest keeps the
fixture's own actions. So the file says it was made by generative AI only
in the manifest it was made from, which is where two real files put it
(measured 2026-09-28, not copied here: their licence is unknown).

| variant | c2patool 0.27.22 | here |
|---|---|---|
| `parent-chain` | `Trusted` with `both-roots.settings.json`; `Valid` without, the ingredient delta `signingCredential.untrusted` | the same |

`both-roots.settings.json` carries the throw-away root and the fixture's
own anchors. Without settings, the ingredient's recorded validation
results are empty, and only the verifier's delta says its signer is
unknown. A reader that trusts the recorded results instead of the delta
gets a different answer. c2patool's JSON is in
`../c2patool/ai-history/`; the test is
`tests/Unit/Verifier/AiHistoryTest.php`.
