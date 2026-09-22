# Step 58 — The amendments since step 51, for the maintainer's confirmation

*2026-09-22.* The spec template allows an approved spec to be amended
when a measurement made before or during its tests-first step shows the
criterion wrong; the amendment is written into the spec at once, so that
the tests are never green against a text they contradict. Step 51 put the
first fifty-one on one page and Maurice van Loon confirmed all three
groups. **Seventeen** have been written since, across the CLI (SPEC-019)
and M7 (SPEC-020, SPEC-021, SPEC-022, with consequences in SPEC-005,
SPEC-007, SPEC-013 and SPEC-018). This page is where they become his
decisions too. One line per amendment — what, why — sorted by weight; the
last column is for his word.

Legend for **weight**: **A** = a rule of the verifier changed (what is
`Valid`, `Invalid`, `Trusted`, which code, or what is read at all);
**B** = the report's shape or the API changed, verdicts unchanged;
**C** = a test literal, a count, a message, a seam, or a layer line —
nothing a user of the verifier could notice.

## A — rules of the verifier

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-013 | 11 | **A store with more than one manifest is no longer refused.** Amendment 5's "`Invalid` until M7" is lifted: the manifests an ingredient assertion names are validated, so a fault in one is reported rather than refused unseen | SPEC-021; seventeen corpus files are measured now, sixteen with c2patool's verdict exactly | |
| SPEC-005 | 1 | An **update manifest (`c2um`) is read** like a `c2ma` box; **`c2tm`** (the deprecated time-stamp manifest) takes its place among the refusals, beside `c2cm` and `brob` | SPEC-022 validates one; §11.2.5 says a `c2tm` is "not to be … read by manifest consumers" | |
| SPEC-018 | 3 | The opening rule (a 2.x manifest opens with `c2pa.created` or `c2pa.opened`) **does not apply to an update manifest** | this spec's own Problem section said so; the code could not tell, because `c2um` was refused when it was written. Measured: without the exemption, SPEC-022's variant reports a code c2patool does not | |
| SPEC-022 | 2 | A **hash assertion in an update manifest** is `manifest.update.invalid` — **stricter than c2patool**, which calls the same file `Trusted` | C2PA 2.4 §11.2.3 forbids it in as many words; c2pa-rs's rule for it sits in a branch that can never run (`claim.rs verify_internal`), so c2patool validates the assertion as the asset's binding instead. Named in `docs/comparison.md` | |
| SPEC-022 | 4 | An **empty `claim_generator_info`** (`[]`) is read as a field that is there and says nothing, not refused as malformed — and rendered as `[]` | c2patool renders it so for `update_manifest.jpg`'s parent; a `null` one stays absent (SPEC-007 amendment 4) | |
| SPEC-021 | 4 (= SPEC-022 #5) | The set of statuses an ingredient assertion's record silences is the **whole store's**, not one assertion's, and it covers the graph's statuses too | a v3 assertion records the whole tree it validated: `update_manifest.jpg`'s active assertion carries the *parent's* two `ingredient.unknownProvenance` entries, and c2patool drops both — one delta where this verifier had three. The guard is unchanged: nothing about the active manifest is ever dropped | |

## B — the report's shape, the API

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-007 | 5 | `claim_generator_info` is rendered through `ManifestStore::plain()` like every other value (bytes as base64) | `toJson()` threw `JsonException` on OpenAI's file, whose generator carries an icon with a 32-byte hash — an exception escaping the public API, unseen because the writers alarm compares states and codes, not the rendering | |
| SPEC-020 | 2 (part) | The `ingredients` rendering follows the files: `manifest_data` only when the referenced label is in the store; an ingredient's `thumbnail` identifier is printed where the thumbnail lives (the ingredient's own manifest when the URI is absolute, the referring one when it is relative) | c2patool prints exactly that (`E-clm-CAICAI`, `CACAE-uri-CA`) | |

## C — literals, counts, seams, layers

| spec | # | what | confirmed |
|---|---|---|---|
| SPEC-019 | 1 | the `Cli` Deptrac layer also names `Report` (`ValidationState` for the exit status); the API sketch had three layers | |
| SPEC-020 | 1 | the two corpus files that declare their manifest by URL carry **no store**, so they cannot be compared: AC3's list is fifteen files, and AC1's v3 examples come from `c2pa-rs/CACA` and `adobe-20220124-CAI` | |
| SPEC-020 | 2 (rest) | c2pa-rs writes `alg: sha256` on its ingredient reference (AC1 said none); the two `E-clm` files keep one manifest the walk never reaches, so `unreferenced` is not empty for them | |
| SPEC-020 | 3 | c2patool's delta list is a **subsequence** of the walk (it drops what an assertion recorded), and `ManifestGraph::$walk` is a public field the API sketch did not name | |
| SPEC-021 | 1 | AC2's "one scope" holds for the statuses that spec adds; the report has a second delta because the ingredient manifest has an ingredient of its own | |
| SPEC-021 | 2 | `checks_performed` holds `ingredients` only where the graph actually reached a manifest (`E-clm-CAICAI` names one that is not in the store) | |
| SPEC-021 | 3 | five criteria of earlier specs changed with it: SPEC-013 AC11 and AC12, SPEC-017's test helper (the active manifest's statuses only), the enum count, SPEC-020 AC6 | |
| SPEC-022 | 1 | AC4's fourth rule is tested at the seam and AC4(c) uses `inputTo` rather than `componentOf`: this update manifest's claim uses **indefinite-length CBOR**, which the byte-level tooling does not write | |
| SPEC-022 | 3 | (the same fact as SPEC-018 #3, seen from this spec) | |
| SPEC-022 | 5 | (the same fact as SPEC-021 #4, seen from this spec) | |

## Two things worth a second look before confirming

1. **SPEC-022 #2 is the first place this verifier is stricter than
   c2patool on a rule c2patool *has* but cannot reach.** The earlier
   "stricter" entries were all cases where c2patool has no rule at all.
   If you would rather match c2patool here — accept a hash assertion in
   an update manifest and validate it as the binding — say so: it is a
   two-line change and a row in `docs/comparison.md`.
2. **SPEC-013 #11 is the amendment that changes the most verdicts**:
   eighteen files move from a refusal to a real answer. Twelve become
   `Trusted`, four stay `Invalid` for c2patool's own reasons, and two
   (`ocsp`, `ocsp_with_assertion`) stay `Invalid` for the TSA leniency
   ADR-0004 already named.

## How to confirm (the procedure, as in step 51)

Per group, or per line: "bevestigd" (all of A, B, C), or a question or a
"no" naming the spec and number. A "no" on an A-line reverses a rule and
gets its own step: the spec text back, a test that shows the reversed
rule, and the note. Confirmations are dated on this page and, for group
A, in the spec's amendment line itself.
