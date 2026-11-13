# Step 531 — SPEC-042 built: a slash-less JUMBF URI read from the store's root

*2026-11-12.* Maurice:
- "schrijf de failing tests voor SPEC-042";
- "akkoord met amendement 4, implementeer SPEC-042";
- SPEC-007 amendment 12, agreed when the conflict came up.

## What changed

- **`src/Manifest/Manifest.php`.** One private helper, `normalized()`. It
  reads `self#jumbf=c2pa/…` as `self#jumbf=/c2pa/…`, as `c2pa-rs`'s
  `to_normalized_uri` does. Three sites use it:
  - `resolve()` (error messages keep the URI as written);
  - `absoluteUri()`;
  - SPEC-040's `assertion.outsideManifest` check in `checkReferences()`.

  Nothing after resolution changed.
- **Fixtures.** `bin/make-spec042-variants.php` builds them by SPEC-040's
  route: the claim's URIs are rewritten, the claim is re-signed under a
  throwaway root, and the COSE is padded so the data hash still holds.
  - `tests/Fixtures/slashless/` holds four variants: `resigned` (control),
    `all-slashless`, `outside-manifest`, `signature-other-manifest`.
  - The answers of `c2patool` 0.28.6 and 0.27.22 are under
    `tests/Fixtures/c2patool/slashless/`.
- **Tests.** `tests/Unit/Manifest/SlashlessUriTest.php`, AC1–AC7.
- **SPEC-041 amendment 4** (weight C). AC1 no longer asks for no
  `general.error` at all, but for none from the container. The Bing file
  now reaches its box hash, refused by name.
- **SPEC-007 amendment 12** (weight B). The scope sentence and step 441's
  assertion in `ManifestStoreTest` AC4 had pinned the slash-less form as
  relative. Now such a URI resolves to the same box as its absolute form,
  and a slash-less path not starting with `c2pa/` stays relative.
- **Docs.**
  - `docs/comparison.md`: the Bing URI row has left *"Where `c2patool` can
    do more"*. A paragraph under *"Same verdict, different informational
    code"* covers the status url: absolute here, as written there (open
    question 1). The box-hash row now names the Bing file as reaching the
    refusal.
  - `docs/reading-c2pa-2.4.md` §8.4.2.1.
  - ADR-0005's consequence.

## Measured

### Oracles

Both `c2patool` versions, with the throwaway root as anchor:

| variant | 0.28.6 and 0.27.22 |
|---|---|
| `resigned` | `Trusted` |
| `all-slashless` | `Trusted`; hashed-URI urls as written |
| `outside-manifest` | `assertion.outsideManifest` on the url as written, then the abort line |
| `signature-other-manifest` | `claimSignature.missing` |

### OpenSSL 4

On the first build every variant was `Invalid` in both `c2patool`
versions: *"certificate params incorrect"*. OpenSSL 4.0.3 no longer adds
the subject and authority key identifiers to a leaf made by `x509 -req
-extfile`, and `c2pa-rs` refuses a leaf without them. The script now
spells both out.

The other `bin/make-spec0xx-variants.php` scripts are unchanged. They
would hit the same wall if rerun on this machine.

### Red before green

Before `src/` changed:
- six SPEC-042 tests were red, each for its own reason;
- AC7, a guard, was green from the start.

AC4 also asserts the reason ("refers to another manifest"), because the
code alone was already `claimSignature.missing` before the change.

After the change:
- SPEC-042 7/7 green;
- the whole suite showed two other reds, SPEC-041 AC1 and SPEC-007 AC4
  (step 441). Both are now amended, as above.

### Mutations

Each was run against the full suite, and each was caught:

| mutation | red |
|---|---|
| M1: `resolve()` without `normalized()` | 7 tests (SPEC-042 AC1, AC2, AC4, AC5, AC6; SPEC-041 AC1; SPEC-007 AC4) |
| M2: `absoluteUri()` without it | SPEC-042 AC6 |
| M3: SPEC-040's check without it | SPEC-042 AC3 |
| M4: the prefix widened to `c2pa` without the slash | 888 tests |

### Corpus, before and after

Both sides ran:
- the worktree at `ba95785`, with `vendor/` copied (each side loaded its
  own `src/`, checked);
- this tree.

Each read the current `tests/Fixtures/`:
- 1,078 files;
- three trust configurations (none, `trust/full.settings.json`, the
  conformance lists through `fromTrustLists()`), each without and with a
  revocation source answering null;
- validation time 2026-11-13T00:00:00Z.

6,468 runs per side, 0 exceptions.

- **Output** differs in 30 runs, five files, six runs each:
  - the Bing file;
  - `c2pa-rs/prerelease.jpg`;
  - the three slash-less variants.
- **Verdicts** move only for `slashless/all-slashless.png`: `Invalid` →
  `Valid` in all six configurations, the same verdict as its control.
  Without the throwaway root it is untrusted.

The first run used 2026-11-12T12:00:00Z. That was before the throwaway
certificates' notBefore (21:01 UTC that day), so every new variant was
`Invalid` on both sides. Step 528's lesson again: a validation time before
a fixture's certificates hides what moves.

### The rest of `composer check`

- `pest --parallel`: 1,726 passed, 1 skipped;
- phpstan: no errors;
- pint: passed;
- `bin/spec-check.php`: 90 specs, SPEC-042 implemented;
- `bin/api-check.php`: the recorded surface matches;
- deptrac: 0 errors.

## Not covered

These sites read a manifest label from a URI with a `/c2pa/` pattern of
their own and do not go through `normalized()`:
- `ManifestStore` (ingredient manifest references);
- `ActionsCheck` (an ingredient reference "elsewhere", a redaction
  target);
- `IngredientManifestCheck`, which already accepts `/?c2pa/` in two
  regexes.

A slash-less URI there is read as before. SPEC-042 named three sites, and
no corpus file carries such a URI in those places.

## Next

The box-hash spec (SPEC-042 open question 2). Before it, read how
`c2pa-rs` treats slash-less `redacted_assertions` entries (open question
3).
