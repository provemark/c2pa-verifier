# Step 158 — Only a manifest the graph reaches may redact

*2026-09-27. SPEC-035 amendment 5, criterion AC9. Finding 1 of step 157.*

## The problem

A claim may redact an assertion of an ingredient's manifest (C2PA 2.4
§6.8). The box is removed or zeroed, and the redacting claim lists the
assertion's URI in `redacted_assertions`. SPEC-035 collected that list from
**every** claim in the store (its scope item 1), and step 129 built it so.

That includes manifests that nothing references. The ingredient graph never
reaches such a manifest, so it is never validated: its signature is not
checked and it may be unsigned. Its redactions still counted, and they were
applied to the active manifest too. An unsigned manifest of a few hundred
bytes could therefore declare a signed assertion of the active manifest
redacted, and the box could be taken out. For a v1 claim, which needs no
actions assertion, that could include the actions and the generative-AI
`digitalSourceType` in them (reasoned, not measured).

## Measured before the change

`bin/make-redaction-scope-variants.php` takes `fixture-signed.png` and
removes its `c2pa.thumbnail.claim` box (32,470 bytes) from the active
manifest's assertion store. In front of the active manifest it puts an
unsigned manifest of exactly that length that nothing references. The active
claim and signature are untouched and the store keeps its length, so the
claim signature and the data hash still match. Two variants:

| variant | unreferenced `redacted_assertions` | this verifier before | c2patool 0.27.22 and 0.28.0 |
|---|---|---|---|
| `unreferenced-redacts-thumbnail` | the thumbnail | **`Trusted`** | `Invalid`, `assertion.missing` |
| `unreferenced-no-redaction` | empty | `Invalid`, `assertion.missing` | `Invalid`, `assertion.missing` |

All four oracle runs were made with `--settings tests/Fixtures/trust/full.settings.json`,
under which the untouched fixture is `Trusted` in both versions and here.

The first probe of step 157 gave `Error: unknown algorithm` in both
`c2patool` versions. That came from its claim, which had no `alg` field
(as in step 24), not from the redaction. The fixture's unreferenced claim
now has `alg: sha256`, so both versions read it and report on it.

## What c2pa-rs does

Read in `c2pa` `sdk/src/store.rs` (`main`, fetched 2026-09-27):
`get_claim_referenced_manifests_impl()` adds a claim's `redactions()` to
the validation's list when it visits that claim. It visits the active claim,
and then each claim reached through its ingredient assertions. A claim the
walk never visits adds nothing.

## The change

`ManifestStore::fromTree()` still reads every manifest before it checks any
references. Before it collects the redactions, it now builds SPEC-020's
graph over the manifests it has read. Only the active manifest and the
manifests the graph reaches (`redacting()`) contribute. If the graph cannot
be built (a bound is exceeded), only the active manifest counts. The
verifier then reports the graph's own error, so the file is `Invalid`
anyway.

Considered and left out: a rule that no manifest may redact the active one.
The active claim must reference a manifest for it to be reached, and whoever
signs the active claim already decides which assertions it lists. Such a
rule would stop no wrong `Valid`, and ADR-0005 does not allow being stricter
than `c2patool` for nothing. The active claim's own self-redaction stays
`assertion.selfRedacted` (AC4).

## Measured after

- `vendor/bin/pest --group=SPEC-035`: before the change 1 failed, 9 passed.
  The failure was AC9: `unreferenced-redacts-thumbnail` was `'Trusted'`
  where `'Invalid'` was expected. After the change, 10 passed.
- `composer check`: exit 0, 531 passed.
- **All 394 media fixtures under no settings and under every readable
  settings file in `tests/Fixtures/` (51 in all), 20,094 runs, before and
  after.** Only `redaction-scope/unreferenced-redacts-thumbnail.png`
  changed: 16 `Trusted` and 35 `Valid` became `Invalid`. No other file
  changed state or status list. Three settings files are refused by
  `TrustSettings` by design (SPEC-031) and give no runs either time.
  The runner is a scratch script outside the repository. It compares state
  and an MD5 of each status list.

**Weight A:** a file that was wrongly `Valid` or `Trusted` becomes
`Invalid`. Present in every release with SPEC-035, 0.2.1 to 0.2.3; before it a store with a redaction was refused.

## Decided with this step

Finding 2 of step 157, a time-stamping certificate that signs manifests
under the legacy `trust.trust_anchors`: the maintainer chose to refuse such
a signer and to document it as a deliberate difference from `c2pa-rs`
(ADR-0005: it prevents unchecked trust). That is the next step.

## Disclosure

The fix is local until the other findings of step 157 are fixed too.
They go out together as a security release. Pushing a probe or a test
before then would publish the flaw.
