# Reading C2PA 2.4 against the verifier

`docs/conformance.md` checks this verifier against a third party's catalogue
of C2PA 2.4 rules. This document goes to the source instead. Chapter by
chapter, it lists every normative sentence of the C2PA Technical
Specification 2.4 (April 2026, the newest published version on 2026-10-09)
that a validator has to meet. For each one it says where this verifier
meets it, and how that is known.

The text was read from
<https://spec.c2pa.org/specifications/specifications/2.4/specs/C2PA_Specification.html>
(SHA-256 of the page as fetched on 2026-10-09: `d55caebd…d26d`). Rules are
paraphrased; the section number is the reference.

**Why it is needed.** The fuzzer and the matrices test only rules someone
thought of. `c2patool` shows what `c2pa-rs` does, which is not always what
the specification says. The catalogue summarises 237 rules as 150
predicates. On 2026-10-09 a fault in the RSASSA-PSS parameters (SPEC-015
amendment 8) passed all three, although §14.5 states the rule.

## How to read the tables

| verdict | meaning |
|---|---|
| **covered** | the rule is enforced, or met by construction |
| **partial** | part of the rule is enforced; the row says which part is not |
| **by design** | this verifier does something else on purpose; the row names the decision |
| **n/a** | the rule addresses something this verifier does not have |
| **candidate** | not met, or not known to be met; listed under *Candidates* for a probe |

*How known* is **measured** (a test, a probe or a corpus run says so) or
**read** (from the code, not yet measured).

## Where the reading stands

Every chapter of C2PA 2.4 was read on 2026-10-09: §14 by hand in steps
308 and 309, the others in step 312. For step 312 the chapters were cut
into ten packs and drafted in parallel by AI agents (`AI-LOG.md`). Then they
were checked: every test, file and method the tables cite exists (334
references), ten covered rows drawn at random were re-read in the code,
and every candidate marked "possibly more lenient than `c2patool`" was
re-read by hand in this verifier and in `c2pa-rs` 0.91.1. Chapters 1 to
4, 12, 19 and 20 hold no rule for a validator of the formats read here.

| part | rules | covered | partial | by design | n/a | candidate |
|---|---|---|---|---|---|---|
| §5 to §9 | 41 | 19 | 3 | 4 | 14 | 1 |
| §10 to §11 | 55 | 35 | 6 | 4 | 8 | 2 |
| §13, §16, §17, Appendix C | 29 | 18 | 3 | 1 | 6 | 1 |
| §14 | 41 | 28 | 4 | 6 | 2 | 1 |
| §15.1 to §15.6 | 33 | 12 | 6 | 4 | 10 | 1 |
| §15.7 to §15.9 | 47 | 23 | 9 | 4 | 5 | 6 |
| §15.10 to the end of §15 | 124 | 77 | 16 | 13 | 9 | 9 |
| §18.1 to §18.9 | 50 | 23 | 4 | 5 | 11 | 7 |
| §18.10 to §18.16 | 58 | 19 | 10 | 3 | 12 | 14 |
| §18.17 to the end of §18 | 34 | 4 | 2 | 2 | 11 | 14 (and 1 other) |
| Appendix A | 50 | 22 | 2 | 7 | 13 | 6 |
| **all** | **562** | **280** | **65** | **53** | **101** | **62** |

The counts read the verdict column; a row marked "covered for the leaf"
counts as covered, and a candidate row can stand for a candidate that
several rows share.

### Candidates that may be more lenient than `c2patool`

Each was re-read by hand in this verifier and in `c2pa-rs` 0.91.1. Step
313 measured seven of them, and one action-field case, with probes judged
by both `c2patool` versions (see *Measured in step 313* below).

| # | what | here | `c2pa-rs` 0.91.1 | packs |
|---|---|---|---|---|
| L1 | a BMFF hash without `alg` | fell back to SHA-256; **fixed: SPEC-027 amendment 8** | the claim's `alg` (§13.1, §15.4.1) | P03-1, P07-2, P06-12 |
| L2 | a data hash without `pad` (§18.5.2 requires it) | accepted | `DataHash::pad` is required; the assertion cannot be decoded | P07-1 |
| L3 | two tokens in `tstTokens` | the first was judged and its time used; **fixed: SPEC-017 amendment 9** | `timeStamp.malformed`, the timestamp dropped (`sigtst.rs`) | P05-1 |
| L4 | a metadata assertion without `@context` in a version 2 claim | not read | `verify_metadata()` stops the validation | P09-1 |
| L5 | a malformed `c2pa.time-stamp` assertion | not read | `assertion.timestamp.malformed` | P09-2, P06-2 |
| L6 | a malformed `c2pa.certificate-status` assertion | not read | the validation stops | P09-3 |
| L7 | `c2pa.cloud-data` | not checked, though SPEC-039 says "refused by name" | `verify_cloud_data()`: decode, size, no hard binding, actions or ingredient | P08-1, P07-3, P06-1, P04-4 |
| L8 | a soft binding that cannot be decoded | not decoded | `verify_soft_binding_alg()` logs it | P08-2 |
| L9 | a manifest of type `c2md` (§11.2.2: consumers shall accept it) | an unknown box: mostly `claim.missing` (stricter), but in a store `[c2ma, c2md]` the older manifest is made active; **fixed: SPEC-007 amendment 7** | read as a standard manifest | P02-1 |
| L10 | two manifests with one label | `ManifestStore::fromTree()` keeps the first one's place and the later one's content, and makes `array_key_last()` active: `[X, Y, X']` validates `Y`; **fixed: SPEC-007 amendment 7** | the last box, `X'` | P01-1, P02-2, P04-1 |
| L11 | a version 2 manifest whose label is not a C2PA URN | not checked; **fixed: SPEC-007 amendment 7** | `claim.malformed` | P02-3 |
| L12 | a merkle map on a single, unfragmented file | no `count` read as 0, and 0 of 0 was a match; **fixed: SPEC-028 amendment 2** | refuses an `initHash` on unfragmented media (read by the pack) | P06-4 |
| L13 | a version 2 claim whose `claim_generator_info` is an empty map | taken as the empty list SPEC-007 amendment 4 allows for version 1; no `name` check; **fixed: SPEC-007 amendment 7** | `claim.malformed` | P04-2 |

### Measured in step 313

Each probe is a PNG signed by `c2patool` 0.28.1 with a throw-away
hierarchy. Most were then changed in a few bytes of the same length and
the claim signed again, because `c2patool` refuses to write these shapes.
The probes are in a scratch directory; they become fixtures with their
fix.

| probe | `c2patool` 0.28.1 | 0.27.22 | here | candidate |
|---|---|---|---|---|
| control, and control signed again | `Trusted` | `Trusted` | `Trusted` | — |
| data hash without `pad` | error: missing field `pad` | error | **`Trusted`** | L2, confirmed |
| metadata assertion without `@context` | error: could not decode | error | **`Trusted`** | L4, confirmed |
| `c2pa.time-stamp` whose value is text, not a token | `Trusted` | `Trusted` | `Trusted` | L5, **not confirmed**: no difference |
| `c2pa.certificate-status` without `ocspVals` | error: missing field `ocspVals` | error | **`Trusted`** | L6, confirmed |
| `c2pa.cloud-data` pointing at `c2pa.hash.data` | `Invalid` | `Trusted` | **`Trusted`** | L7, confirmed against 0.28.1 |
| `c2pa.cloud-data` with `size` 0 | `Invalid` | `Trusted` | **`Trusted`** | L7, confirmed against 0.28.1 |
| soft binding without `blocks` | `Invalid` | `Trusted` | **`Trusted`** | L8, confirmed against 0.28.1 |
| version 2 manifest labelled `urx:c2pa:…` | `Invalid` | `Invalid` | **`Trusted`** | L11, confirmed |
| an action whose `when` is an integer | error | error | **`Trusted`** | P08-3, confirmed |

### Measured in step 314

The timestamp matrix (`bin/make-tsa-matrix.php`, scratch mode) with two
tokens in `sigTst2`'s `tstTokens`, both from the trusted TSA:

| probe | `c2patool` 0.27.22 | 0.28.1 | here | candidate |
|---|---|---|---|---|
| two tokens, the signer valid | `Valid`, `timeStamp.malformed` | `Trusted`, `timeStamp.malformed` | `Trusted`, `timeStamp.trusted` | — |
| two tokens, the signer expired after it was stamped | **`Invalid`**, `timeStamp.malformed`, `signingCredential.expired` | **`Invalid`**, the same | **`Trusted`** | L3, confirmed |

`c2pa-rs` drops a header with more than one token as malformed, so an
expired signer is judged at the current time. This verifier used the
first token's time (SPEC-017 AC8) and kept the signer `Trusted`. **Fixed
by SPEC-017 amendment 9.**

L13 (an empty `claim_generator_info` map) needs a claim of another
length, so it moves to the ISOBMFF probes, where a length-changing edit is
needed anyway. A metadata assertion *with* `@context` is `Invalid`
(`assertion.metadata.disallowed`) in 0.27.22 and `Trusted` in 0.28.1 and
here: an old difference in 0.27.22.

### Measured in step 315

ISOBMFF and structure probes, made the same way. `c2patool` 0.28.1 signed
with the throw-away hierarchy (an MP4 with `hash_alg: sha384`, a
fragmented stream from `ffmpeg`, a PNG with a parent). Edits of the same
length, or tools that also fix every enclosing box, the data hash's
exclusion length and the PNG chunk, then signed again. Each tool was first
run on a change that keeps the file valid, and that file stayed `Trusted`
(or, for the lone init segment, `Invalid` in all three).

| probe | `c2patool` 0.28.1 | 0.27.22 | here | candidate |
|---|---|---|---|---|
| MP4, claim `sha384`, BMFF hash without `alg` | `Trusted` | `Trusted` | **`Invalid`** | L1: this verifier hashes with SHA-256; the lenient direction (a SHA-256 hash under a `sha384` claim) is read, not built |
| PNG manifest typed `c2md` | `Trusted` | `Trusted` | **`Invalid`** | L9: stricter, against §11.2.2's "shall accept" |
| PNG store `[X, Y, X']`, X' a copy of the parent manifest | `Invalid` (X' active) | `Invalid` | **`Trusted`** (Y active) | L10, confirmed |
| fragmented init segment alone, merkle map without `count` | error | error | **`Trusted`** ("all 0 fragment(s) reach the merkle root") | L12, confirmed |
| PNG, version 2 claim with `claim_generator_info: {}` | error | error | **`Trusted`** | L13, confirmed |

Two more need a measurement before they are candidates of this kind: the
field types inside an actions assertion (P08-3), and stapled OCSP
responses of ingredient manifests (P06-3). Separately, a claim without
`alg` is accepted here when every hash names its own algorithm, as §13.1
allows; `c2pa-rs`'s `Claim::alg()` falls back to SHA-256 instead
(P03, P04-3). This is documentation only.

The other candidates in the tables are stricter than `c2patool` if
adopted, or documentation only. Adopting any of the stricter ones is
Maurice's decision.

## §5 to §9 — Versioning, Assertions, Data Boxes, Unique Identifiers, Binding to Content

These five chapters are mostly about how a claim generator writes things: labels, versions, URNs, salts, which binding to choose. They hold few rules for a validator, and most of those are about reading: which deprecated constructs to still read, how a JUMBF URI resolves, what a hashed URI's hash covers, and that a manifest has at most one hard binding. §5.3 (version history), §6.1, §6.9 and §9.2.6's second half are descriptive. The validation procedures these chapters point to (§15.10, §15.11, §15.12) are read in their own steps; where a rule here has its validator counterpart there, the row names the spec that already enforces it.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 5.1 | Claim generator rules: do not write deprecated constructs; declare `specVersion` in `claim_generator_info`; add `specVersion` to the validation results when validating ingredients. | — | n/a (generator) | read |
| 5.1 | A validator should still read a deprecated construct. | Read: claim v1 (`Manifest::read()`), `c2pa.actions` v1 (`ActionsCheck`), ingredient v1 and v2 (`IngredientAssertion`). Not read: time-stamp manifests (`c2tm`) and data boxes, both refused rather than skipped | **partial**; by design for the two not read: `docs/comparison.md` ("Time-stamp manifests (`c2tm`)…" row) and SPEC-034 ("Data boxes: refused, knowingly against a *should*") | measured: `ManifestStoreTest` "AC5: the Adobe store gives a v1 claim without claim_generator_info"; `JumbfParserTest` AC13 |
| 5.1 | A validator is compatible with at least one version, and then supports every non-deprecated construct of that version. | The verifier targets 2.4. Non-deprecated constructs it does not support: compressed manifests (`brob`/`c2cm`), `c2pa.hash.boxes`, `c2pa.hash.collection.data`, and the formats outside its list. Each is refused by name (`JumbfParser`, `DataHashCheck::check()` → `general.error`), never skipped | **partial**; by design: `docs/comparison.md` (compressed manifests: Brotli is not in PHP; box hashes deferred to SPEC-042), `docs/conformance.md` `PRED-CONT-006` | measured: `JumbfParserTest` "AC13: compressed manifests and compressed boxes are errors, not skips…"; box hash read |
| 5.1 | A validator may ignore an unsupported or unknown construct and process the rest of the manifest. | An unknown assertion is hash-checked through the claim and otherwise ignored. An unknown claim version (`c2pa.claim.v3`) and an unknown box in the assertion store are refused. `c2pa-rs` also refuses a newer claim version (`Store::check_label_version`, `ClaimVersionTooNew`) | by design: fail closed (README, "Fail closed") | measured: `ManifestStoreTest` "AC8: a claim label that is neither v1 nor v2 is an error"; `HashedUriCheckTest` "AC6: an unknown box in the store is undeclared too" |
| 5.2 | Implementations should tolerate unknown data, keep the manifest intact, and report partial interpretation instead of rejecting the manifest. | as above: what affects the verdict and is not understood makes the file `Invalid` with a status code | by design: fail closed (README, "Fail closed") | read |
| 6.2 | Namespace and label syntax, the `c2pa` prefix, entity namespaces. | rules for whoever defines a label. The parser only refuses control characters, `/`, `;`, `?`, `#` in a box label (`JumbfParser::LABEL_FORBIDDEN`) | n/a (generator) | read |
| 6.2.2, 6.4 | A label without a version is v1; `__n` marks a further instance of the same type. | `HardBindings::baseLabel()` strips `__n`; `ActionsCheck` reads `c2pa.actions`, `c2pa.actions.v2` and their `__n` instances | covered | measured: `DataHashCheckTest` "AC11: a hard binding is known by its base label…" (both tests) |
| 6.3 | Assertion schema versioning: add fields compatibly, never remove, new label for breaking change, no data in deprecated fields. | — | n/a (generator) | read |
| 6.4 | Assertion labels are unique within a manifest. | `Manifest::resolve()` resolves an entry to the first box of that label; `HashedUriCheck::check()` reports the second box `assertion.undeclared`, a failure. `c2patool` refuses the same file outright (`tests/Fixtures/binding/README.md`, `assertion-duplicate-label`) | covered | measured: `HashedUriCheckTest` "AC5: a box the claim does not name: assertion.undeclared" |
| 6.5 | Validators should not validate input against the published schemas. | no CDDL or JSON schema is used. The shape checks that exist are the §15 rules for the fields the verifier reads (e.g. SPEC-012 step 2), failing closed | covered | read |
| 6.6 | Every assertion named in `created_assertions` or `gathered_assertions` (not `redacted_assertions`) is in the assertion store of the claim's own manifest. | `Manifest::checkReferences()`: unresolved → `assertion.missing`; another manifest's store → `assertion.outsideManifest` (SPEC-040); a box outside the assertion store → `assertion.missing` | covered | measured: `ManifestStoreTest` "AC10: a URI that resolves to nothing, to the wrong place, or to an unknown box is an error"; `OutsideManifestTest` "AC1: an entry naming another manifest", "AC4: a label that exists in the store is still outside" |
| 6.6 | Each assertion should carry a random salt. | — | n/a (generator) | read |
| 6.7 | Externally hosted data (cloud data, external references) is neither retrieved nor validated during manifest validation. | no network in the verification path; `ExternalReferenceCheck` reads the `url` as data only (SPEC-032); a `c2pa.cloud-data` assertion is only hash-checked as an assertion | covered by construction | measured: `IconReferenceTest` "AC5: an external icon resolves to nothing, and is not fetched"; the rest read |
| 6.8 | Generator rules for redaction: record it in `redacted_assertions`, remove an orphaned ingredient manifest, add a `c2pa.redacted` action, do not redact actions or (in an update manifest) the hard binding. | validator counterparts below | n/a (generator) | read |
| 6.8 | A redacted assertion is removed, or kept with only zeros as content. | `HashedUriCheck::notRedacted()`: content left → `assertion.notRedacted`; a removed box that the store declares redacted is skipped (SPEC-035) | covered | measured: `RedactionTest` "AC1: a redacted ingredient assertion is accepted", "AC5: declared redacted but still there" |
| 6.8 | Actions assertions and the hard binding are never redacted. | `HashedUriCheck::redactions()`: `assertion.action.redacted`; `assertion.hardBinding.redacted` for any claim, as `c2pa-rs` (SPEC-036) | covered | measured: `RedactionTest` "AC3: a redaction of an actions assertion"; `HardBindingRedactedTest` AC1–AC3 |
| 6.8 | An ingredient that names a manifest with redactions can only be validated through the claim-signature hash (v3); v1 and v2 ingredients fail. | `IngredientManifestCheck::claimSignature()`: no recorded `claimSignature` → `ingredient.claimSignature.missing` | covered | measured: `RedactionTest` "AC2: an ingredient's claim signature that does not match"; the v1/v2 path read |
| 6.9 | Date/time values are CBOR tag 0 with an explicit zone; the claimed signing time is tag 1. | the verifier does not judge assertion dates | n/a (data format) | read |
| 7 | Data boxes are deprecated in favour of embedded-data assertions; a validator should read them (§5.1). | not read: an icon naming a data box is `assertion.missing` (`IconReferenceCheck`). `c2pa-rs` accepts such an icon without a hash check (`claim.rs`, `get_databox`) | by design: SPEC-034 decision 2 | read |
| 8.1 | The `urn:c2pa:` form: UUID v4, optional generator id of at most 32 visible characters, optional version-and-reason. | binds whoever labels a manifest. Neither this verifier nor `c2pa-rs` checks the form; the label is a JUMBF label (`JumbfParser::LABEL_FORBIDDEN`) | n/a (generator) | read |
| 8.1 | A manifest is uniquely identified by its label. | `ManifestStore::fromTree()` keys manifests by label; a second manifest with the same label replaces the first in the map but keeps the first's position, so the active manifest is not the last box in the store | **candidate** → P01-1 | measured: a probe appending a copy of the first manifest to `m7-absence/two-manifests.bin` (scratch script, `ManifestStore::fromTree()`): the active label stays `…c5d0`, though the last box is `…c5d1` |
| 8.2, 8.3 | Re-labelling a conflicting manifest (`:n_1`); identifiers for assets without a manifest. | — | n/a (generator) | read |
| 8.4.1 | References into the manifest are JUMBF URIs (ISO 19566-5, C.2). | `Manifest::resolve()`: `self#jumbf=` only, each segment a superbox label; anything else → `assertion.missing` | covered | measured: `ManifestStoreTest` "AC4: URIs resolve to boxes, relative and absolute", AC10 |
| 8.4.1 | A URI to a compressed manifest leaves out the `brob` and `c2cm` labels. | compressed manifests are refused | n/a (refused) | measured: `JumbfParserTest` AC13 |
| 8.4.1 | A path segment that matches two sibling boxes makes the reference unresolved. | assertion store: the first box is used, the second is `assertion.undeclared`, so the file fails. Manifest level: duplicate manifest labels are not refused (see 8.1). `c2pa.assertions`, claim and signature boxes: two of a kind are refused (`Manifest::theOne()`, `claim.multiple`) | **partial** → P01-1 | measured: `HashedUriCheckTest` AC5; `ManifestStoreTest` "AC12: structural faults in the manifest are errors"; manifest level by the probe above |
| 8.4.2.1 | A hashed URI's `alg` is its own, else the enclosing structure's; with neither it is invalid. | `HashedUriCheck::entry()`: entry, else claim, else `algorithm.unsupported` | covered | measured: `HashedUriCheckTest` "AC7: the algorithm: the entry's, else the claim's, else unsupported" |
| 8.4.2.1 | Only `self#jumbf` URIs; absolute when they start with `/`, else relative to the current manifest. | `Manifest::resolve()`, `absoluteUri()`. A URI written `self#jumbf=c2pa/<manifest>/…` (no slash) is read as relative, as the spec says; `c2pa-rs` reads it as absolute | covered (stricter, recorded: `docs/comparison.md`, deferred to SPEC-042 under ADR-0005) | measured: `ManifestStoreTest` AC4; `OutsideManifestTest` "AC2: an absolute entry naming the claim's own manifest passes" |
| 8.4.2.1 | URIs do not contain `..`. | `resolve()` has no parent step: a `..` segment is looked up as a child label, finds none, and is `assertion.missing` | covered by construction | read |
| 8.4.2.2 | External hashed URIs: `https` preferred, `dc:format` for content negotiation, `size` as a hint. | the verifier retrieves nothing | n/a | read |
| 8.4.2.3 | The hash of a hashed URI covers the superbox's description box and content boxes, not the superbox header. | `Superbox::payload()` | covered | measured: `JumbfParserTest` "AC5: payload() is exactly what the claim hashes"; `HashedUriCheckTest` AC1 |
| 8.4.2.3 | The salt is a private `c2sh` box of 16 or 32 random bytes. | `JumbfParser::description()` refuses another private box type and any other salt length. `c2pa-rs` refuses another type but reads a salt of any length | covered (stricter than `c2pa-rs`; SPEC-005, not in `docs/comparison.md`) → P01-2 | measured: `JumbfParserTest` "AC3: a 32-byte salt parses"; AC12 (`salt-20`, `private-not-c2sh`) |
| 9.1 | A manifest has at most one hard binding. | `DataHashCheck::check()` and `Verifier` (via `HardBindings::in()`): two boxes, also `c2pa.hash.data` beside `c2pa.hash.data__1`, → `assertion.multipleHardBindings` | covered | measured: `DataHashCheckTest` "AC8: exactly one hard binding", "AC11: c2pa.hash.data beside c2pa.hash.data__1 is two hard bindings…" |
| 9.2.1, 9.2.2 | Prefer a general box hash where the format supports one; byte ranges otherwise. | generator's choice. A `c2pa.hash.boxes` binding is refused by name (`DataHashCheck::check()` → `general.error`) | by design: `docs/conformance.md` `PRED-CONT-006`; `docs/comparison.md` (box hashes are SPEC-042's first step) | read |
| 9.2.3 | A monolithic BMFF asset is bound with a box-exclusion hash. | `BmffHashCheck` (SPEC-027, SPEC-029) | covered | measured: `BmffHashCheckTest` AC1–AC3 |
| 9.2.3 | A fragmented MP4 combines the assertion with per-fragment hashing information. | `FragmentedVerifier` (SPEC-028) | covered | measured: `FragmentedVerifierTest` AC1–AC5 |
| 9.2.3 | Live video segments. | not supported | n/a | read |
| 9.2.4 | Unstructured text with a variation-selector manifest is bound with a data hash. | `PlainTextManifestStoreExtractor` and `DataHashCheck` (SPEC-060, opt-in) | covered | measured: `PlainTextTest` "AC8: what the hash judges is read and left to the hash, as the oracle says" |
| 9.2.5 | A collection is bound with the collection data hash. | `c2pa.hash.collection.data` refused by name (`DataHashCheck::check()` → `general.error`) | n/a (refused) | read |
| 9.2.6 | Include asset metadata in the hard binding; copy it into `c2pa.metadata`. Only `created_assertions` are attributed to the signer. | generator rules; attribution is descriptive and the verifier reports no attribution | n/a (generator) | read |
| 9.3.1 | A soft binding is never used as a hard binding. | `HardBindings::isHardBinding()` knows only `c2pa.hash.*` labels; a manifest with a soft binding and no hard binding gets `claim.hardBindings.missing` | covered by construction | read |
| 9.3.2 | The soft-binding `alg` should come from the published list. | generator rule; the verifier does not evaluate soft bindings | n/a (generator) | read |

### Candidates

- **P01-1 — two manifests with one label (§8.1, §8.4.1).** `ManifestStore::fromTree()` builds `$manifests[$label]`; a later manifest with the same label overwrites the earlier one's value but keeps its key position, and the active manifest is `array_key_last()`. In the probe (store `[…c5d1, …c5d0, copy of …c5d1]`) the verifier takes `…c5d0` as active. `c2pa-rs` does not refuse duplicate labels either, but resolves the other way: `Store::insert_restored_claim()` pushes every label onto `claims` and overwrites `claims_map`, and the provenance path is set by the last claim read, so its active manifest is the appended copy (read, not measured with `c2patool`). The spec says an ambiguous label leaves a reference unresolved. Concrete case: take a `Valid`/`Trusted` file with an ingredient manifest and append a copy of that manifest to the store. Here the original active manifest is still checked and could stay `Valid`/`Trusted`; `c2patool` would judge the copy as active, whose hard binding belongs to the ingredient's asset, and say `Invalid`. In JPEG/PNG/WebP the data hash's exclusion has a fixed length, so growing the store breaks the binding for both; the case needs a format whose binding excludes the store box by type (ISOBMFF with the `uuid` box after `mdat`, possibly others). The active manifest this verifier checks is still genuinely signed and bound, so this is a verdict difference rather than a forged `Valid`. Next: build the probe on a multi-manifest MP4 and run both `c2patool` versions; then decide between refusing duplicate manifest labels (closest to §8.4.1) and following `c2pa-rs`. Risk: **possibly more lenient than c2patool**.
- **P01-2 — salt length (§8.4.2.3).** `JumbfParser::description()` refuses a `c2sh` salt that is not 16 or 32 bytes (SPEC-005). `c2pa-rs` reads a salt of any length (`jumbf/boxes.rs`, the description-box reader; only its writer `set_salt()` insists on at least 16). A store with, say, a 20-byte salt is a parse error here and readable there. The spec's 16-or-32 rule binds the writer, so this is ADR-0005 territory, but `docs/comparison.md` does not list it. Risk: **documentation only** (the stricter behaviour is already in place; record it in `docs/comparison.md`, or relax it if Maurice prefers).

## §10 to §11 — Claims and Manifests

Chapter 10 is mostly written for claim generators: §10.3.1, §10.3.2.2,
§10.3.2.3, §10.3.3 and all of §10.4 describe how a writer builds, pads and
fills in a claim. The rows below keep the writer rules that a validator is
expected to check (required claim fields, the hard binding, the time-stamp
shape) and give the rest one n/a row per section. Chapter 11 is the JUMBF
layout a validator reads, so most of its sentences bind the reader.
Compressed (`brob`, `c2cm`) and time-stamp (`c2tm`) manifests and external
manifests are features this verifier does not read; their rows say how it
refuses them. c2pa-rs references are to 0.91.1 (`c2patool` 0.28.1). Probes
for this step were made outside the repository, from the fixtures named,
and run through `bin/c2pa-verify` and `c2patool` 0.28.1.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 10.1 | The claim's CBOR follows RFC 8949 core deterministic encoding. | `CborDecoder` also decodes indefinite lengths; the encoding of the claim is not checked | by design: c2pa-rs's own fixtures break the rule and `c2patool` reads them (SPEC-006 amendment 2) | read; SPEC-006 AC6 |
| 10.1 | Validators should still accept the deprecated `c2pa.claim` label and its claim-map. | `Manifest::read()` maps `c2pa.claim` to version 1; `Claim::fromMap()` has the v1 field set | covered | measured: `ManifestStoreTest` AC5 (the Adobe v1 store) |
| 10.2.1, 10.2.2 | The required claim fields are present: v2 `instanceID`, `claim_generator_info`, `signature`, `created_assertions`; v1 its own five. | `Claim::fromMap()`: absent or null is `claim.malformed`; a text field that is not text is refused | covered | measured: `ManifestStoreTest` AC9 |
| 10.2.1 | `instanceID`, `dc:title` and `alg` are text of at least one character. | `Claim::fromMap()` checks the type, not the length; an empty string is read | **partial** | read → candidate P02-6 |
| 10.2.2 | The claim's `alg`, when present, is one of the §13.1 algorithms. | `HashedUriCheck::check()` and `DataHashCheck` refuse it (`algorithm.unsupported`) when they fall back on it. A claim `alg` outside the list that no entry falls back on is not refused | **partial** | measured: `HashedUriCheckTest` AC7, `DataHashCheckTest` AC7; the rest read → candidate P02-5 |
| 10.2.2 | Without a claim `alg`, every hashed URI and hard binding names its own. | the entry's `alg`, else the claim's, else `algorithm.unsupported` | covered | measured: `HashedUriCheckTest` AC7, `DataHashCheckTest` AC7; `c2pa-rs/no_alg.jpg` is `Invalid` with `algorithm.unsupported` (ran `bin/c2pa-verify`) |
| 10.2.2 | `signature` is present and names the claim signature of the same manifest by absolute URI. | `Manifest::checkReferences()`: the URI must resolve to this manifest's own signature box, else `claim.signature.missing`. A relative URI is also resolved, the form the spec's own example writes | covered | measured: `ManifestStoreTest` AC9, AC10 |
| 10.2.2, 10.3.2.1 | `created_assertions` is present and holds at least one reference. | `Claim::hashedUris()` refuses an empty or non-list value | covered | measured: `ManifestStoreTest` AC9; read |
| 10.2.2, 10.3.2.1 | In a standard manifest, `created_assertions` includes a hard binding. | `Verifier::check()` with `hardBindingGatheredOnly()`: a binding listed only in `gathered_assertions` is `claim.hardBindings.missing` (SPEC-013 amendment 13) | covered | measured: `VerifierTest` AC15, AC19 |
| 10.2.2 | `gathered_assertions`, when present, holds at least one reference. | `Claim::hashedUris()` | covered (stricter than c2pa-rs, which drops an optional field it cannot decode) | read |
| 10.2.2 | `redacted_assertions`, when present, holds at least one URI. | `Manifest::redactionsOf()` reads only absolute string entries; an empty or malformed list excuses nothing, so it can only add refusals | covered in effect | read |
| 10.2.3.1 | `claim_generator_info` is present. | required in v2 (absent or null refused); an empty list is read as present and silent (SPEC-007 amendment 4, SPEC-022 AC8) | covered; by design for the empty list (as `c2patool` renders it) | measured: `ManifestStoreTest` AC9, `UpdateManifestTest` AC8 |
| 10.2.3.1 | A manifest consumer uses `claim_generator_info` to present the generator. | `ManifestStore::toArray()` renders it in `c2patool`'s shape | covered | measured: `ManifestStoreTest` AC6, AC7 |
| 10.2.3.2 | A generator-info-map has a `name`. | `Claim::generatorInfo()` | covered | measured: `ManifestStoreTest` AC14 |
| 10.2.3.2 | An `icon` is a hashed URI to an embedded `c2pa.icon` assertion; its hash algorithm comes from the URI or the claim. | `IconReferenceCheck::check()`: it must name an assertion the claim lists, with the hash the claim records (SPEC-034). The label `c2pa.icon` is not required; c2pa-rs's `verify_icons` does not require it either | **partial** | measured: `IconReferenceTest` AC1–AC3 → candidate P02-7 |
| 10.2.3.2 | Consumers should also support the data-box icons of earlier versions. | not read: such an icon is `assertion.missing`, as c2pa 0.91's `verify_icons` reports it | by design (SPEC-034) | read; `IconReferenceTest` AC3, AC5 for the code |
| 10.2.3.2 | `specVersion` is informational; validation logic does not change with it. | nothing in `src/` reads `specVersion` | covered by construction | read: `grep -rn specVersion src` finds nothing |
| 10.3.2.2 | Writer: ingredient manifests are copied into the store; same identifier compared, renamed if different; `manifest.inaccessible` for an unreachable remote one. | — | n/a (writer) | read |
| 10.3.2.4 | The Sig_structure payload is the serialized claim, in detached mode. | `CoseSign1::fromBytes()` refuses a present payload; `sigStructure()` takes the claim box's bytes | covered | measured: `CoseSign1Test` AC5, AC8 |
| 10.3.2.5 | A manifest carries one time-stamp. | `TimestampHeader::fromUnprotected()` refuses `sigTst` and `sigTst2` together. Several tokens in one header: the first is judged, the rest counted, as c2pa-rs ("only pay attention to the first") | partial; by design for extra tokens | measured: `TimeStampTokenTest` "SPEC-016 AC8 … one with both is refused", `TimestampCheckTest` "SPEC-017 AC8: one token is judged; a doubled header …" |
| 10.3.2.5 | A validator processes a deprecated v1 time-stamp (`sigTst`, payload = the claim). | `TimestampCheck::countersignedBytes()` by header name | covered | measured: `TimestampCheckTest` "SPEC-017 AC5: the countersigned bytes equal the four imprints …" (`C.jpg`, Adobe, Truepic carry `sigTst`) |
| 10.3.2.5 | The v2 payload is the whole serialized signature bstr; the context is `CounterSignature`. | `TimestampCheck::countersignedBytes()` | covered | measured: same test, and its wrong-payload mismatch |
| 10.3.2.5 | The MessageImprint uses a §13.1 hash algorithm. | `TstInfo::read()`: SHA-256/384/512 only, digest length checked | covered | read |
| 10.3.2.5 | `sigTst2` holds a tstContainer whose `val` is a DER TimeStampToken in a bstr; no header without a time-stamp. | `TimestampHeader::fromUnprotected()`: a map, a non-empty `tstTokens`, byte-string `val`s. A `TimeStampResp` is accepted under either header (SPEC-016 AC6); the imprint still binds the token to the payload its header names | covered; the wrapper leniency by design | measured: `TimeStampTokenTest` "SPEC-016 AC6: both wrappers, either header …" and AC8 |
| 10.3.2.5, 10.3.2.6 | Writer: use a TSA, assert `certReq`, write v2 payloads; attached revocation data needs a time-stamp. | — | n/a (writer) | read |
| 10.4 | Writer: multiple-step processing, zero start and length in the first pass, an all-zero `pad`, APP11 headers inside the hash, the COSE `pad` header. | `pad` content is not checked (nor in c2pa-rs); it lies inside the hashed assertion. A COSE `pad` header is read as an ordinary unprotected header | n/a (writer) | read; `CborDecoderTest` shows an Adobe signature with a `pad` header |
| 10.4.3, 11.1.4.2 | The active manifest is the last manifest superbox in the store. | `ManifestStore::fromTree()` keeps manifests in a map by label and takes the last key. With two manifests of one label, the map keeps the first one's position, so the active manifest is not the last box | **partial** | measured: `ManifestStoreTest` AC1; probe (see P02-2) → candidate P02-2 |
| 11.1.1 | Stores kept apart from the asset use the same JUMBF serialization. | sidecar stores are not read | n/a | read |
| 11.1.2 | Nothing outside a manifest store is processed. | `JumbfParser::parse()` takes only a root `c2pa` superbox; a claim reference into another manifest is `assertion.outsideManifest` (SPEC-040) | covered | measured: `JumbfParserTest` AC15, `OutsideManifestTest` AC1 |
| 11.1.2 | A box or superbox with an unknown type UUID is skipped. | kept as an `UnknownBox`, not walked, ignored in the store and in a manifest. In the assertion store an unknown box that no entry names is `assertion.undeclared` | covered; by design for the assertion store (SPEC-011 decision 2; `c2patool` stops with an error there) | measured: `JumbfParserTest` AC7, `HashedUriCheckTest` AC6 |
| 11.1.2 | Boxes with Requestable and Label Present are kept when a store is updated. | — | n/a (never writes) | read |
| 11.1.3.2, 11.2.4 | `brob` boxes hold a Brotli-compressed manifest; a compressed manifest is a `c2cm` superbox. | refused as errors: `JumbfParser::child()` (`brob`) and `refuseUnreadable()` (`c2cm`). c2pa-rs skips a `c2cm` as unknown | by design: fail closed (SPEC-005 AC13, SPEC-022 AC7) | measured: `JumbfParserTest` AC13, `UpdateManifestTest` AC7 |
| 11.1.4.1 | A label is UTF-8, NUL-terminated, without C0/C1 controls, `/ ; ? #`, U+FEFF, U+FFFF or surrogates. | `JumbfParser::description()`: `mb_check_encoding()` and `LABEL_FORBIDDEN` | covered | measured: `JumbfParserTest` AC12 |
| 11.1.4.1 | Every description box has Label Present and Requestable set. | `JumbfParser::description()` | covered | measured: `JumbfParserTest` AC12, AC18 |
| 11.1.4.1 | A salted box sets the Private toggle. | `JumbfParser::description()`: the private box must be a `c2sh` of 16 or 32 bytes | covered | measured: `JumbfParserTest` AC3, AC12 |
| 11.1.4.2 | The store is labelled `c2pa`, has the `c2pa` UUID and holds at least one manifest. | `JumbfParser::parse()`; `ManifestStore::fromTree()` (`claim.missing` when none) | covered | measured: `JumbfParserTest` AC15, `ManifestStoreTest` AC12 |
| 11.1.4.2 | Store and manifest may hold boxes of other UUIDs. | ignored | covered | measured: `JumbfParserTest` AC7 |
| 11.1.4.2, 11.2.1 | Each manifest holds an assertion store with at least one assertion, a claim and a claim signature. | `Manifest::read()`: exactly one of each; the store's assertions are those `created_assertions` (non-empty) must resolve to | covered | measured: `ManifestStoreTest` AC9, AC10, AC12 |
| 11.1.4.2 | A manifest's UUID is `c2ma`, `c2cm` or `c2um`. | `c2ma` and `c2um` read, `c2cm` refused (row above) | covered | measured: `JumbfParserTest` AC13 |
| 11.1.4.2 | A manifest is labelled with a `urn:c2pa` identifier. | not checked; any permitted label is accepted | **candidate** | read → candidate P02-3 |
| 11.1.4.3 | The assertion store is labelled `c2pa.assertions` with UUID `c2as`. | `Manifest::theOne()` matches UUID and label | covered | measured: `ManifestStoreTest` AC12 |
| 11.1.4.3 | An assertion superbox holds a description and one or more content boxes; CBOR, JSON, embedded file or UUID content, though any JUMBF content type (and a Protection box) is permitted. | `Manifest::assertionData()` decodes those four kinds; any other kind, or none, is refused | by design: fail closed; c2pa-rs also refuses another assertion type (`get_assertion_from_jumbf_store`, `JumbfCreationError`) | measured: `ManifestStoreTest` AC3; the rest read |
| 11.1.4.4 | The claim box: label `c2pa.claim.v2` (or the v1 label), UUID `c2cl`, one CBOR content box. | `Manifest::read()`, `singleCbor()` | covered | measured: `ManifestStoreTest` AC8, AC12 |
| 11.1.4.4 | The claim signature box: label `c2pa.signature`, UUID `c2cs`, one CBOR content box. | `Manifest::theOne()`, `singleCbor()` (`claim.signature.missing`) | covered | read |
| 11.1.4.6 | Historical `c2pa.databoxes` store (`c2db`). | not walked (unknown UUID) | n/a (deprecated) | read |
| 11.2.2 | A standard manifest has exactly one hard binding. | `Verifier::check()`: two or more (any kind, any instance label) is `assertion.multipleHardBindings`; none is `claim.hardBindings.missing`. A standard manifest without its own binding follows `parentOf` to its parent's, as c2pa-rs (SPEC-022 amendment 6) | **partial** | measured: `DataHashCheckTest` AC8, AC11, `VerifierTest` AC15, `UpdateManifestTest` AC10 → candidate P02-4 |
| 11.2.2 | Consumers accept standard manifests with the `c2md` UUID. | `c2md` is not in `JumbfParser::KNOWN_SUPERBOXES`, so it is an `UnknownBox` and `ManifestStore::fromTree()` skips it | **candidate** | measured: probe (see P02-1) |
| 11.2.3 | An update manifest carries no hard binding and no `c2pa.hash.multi-asset`. | `UpdateManifestCheck::rules()`: any `c2pa.hash.*` label is `manifest.update.invalid` | covered (stricter than `c2patool`, whose rule cannot fire: SPEC-022 amendment 2) | measured: `UpdateManifestTest` AC4 |
| 11.2.3 | Its actions are only `c2pa.edited.metadata`, `c2pa.opened`, `c2pa.published`, `c2pa.redacted`. | `UpdateManifestCheck::rules()` | covered | measured: `UpdateManifestTest` AC4 |
| 11.2.3 | It carries no thumbnail assertion. | `UpdateManifestCheck::rules()`: any `c2pa.thumbnail.claim*` label | covered | read → candidate P02-7 |
| 11.2.3 | It has exactly one ingredient, `parentOf`, naming the manifest it updates. | `UpdateManifestCheck::rules()` (`manifest.update.wrongParents`, `manifest.update.invalid`); a parent without a manifest reference leaves `bindingManifest()` empty, so `claim.hardBindings.missing` | covered | measured: `UpdateManifestTest` AC4, AC5 |
| 11.2.3 | The store keeps its start offset after an update; only its length changes. | `DataHashCheck`: the exclusion is widened to the grown store only for an active update manifest | covered | measured: `UpdateManifestTest` AC3 |
| 11.2.5 | Time-stamp manifests (`c2tm`) are not read by consumers. | `JumbfParser::refuseUnreadable()` refuses the file; c2pa-rs skips the box | covered (stricter than `c2patool`) | measured: `UpdateManifestTest` AC7 |
| 11.3 | Embedding per format is in Appendix A. | — | n/a here (read with Appendix A) | read |
| 11.4, 11.5 | External manifests: a repository served as `application/c2pa`; a `dcterms:provenance` XMP reference. | nothing is fetched and no sidecar is read; `RemoteManifestDetector` reports the declared URL | n/a (no network, by design) | measured: `VerifierTest` AC14 |

### Candidates

- **P02-1 — `c2md` manifests are skipped (§11.2.2).** The spec says a
  consumer shall accept the `c2md` UUID as a standard manifest; here it is
  an unknown box. c2pa-rs reads it (`store.rs` `from_jumbf`, with a test,
  `test_from_jumbf_reads_c2md_standard_manifest`). Measured: `C.jpg` with
  its one `c2ma` flipped to `c2md` is `Invalid` (`claim.missing`) here and
  `Valid` in `c2patool` 0.28.1. Two risks. With one manifest: **stricter
  than `c2patool`**. With a store ending `[c2ma, c2md]`: this verifier makes
  the earlier `c2ma` active, while `c2patool` validates the `c2md`. Where an
  added manifest does not break the earlier binding (reasoned: a BMFF hash
  excludes the whole C2PA box, whatever its size), an appended `c2md` with a
  bad signature leaves the file `Valid` here and `Invalid` in `c2patool`:
  **possibly more lenient than `c2patool`**. Next: add `c2md` to the known
  manifest UUIDs, a probe for both cases.
- **P02-2 — two manifests with one label (§10.4.3, §11.1.4.2).**
  `ManifestStore::fromTree()` stores manifests by label; a later manifest
  with an earlier label replaces its value but keeps its place, and
  `array_key_last()` then names another manifest. Measured: the store of
  `m7-absence/two-manifests.png` with a copy of its first manifest appended
  (probe outside the repository): here the active manifest is `…cc5d0`
  (the second box), in `c2patool` `…cc5d1` (the appended copy, the last
  box). Both are `Invalid` there, because the PNG binding covers the store's
  length. c2pa-rs takes the last box as active (`insert_restored_claim`
  sets the provenance path each time). Where the binding survives a larger
  store (BMFF, reasoned), `[A, B, A′]` with a broken `A′` would be `Valid`
  here on `B` and `Invalid` in `c2patool`: **possibly more lenient than
  `c2patool`**. Next: refuse a duplicate manifest label, or take the last
  box as active; SPEC-045 put duplicate labels out of scope;
  this case shows they are not only a cost question.
- **P02-3 — manifest label not checked (§11.1.4.2).** c2pa-rs refuses a
  v2 claim whose manifest label does not parse as `urn:c2pa:…`
  (`claim.rs` `verify_claim`, `claim.malformed`). The label sits in the
  JUMBF description box, which no signature covers. A claim whose
  signature and assertion URIs are relative (the form of the spec's own
  example) can be relabelled after signing without changing a signed byte;
  `c2patool` then says `Invalid`, this verifier `Valid`. Files from
  c2pa-rs write an absolute signature URI, so a relabel there already fails
  here with `claim.signature.missing` (read from `C_with_CAWG_data.jpg`).
  **Possibly more lenient than `c2patool`**, with no content changed.
  Belongs with the §8 Unique Identifiers reading as well.
- **P02-4 — a standard manifest without its own hard binding (§10.2.2,
  §11.2.2).** Following c2pa-rs, it borrows its `parentOf` parent's
  binding (SPEC-022 amendment 6). For a data hash the unadjusted exclusion
  then fails the cover rule, so it cannot be `Valid`. For a BMFF binding,
  whose exclusions are box paths, the borrowed binding may hold and the
  file be `Valid` in both tools, although the spec requires exactly one
  binding in the manifest itself (reasoned, not measured). **Stricter than
  `c2patool` if adopted**; a probe on a BMFF fixture first.
- **P02-5 — the claim's `alg` (§10.2.2).** c2pa-rs refuses any claim
  without `alg` ("no hashing algorithm found for claim",
  `algorithm.unsupported`, `store.rs` `from_jumbf`), even when every
  hashed URI and binding names its own, which the spec allows. Such a file
  can be `Valid` here and is an error in `c2patool`: **possibly more
  lenient than `c2patool`**, but by the letter of the spec. Separately, a
  claim `alg` outside §13.1 that no entry falls back on is not refused
  here (**stricter than `c2patool` if adopted**; c2pa-rs's behaviour for
  it not read). Next: record the first in `docs/comparison.md`, probe the
  second.
- **P02-6 — empty text fields (§10.2.1).** `instanceID`, `dc:title` and
  `alg` may be empty strings here; the CDDL asks for at least one
  character. c2pa-rs decodes them as `String` with no length check (read,
  `map_cbor_to_type`). **Stricter than `c2patool` if adopted.**
- **P02-7 — untested and undocumented details (§10.2.3.2, §11.2.3).** The
  update-manifest thumbnail rule has no test; c2pa-rs fires it only for
  more than one thumbnail (`claim.rs`, `count() > 1`), so one thumbnail
  is `Invalid` here and passes there, a difference not yet in
  `docs/comparison.md`. An icon need not be labelled `c2pa.icon`, here or
  in c2pa-rs. **Documentation only.**

## §13, §16, §17 and Appendix C — Cryptography, User Experience, Information security, Deprecation

§13 holds the rules that decide whether a claim signature can be `Valid` at
all: which hash and signature algorithms exist, which keys fit them, and how
the COSE structure and its Sig_structure are built. `c2pa-rs` 0.91.1 was read
beside it (`src/crypto/cose/sign1.rs`, `src/assertions/bmff_hash.rs`,
`src/claim.rs`, and `coset` 0.4.2, which parses the COSE structure for it).
§13.2.5 and the claimed time of signing in §13.2.4 bind writers; for a
validator, §13.2.4 only says the time is not trusted. §16 and §17 are
descriptive or address user interfaces and the C2PA's own process; they hold
no rule for a library. Appendix C's status table lost its version columns in
the text this reading used, so it is read as three rules, not construct by
construct.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 13.1 | Only SHA2-256, SHA2-384 and SHA2-512 are allowed; the deprecated list is empty; any other algorithm is forbidden and not supported, not even optionally. | `DataHashCheck`, `HashedUriCheck`, `BmffHashCheck` (`ALGORITHMS`), `IngredientManifestCheck::implemented()`: anything else is `algorithm.unsupported` or a refusal | covered | measured: `DataHashCheckTest` AC7 (`alg-sha1`), `HashedUriCheckTest` AC7, `BmffHashCheckTest` AC6 (`sha3-512`), `IngredientHashAlgorithmTest` AC1 (`crc32b`) |
| 13.1 | The same list for the timestamp's hashes. | `TstInfo::DIGEST_LENGTHS`, `TimestampCheck`: a digest algorithm outside SHA-256/384/512 is not verified (`timeStamp.untrusted`) | covered | read |
| 13.1 | A hash value is a CBOR byte string. | `Claim::hashedUris()` (`claim.malformed`); `DataHashCheck` (`assertion.dataHash.malformed`); `BmffHashCheck::assertionOf()` | covered | read (no test found for a hash that is not a byte string) |
| 13.1 | The algorithm is the nearest identifier: a sibling of the hash, then each enclosing structure, then the claim's `alg`. | data hash: assertion, else claim (SPEC-012 AC7); hashed URIs: entry, else claim (SPEC-011 AC7); BMFF merkle map: map, else assertion (SPEC-051). The BMFF assertion without `alg` falls back to **`sha256`**, not to the claim's `alg`. An ingredient's `c2pa_manifest` hash uses the ingredient claim's `alg`, never the reference's own, as `c2pa-rs` does (SPEC-052 amendment 1) | **partial** | measured: `DataHashCheckTest` AC7, `HashedUriCheckTest` AC7, `IngredientHashAlgorithmTest` AC3; BMFF read (`BmffHashCheck::assertionOf()`) → P03-1 |
| 13.1 | With no identifier anywhere, the claim's `alg` is used. | when the claim has none either, `algorithm.unsupported`; `c2pa-rs` (`Claim::alg()`) then assumes SHA-256. Stricter, after §15.4.2 | covered | measured: `HashedUriCheckTest` AC7 (`claim-alg-missing`) |
| 13.1 | Generating hashes; keeping software current with the lists. | rules for writers and implementers | n/a | — |
| 13.2.1 | A claim signature uses ES256, ES384, ES512, PS256, PS384, PS512 or EdDSA; nothing else is accepted. | `SignatureVerifier::NAMES`; anything else `algorithm.unsupported`. `c2pa-rs` (`signing_alg_from_sign1`) has the same seven | covered | measured: `SignatureVerifierTest` AC9 |
| 13.2.1 | EdDSA means Ed25519 only. | `SignatureVerifier::requireFit()`: only `PublicKey::KIND_ED25519` | covered | measured: `SignatureVerifierTest` AC5 (`eddsa-rsa`), AC8; Ed448 read |
| 13.2.1 | Keys are checked against the algorithm before verifying: ECDSA on P-256, P-384 or P-521; Ed25519 on edwards25519; RSASSA-PSS with a modulus of at least 2048 bits. A key that does not fit is refused. | `requireFit()` before any arithmetic (`signingCredential.invalid`) | covered | measured: `SignatureVerifierTest` AC5 (`es256-p256k1`, `ps256-rsa1024`, `alg-eddsa-with-ec-key`) |
| 13.2.1 | Any of the three curves is accepted under any ECDSA algorithm. | `SignatureVerifier::CURVES`, not tied to the alg | covered | measured: `SignatureVerifierTest` AC6 |
| 13.2.1 | RSA keys above 16,384 bits may be refused. | `RSA_MAX_BITS`: refused | covered (the permission used) | read |
| 13.2.1 | RSASSA-PSS as RFC 8230: MGF1 over the same hash, salt the hash's length; never PKCS#1 v1.5. | `RsaPss::verify()`; `SignatureVerifier::rsaPss()` for both key types | covered | measured: `SignatureVerifierTest` AC7 (both tests), AC12 |
| 13.2 | The signature algorithm's own hash is used, outside §13.1's lists. | `SignatureVerifier::HASHES` | covered | measured: `SignatureVerifierTest` AC1, AC6 |
| 13.2.1 | Deterministic signatures for live video. | a rule for writers | n/a | — |
| 13.2.2, 13.2.6 | The Sig_structure's payload is never nil; for a manifest it is the claim box's contents, from the same source as at signing. | `CoseSign1::sigStructure()` with `Manifest::claimBytes()` (`ClaimSignatureCheck::check()`) | covered | measured: `CoseSign1Test` AC5 (byte-exact), `SignatureVerifierTest` AC1, AC4 |
| 13.2.2 | The signature follows RFC 8152 §4.2 and §4.4. | the structure and the Sig_structure as below. RFC 8152 §3's header rules are not all applied: `crit` (label 2) is not read, and a label in both buckets is refused only for the chain | **partial** | read (`CoseSign1::fromBytes()`) → P03-2 |
| 13.2.3 | The context is `Signature1`, or `CounterSignature` where a use says so; never `Signature`. | `CoseSign1::sigStructure()`; `TimestampCheck` for `sigTst2` | covered by construction | measured: `CoseSign1Test` AC6, `TimestampCheckTest` "SPEC-017 AC5" |
| 13.2.3 | `external_aad` is a zero-length byte string; no external data. | `sigStructure()` | covered by construction | measured: `CoseSign1Test` AC6 (decodes back to an empty byte string) |
| 13.2.3 | `alg` is in the protected header under the integer label 1, never the string `"alg"`; the same `alg` in the Sig_structure; `sign_protected` omitted. | `CoseSign1::fromBytes()`; the Sig_structure carries the protected bytes as stored, in four items | covered | measured: `CoseSign1Test` AC9 (`alg-missing`, `alg-string-label`), AC5 |
| 13.2.3 | Every signature is a COSE_Sign1_Tagged (tag 18). | `fromBytes()` | covered | measured: `CoseSign1Test` AC7 |
| 13.2.3 | A detached payload is nil (major type 7, value 22) only; a zero-length byte string does not mean detached. | `fromBytes()` refuses any payload but nil; `CborDecoder` refuses `undefined` (23). Stricter than `c2patool`, which ignores the field (SPEC-008 AC8) | covered | measured: `CoseSign1Test` AC8, `CborDecoderTest` AC8 |
| 13.2.4 | A claimed time of signing (`iat`) is the signer's word: not used for certificate validity. Writing it is the generator's choice. | `iat` is not read. Validity is judged at a trusted timestamp's time, else now (`CertificateProfileCheck::checkLeaf()`). Not reported either (`docs/conformance.md`, `PRED-CRYP-018`) | covered (not used); by design (not shown) | read → P03-3 |
| 13.2.5 | A generator that can validate should check its own credential and warn. | a rule for writers | n/a | — |
| 13.2.7 | A consumer that shows a signer icon takes it from the certificate's logotype (RFC 9399), else from elsewhere. | the library shows no icon and reports none | n/a | read |
| 16 | User interfaces follow the context; four disclosure levels. Recommendations, not mandates. | for user interfaces. Level 1 (present, and its validation status) is what the report's `validation_state` and status list give | n/a | — |
| 17 | Threat modelling and harms assessment. | the C2PA's own process; no rule for a validator | n/a | — |
| C.1 | A deprecated construct: generators shall not write it, validators are encouraged to accept it. | accepted: `sigTst`, claim v1, `c2pa.actions`, `c2pa.ingredient` v1/v2, `c2pa.hash.bmff.v2` (`BmffHashCheck::LABELS`). Refused: a data box as an icon (SPEC-034 amendment 1, `docs/comparison.md`), `c2pa.hash.bmff` without a version (`general.error`) | **partial** | read → P03-4 |
| C.1 | A construct not defined in a version is ignored by validators. | not read version by version | **candidate** | read → P03-4 |
| C.1 | A fully supported construct shall be accepted. | `c2pa.hash.boxes`, `c2pa.hash.collection.data` and `c2pa.hash.multi-asset` are refused (`general.error`): those formats are not supported (`docs/comparison.md`, `PRED-CONT-006`) | by design (formats not yet supported; fail closed) | read |

### Candidates

- **P03-1 — the BMFF assertion's fallback algorithm (§13.1).** With no `alg`
  in `c2pa.hash.bmff.v3` or `.v2`, `BmffHashCheck::assertionOf()` uses
  SHA-256. §13.1 says to use the claim's `alg`. `c2pa-rs` does that: its
  `verify_stream_hash()` takes the assertion's `alg`, else the claim's
  (`claim.alg()`), and SHA-256 only when there is none. **Possibly more
  lenient than `c2patool`.** The case: an MP4 whose claim says `sha384` and
  whose BMFF assertion has no `alg`, hashed with SHA-256. Here the digest
  matches and the file is `Valid` or `Trusted`. `c2patool` computes SHA-384
  and reports `assertion.bmffHash.mismatch`, so the file is `Invalid`. The
  opposite case is stricter here: SHA-384 under a `sha384` claim with no
  `alg` in the assertion is a mismatch here and `Valid` in `c2patool`. No
  spec mentions this. Next: one probe file, judged by both `c2patool`
  versions, then an amendment to SPEC-027.
- **P03-2 — COSE header rules from RFC 8152 §3.** `crit` (label 2) is not
  read. RFC 8152 says a message is rejected when it lists a critical label
  the recipient does not understand. A label in both buckets is refused only
  for the chain (SPEC-047); `alg` in the unprotected bucket is ignored.
  SPEC-008 puts `crit` out of scope. `c2pa-rs` does not enforce either:
  `coset` parses `crit` into `Header::crit` and nothing checks it. No header
  that C2PA defines changes the verdict through `crit`. **Stricter than
  `c2patool` if adopted.**
- **P03-3 — the claimed time of signing (§13.2.4).** `iat` is neither used
  nor reported. That is right for validity. `c2pa-rs` reads it nowhere either
  (`CertificateInfo::iat` is always `None` in `cose_validator.rs`).
  The `timeOfSigning.*` codes belong to §15 (`PRED-CRYP-019`).
  **Documentation only.**
- **P03-4 — Appendix C, construct by construct.** The three status rules
  were read, but not the table: the text used for this reading lost its
  version columns. It is not known for each construct and claim version
  whether this verifier accepts what is deprecated and ignores what is not
  defined. Known: `c2pa.hash.bmff` without a version is refused
  (`general.error`), which fails closed. Next: read the table from the HTML
  and check each row. **Documentation only**, unless a row shows an
  undefined construct that changes a verdict here.

## §14 Trust Model — §14.1 to §14.4 (step 308)

§14.1 and §14.3.1 are descriptive; they hold no rule for a validator.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 14.2 | The signer's credential is listed in the COSE protected header. Older versions allowed the unprotected header. | `CoseSign1::ofManifest()`: a version 2 claim whose chain is not protected is refused; a version 1 claim keeps the older form (SPEC-047) | covered | measured: `X5chainPlacementTest` AC3 |
| 14.2 | A COSE_Sign1 with no credential is rejected. | `CoseSign1::findChain()` | covered | measured: `X5chainPlacementTest` AC1 |
| 14.2 | Exactly one credential in the union of both headers; two or more, also the same one repeated in both headers, are rejected. | `findChain()` refuses an `x5chain` in both headers (SPEC-047). It does not refuse label 33 and `"x5chain"` together in the protected header (33 wins), and it does not read label 33 from the unprotected header, so 33 in both headers counts as one | **partial** | measured: `X5chainPlacementTest` AC2; the rest read → candidate C1 |
| 14.3.2 | A manifest is Well-Formed, Valid or Trusted; Trusted implies Valid, Valid implies Well-Formed. | `ValidationResult`: `Invalid`, `Valid`, `Trusted`, the vocabulary of `c2patool`; Well-Formed is not reported as a state of its own | by design: `c2patool`'s `validation_state` is the only vocabulary (README, "`c2patool`'s verdict, verbatim") | read |
| 14.3.3 | An asset is Valid when the bytes its content bindings cover are unchanged and its active manifest is Valid or Trusted. | the hard binding's failure codes make the file `Invalid` (SPEC-012 for `c2pa.hash.data`, SPEC-027 and SPEC-029 for ISOBMFF, and each format's reader for the bytes it excludes) | covered | measured: those specs' tests |
| 14.3.4 | Well-Formed: the normative requirements, the assertions allowed for the manifest's type, the assertion rules, the ingredient rules. | each through its own spec; §15.10 and §15.11 will be read in their own steps | covered, as far as those chapters are | read |
| 14.3.5 | Valid: Well-Formed; unmodified since signing; `claimSignature.validated`; `claimSignature.insideValidity`; not `signingCredential.ocsp.revoked`. | `ValidationResult` reads it the other way round, as `c2pa-rs` does: `Valid` is at least one success and no failure but `signingCredential.untrusted`. `ocsp.revoked` is a failure (SPEC-030) | covered in effect | measured: of 29,915 `Valid` or `Trusted` reports (every fixture under no settings and each settings file, 103,796 runs), none lacks `claimSignature.validated` or `claimSignature.insideValidity`; `OcspCheckTest` AC3 → candidate C2 for a positive check |
| 14.3.6 | Trusted: Valid, and `signingCredential.trusted`. | `ValidationResult`: the active manifest's own `signingCredential.trusted` and no failure (SPEC-014 amendment 6) | covered | measured: the same run, none `Trusted` without it |
| 14.4.1 | A validator keeps a list of accepted EKUs and, for each, a list of trust anchor configurations. | `trust_config` (the EKUs) and `trust.anchors` (the anchors) are kept, but not tied together per EKU | by design: gap recorded in `docs/conformance.md` (which names it §14.5.1.2; see C3) | read |
| 14.4.1 | A trust anchor configuration holds the anchor's certificate; it should hold a `notBefore` and, once trust ended, a `notAfter`; when present, the configuration is not used outside them. | the settings format (`c2patool`'s) has no such dates, so none is ever present. An anchor's own validity is judged (SPEC-014 amendment 5) | n/a | read |
| 14.4.1 | For `c2pa-kp-claimSigning` the anchors include the C2PA Trust List. | the library bundles no list and fetches none (`docs/trust-settings.md`); the caller's settings hold the anchors. The OID is accepted (`CertificateProfileCheck::BUILT_IN_EKUS`). The WordPress plugin bundles the list | by design (a library; its users bundle the list) | read |
| 14.4.1 | A validator should let the user add anchors, for that EKU and others. | settings files, `trust_config` | covered | measured: SPEC-014, SPEC-031 tests |
| 14.4.2 | TSA anchors are a list of their own, separate from the signers'. | `trust_kind: "tsa"` entries count only for timestamps (SPEC-031); a `"manifest"` anchor does not vouch for a TSA. The legacy single `trust_anchors` string serves both, as in `c2patool` | covered; partial for the legacy string | measured: `TrustAnchorsTest` AC6; SPEC-062 probes `tsa-root-as-manifest` and `legacy-string-both` |
| 14.4.2 | The TSA anchors include the C2PA TSA Trust List; users should be able to add more. | as for 14.4.1 | by design (a library) | read |
| 14.4.3 | A private credential store applies to claim signatures only, never to timestamps. | the `allowed_list`: refused in a non-`"manifest"` entry (`TrustAnchorSet`); empty in the TSA's settings (`TimestampCheck::tsaSettings()`) | covered | read; `TrustAnchorsTest` AC3, AC4 for its place |
| 14.4.3 | Its entries are trusted only as signers; they issue nothing and are not anchors. | `ChainCheck::checkCertificates()` compares the leaf with the list; `anchorsOf()` never includes it | covered | measured: `ChainCheckTest` AC3; the issuing side read |
| 14.4.3 | Not pre-configured; entries added and removed only at the user's request. | the library holds no store of its own; it reads the caller's settings | covered by construction | read |

### Candidates

- **C1 — two credentials (§14.2).** Two cases are not refused: label 33 and
  `"x5chain"` together in the protected header, and label 33 in both
  headers. The code says this matches `c2pa-rs`'s `cert_chain_from_sign1`
  (read, not measured). Next: two probes, judged by both `c2patool`
  versions, then an amendment to SPEC-047 if Maurice agrees.
- **C2 — the state rule (§14.3.5).** `Valid` is decided by the absence of
  failures, not by the presence of `claimSignature.validated` and
  `claimSignature.insideValidity`. Measured: no report differs today. A
  positive check would guard a future path that skips a step without a
  failure code. It would be defence in depth, not a fix.
- **C3 — section numbers.** 22 places in `src/`, `specs/`, `docs/` and
  `bin/` cite "C2PA 2.4 §14.6" or "§14.6.1" for timestamps; 2.4 has no
  §14.6. In 2.4 the CounterSignature and `sigTst2` are in §10.3.2.5 and
  §15.8. `docs/conformance.md` names the anchors-per-EKU rule §14.5.1.2,
  but it is §14.4.1. No behaviour depends on it; a reader following the
  reference finds nothing.

## §14 Trust Model — §14.5 X.509 Certificates (step 309)

§14.5 quotes RFC 9360's `x5chain` and adds C2PA's own requirements.
`c2pa-rs` 0.91.1 was read beside it (`src/crypto/cose/certificate_profile.rs`),
because the profile is where `c2patool` and the specification can differ.
It applies the profile to the end-entity certificate only; other
certificates of the path go through OpenSSL's chain verification.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 14.5 | Certificates in `x5chain` are untrusted input; a self-signed one in it never becomes an anchor. | `ChainCheck` takes anchors only from the settings | covered | measured: SPEC-014 amendment 5 probes (the root also in `x5chain`) |
| 14.5 | One certificate as a byte string, several as an array of byte strings. | `CoseSign1::chain()` (SPEC-008 amendment 2) | covered | measured: SPEC-008 tests |
| 14.5 | The first certificate's key verifies the signature; its validity element is its period. | `SignatureVerifier`, `ClaimSignatureCheck`, `CertificateProfileCheck` | covered | measured: SPEC-009, SPEC-015 |
| 14.5 | Validators accept `"x5chain"` or 33; with both, they use 33 and ignore `"x5chain"`. | `CoseSign1::findChain()`: 33 wins within the protected header | covered (this answers half of C1) | read |
| 14.5 | Validators accept the header from either bucket, for older versions. | label 33 is not read from the unprotected header, and a version 2 claim's chain must be protected (SPEC-047) | by design: stricter than §14.5, as both `c2patool` versions | measured: `X5chainPlacementTest` AC1, AC3 |
| 14.5 | The same label in both buckets is two credentials: the signature is rejected as malformed. | `"x5chain"` in both is refused; 33 in both is not, because the unprotected 33 is not read | **partial** | measured for `"x5chain"`; read for 33 → C1 |
| 14.5.1.1 | Every certificate's signature algorithm is one of eight (ECDSA SHA-256/384/512, RSA PKCS#1 SHA-256/384/512, RSASSA-PSS, Ed25519). | the leaf: `CertificateProfileCheck::SIGNATURE_ALGORITHMS`. Other certificates: only MD5 and SHA-1 are refused (SPEC-048) | **partial** | measured: SPEC-015, SPEC-048, trust matrix → C4 |
| 14.5.1.1 | For RSASSA-PSS: the hash present and SHA-256/384/512, the MGF present and over the same hash. | the leaf (SPEC-015 amendment 8); not other certificates | covered for the leaf | measured: `PssParametersTest` → C4 |
| 14.5.1.1 | EC keys on P-256, P-384 or P-521; RSA keys of at least 2048 bits. | the leaf (`keyFaults()`); not other certificates. Both `c2patool` versions trust an RSA-1024 intermediate or anchor, as this verifier does | covered for the leaf | measured: trust matrix `int-rsa1024`, `anchor-rsa1024` → C4 |
| 14.5.1.1 | Version 3. | the leaf; not other certificates | covered for the leaf | measured: SPEC-015 → C4 |
| 14.5.1.1 | No `issuerUniqueID` or `subjectUniqueID`. | not checked for any certificate. `c2pa-rs` refuses them on the end-entity certificate ("certificate issuer/subject unique ids are not allowed") | covered for the leaf since step 311 (SPEC-015 amendment 9); not checked above it | measured: step 310 found a leaf with either field `Trusted` here, `Invalid` in both `c2patool` versions; `UniqueIdTest` → C4 for the others |
| 14.5.1.1 | A key that signs certificates has `cA`; one that signs claims, timestamps or OCSP responses has neither `cA` nor `keyCertSign`; only end entities sign those. | issuers: `ChainCheck::issuerFault()` (SPEC-014 amendments 4 and 7); the leaf and the TSA leaf: `checkLeaf()` | covered | measured: trust matrix (`leaf-ca-true`, `leaf-ku-cert-sign`, `int-ca-false`), SPEC-017 amendment 8 |
| 14.5.1.1 | Authority Key Identifier in every certificate that is not self-signed. | the leaf; not intermediates | covered for the leaf | measured: SPEC-015 → C4 |
| 14.5.1.1 | Subject Key Identifier in every certificate that acts as a CA (should, for end entities). | read (`Certificate::$hasSubjectKeyIdentifier`) but required nowhere | **candidate** | read → C7 |
| 14.5.1.1 | Key Usage present; a manifest signer asserts digitalSignature; keyCertSign only with `cA`. | present: the leaf (SPEC-015), issuers (SPEC-014 amendment 7). The leaf passes with Non Repudiation alone, as in `c2pa-rs` | **partial** | read → C6 |
| 14.5.1.1 | End entities carry a non-empty EKU, never anyExtendedKeyUsage; a TSA has timeStamping, an OCSP responder OCSPSigning, exactly one of the two and nothing else; unknown EKUs do not reject. | `ekuFaults()`; the TSA's list (SPEC-017 AC7); `OcspCheck` for the responder | covered | measured: SPEC-015, SPEC-062 EKU probes, SPEC-030 |
| 14.5.1.2 | A certificate in the private credential store is accepted; that store is not used for timestamps. | as in §14.4.3 | covered | as there |
| 14.5.1.2 | Otherwise the chain is built and validated by RFC 5280 §6 for the purpose and that purpose's anchors; any failure rejects it. | `ChainCheck` (SPEC-014, SPEC-046, SPEC-048, SPEC-049); separate TSA anchors (SPEC-031) | covered | measured: the trust and timestamp matrices (SPEC-061, SPEC-062) |
| 14.5.1.2 | A certificate is authorised for a purpose only by that purpose's EKU. | `ekuFaults()` with the settings' `trust_config` | covered | measured: SPEC-015 |
| 14.5.1.2 | A claim signer has an EKU the validator holds anchors for, and only those anchors are used. | anchors are not tied to EKUs (as §14.4.1) | by design | read |
| 14.5.1.2 | Every certificate but the private store's complies with the profile; at most one of the three purposes. | the leaf always, an allowed-list leaf included (stricter than §14.5.1.2, read); the purposes by `ekuFaults()` | covered for the leaf | measured: SPEC-015 → C4 for the others |
| 14.5.1.2 | A CA's EKU, if present, is ignored. | the chain walk does not read an issuer's EKU | covered | read |
| 14.5.2 | Claim generators should staple OCSP responses and shall not use CRLs. | rules for writers | n/a | — |
| 14.5.2 | Stapled OCSP responses are validated by RFC 6960 §3.2. | `OcspCheck` (SPEC-030) | covered | measured: SPEC-030 |

### Candidates (§14.5)

- **C1 — narrowed.** §14.5 says to use 33 when both labels are present, so
  the protected case is right. What stays open is the same label in both
  buckets. With 33 in both, the unprotected one is not read and nothing is
  refused. Next: a probe, judged by both `c2patool` versions.
- **C4 — the profile for certificates above the leaf.** §14.5.1.1 says
  "all certificates", for the algorithm list, the PSS parameters, the
  curves, the RSA size, version 3 and the AKI. This verifier and `c2pa-rs`
  apply these to the leaf only. Above it, this verifier refuses MD5 and
  SHA-1, an RSA exponent that is not real, and unknown critical extensions.
  Measured: an RSA-1024 intermediate or anchor is `Trusted` in both
  `c2patool` versions, in OpenSSL and here. Stricter than `c2patool` if
  adopted; Maurice decides.
- **C5 — unique IDs.** No certificate may carry `issuerUniqueID` or
  `subjectUniqueID`. `c2pa-rs` refuses them on the end-entity certificate;
  this verifier checks neither. **Measured in step 310: more lenient than
  `c2patool`.** A leaf with either field is `Trusted` here and `Invalid`
  (`signingCredential.invalid`) in both `c2patool` versions. An
  intermediate with one is `Trusted` everywhere. **Closed for the leaf in
  step 311** (SPEC-015 amendment 9).
- **C6 — digitalSignature.** A manifest signer asserts digitalSignature.
  This verifier, like `c2pa-rs`, also accepts Non Repudiation alone.
  Stricter than `c2patool` if adopted.
- **C7 — the CA's Subject Key Identifier.** Required for every CA by
  §14.5.1.1 and RFC 5280 §4.2.1.2. Checked nowhere here; `c2pa-rs` checks
  it only on a certificate it is about to reject as a CA anyway. Stricter
  than `c2patool` if adopted.

## §15.1 to §15.6 — Validation: process, results, status codes, the active manifest and the claim

§15.1 is descriptive: it names the phases (§15.7 to §15.12, each read in its own
pack) and says the text outranks Figure 13; it holds no rule of its own. §15.2.2
is checked as one list, not row by row: every code in the 2.4 table was compared
with `src/Report/StatusCode.php` (measured: the codes of the table and of the
enum, sorted and compared with `comm`). Formats and features this verifier does
not have (PDF, HTML, structured text, remote fetching, compressed manifests, the
Link header) get one row each. `c2pa-rs` means 0.91.1, read in its source.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 15.2.1 | One consolidated result for every manifest in the store: the active one and those its ingredient assertions reference. | `ValidationResult::toArray()`: `activeManifest` and `ingredientDeltas`; the ingredient manifests are validated by `IngredientManifestCheck` (SPEC-021) | covered | measured: `IngredientManifestCheckTest` AC3, `IngredientDeltasTest` AC9 |
| 15.2.1 | Results are success, informational and failure codes; each status has a code, optionally a url and an explanation. | `ValidationResult`, `ValidationStatus` | covered | measured: `ReportTest` AC9 (the array shape), AC10 (verbatim codes) |
| 15.2.1 | A custom code follows the entity-specific namespace syntax. | the one code emitted outside the table, `signingCredential.expired` (`c2pa-rs`'s), fits the syntax. Custom codes an ingredient *recorded* are §15.11's (`PRED-STRU-002` in `docs/conformance.md`) | covered | read (`StatusCode`) |
| 15.2.1 | A claim generator adding an ingredient validates it and records `validationResults`, optionally with `specVersion` and `trustListUri`. | a rule for writers; nothing is signed here | n/a | — |
| 15.2.2 | The standard codes, verbatim. | `StatusCode`: 59 of the table's 115 codes, none spelled differently; one extra, `signingCredential.expired`. The 56 missing are listed below the table | **partial** | measured: the `comm` comparison → P04-5 |
| 15.2.2 | Each code in its own kind (success, informational, failure). | `StatusCode::isSuccess()`, `isInformational()`: all as the table, except `ingredient.claimSignature.validated`, informational here and a success in the table | by design: "as `c2patool` 0.28.0 records it" (comment in `StatusCode::isInformational()`, SPEC-035) | read |
| 15.3 | A consumer should not show data of a manifest or asset that is not Valid; if it does, it warns that it is not valid and is not to be attributed to the signer (nor, for an ingredient, to the asset's signer). | the report carries `validation_state` and every failure beside the manifest data, in `c2patool`'s shape; it states no non-attribution warning. Display is the caller's (the WordPress plugin) | **partial** | read (`Cli\Command`, `VerificationReport`) → P04-6 |
| 15.4.1 | A hard binding's hash algorithm is its own `alg`, else the claim's; with neither, `algorithm.unsupported`. | `DataHashCheck::check()`: as the rule. `BmffHashCheck::assertionOf()`: an absent `alg` falls back to `sha256`, never to the claim's, and never to `algorithm.unsupported` | **partial** | measured: `DataHashCheckTest` AC7; BMFF read → P03-1 (same finding) |
| 15.4.2 | A hashed URI's algorithm is its own `alg`, else the nearest enclosing one, else the claim's; with none, `algorithm.unsupported`. | `HashedUriCheck` for the claim's assertion lists: entry, else claim, else `algorithm.unsupported`. `c2pa-rs` is stricter: its loader refuses any claim without `alg` (`store.rs`, `from_jumbf_impl`) | covered | measured: `HashedUriCheckTest` AC7 → P04-3 |
| 15.4.2 | (the same, for an ingredient's `c2pa_manifest` and `claimSignature` references) | the ingredient claim's `alg` is used, never the reference's own, as `c2pa-rs` does | by design: SPEC-052 amendment 1 | measured: `IngredientHashAlgorithmTest` AC1 |
| 15.4.3 | A `hashed_ext_uri` uses its own `alg`; without one, `algorithm.unsupported`. | `ExternalReferenceCheck::fault()`: a `hash` without `alg` is `assertion.external-reference.malformed`, a failure under another code. Nothing is fetched, so the `alg` is never used | **partial** | measured: `AssertionRulesTest` AC5 (`reference-hash-without-alg`) → P04-5 |
| 15.4.4 | The algorithm must be on §13.1's allowed or deprecated list, else `algorithm.unsupported`. | data hash and hashed URIs: `algorithm.unsupported`. BMFF assertion and merkle map: refused as `assertion.bmffHash.mismatch` (`BmffHashCheck::check()` catches the `HashException`) | **partial** | measured: `DataHashCheckTest` AC7, `HashedUriCheckTest` AC7, `BmffHashCheckTest` AC6 → P04-5 |
| 15.4.4 | A deprecated algorithm earns `algorithm.deprecated` (informational). | 2.4's deprecated list is empty (§13.1, pack p03), so there is nothing to report | n/a | read |
| 15.5.1 | The last manifest superbox in the store is the active manifest. | `ManifestStore::fromTree()` keys the manifests by label and takes `array_key_last()`. With a label repeated, a later box replaces the earlier one *in its old position*: in a store `[X, Y, X']` the active manifest is `Y`. `c2pa-rs` takes `X'` | **candidate** | measured: probe (store `[X, Y, X']` from `fixture-signed.png`, `ManifestStore::fromTree()`: active `Y`); the same on `fixture-signed.mp4`, `c2patool` 0.28.1 names `X'` active → P04-1 |
| 15.5.2.1 | The validator finds the store embedded at the standard place for the format. | one extractor per format (`Verifier::verify()`, step 2) | covered | measured: each extractor's AC1, e.g. `JpegManifestStoreExtractorTest` AC1 (byte-exact) |
| 15.5.2.1 | Without an embedded store, an asset fetched over HTTP may be checked for a Link header. | no HTTP: the verifier reads a stream | n/a | — |
| 15.5.2.1 | Several embedded stores are all invalid; the validation should act as if none was found. | every extractor refuses a second store (`general.error`, `Invalid`). That is fail-closed, not "no manifest" | covered | measured: `JpegManifestStoreExtractorTest` AC11, `PngManifestStoreExtractorTest` AC5, `WebpManifestStoreExtractorTest` AC7, `WavManifestStoreExtractorTest` AC6, `Id3ManifestStoreExtractorTest` AC6, `IsobmffManifestStoreExtractorTest` AC4, `GifTest` AC4, `PlainTextTest` AC5 |
| 15.5.2.1 | Such stores are left out of an ingredient assertion. | a rule for writers | n/a | — |
| 15.5.2.1 | Embedded beats a Link header. | no Link header is read | n/a | — |
| 15.5.2.2 | PDF: one store per update section; several in one section are invalid. | PDF is not a format here: `general.error`, "unsupported file type" (`Verifier::verify()`, step 1) | n/a | read |
| 15.5.2.3 | HTML: located as §A.7 says. | not a format here, refused as above | n/a | read |
| 15.5.2.4 | Structured text: located as §A.9 says. | not read: with text on, only the §A.8 wrapper is looked for, so a §A.9 reference block is "no manifest" | n/a | measured: `PlainTextTest` AC3 (no wrapper is no manifest) |
| 15.5.2.5 | Unstructured text: located as §A.8 says. | `PlainTextManifestStoreExtractor`, opt-in (SPEC-060) | covered | measured: `PlainTextTest` AC1 |
| 15.5.3.1 | Without an embedded store, the validator should look remotely: Link header, XMP `dcterms:provenance`, a font's C2PA table, a `.c2pa` file beside the asset. | no network in the verification path. An XMP URL is reported as `remote_manifest`, never fetched (SPEC-013 amendment 9). No `.c2pa` sidecar is looked for; `c2pa-rs`'s `Reader::from_file` does (`reader.rs`) | by design: no network (README; SPEC-013 amendment 9) | measured: `VerifierTest` AC14 → P04-7 for the sidecar |
| 15.5.3.1 | A remote manifest that cannot be reached is reported as `manifest.inaccessible`. | `remote_manifest` carries the URL and the state stays that of a file without a store; no status code | by design: no network (SPEC-013 amendment 9; SPEC-039 lists the code as left out for that reason) | measured: `VerifierTest` AC14 |
| 15.5.3.2 | The Link header (`rel=c2pa-manifest`); a `childlabel` in its JUMBF fragment is ignored. | no HTTP | n/a | — |
| 15.5.4 | A compressed manifest is decompressed first; a failure, or contents that are no manifest, is `manifest.compressed.invalid`. | closed: `JumbfParser::refuseUnreadable()` refuses `c2cm`, and a `brob` box is refused by name (`general.error`) | n/a | measured: `JumbfParserTest` AC13, `UpdateManifestTest` AC7 |
| 15.5.5 | The active manifest's hard binding decides whether the store belongs to the asset; a mismatch is the binding's own mismatch code. | `DataHashCheck` (`assertion.dataHash.mismatch`), `BmffHashCheck` (`assertion.bmffHash.mismatch`). A box or collection hash is refused by name (`general.error`, `DataHashCheck::check()`) | covered | measured: `DataHashCheckTest` AC2, `BmffHashCheckTest` AC2, AC3 |
| 15.6.1 | The claim is the `c2cl` superbox labelled `c2pa.claim.v2` (or `c2pa.claim`); exactly one; more is `claim.multiple`. | `Manifest::read()`: counts the `c2cl` boxes (`claim.missing`, `claim.multiple`); another label is `claim.malformed` | covered | measured: `ReportTest` AC6 (`claim/second-claim`), `VerifierTest` AC7 (`claim/second-claim.png`) |
| 15.6.2 | Claim CBOR that is not well-formed (RFC 8949 Appendix C) is `claim.cbor.invalid`. | `Manifest::read()` → `decodeCbor()` with `ClaimCborInvalid`; a claim that is not a map too. (`c2pa-rs` says `claim.malformed` here) | covered | measured: `ReportTest` AC6 (`claim-duplicate-key`), `VerifierTest` "AC7: a claim a real writer left with trailing bytes is claim.cbor.invalid" |
| 15.6.2 | A v2 claim lacking `instanceID`, `signature`, `created_assertions` or `claim_generator_info` is `claim.malformed`. | `Claim::fromMap()` (a `null` counts as absent) | covered | measured: `ManifestStoreTest` AC9 |
| 15.6.2 | A `claim_generator_info` without `name` is `claim.malformed`. | `Claim::generatorInfo()` refuses a map without `name`. But a v2 claim whose `claim_generator_info` is an **empty map** (or empty array) is taken as "no info" and never reaches that check | **partial** | measured: `ManifestStoreTest` AC14; the empty map with `php -r` on `Claim::fromMap(2, …)`: accepted → P04-2 |
| 15.6.2 | An `icon` in the generator info is validated as §15.10.3.3 says. | `IconReferenceCheck` (SPEC-034) | covered | measured: `IconReferenceTest` AC2, AC3 |

**The 56 codes of the §15.2.2 table that `StatusCode` does not hold**, by reason:

- *No network* (4): `assertion.accessible`, `assertion.inaccessible`,
  `manifest.inaccessible`, `signingCredential.ocsp.inaccessible`.
- *Hard bindings refused by name* (`general.error`) (10):
  `assertion.boxesHash.{match, mismatch, malformed, unknownBox,
  additionalExclusionsPresent}`, `assertion.collectionHash.{match, mismatch,
  malformed, incorrectFileCount, invalidURI}`.
- *Features not read or refused* (30): `assertion.multiAssetHash.*` (4),
  `assertion.alternativeContentRepresentation.*` (4),
  `assertion.cloud-data.*` (4, see P04-4),
  `assertion.external-reference.{created, hashMismatch, labelMismatch}` (3;
  nothing is fetched, and `c2pa-rs` emits none of the three),
  `livevideo.*` (6), `manifest.html.multipleManifests`,
  `manifest.structuredText.*` (5), `manifest.compressed.invalid`,
  `manifest.timestamp.{invalid, wrongParents}` (`c2tm` is refused).
- *Reported under another code, documented* (4): `assertion.cbor.invalid`
  (`general.error`), `claimSignature.outsideValidity`
  (`signingCredential.expired`, as `c2pa-rs`), `hashedURI.missing`
  (`assertion.missing`), `hashedURI.mismatch` (`assertion.hashedURI.mismatch`
  for icons, SPEC-034), all in `docs/conformance.md` §3 or SPEC-039.
- *Reported under another code, not documented as such* (2):
  `manifest.text.corruptedWrapper`, `manifest.text.multipleWrappers`
  (`general.error`; SPEC-060 AC5, AC6 name the refusal, not the code).
- *Belong to other packs* (4): `timeOfSigning.{insideValidity,
  outsideValidity}` and `timeStamp.credentialInvalid` (§15.8, already gaps in
  `docs/conformance.md`), `assertion.timestamp.malformed` (the `c2pa.time-stamp`
  assertion is not read; `PRED-TIME-002`).
- *Nothing to report* (2): `algorithm.deprecated` (empty list),
  `assertion.dataHash.redacted` (deprecated; `assertion.hardBinding.redacted`
  is emitted instead, SPEC-036).

### Candidates

- **P04-1 — a repeated manifest label moves the active manifest (§15.5.1).**
  `ManifestStore::fromTree()` stores manifests in a label-keyed array; PHP
  keeps the first position of a key that is written twice, so for `[X, Y, X']`
  the last key is `Y` and `Y` is validated as the active manifest. The spec
  and `c2pa-rs` (`insert_restored_claim()` sets the provenance path from each
  claim in turn, so the last box wins) take `X'`. Measured: on a rewritten
  `fixture-signed.mp4` this verifier reports `Y` active, `c2patool` 0.28.1
  reports `X'` (both `Invalid` there, because the larger store moved the boxes
  after it). Nothing refuses a repeated label (`JumbfParser`, `ManifestStore`,
  read). **Possibly more lenient than `c2patool`.** The case: a file whose
  hard binding survives a larger store (an ISOBMFF file whose C2PA box is last,
  or any binding that excludes the store by box rather than by byte range),
  signed with `Y` alone; someone adds `X` before and a broken `X'` after. Here
  `Y` is judged and the file can be `Trusted`; `c2patool` judges `X'` and says
  `Invalid`. Not a forgery (`Y` is genuine for these bytes), but a verdict that
  differs. Next: refuse a repeated manifest label (or key by position), with a
  probe judged by both `c2patool` versions.
- **P04-2 — an empty `claim_generator_info` in a v2 claim (§15.6.2).**
  `Claim::fromMap()` treats `[]` as "present but empty" (SPEC-022's extension
  of SPEC-007 amendment 4, measured on a v1 parent claim). CBOR's empty map
  decodes to the same `[]`, so a v2 claim with `claim_generator_info: {}` is
  accepted with no `name` (measured with `php -r`). `c2pa-rs` decodes the v2
  field into `ClaimGeneratorInfo`, whose `name` is required (`claim.rs`, the
  v2 decoder, "claim_generator_info is missing or invalid"), so the claim is
  refused. **Possibly more lenient than `c2patool`.** The case: a signer
  writes `{}`; here `Valid`, in `c2patool` `Invalid` (`claim.malformed`).
  Next: limit the empty-list exception to version 1 claims, with a probe.
- **P04-3 — a claim without `alg` (§15.4.2).** This verifier follows the
  spec: with every hash carrying its own `alg`, a claim without one is fine.
  `c2pa-rs`'s loader refuses such a claim outright (`store.rs`,
  `from_jumbf_impl`: no `alg_raw()` → `algorithm.unsupported`). **Possibly
  more lenient than `c2patool`**, while conformant. The case: a JPEG whose
  claim has no `alg`, whose data hash and every claim hashed URI say
  `sha256`: here `Valid` or `Trusted`, in `c2patool` `Invalid`. For ISOBMFF it
  combines with P03-1: no `alg` anywhere still yields SHA-256 here. Note: P03's
  row on §13.1 says `c2pa-rs` "then assumes SHA-256"; that holds for
  `Claim::alg()`, but the loader refuses first. Next: decide whether to follow
  `c2patool`; record the difference in `docs/comparison.md` either way.
- **P04-4 — `c2pa.cloud-data` is not read (§15.2.2 codes; the rules are in
  §15.10).** No code in `src/` checks a cloud-data assertion; only
  `ExternalReferenceCheck::FORBIDDEN_LABELS` names the label. SPEC-039 says
  the `assertion.cloud-data.*` codes are "refused by name"; that does not
  match `src/` (read). `c2pa-rs` 0.91.1 checks the structure without fetching
  (`claim.rs`, `verify_cloud_data()`: size, a hard binding behind it, actions
  in an update manifest, forbidden labels). **Possibly more lenient than
  `c2patool`.** The case: a signed manifest whose cloud-data assertion names
  `c2pa.hash.data`, or has size 0: here `Valid`, in `c2patool` `Invalid`
  (`assertion.cloud-data.hardBinding` or `.malformed`). To be weighed with
  pack p06 (§15.10).
- **P04-5 — codes under another name, not yet recorded.** BMFF's unknown
  algorithm (`assertion.bmffHash.mismatch` for `algorithm.unsupported`), a
  `hashed_ext_uri` without `alg` (`assertion.external-reference.malformed`),
  and plain text's two refusals (`general.error` for
  `manifest.text.multipleWrappers` and `.corruptedWrapper`). Every one is a
  failure either way. **Documentation only**: a line in `docs/comparison.md`
  or `docs/conformance.md` §3.
- **P04-6 — no non-attribution warning (§15.3).** The report gives the state
  and the failures, but nothing tells a reader not to attribute an invalid
  manifest's data to its signer. **Documentation only**: the README's
  section for callers, and the plugin's display.
- **P04-7 — no `.c2pa` sidecar (§15.5.3.1).** `c2patool` reads
  `asset.c2pa` beside a file path that embeds no store (`c2pa-rs`
  `Reader::from_file`, `reader.rs`); this verifier reads one stream and says
  "no manifest". Never a wrong `Valid`. **Documentation only**: a row in
  `docs/comparison.md`.

## §15.7 to §15.9 — Signature, time-stamp and revocation

These three sections are all validator rules: how the claim signature is
judged (§15.7), how a time-stamp is found and judged and which time the
signer's certificate is then judged at (§15.8), and how revocation is
established (§15.9). The verifier makes no network request by design, so
revocation is known only from the OCSP responses the signer stapled into
`rVals` (SPEC-030); everything §15.9 says about online queries and AIA
fetches is out by that rule. Two status-code habits run through the rows:
like `c2pa-rs`, this verifier reports an expired signer as
`signingCredential.expired` and issues `claimSignature.insideValidity` with
every verified signature (SPEC-039), where §15.8.2 names
`claimSignature.outsideValidity` and `insideValidity` only inside the
period. `c2pa-rs` here means 0.91.1, the engine of `c2patool` 0.28.1.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 15.7 | Resolve the claim's signature URI; absent, unresolvable or outside this manifest's box is `claimSignature.missing`. | `Manifest::checkReferences()`: the URI must resolve to this manifest's own signature box, a URI into another manifest is refused, both remapped to `claimSignature.missing`; a missing signature box is the same code (`Manifest::theOne()`) | covered | read |
| 15.7 | The signer's credential is validated per Chapter 14. | SPEC-014, SPEC-015, SPEC-047 | covered, as far as the §14 tables are | read: the §14 tables above |
| 15.7 | A credential not acceptable for its type is `signingCredential.invalid`. | `CertificateProfileCheck::checkLeaf()` (SPEC-015) | covered | measured: `CertificateProfileCheckTest` AC2 |
| 15.7 | A signature algorithm not on the §13.2 lists is `algorithm.unsupported`. | `SignatureVerifier`: any alg other than ES256/384/512, PS256/384/512, EdDSA throws with `AlgorithmUnsupported` | covered | measured: `SignatureVerifierTest` AC9 |
| 15.7 | Before the chain is built, first determine whether a valid time-stamp is present. | `Verifier::verify()` runs `TimestampCheck::check()` before `check()`; the chain is judged at `TimestampResult::trustedTime()` | covered | read |
| 15.7 | A chain should be verified from the credential to a configured anchor. | `ChainCheck::checkCertificates()` | covered | measured: `ChainCheckTest`, `TrustMatrixTest` |
| 15.7 | An anchor configuration's `notBefore` / `notAfter` bound the signatures it validates. | the settings format has no such dates (as in the §14.4.1 row) | n/a | read |
| 15.7 | No chain: `signingCredential.untrusted`, the claim rejected; else `signingCredential.trusted`. | both codes, verbatim. The rejection is not a state of its own: untrusted leaves the file `Valid`, as `c2patool` (§14.3.2 row) | covered; the state by design | measured: `ChainCheckTest` AC2 ("untrusted, and the state is Valid") |
| 15.7 | Verify the signature per §13.2: failure `claimSignature.mismatch`, success `claimSignature.validated`. | `ClaimSignatureCheck::checkBytes()` | covered | measured: `SignatureVerifierTest` AC2, AC3 |
| 15.7 | Headers are the union of both buckets unless §13.2 or §14.5 says otherwise. | `alg` protected (`CoseSign1`; `CoseSign1Test` AC9, SPEC-008); `x5chain` per §14.5 (SPEC-047); `sigTst`, `sigTst2`, `rVals` read from the unprotected bucket, where §10.3.2.5 and §14.5 place them (`TimestampHeader::fromUnprotected()`, `OcspCheck::responseBytes()`), as `c2pa-rs` | covered | read |
| 15.8.1.1 | More than one token in `tstTokens`: `timeStamp.malformed`, and the time-stamps are ignored. | `TimestampCheck::checkHeader()` judges the first token and only counts the rest (SPEC-017 AC8); nine or more are malformed (`TimestampHeader::DEFAULT_MAX_TOKENS`) | **candidate** | measured: `TimestampCheckTest` "SPEC-017 AC8: one token is judged; a doubled header judges the first only" → P05-1 |
| 15.8.1.1 | `sigTst`: the value is a `TimeStampResp`; a status other than 0 or 1 is `timeStamp.malformed` and ignored. | `TimeStampToken::status()` refuses, `checkHeader()` turns it into `timeStamp.malformed` | covered | measured: `TimeStampTokenTest` SPEC-016 AC6 (status 1 accepted, status 2 refused) |
| 15.8.1.1 | `sigTst`: take the `timeStampToken` from the response. | `TimeStampToken::fromHeaderValue()` | covered | measured: `TimeStampTokenTest` SPEC-016 AC6 (both wrappers) |
| 15.8.1.1 | `sigTst2`: the value is a bare `TimeStampToken`. | the same reader; either shape is read from either header, told apart by the first child | covered | measured: the same test |
| 15.8.1.2 | Skip the time-stamp assertion step when a header token passed; follow it otherwise. | no `c2pa.time-stamp` assertion is read anywhere in `src/` | **candidate** | read → P05-2 |
| 15.8.1.2 | Look the manifest up in the map built from time-stamp assertions; try each token until one passes. | as above | **candidate** | read → P05-2 |
| 15.8.2 | The token's signature algorithm not on the §13.2 lists: `timeStamp.untrusted`, ignored. | an OID outside `TimestampCheck::SIGNATURE_ALGORITHMS` is `timeStamp.untrusted` naming it. That table holds RSA PKCS#1 v1.5 (`rsaEncryption`, `sha*WithRSAEncryption`), which §13.2.1 does not list (its deprecated list is empty) | **partial** | measured: `TimestampCheckTest` SPEC-017 AC3; the rest read → P05-3 |
| 15.8.2 | The token's signature does not validate (RFC 2630 §5.6): `timeStamp.mismatch`, ignored. | a `messageDigest` that does not match the TSTInfo is `timeStamp.mismatch`; a CMS signature that does not verify is `timeStamp.untrusted`, as `c2pa-rs` (`time_stamp/verify.rs`). Ignored either way | partial (the code only) | measured: `TimestampCheckTest` SPEC-017 AC4 → P05-9 |
| 15.8.2 | No `messageImprint`: `timeStamp.malformed`, ignored. | `TstInfo::fromDer()` requires it and its two fields | covered | read |
| 15.8.2 | Imprint hash algorithm not on the §13.1 list: `timeStamp.untrusted`, ignored. | only SHA-256/384/512 are read; another is refused at parse, so it reports `timeStamp.malformed` (`c2pa-rs`: untrusted). Ignored either way | partial (the code only) | read: `TstInfo::fromDer()` → P05-9 |
| 15.8.2 | The imprint matches the claim (`sigTst`) or the signature (`sigTst2`) per §10.3.2.5.2; else `timeStamp.mismatch`. | `TimestampCheck::countersignedBytes()`, step 6 of `judge()` | covered | measured: `TimestampCheckTest` SPEC-017 AC5; probe `token-over-wrong-bytes` (SPEC-062) |
| 15.8.2 | `certificates` present, the TSA certificate in it, a chain to a TSA anchor; else `timeStamp.untrusted`. | the chain: `ChainCheck` with `TimestampCheck::tsaSettings()` (TSA anchors only, SPEC-031), untrusted when it fails. No certificates, or none matching the `sid`, is `timeStamp.malformed` instead. Ignored either way | partial (the code only) | measured: `TimestampCheckTest` SPEC-017 AC6, `TsaMatrixTest` AC5, `TimeStampTokenTest` SPEC-016 AC7 ("no certificates at all") → P05-9 |
| 15.8.2 | A validator may add `timeStamp.credentialInvalid` for an invalid certificate. | not issued; a TSA leaf off the profile is `timeStamp.untrusted`, and for a v2 claim also `signingCredential.invalid` (a failure), as `c2pa-rs` logs it | by design: SPEC-017 amendment 8 (the verdicts compare with `c2patool`) | measured: `TsaMatrixTest` AC1–AC4 (the `tsa-leaf-*` probes) |
| 15.8.2 | `genTime` within the TSA certificate's and every CA's validity; else `timeStamp.outsideValidity`, ignored. | the leaf: step 5 of `judge()`, `timeStamp.outsideValidity` (no accuracy margin; `c2pa-rs` allows one). A CA outside its validity at `genTime` breaks the chain walk (`ChainCheck::issuerFault()`) and reports `timeStamp.untrusted`. Ignored either way | partial (the code for CAs) | measured: `TimestampCheckTest` SPEC-017 AC4; probes `tsa-leaf-expired`, `tsa-int-expired`, `tsa-root-expired` (SPEC-062) |
| 15.8.2 | All checks passed: `timeStamp.trusted` and `timeStamp.validated`. | `judge()`; with `verify_trust: false` only `validated`, and the time is then not used | covered | measured: `TimestampCheckTest` SPEC-017 AC6 |
| 15.8.2 | Trusted and validated: `genTime` within the signer's and every CA's validity; else reject with `claimSignature.outsideValidity`. | the signer: `CertificateProfileCheck::checkLeaf()` at the timestamp's time, a failure named `signingCredential.expired`, as `c2pa-rs` (`certificate_profile.rs`). The CAs: only in the trust walk, as `signingCredential.untrusted`, which leaves the file `Valid` | **partial** | measured: `CertificateProfileCheckTest` AC3, `TsaMatrixTest` AC6; the CAs read → P05-4 |
| 15.8.2 | A time-stamp stays valid after the TSA's certificate expires, if `genTime` was inside its validity. | the TSA leaf and chain are judged at `genTime`, not now | covered | measured: `TimestampCheckTest` SPEC-017 AC6 (Truepic: trusted, no longer expired); `AnchorValidityTest` AC12 |
| 15.8.2 | With a present, trusted, validated time-stamp, use its time, not now, for the signer's and the TSA's certificate. | `Verifier::check()`: `$at = $timestamp->trustedTime()` for the profile, the chain and revocation | covered | measured: `TsaMatrixTest` AC6 ("an expired signer is kept only by a trusted timestamp") |
| 15.8.2 | TSA revocation need not be captured nor checked. | not checked | n/a | read |
| 15.8.2 | No usable time-stamp: the signer and every CA at now; `claimSignature.insideValidity`, or reject with `claimSignature.outsideValidity`. | the signer at now, `signingCredential.expired` when outside (SPEC-017 AC8, Nikon). `insideValidity` is issued with every verified signature, an expired signer included, as `c2patool` (SPEC-039). CAs as in the row two above | **partial**: the codes by design (SPEC-039), the CAs → P05-4 | measured: `TimestampCheckTest` SPEC-017 AC8; `InsideValidityTest` AC2 |
| 15.8.3 | A validator may check the `iat` header against the signer's validity and the time-stamp; if it does, `timeOfSigning.insideValidity` / `outsideValidity`. | `iat` is not read; `c2pa-rs` defines the two codes but issues neither | n/a (the option is not taken) | read |
| 15.9 | For CA certificates, revocation should be determined as their AIA indicates. | no network in the verification path (`OcspCheck` class comment, SPEC-030) | by design | read |
| 15.9 | For CA certificates, stapled OCSP responses should be used where AIA offers OCSP. | `OcspCheck::matching()` matches the leaf's `CertID` only; a response about an intermediate is skipped | **candidate** | read → P05-5 |
| 15.9 | A CA revoked at the judged time rejects the claim with `signingCredential.untrusted`. | never determined (see above) | **candidate** | read → P05-5 |
| 15.9 | A certificate with no revocation method is treated as not revoked. | no `rVals`: one `signingCredential.ocsp.skipped`, no failure | covered | measured: `OcspCheckTest` AC5 |
| 15.9 | Stapled `rVals` responses are decoded and validated per §15.9.1. | `OcspCheck::check()` | covered | measured: `OcspCheckTest` AC1–AC4 |
| 15.9 | Certificate status assertions in other manifests are used; several responses, each tried until one passes. | no `c2pa.certificate-status` assertion is read anywhere in `src/` | **candidate** | read → P05-6 |
| 15.9 | Nothing in the store and online: should query the OCSP responder. | no network | by design | read |
| 15.9.1 | Decode responses per RFC 6960 §3.2 requirements 1 to 4. | `OcspCheck::usable()`: the `CertID` names the leaf under its issuer, the signature verifies, the signer is the issuer or a responder it issued with id-kp-OCSPSigning. Not checked: the delegated responder's own validity (requirement 4, "currently authorized") | **partial** | measured: `OcspCheckTest` AC2, AC4; the rest read → P05-7 |
| 15.9.1 | Not revoked is established only with a trusted time-stamp, `thisUpdate` not after now, the stamped time before `thisUpdate` or inside the window (`producedAt` + 24 h without `nextUpdate`), `good`, an authorized responder. | `OcspCheck::statusOf()`: `good` inside `[thisUpdate, nextUpdate]` at the judged time, which is now when there is no trusted time-stamp; no 24-hour bound without `nextUpdate`; a stamped time before `thisUpdate` is skipped | **partial** | measured: `OcspCheckTest` AC1, AC6; the rest read → P05-7 |
| 15.9.1 | Check `revocationReason` to tell `removeFromCRL` from a revocation. | `OcspCheck::statusOf()`, `REASON_REMOVE_FROM_CRL` | covered | measured: `OcspCheckTest` AC8 |
| 15.9.1 | Conditions met: `signingCredential.ocsp.notRevoked` (or an online check). | `statusOf()`; a success that changes no state | covered | measured: `OcspCheckTest` AC1 |
| 15.9.1 | Conditions met but `revoked`: reject with `signingCredential.ocsp.revoked`. | `statusOf()`. Stricter than the rule: a stale `revoked` and one without a time-stamp still count (SPEC-030 open question 1, decided) | covered | measured: `OcspCheckTest` AC3 |
| 15.9.2 | A validator may query the OCSP responder online. | no network | by design | read |
| 15.9.2 | No online check: issue `signingCredential.ocsp.skipped`. | issued whenever no stapled response gives an answer, with the reason | covered | measured: `OcspCheckTest` AC5, AC6, AC7 |
| 15.9.2 | A query without a response: `signingCredential.ocsp.inaccessible`. | no query is ever made | n/a | read |
| 15.9.2 | The rules for an online response: the window, `good` or `removeFromCRL`, an authorized responder, a revocation after the stamped time, `unknown` recorded, else revoked. | no online response exists. The stapled `unknown` is recorded as `signingCredential.ocsp.unknown` | n/a | read |

### Candidates

- **P05-1 — two or more time-stamp tokens (§15.8.1.1).** The rule:
  `timeStamp.malformed` and no time-stamp. This verifier judges the first
  token and uses its time (SPEC-017 AC8, which cites a `c2pa-rs` comment
  about the first *header*). `c2pa-rs` 0.91.1 refuses more than one token
  (`parse_and_validate_sigtst` in `crypto/cose/sigtst.rs`: malformed,
  `NoTimeStampToken`). **Possibly more lenient than `c2patool`.** The
  case: a signer whose certificate has expired, a `sigTst2` with two
  tokens of which the first is valid and from a trusted TSA. Here the
  signer is judged at `genTime`, so the file is `Valid` or `Trusted`. In
  0.28.1 (read) the time-stamp is ignored and the signer is judged at now:
  `signingCredential.expired`, `Invalid`. Next: a `two-tokens` probe in
  the SPEC-062 matrix (expired signer, trusted TSA, the token doubled),
  judged by both `c2patool` versions; the behaviour of 0.27.22 was not
  read.
- **P05-2 — time-stamp assertions (§15.8.1.2).** `c2pa.time-stamp` is not
  read, so a manifest stamped later by another manifest's assertion is
  judged at now. `c2pa-rs` implements it: `store.rs` collects the tokens
  into `svi.timestamps` and `claim.rs` passes them to `verify_cose`. Today
  this verifier is the stricter one (a manifest `c2patool` keeps alive can
  be `expired` here); adopting the rule aligns it. Documentation only.
  One side case is **possibly more lenient than `c2patool`**: `c2pa-rs`
  fails a time-stamp assertion that does not parse with
  `assertion.timestamp.malformed` (a failure), and this verifier does not
  look at it. That is a §18 rule; pass it to that pack.
- **P05-3 — TSA signature algorithms (§15.8.2, §13.2.1).** RSA PKCS#1
  v1.5 is accepted for the token's signature, although §13.2.1 lists only
  ECDSA, RSASSA-PSS and Ed25519 and its deprecated list is empty.
  `c2pa-rs` accepts it too: `c2patool` validates the DigiCert and Truepic
  tokens of the corpus (measured, SPEC-017 AC1), and those are PKCS#1
  v1.5. Stricter than `c2patool` if adopted, and it would drop nearly
  every real time-stamp. SPEC-017's scope lists the algorithms from the
  corpus but does not name §15.8.2; one sentence there would record the
  decision.
- **P05-4 — CA validity at the judged time (§15.8.2).** The rule rejects
  the claim (`claimSignature.outsideValidity`) when any CA up to the
  anchor is outside its validity at the stamped time, or at now without a
  time-stamp. Here a CA's validity is checked only in the trust walk, so
  it costs trust (`signingCredential.untrusted`, the file stays `Valid`),
  and with `verify_trust: false` it is not checked. `c2pa-rs` does the
  same (`certificate_trust_policy.rs`, `test_intermediate_validity_is_checked`:
  not trusted); `AnchorValidityTest` AC12 measured 0.28.1 `Valid` on an
  expired intermediate. Stricter than `c2patool` if adopted.
- **P05-5 — revocation of CA certificates (§15.9).** Stapled responses
  about an intermediate are not used, so a revoked CA is never found.
  `c2pa-rs` also matches the end-entity certificate only
  (`cert_id_matches_signer` in `crypto/ocsp/mod.rs`). It is a "should";
  stricter than `c2patool` if adopted.
- **P05-6 — certificate status assertions (§15.9).** `c2pa.certificate-status`
  is not read. `c2pa-rs` collects such assertions (`store.rs`) but uses
  them only when `builder.certificate_status_should_override` is set, and
  that setting defaults to off (`settings/builder.rs`). Stricter than
  `c2patool` if adopted.
- **P05-7 — when a stapled response proves "not revoked" (§15.9.1).** Five
  differences, each only between `notRevoked` (a success that moves no
  state) and `skipped`: `notRevoked` is issued without a time-stamp, at
  now; `thisUpdate` is not compared with now when the time is the
  stamp's; a stamped time before `thisUpdate` is skipped where the rule
  accepts it; without `nextUpdate` there is no `producedAt` + 24 h bound;
  a delegated responder's own validity is not checked (RFC 6960 §3.2
  requirement 4). Documentation only. If the responder's validity were
  checked, a `revoked` from an expired responder would stop counting.
- **P05-8 — where `c2pa-rs` says revoked and the rule does not.** Read in
  `crypto/ocsp/mod.rs`: `c2pa-rs` logs `signingCredential.ocsp.revoked`
  for a `good` response whose window does not hold the stamped time, and
  for a `removeFromCRL` whose revocation time is at or before it. Both
  only when the responder's certificate is embedded and chains to a
  configured anchor (`check_stapled_ocsp_response` in
  `crypto/cose/ocsp.rs`). This verifier follows §15.9.1 and skips both,
  so the file stays `Valid` or `Trusted`. **Possibly more lenient than
  `c2patool`**, though the specification sides with this verifier. Not
  measured: on the two corpus files with a staple, 0.27.22 emits no OCSP
  code (SPEC-030), which fits responders that do not reach an anchor.
  Next: two probes with a responder under a configured anchor, judged by
  both versions, to decide whether this belongs in `docs/comparison.md`.
- **P05-9 — informational code names.** Three faults carry a different
  `timeStamp.*` code than §15.8.2 names: a CMS signature that fails
  (`untrusted`, the rule says `mismatch`), an imprint hash outside §13.1
  (`malformed`, the rule says `untrusted`), a token with no certificate
  for its signer (`malformed`, the rule says `untrusted`). In every case
  the time-stamp is ignored, as the rule wants. The first matches
  `c2pa-rs`. Documentation only.

## §15.10 to the end of §15 — Assertions, Ingredients and the Asset's Content

These sections are the validator's own algorithm: which assertions a
manifest type must and must not carry (§15.10.1), the hashed-URI and
assertion-specific checks (§15.10.3), external data (§15.10.4), the
recursive ingredient walk (§15.11) and the hard bindings (§15.12). Every
sentence binds a validator. Where `c2pa-rs` is named, it was read at 0.91.1
(the version `c2patool` 0.28.1 uses). Hard bindings for formats this
verifier does not read (box hashes, JPEG-XL, fonts, multi-asset,
collections, ZIP) are one row each. "v2 only" refers to the decision in
`docs/conformance.md`, *§15.10.3.2.3 for version 1 claims — not applied, by
decision*, which follows `c2pa-rs` without `verify.strict_v1_validation`.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 15.10.1.1 | Check the assertions a manifest type requires and forbids. | the rows of §15.10.1.2 and §15.10.1.3 below | covered, as far as those rows are | read |
| 15.10.1.2 | A standard manifest without a hard binding is rejected, `claim.hardBindings.missing`. | `Verifier::check()`, `DataHashCheck::check()`; a binding listed only in `gathered_assertions` counts as none (SPEC-013 amendment 13) | covered | measured: `VerifierTest` "AC15: a signed manifest with no hard binding is claim.hardBindings.missing and Invalid, never Valid", "AC19" |
| 15.10.1.2 | More than one hard binding is rejected, `assertion.multipleHardBindings`. | `HardBindings::in()` counts boxes of every hard-binding family before a check is chosen (SPEC-012 amendment 9) | covered | measured: `DataHashCheckTest` "AC8: exactly one hard binding", both "AC11" tests |
| 15.10.1.2 | At most one `parentOf` ingredient, else `manifest.multipleParents`. | `UpdateManifestCheck::rules()`; counts every `parentOf` assertion, where `c2pa-rs` counts only those with a manifest reference (stricter) | covered | measured: `UpdateManifestTest` "AC6: a standard manifest with two parentOf ingredients is manifest.multipleParents" |
| 15.10.1.2 | Exactly one actions assertion holds the `c2pa.created` or `c2pa.opened`. | `ActionsCheck::checkAssertions()` (first action is an opening) and `contentRules()` (one opening across the claim) | covered for v2 claims; by design for v1 (conformance decision) | measured: `ActionsCheckTest` "AC3", `ActionsContentTest` "AC1: one opening" |
| 15.10.1.3 | An update manifest has exactly one ingredient, `parentOf`, else `manifest.update.wrongParents`. | `UpdateManifestCheck::rules()`: no `parentOf` is `wrongParents`; more than one ingredient is `manifest.update.invalid`, `c2pa-rs`'s code | covered (one code is `c2pa-rs`'s) | measured: `UpdateManifestTest` "AC4: an update manifest that breaks §11.2.3" |
| 15.10.1.3 | No hard binding and no thumbnail in an update manifest, else `manifest.update.invalid`. | `rules()`: any `c2pa.hash.*` and any `c2pa.thumbnail.claim*` (`c2pa-rs` refuses only more than one claim thumbnail); an ingredient thumbnail is allowed | covered | measured: `UpdateManifestTest` "AC4" |
| 15.10.1.3 | No `c2pa.hash.multi-asset` in an update manifest. | `rules()`: the `c2pa.hash.` prefix includes it | covered | read |
| 15.10.1.3 | Every action of an update manifest is one of the allowed four, else `manifest.update.invalid`. | `UpdateManifestCheck::ALLOWED_ACTIONS` | covered | measured: `UpdateManifestTest` "AC4" |
| 15.10.2 | Gather the redacted assertions of each ingredient manifest from `redacted_assertions`. | `Manifest::redactionsOf()`, `ManifestStore::redacting()`: the active manifest and the manifests the graph reaches (SPEC-035 amendment 5) | covered | measured: `RedactionTest` "AC1", "AC9: only a manifest the graph reaches may redact (amendment 5)" |
| 15.10.2, 15.10.3.1 | A claim never redacts its own assertions: `assertion.selfRedacted`. | `HashedUriCheck::redactions()` | covered | measured: `RedactionTest` "AC4: self-redaction" |
| 15.10.3.1 | A redacted actions assertion is rejected, `assertion.action.redacted`. | `HashedUriCheck::redactions()`; also a redacted hard binding, `assertion.hardBinding.redacted` (SPEC-036) | covered | measured: `RedactionTest` "AC3", `HashedUriCheckTest` "AC8", `HardBindingRedactedTest` |
| 15.10.3.1 | Any other redacted assertion is considered valid. | `HashedUriCheck::check()` skips a redacted entry only when its box is gone; a zeroed box still present is hashed | partial | read → P06-9 |
| 15.10.3.1 | Gathered assertions are validated like created ones. | `HashedUriCheck::check()` walks both lists; so do `ActionsCheck` and `ManifestGraph` | covered | measured: `ActionsCheckTest` "AC4" for actions; the rest read |
| 15.10.3.1 | A URI outside the same manifest is rejected, `assertion.outsideManifest`. | `Manifest::checkReferences()` (SPEC-040) | covered | measured: `OutsideManifestTest` "AC1", "AC4" |
| 15.10.3.1 | A URI that cannot be resolved is rejected, `assertion.missing`. | `Manifest::resolve()`, `checkReferences()` | covered | measured: `ManifestStoreTest` "AC10" |
| 15.10.3.1 | Hash per §15.4 and §8.4.2.3; mismatch `assertion.hashedURI.mismatch`, else `.match`. | `HashedUriCheck::entry()` | covered | measured: `HashedUriCheckTest` "AC1", "AC2", "AC7" |
| 15.10.3.1 | CBOR that is not well-formed: `assertion.cbor.invalid`; bad JSON: `assertion.json.invalid`. | `Manifest::assertionData()`: JSON as specified; CBOR refused as `general.error` | partial (code differs; still `Invalid`) | measured: `ManifestStoreTest` "AC13"; CBOR read (`PRED-ASSE-007`) → P06-10 |
| 15.10.3.1 | An assertion in the store that no claim list names: `assertion.undeclared`. | `HashedUriCheck::check()`, unknown boxes included | covered | measured: `HashedUriCheckTest` "AC5", "AC6" |
| 15.10.3.1 | The contents of a `metadata` field are not validated. | nothing reads it | covered by construction | read |
| 15.10.3.2 | Dispatch by label to the assertion-specific steps. | `Verifier::check()`: actions, external references, icons, ingredients (via the graph). Not dispatched: `c2pa.cloud-data`, `c2pa.session-keys`, `c2pa.time-stamp`, `c2pa.alternative-content-representation` | partial | read → P06-1, P06-2, P06-8 |
| 15.10.3.2.1 | `c2pa.cloud-data` needs `label`, `size`, `location`, else `assertion.cloud-data.malformed`. | no code reads the assertion (its label appears only in `ExternalReferenceCheck`'s forbidden list) | candidate | read → P06-1 |
| 15.10.3.2.1 | A cloud-data label naming a hard binding (or ingredient, actions.v2, cloud-data) is `assertion.cloud-data.hardBinding`. | none | candidate | read → P06-1 |
| 15.10.3.2.1 | In an update manifest, a cloud-data label naming actions is `assertion.cloud-data.actions`. | none | candidate | read → P06-1 |
| 15.10.3.2.1 | The forbidden labels are `assertion.cloud-data.malformed`. | none | candidate | read → P06-1 |
| 15.10.3.2.1 | `location` per §15.10.4.2; a retrieved box's label matches, else `.labelMismatch`. | nothing is retrieved | n/a | read |
| 15.10.3.2.2 | `location` with a `url`, else `assertion.external-reference.malformed`. | `ExternalReferenceCheck::fault()` (SPEC-032) | covered | measured: `AssertionRulesTest` "AC5: the location must hold a url, and a hash its algorithm" |
| 15.10.3.2.2 | `alg` without `hash`, or the reverse, is malformed. | `ExternalReferenceCheck::fault()` | covered | measured: `AssertionRulesTest` "AC5" |
| 15.10.3.2.2 | Hashed variant, data retrieved: compare, else `.hashMismatch`. | nothing is retrieved | n/a | measured: `AssertionRulesTest` "AC6: a well-formed external reference passes, and nothing is fetched" |
| 15.10.3.2.2 | A forbidden `label` is malformed. | `ExternalReferenceCheck` forbidden list | covered | measured: `AssertionRulesTest` "AC4" |
| 15.10.3.2.2 | Retrieved box's label matches `label`, else `.labelMismatch`. | nothing is retrieved | n/a | read |
| 15.10.3.2.3 | An actions assertion has an `actions` field, else `assertion.action.malformed`. | `ActionsCheck::checkData()` | covered, v2 only | measured: `ActionsCheckTest` "AC6: malformed content is refused naming the field; as claim v1 the same six pass" |
| 15.10.3.2.3 | `c2pa.created`/`c2pa.opened` only as the first action of the first actions assertion. | `checkAssertions()` (first action) plus `contentRules()` (one opening): together the same set `c2pa-rs`'s three checks refuse | covered, v2 only | measured: `ActionsCheckTest` "AC3", `ActionsContentTest` "AC1" |
| 15.10.3.2.3 | `opened`, `placed`, `removed` without `parameters`, or empty: `ingredientMismatch`. | `contentRules()` | covered, v2 only | measured: `ActionsContentTest` "AC2: opened, placed, removed without references" |
| 15.10.3.2.3 | No `ingredients` (or v1 `ingredient`) field: `ingredientMismatch`. | `contentRules()`; either field accepted in either version, as `c2pa-rs` | covered, v2 only | measured: `ActionsContentTest` "AC2" |
| 15.10.3.2.3 | `ingredients` not an array of at least one: `ingredientMismatch`. | `contentRules()` | covered, v2 only | measured: `ActionsContentTest` "AC2" |
| 15.10.3.2.3 | `c2pa.opened`: exactly one valid hashed URI to a `parentOf` ingredient of this manifest. | `contentRules()`: references counted by their last path segment; the reference's hash is not compared; one good and one unresolvable reference pass | partial | measured: `ActionsContentTest` "AC3"; the rest read → P06-5, P06-6 |
| 15.10.3.2.3 | `c2pa.placed`: each reference resolves to a `componentOf` ingredient here. | `contentRules()`: at least one, as `c2pa-rs` | partial | measured: `ActionsContentTest` "AC2", "AC3" → P06-7 |
| 15.10.3.2.3 | `c2pa.removed`: each reference resolves to a `componentOf` ingredient in another manifest. | `contentRules()`: read as `placed`, in this manifest, as `c2pa-rs` | partial | read → P06-7 |
| 15.10.3.2.3 | `c2pa.transcoded`/`repackaged`: each given reference is a `parentOf` here. | `contentRules()`: at least one, as `c2pa-rs` | partial | measured: `ActionsContentTest` "AC4" → P06-7 |
| 15.10.3.2.3 | `c2pa.redacted`: `parameters.redacted` present and resolving, else `assertion.action.redactionMismatch`. | `ActionsCheck::redactionFault()`, only when the action has `parameters` | by design: SPEC-037, as `c2patool` reads the rule | measured: `RedactedActionTest` "AC1"–"AC5" → P06-7 |
| 15.10.3.2.3 | A `softwareAgent(s)` icon is validated per §15.10.3.3. | `IconReferenceCheck::actionsIcons()` (SPEC-034) | covered, v2 only | measured: `IconReferenceTest` "AC4: icons in the actions assertion" |
| 15.10.3.2.3 | `relatedAssertions` must be a non-empty array, else `assertion.action.malformed`. | `contentRules()` | covered, v2 only | measured: `ActionsContentTest` "AC6: related assertions" |
| 15.10.3.2.3 | Each `relatedAssertions` entry: hashed URI validated, resolving within this manifest, else malformed. | `contentRules()`: the label must be one this claim lists and the URI not another manifest's; the hash is not compared | partial | measured: `ActionsContentTest` "AC6"; hash read → P06-5 |
| 15.10.3.2.3 | `relatedAssertions` naming an ingredient or actions assertion: malformed. | `contentRules()` | covered, v2 only | measured: `ActionsContentTest` "AC6" |
| 15.10.3.2.3 | `c2pa.watermarked(.bound)` needs a `c2pa.soft-binding`, else `softBindingMissing`. | `contentRules()` | covered, v2 only | measured: `ActionsContentTest` "AC7: watermarks" |
| 15.10.3.2.3 | A template's `icon` is validated per §15.10.3.3. | `IconReferenceCheck::actionsIcons()` | covered, v2 only | measured: `IconReferenceTest` "AC4" |
| 15.10.3.2.3 | All of the above for v1 claims (`c2pa.actions`, `ingredient`). | only "at most one actions assertion" is applied | by design: `docs/conformance.md`, *§15.10.3.2.3 for version 1 claims — not applied, by decision* | measured: `ActionsCheckTest` "AC4", "AC6"; `RedactedActionTest` "AC6: v1 claims are not checked" |
| 15.10.3.2.4 | No validation for `c2pa.metadata`; unlisted fields should not be rejected. | nothing checks it | covered by construction | read |
| 15.10.3.2.5 | `c2pa.session-keys`: verify `signerBinding` with the session key over the signer's certificate. | none | candidate | read (`PRED-CRYP-024`) → P06-8 |
| 15.10.3.2.6 | `c2pa.time-stamp` is one CBOR map with at least one pair, else `assertion.timestamp.malformed`. | none: the assertion is never read | candidate | read → P06-2 |
| 15.10.3.2.6 | Keep the token for §15.8.2. | not read; the signer is judged at *now* | by design: `docs/conformance.md` §3 (`PRED-TIME-002`, `-003`), stricter | read |
| 15.10.3.2.7 | At most one `exif.originalPreservationImage` representation, else `alternativeContentRepresentation.malformed`. | none | candidate | read (`PRED-ASSE-013`) → P06-8 |
| 15.10.3.2.7 | Exactly one of `multiAssetPartIndex` / `embeddedOriginalPreservationImage`; the index needs a multi-asset hash and must be in bounds. | none | candidate | read (`PRED-ASSE-026`) → P06-8 |
| 15.10.3.2.7 | The embedded image's hashed URI has a hash that matches, else `.hashMismatch`; otherwise record `.match`. | none | candidate | read (`PRED-ASSE-014`) → P06-8 |
| 15.10.3.3 | A `hashed_ext_uri` the validator retrieves goes through §15.10.4.2. | nothing is retrieved | n/a | read |
| 15.10.3.3 | A `hashed_uri` with no `url` or no destination: `hashedURI.missing`. | icons: `assertion.missing` (`IconReferenceCheck`); an ingredient's manifest: `ingredient.manifest.missing` (`ManifestGraph::descend()`); ingredient thumbnails and `data`, action references: resolved by label or not at all | partial | measured: `IconReferenceTest` "AC3", `IngredientDeltasTest` "AC6"; the rest read → P06-5 |
| 15.10.3.3 | No `hash` field, or a hash that differs: `hashedURI.mismatch`. | icons: compared with the hash the claim records (`IconReferenceCheck::check()`); ingredient `activeManifest`/`claimSignature`: `IngredientManifestCheck`; ingredient thumbnail and `data`, action ingredient references, `relatedAssertions`: not compared, as `c2pa-rs` | partial | measured: `IconReferenceTest` "AC2"; the rest read → P06-5 |
| 15.10.4.1 | Validate the claim before retrieving external data; never retrieve for a rejected claim. | nothing is retrieved (no network, SPEC-013, SPEC-014) | covered by construction | read |
| 15.10.4.1 | Failing to retrieve external data never rejects a claim. | nothing is retrieved | covered by construction | read |
| 15.10.4.1 | Retrieved assertions are validated as if embedded. | nothing is retrieved | n/a | read |
| 15.10.4.2 | The retrieval procedure: url, size, Content-Type, hash, `assertion.hashedURI.*`. | nothing is retrieved | n/a | read |
| 15.11.1 | Validate the active manifest; reject it on any failing step. | `Verifier::check()` | covered | measured: `VerifierTest` "AC10: the drift alarm" |
| 15.11.2.1 | Validate every ingredient of a standard manifest, whatever its relationship. | `ManifestGraph::descend()` follows every reference, `inputTo` too; `IngredientManifestCheck::check()` | covered | measured: `ManifestGraphTest` "AC5", `IngredientManifestCheckTest` "AC3" |
| 15.11.2.2 | An update manifest's `parentOf` ingredient is validated. | the same walk | covered | measured: `UpdateManifestTest` "AC1" |
| 15.11.2.3 | Time-stamp manifests in an ingredient are ignored. | `JumbfParser::refuseUnreadable()` refuses a `c2tm` box | by design: fail closed, stricter | measured: `UpdateManifestTest` "AC7: c2cm and c2tm stay refused; only c2um was opened" |
| 15.11.3.2 | No `relationship`: `assertion.ingredient.malformed`. | `IngredientAssertion::fromAssertion()` | covered | measured: `IngredientAssertionTest` "AC2" (`no-relationship`) |
| 15.11.3.2 | `relationship` not `parentOf`, `inputTo` or `componentOf`: malformed. | `fromAssertion()` | covered | measured: `IngredientAssertionTest` "AC2" (`relationship-childof`, `relationship-int`) |
| 15.11.3.2 | `activeManifest` and `digitalSourceType` together: malformed. | `fromAssertion()`; `c2pa-rs` has no such rule (`docs/comparison.md`) | covered | measured: `IngredientAssertionTest` "AC2" (`manifest-and-dst`) |
| 15.11.3.3 | Validate all ingredient manifests recursively, with results equal to the depth-first algorithm. | `ManifestGraph::fromStore()`, `descend()`; bounded at 32 levels and 256 ingredient assertions (`general.error` beyond) | covered | measured: `ManifestGraphTest` "AC5", all three "AC7" tests |
| 15.11.3.3 | A manifest whose claim cannot be located: `claim.missing`. | `Manifest::read()` | covered | read |
| 15.11.3.3 | An ingredient assertion that does not resolve, mismatches or is zeroed is skipped. | the walk follows it anyway; its mismatch already fails the claim in `HashedUriCheck` | covered in effect (stricter) | read |
| 15.11.3.3 | An ingredient with `activeManifest` (or `c2pa_manifest`) is recorded and walked. | `descend()`, `$referenced` | covered | measured: `ManifestGraphTest` "AC5" |
| 15.11.3.3 | No manifest reference: `ingredient.unknownProvenance`, unless `inputTo`. | `descend()` | covered | measured: `IngredientAssertionTest` "AC4: an inputTo ingredient without a manifest is not unknown provenance" |
| 15.11.3.3 | A manifest with matching redactions uses the claim-signature method. | `IngredientManifestCheck::hash()` → `claimSignature()`, for a v2 claim as `c2pa-rs`; a v1 claim stays with the box hash (SPEC-035 amendment 3) | covered | measured: `RedactionTest` "AC1", "AC6" |
| 15.11.3.3 | Otherwise either method. | `hash()`: the box hash; the pre-1.3 hash over the claim is accepted silently, as `c2pa-rs` | covered | measured: `IngredientManifestCheckTest` "AC1: the box hash — validated, legacy (silent), mismatch" |
| 15.11.3.3 | Merge `validationResults`: return recorded entries not reproduced, and new entries not recorded. | `IngredientManifestCheck::drop()` removes a reproduced, recorded status (never one naming the active manifest or the borrowed binding); recorded entries not reproduced are not added | partial | measured: `IngredientManifestCheckTest` "AC4", "AC5" → P06-11 |
| 15.11.3.3 | A v3 ingredient with `activeManifest` and no `validationResults`: `assertion.ingredient.malformed`. | `fromAssertion()` | covered | measured: `IngredientAssertionTest` "AC2" (`manifest-no-results`) |
| 15.11.3.3 | Manifests outside the ingredient list should be ignored. | `ManifestGraph::$unreferenced` | covered | measured: `M7AbsenceTest` "absence: a manifest nobody references is never validated — a broken signature there changes nothing" |
| 15.11.3.3.1 | `claimSignature` absent or unresolvable: `ingredient.claimSignature.missing`. | `claimSignature()`: absent is `.missing`; the signature box hashed is the ingredient claim's own, not the recorded url's target | covered | measured: SPEC-035 AC2 tests for the mismatch; absence read |
| 15.11.3.3.1 | Hash the signature box per §15.4; differ or no hash: `.mismatch`; equal: `.validated`. | `claimSignature()`, under the ingredient claim's algorithm (SPEC-052 amendment 1, as `c2pa-rs`) | covered | measured: `RedactionTest` "AC2" |
| 15.11.3.3.1 | Validate the ingredient's signature, time-stamp and revocation (§15.7–§15.9). | `IngredientManifestCheck::manifest()`: timestamp, signature, profile, chain; no stapled-OCSP step | partial | read → P06-3 |
| 15.11.3.3.1 | A redacted URI whose box holds non-zero content: `assertion.notRedacted`. | `HashedUriCheck::notRedacted()` | covered | measured: `RedactionTest` "AC5: declared redacted but still there" |
| 15.11.3.3.1 | Validate the non-redacted assertions per §15.10, hard bindings excepted. | `manifest()`: hashed URIs, actions, external references, icons; `UpdateManifestCheck` over every manifest; never the data hash | covered, with §15.10's gaps | measured: `IngredientManifestCheckTest` "AC7: the data hash never runs on an ingredient manifest", `M7AbsenceTest` |
| 15.11.3.3.1 | No hash-mismatch code for `activeManifest` under this method. | `hash()` returns before the box hash is tried | covered | measured: `RedactionTest` "AC1" |
| 15.11.3.3.2 | `activeManifest` url absent or unresolvable: `ingredient.manifest.missing`. | `descend()`: a label not in the store is `.missing`; a reference without url is `assertion.ingredient.malformed` | covered (one code differs) | measured: `IngredientDeltasTest` "AC6"; `IngredientAssertionTest` "AC2" |
| 15.11.3.3.2 | Box hash per §15.4; differ or absent: `ingredient.manifest.mismatch`; equal: `.validated`. | `hash()`; an absent hash is `assertion.ingredient.malformed` | covered | measured: `IngredientManifestCheckTest` "AC1" |
| 15.12 | The binding of the active standard manifest, or of the first standard manifest up the `parentOf` chain; none: `claim.hardBindings.missing`. | `Verifier::bindingOf()`, `UpdateManifestCheck::bindingManifest()` (follows `c2pa.hash.data`; any other parent binding ends as missing) | covered | measured: `UpdateManifestTest` "AC2", "AC5" |
| 15.12 | Parts of a multi-part asset may be validated separately. | not read | by design (`PRED-CONT-001`) | read |
| 15.12.1.1 | Exclusions that overlap or are negative: `assertion.dataHash.malformed`. | `DataHashCheck::check()`; the list is sorted first, as `c2pa-rs` sorts it | covered | measured: `DataHashCheckTest` "AC5", "AC6" → P06-7 for unsorted lists |
| 15.12.1.1 | After update manifests, widen the store exclusion and shift the later ones. | `DataHashCheck::check()` with `$adjustForUpdate`; the cover rule still applies afterwards | covered | measured: `UpdateManifestTest` "AC3: the stale exclusion is adjusted to the store, and no further" |
| 15.12.1.1 | Hash all bytes but the exclusions; an exclusion past the end: `assertion.dataHash.mismatch`. | `DataHashCheck::check()`, `hashExcept()` | covered | measured: `DataHashCheckTest` "AC2"; past-the-end read |
| 15.12.1.1 | An `alg` outside §13.1: `algorithm.unsupported`; no `hash`: `assertion.dataHash.mismatch`. | `DataHashCheck::check()` | covered | measured: `DataHashCheckTest` "AC6", "AC7" |
| 15.12.1.1 | The store's exclusion holds only the store and padding, else `mismatch`. | `DataHashCheck::check()` (SPEC-012 amendment 7) | covered | measured: `DataHashCheckTest` "AC3: the store's exclusion holds the store and nothing else" |
| 15.12.1.1 | Other exclusions: `assertion.dataHash.additionalExclusionsPresent`. | `DataHashCheck::check()` | covered | measured: `DataHashCheckTest` "AC4" |
| 15.12.1.1 | Ignore `pad` and `pad2`. | never read | covered by construction | read |
| 15.12.1.1 | No error: `assertion.dataHash.match`. | `DataHashCheck::check()` | covered | measured: `DataHashCheckTest` "AC1" |
| 15.12.1.1 | On a mismatch, try a multi-asset hash; without one, `mismatch`. | the mismatch stands; no fallback | by design: stricter (`docs/conformance.md` §3, `PRED-ASSE-021`) | read |
| 15.12.1.2 | JPEG: the exclusion's length equals all C2PA APP11 segments together. | `DataHashCheck::check()`: the range holds exactly its pieces | covered | measured: `DataHashCheckTest` "AC3" |
| 15.12.1.3 | Text: find the wrapper matching the exclusions; none: `assertion.dataHash.malformed`. | `PlainTextManifestStoreExtractor`, then the cover rule: an uncovered wrapper is `mismatch` | covered (code differs) | measured: `PlainTextTest` "AC9", "AC14" → P06-10 |
| 15.12.1.3 | Two matching wrappers: `manifest.text.multipleWrappers`. | the extractor refuses two wrappers as a container error | covered (code differs) | measured: `PlainTextTest` "AC5: two wrappers are an error (stricter than the oracle, named)" |
| 15.12.1.3 | Remove the wrapper, normalise to NFC, encode UTF-8, hash. | raw bytes are hashed, as the oracle does | by design: SPEC-060 open question 5, decided 2026-10-07 | measured: `PlainTextTest` "AC14" |
| 15.12.1.3 | Compare: `assertion.dataHash.match` or `.mismatch`. | `DataHashCheck::check()` | covered | measured: `PlainTextTest` "AC14: a changed letter and a flipped signature byte fail as the oracle says" |
| 15.12.1.3 | A corrupted wrapper: `manifest.text.corruptedWrapper`, with details where possible. | a wrapper whose store does not fit is a container error naming the fault; another version is not a wrapper, as the oracle | covered (code differs) | measured: `PlainTextTest` "AC6", "AC4" |
| 15.12.1.3 | A fragment of signed text is rejected; it should be detected and named as a fragment. | a fragment has no wrapper or fails the hash; it is not named a fragment | partial (the *should*) | read |
| 15.12.2 | Validate rendered content per §9.2, signal failures, absent content fails, keep reporting failure. | `BmffHashCheck` over the whole file, one verdict | covered for a file; by design for rendering (`PRED-STREAM-001`) | measured: `BmffHashCheckTest` "AC1", "AC2" |
| 15.12.2 | Streaming: validate each portion before rendering, in sequence; locations start at zero. | `FragmentedVerifier`: the count and each leaf's position; rendering is the player's | covered for the sequence; by design for rendering | measured: `FragmentedVerifierTest` "AC3"–"AC5", "AC8" |
| 15.12.2 | Progressive download: blocks fetched, validated, tracks selected; non-standard playback signalled. | not a player | by design (`PRED-CONT-002`–`004`) | read |
| 15.12.2 | `update`-purpose ContentProvenanceBox: search it first, follow the parent chain. | `IsobmffManifestStoreExtractor` refuses a purpose it does not read (SPEC-026 AC5) | by design: closed (`PRED-INGR-008`) | measured: SPEC-026 AC5 test |
| 15.12.2 | No `exclusions`, or an empty list: `assertion.bmffHash.malformed`. | `BmffHashCheck::shapeFault()` (SPEC-038) | covered | measured: `BmffShapeTest` "AC1" |
| 15.12.2 | Other exclusions: `assertion.bmffHash.additionalExclusionsPresent`. | `hasAdditionalExclusions()` | covered | measured: `BmffShapeTest` "AC5" |
| 15.12.2 | Ignore `pad` and `pad2`. | never read | covered by construction | read |
| 15.12.2 | The algorithm per §15.4. | `assertionOf()`: the assertion's `alg`, else `sha256`, not the claim's | partial | read → P06-12 |
| 15.12.2 | Subsets out of order, overlapping or negative: malformed; other failures `mismatch`; else `match`. | `shapeFault()`, `check()` | covered | measured: `BmffShapeTest` "AC2"; `BmffHashCheckTest` "AC1", "AC2" |
| 15.12.2 | On a mismatch, try a multi-asset hash; without one, `mismatch`. | no fallback | by design: stricter (`PRED-ABMF-001`) | read |
| 15.12.2.1 | Non-fragmented Merkle tree: leaves from `mdat` by fixed or variable block sizes; both present, or wrong sums: malformed. | `checkMerkle()` treats every `merkle` map as fragmented; block sizes are not read | partial | read → P06-4 |
| 15.12.2.1 | `count` against `hashes` and auxiliary boxes; fewer than the leaves: malformed; a leaf that differs: mismatch. | `checkMerkle()`: a single file offers no fragments, so a `count` of 1 or more fails as `mismatch`; a `count` of 0 or absent matches | partial | read → P06-4 |
| 15.12.2.2 | Fragmented: no auxiliary box: malformed; a leaf that differs: mismatch. | `FragmentedVerifier`, `checkFragment()`: both are `mismatch` | covered (code differs) | measured: `FragmentedVerifierTest` "AC1"–"AC5" |
| 15.12.3, 15.12.3.1 | General box hash, JPEG APP11 handling. | `c2pa.hash.boxes` is refused by name, `general.error` (`DataHashCheck::check()`, SPEC-012 AC8) | by design: closed, stricter than `c2patool` (`PRED-CONT-006`) | read |
| 15.12.3.2 | JPEG-XL box hash. | JPEG-XL is not read | n/a | read |
| 15.12.3.3 | Font box hash. | fonts are not read | n/a | read |
| 15.12.4 | Multi-asset hash: parts, locators, coverage, part hashes. | not implemented; a failed binding stays failed | by design: closed (`docs/conformance.md` §3) | read |
| 15.12.5.1 | Collection data hash. | `c2pa.hash.collection.data` refused by name, `general.error` | by design: closed | read |
| 15.12.5.2 | ZIP central directory hash. | ZIP is not read | n/a | read |

### Candidates

- **P06-1 — `c2pa.cloud-data` is not validated (§15.10.3.2.1).** Missing
  fields, a hard-binding or forbidden label, and an actions label in an
  update manifest are not refused. `c2pa-rs` enforces all four without
  fetching anything (`verify_cloud_data`, `claim.rs`, run for every claim
  version). Concrete case: a signed manifest whose claim lists a
  `c2pa.cloud-data` assertion with `label: "c2pa.hash.data"` and `size: 0`.
  `c2patool` 0.28.1 records `assertion.cloud-data.hardBinding` and
  `.malformed`, so `Invalid`; this verifier says `Valid` or `Trusted`.
  No asset byte changes: the signer wrote it. SPEC-039 says the
  `assertion.cloud-data.*` codes are "refused by name"; for this
  assertion no code refuses it. **Possibly more lenient than c2patool.**
- **P06-2 — `c2pa.time-stamp` shape (§15.10.3.2.6).** The assertion is
  never read, so a malformed one passes. `c2pa-rs` decodes every
  time-stamp assertion of every manifest and stops validation with
  `assertion.timestamp.malformed` when it fails (`store.rs`, log tag
  `get_claim_referenced_manifests`, `failure_as_err`). Concrete case: a
  signed manifest carrying a `c2pa.time-stamp` whose content is a CBOR
  array: `Invalid` at `c2patool`, `Valid` here. SPEC-039 points to issue
  #6. **Possibly more lenient than c2patool.**
- **P06-3 — revocation of an ingredient's signer (§15.11.3.3.1).**
  `IngredientManifestCheck::manifest()` has no stapled-OCSP step; the
  active manifest gets one (SPEC-030). `c2pa-rs` calls
  `check_ocsp_status` inside `Claim::verify_claim`, which
  `ingredient_checks` runs for every ingredient claim. Concrete case: an
  ingredient manifest whose signer stapled a `revoked` response:
  `c2patool` should record `signingCredential.ocsp.revoked` under that
  ingredient. `M7AbsenceTest` shows that ingredient failures cost the file
  its verdict at `c2patool`, so this verifier would say `Valid` where
  `c2patool` says `Invalid`. Not measured; no fixture with a revoked
  response exists (SPEC-030). **Possibly more lenient than c2patool.**
- **P06-4 — a `merkle` map on a single file (§15.12.2.1).**
  `checkMerkle()` reads an absent `count` as 0. With no fragments offered,
  0 fragments equals the count and the result is `assertion.bmffHash.match`.
  At that point only the `initHash` over the non-excluded ranges has been
  hashed. `c2pa-rs` refuses an `initHash` on non-fragmented media
  ("BMFF inithash must not be present for non-fragmented media",
  `bmff_hash.rs`, `verify_stream_hash_with_progress`). The specification
  says a count smaller than the leaves (at least one, the whole `mdat`) is
  `assertion.bmffHash.malformed`. Concrete case: an MP4 whose
  `c2pa.hash.bmff.v3` carries `merkle: [{initHash, hashes: [x]}]` with no
  `count`. It is `Valid` here and `Invalid` at `c2patool`. If the
  exclusions also name `/mdat`, the media bytes are bound by nothing. A
  plain-hash assertion excluding `/mdat` is `Valid` in both, so the new
  exposure is the divergence. Next: a probe built in the sister
  repository; the fix is to refuse a `merkle` map outside
  `FragmentedVerifier`, or one whose `count` is not a positive integer.
  **Possibly more lenient than c2patool.**
- **P06-5 — hashed URIs inside assertions (§15.10.3.3).** The hash in an
  ingredient's `thumbnail` and `data`, in an action's `ingredients`, and
  in `relatedAssertions` is not compared with the target. These references
  are resolved by label or not at all. `c2pa-rs` does the same: it compares
  only the claim's entries (`verify_internal`) and icons (`verify_icons`).
  The signer wrote both hashes, so no asset byte is at stake.
  **Stricter than c2patool if adopted.**
- **P06-6 — a `c2pa.opened` with more than one reference
  (§15.10.3.2.3).** This verifier counts the good references across the
  list and asks for exactly one. `c2pa-rs` overwrites its count on every
  reference, so only the last one decides (`verify_actions`, rule
  2.b.iv.A). Concrete case: `ingredients: [parentOf-ref, unresolvable-ref]`
  is `ingredientMismatch` at `c2patool` and passes here. The reverse,
  `[parentOf-ref, the same ref again]`, passes at `c2patool` and fails
  here. Not measured. **Possibly more lenient than c2patool** (first
  shape); stricter (second).
- **P06-7 — the letter of the rules against both implementations.**
  - `placed`, `transcoded` and `repackaged` ask that *each* reference
    resolve; both implementations accept one good one.
  - `removed` asks for an ingredient in *another* manifest; both read it
    in this one.
  - A `c2pa.redacted` without `parameters` passes (SPEC-037, as
    `c2patool`).
  - An exclusion list out of order is malformed by the letter of
    §15.12.1.1; both implementations sort it first.

  Adopting any of these would refuse files `c2patool` accepts.
  **Stricter than c2patool if adopted.**
- **P06-8 — `c2pa.session-keys` and
  `c2pa.alternative-content-representation` (§15.10.3.2.5, §15.10.3.2.7).**
  Neither is implemented. `c2pa-rs` 0.91.1 has no validation for either
  (no match for `session-keys`, `signerBinding` or
  `alternative-content` in its `src`). These are already recorded as gaps
  `PRED-CRYP-024`, `PRED-ASSE-013`, `-014` and `-026`.
  **Stricter than c2patool if adopted.**
- **P06-9 — a zeroed redacted assertion (§15.10.3.1).** A redacted entry
  is skipped only when its box is gone. A zeroed box still present is
  hashed and would be `assertion.hashedURI.mismatch`, where the rule says
  "considered valid". `c2pa-rs` skips hashing any redacted entry
  (`verify_internal`). Both implementations decode every CBOR assertion
  first (`Manifest::assertionData()`; `store.rs`, `ASSERTION_CBOR_INVALID`),
  however, and zero bytes are not a single CBOR item. So both probably
  refuse such a store before the redaction rule is reached. `c2patool`'s
  builder removes rather than zeroes (SPEC-035 amendment 1). Not measured.
  **Documentation only.**
- **P06-10 — status codes that differ (all still `Invalid`).**
  - `general.error` for `assertion.cbor.invalid`.
  - `assertion.missing` for `hashedURI.missing` (icons).
  - `manifest.update.invalid` for an update manifest with more than one
    ingredient (`c2pa-rs`'s code; the specification says `wrongParents`).
  - Container errors or `assertion.dataHash.mismatch` for the
    `manifest.text.*` codes and `dataHash.malformed` of §15.12.1.3.
  - `assertion.bmffHash.mismatch` for a missing auxiliary box.
  - `assertion.ingredient.malformed` for an `activeManifest` without `url`.

  **Documentation only.**
- **P06-11 — `validationResults` merge (§15.11.3.3).** Entries an
  ingredient recorded that this run does not reproduce are not added to
  the report; the rule says to return them. Reproduced, recorded statuses
  are dropped, as `c2pa-rs` does. No verdict depends on it: the active
  manifest's own statuses are never dropped. **Documentation only.**
- **P06-12 — BMFF algorithm fallback (§15.4 via §15.12.2).**
  `BmffHashCheck::assertionOf()` falls back to `sha256` when the
  assertion has no `alg`. `c2pa-rs` falls back to the claim's `alg`
  (`verify_stream_hash_with_progress`, called with `claim.alg()`). Under a
  `sha384` claim this refuses a correct file (`mismatch`). It accepts a
  file whose binding was written in `sha256`, which `c2patool` refuses.
  The second case is signer-made only. The `merkle` map's fallback has the
  same shape. **Possibly more lenient than c2patool** (lowest priority;
  mostly a false `Invalid`).

## §18.1 to §18.9 — Standard Assertions: introduction, metadata and the hash assertions

§18.1 to §18.4 are mostly about writing assertions; a validator gets
little from them beyond the encoding rules. §18.5 (`c2pa.hash.data`) and
§18.6 (`c2pa.hash.bmff.v2`/`.v3`) hold the rules this verifier enforces
most closely (SPEC-012, SPEC-027, SPEC-028, SPEC-029, SPEC-038, SPEC-051,
SPEC-053). §18.7 (box hash) and §18.8 (collection hash) are hard bindings
this verifier refuses by name. §18.9 (multi-asset hash) is not a hard
binding and is not read. `c2pa-rs` 0.91.1 was read beside it
(`assertions/data_hash.rs`, `assertions/bmff_hash.rs`, `assertions/labels.rs`,
`claim.rs`, `asset_handlers/bmff_io.rs`, `utils/hash_utils.rs`). The
§18.6.3 CDDL comments are non-normative (§18.1), so the prose decides.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 18.1 | Every assertion has a label as §6.2 says and is versioned as chapter 5 says. | read in those chapters' own steps | n/a here | — |
| 18.1 | No C2PA assertion uses the Codestream JUMBF content type. | `JumbfParser`: a superbox whose type is not a known one is kept as an `UnknownBox`, neither walked nor refused; the claim's hashed URI still covers it. `c2pa-rs` does not check it either | **candidate** | measured: `JumbfParserTest` AC7 (unknown type kept); → P07-8 |
| 18.1 | Standard assertions are CBOR, in Core Deterministic Encoding (RFC 8949 §4.2.1). | `CborDecoder` reads any well-formed CBOR and does not enforce deterministic form | by design: SPEC-006 *Out of scope* (a writer's obligation; the signature covers the bytes as they are; refusing would diverge from `c2patool`) | read |
| 18.2 | Regions of interest: range types, the time, frame and text rules, and `role` no longer written. | regions are not interpreted; the assertions that carry them are kept as decoded | n/a (writer guidance; nothing here depends on a region) | read |
| 18.3 | Assertion metadata: namespaced custom keys, `dataSource`, `reviewRatings` (value 1 to 5, none beside a `humanEntry` source), `dateTime` format, localization dictionaries. | not read. §18.3.1 says a consumer need not read any of it | n/a | read |
| 18.4 | The table of standard labels: `c2pa.hash.bmff` removed, `.v2` deprecated, `.v3` current; box, collection and multi-asset hashes listed. | `HardBindings` (data, bmff family, boxes, collection); `BmffHashCheck::LABELS` (v2, v3) | covered for the hash labels; see the rows below | read |
| 18.5.1 | The data hash's `alg`, when present, is an allowed algorithm (§13.1) and is the one used. | `DataHashCheck::check()`: `sha256`, `sha384`, `sha512`, else `algorithm.unsupported` | covered | measured: `DataHashCheckTest` AC7 |
| 18.5.1 | Without `alg`, the claim's `alg` decides. | `DataHashCheck::check()` (`$data['alg'] ?? claim alg`; neither → `algorithm.unsupported`) | covered | measured: `DataHashCheckTest` AC7 |
| 18.5.1 | The hash value is in `hash`. | `DataHashCheck::check()`: none → `assertion.dataHash.mismatch`, as `c2patool`'s hard error | covered | measured: `DataHashCheckTest` AC6 |
| 18.5.1 | Exclusions are in increasing order of `start`. | `DataHashCheck::check()` sorts them first, as `c2pa-rs` does (`hash_utils.rs`) | by design: SPEC-012 AC5 (`exclusions-unsorted.png` gives the same statuses as the sorted file) | measured: `DataHashCheckTest` AC5 |
| 18.5.1 | Exclusions do not overlap. | `DataHashCheck::check()`: `assertion.dataHash.malformed` (`c2patool` says `.mismatch`) | covered | measured: `DataHashCheckTest` AC5 |
| 18.5.1 | An exclusion stays inside one logical unit, does not cover header or length fields (free or pad data apart), and holds only the manifest store or asset metadata. | the exclusion holding the store may hold only the store (SPEC-012 amendment 7); every other exclusion is honoured and reported once as `assertion.dataHash.additionalExclusionsPresent`. What sits in those other ranges is not judged, nor by `c2pa-rs` | partial (as `c2pa-rs`) | measured: `DataHashCheckTest` AC3, AC4 |
| 18.5.1 | Consumers ignore a deprecated `url`, which still counts in the assertion's hashed URI. | `DataHashCheck` never reads `url`; `HashedUriCheck` hashes the whole assertion box | covered by construction | read |
| 18.5.1 | The data hash's label is `c2pa.hash.data`. | `HardBindings` (instances `__n` by base label, SPEC-012 amendment 9) | covered | measured: `DataHashCheckTest` AC11 |
| 18.5.1, 18.6.1, 18.7.1 | A data, BMFF or box hash is not in an external reference assertion. | `ExternalReferenceCheck::FORBIDDEN_LABELS` → `assertion.external-reference.malformed` (SPEC-032) | covered | measured: `AssertionRulesTest` AC4 |
| 18.5.1, 18.6.1, 18.7.1 | ... nor in a cloud data assertion. | `c2pa.cloud-data` is not read anywhere in `src/`; only its hashed URI is checked | **candidate** | read: `grep -i cloud src` finds only the external-reference list; → P07-3 |
| 18.5.1 | A data hash is not used with a compressed manifest. | compressed manifests (`c2cm`) and boxes (`brob`) are refused before any assertion is read | covered by construction | measured: `JumbfParserTest` AC13 |
| 18.5.2 | `start` and `length` are written as short as possible, or as 32-bit placeholders. | the decoder reads either form | n/a (writer guidance) | read |
| 18.5.2 | `pad` is present and zero-filled; `pad2`, when present, is zero-filled. | `DataHashCheck` reads past both and does not require `pad`. `c2pa-rs` requires `pad` (its `DataHash` has no serde default) but does not check the zeros | **candidate** | read: `DataHashCheck::check()`, SPEC-012 *Behavior* 2 (`name` and `pad` are read past); `data_hash.rs`; → P07-1 |
| 18.5.3 | JPEG: the APP11 marker and `Lp` of every C2PA segment are inside the exclusion; the segments are contiguous, so one range is enough. | `ManifestStoreBytes::$ranges` include each segment's framing; every piece must sit inside an exclusion that holds only the store | covered | measured: `DataHashCheckTest` AC3 |
| 18.5.4 | PNG: the chunk's length and `caBX` type are inside the exclusion. | as above (PNG ranges start at the length field) | covered | measured: `DataHashCheckTest` AC3 |
| 18.5.5 | TIFF: the IFD entry's count should be excluded. | no TIFF reader | n/a | — |
| 18.6.1 | The BMFF hash's label is `c2pa.hash.bmff.v3`; `.v2` is deprecated. | `BmffHashCheck::LABELS` verifies both | covered | measured: `BmffHashCheckTest` AC1, `BmffV2ExclusionsTest` AC1 |
| 18.6.1 | Merkle trees are balanced binary trees of minimum depth, NULL padding only on the right. | `BmffHashCheck::path()`: the largest power of two below the leaf count on the left, as `c2pa-rs` builds it (SPEC-028, measured in step 82). Whether a level is more than half full is not checked; a tree of another shape fails to reach the root | covered | measured: `FragmentedVerifierTest` AC1, AC8 |
| 18.6.1 | Validators ignore a `c2pa.hash.bmff` (no version), as if absent. | not ignored: alone it gets `general.error` (verdict `Invalid`, as the spec's `claim.hardBindings.missing` would be); beside a v3 it counts as a second hard binding. `c2pa-rs` counts it and verifies it as version 1 | partial | read: `HardBindings::FAMILIES`, `DataHashCheck::check()`; `labels.rs` `is_hard_binding_label`; → P07-4 |
| 18.6.1 | Consumers ignore a deprecated `url`, which still counts in the hashed URI. | `BmffHashCheck` never reads `url` | covered by construction | read |
| 18.6.2 | The BMFF hash's `alg`, when present, is an allowed algorithm and is the one used; also in a merkle map. | `BmffHashCheck::assertionOf()`, `merkleMapOf()`, `digest()` (SPEC-051) | covered | measured: `MerkleHashAlgorithmTest` AC1 to AC4 |
| 18.6.2 | Without `alg`, the claim's `alg` decides. | `assertionOf()` falls back to `sha256`, never to the claim's `alg`. `c2pa-rs` uses the assertion's, else the claim's, else `sha256` | **candidate** | read: `BmffHashCheck::assertionOf()`; `bmff_hash.rs` `verify_stream_hash_with_progress`; → P07-2 |
| 18.6.2 | All bytes are hashed except boxes matching an exclusion; a box included or excluded whole carries its header with it. | `BmffHashCheck::plan()`, `withTail()` | covered | measured: `BmffHashCheckTest` AC1, AC2, AC8 |
| 18.6.2 | `subset` offsets count from the box start, header included; subsets are ordered by offset and do not overlap. | `ranges()`; `shapeFault()` → `assertion.bmffHash.malformed` (SPEC-038) | covered | measured: `BmffShapeTest` AC2, `BmffV2ExclusionsTest` AC4 |
| 18.6.2 | v2 and v3: each root box not wholly excluded is hashed as its 8-byte offset followed by its data. | `plan()` markers (SPEC-027, SPEC-038) | covered | measured: `BmffHashCheckTest` AC3, `BmffShapeTest` AC3 |
| 18.6.2 | With both `hash` and `merkle`, Merkle leaves carry no offset and a mandatory exclusion takes most of `mdat`. | an assertion with both is refused (`assertionOf()`: "a binding is one or the other") → `assertion.bmffHash.mismatch` | **candidate** | read; → P07-5 |
| 18.6.2 | A box matches an exclusion by its path: `/foo/bar[2]` matches `bar[2]` under any `foo`. | `matches()` compares the box's path (`/moov/trak/…`, never indexed) with the `xpath` string; a node with `[n]` matches nothing | **candidate** | read: `matches()`, `IsobmffManifestStoreExtractor::descend()`; `bmff_io.rs` `fetch()` reads indices; → P07-6 |
| 18.6.2 | The `length`, `version`, `flags` and `exact` filters. | refused by name once the path resolves | by design: SPEC-027 and SPEC-029 *Out of scope* (no file exercises them; ignoring one would hash the wrong bytes) | measured: `BmffHashCheckTest` AC5, `BmffV2ExclusionsTest` AC6 |
| 18.6.2 | The `data` filter: bytes at each offset equal the given value. | `matches()` | covered | measured: `BmffHashCheckTest` AC4, `BmffV2ExclusionsTest` AC5 |
| 18.6.2 | `xpath` syntax is limited: abbreviated, full paths, `node` or `node[n]`, no `//`, 4cc nodes only. | a leading `/` is required (else mismatch); other syntax is not parsed and matches no box, so the box is hashed and the binding fails | partial | read: `matches()`; → P07-6 |
| 18.6.2 | One exclusion may match zero or more boxes. | `plan()` applies each exclusion to every box | covered | measured: `BmffV2ExclusionsTest` AC3, AC6 |
| 18.6.2 | A non-leaf `xpath` node is a plain container (not a FullBox); children of unusual boxes are excluded by `subset`. | `boxTree()` descends only the containers it knows, so a path through another box matches nothing and is hashed | covered by construction | read |
| 18.6.2 | An embedded manifest's box is one of the exclusions. | an absent or empty `exclusions` → `assertion.bmffHash.malformed`; a hash that covers its own box cannot match | covered | measured: `BmffShapeTest` AC1; the rest read |
| 18.6.2 | `free` boxes, the two-pass method and post-embed offsets. | — | n/a (writer guidance) | — |
| 18.6.2 | An updated store may be appended at the end (`box_purpose` `update`, the first set to `original`); `box_purpose` is outside the hash. | only the purpose `manifest` (and `merkle` for fragments) is read; any other purpose, and a second C2PA box, is an error | by design: SPEC-026 (a purpose not read is an error, never silence) | read: `IsobmffManifestStoreExtractor` |
| 18.6.6 | A chunk is validated by the init segment's `initHash`, then by its Merkle proof up to the stored row. | `checkMerkle()`, `checkFragment()`; every declared fragment must also be offered (stricter than the spec, SPEC-028 AC5) | covered | measured: `FragmentedVerifierTest` AC1 to AC5, AC8 |
| 18.6.3 | `initHash` is required for fragmented MP4 and absent for non-fragmented MP4. | required (`checkMerkle()`: "no initHash" → mismatch); a non-fragmented Merkle tree is never verified | partial | read; → P07-5 |
| 18.6.6 | Several init segments carry the same manifest store. | one init segment per verification (`FragmentedVerifier`) | n/a | read |
| 18.6.6 | `uniqueId` and `localId` select the merkle map. | more than one map is refused by name | by design: SPEC-028 *Out of scope* (what the ids select is unmeasured) | measured: `FragmentedVerifierTest` AC6 |
| 18.6.3 | `fixedBlockSize` and `variableBlockSizes` split one `mdat` into leaves. | not implemented; such an assertion has no `initHash` and fails as above | **candidate** | read; → P07-5 |
| 18.6.4 | The basic exclusion profile. | — | n/a (writer guidance) | — |
| 18.7 | General box hash (`c2pa.hash.boxes`): order of boxes, `excluded`, `exclusions`, `alg` chain, the `C2PA` box, `c2pa.after`, and the JPEG, PNG, GIF, RIFF, font and Ogg rules. | not verified: `DataHashCheck::check()` refuses it by name (`general.error`, `Invalid`) | n/a (refused by name; SPEC-012 *Out of scope*, `docs/comparison.md` names the Bing files it costs) | read |
| 18.8 | Collection data hash (`c2pa.hash.collection.data`): relative URIs without `.` or `..`, each member hashed whole. | refused by name the same way | n/a (refused by name) | read |
| 18.9 | Multi-asset hash (`c2pa.hash.multi-asset`): not a hard binding; parts in file order, contiguous, covering every byte; part hashes labelled `.part`. | not read, not counted as a hard binding; `c2pa.hash.data.part` is not counted either, so the file's own data hash is verified. `c2patool` does the same | n/a | measured: `php bin/c2pa-verify` on `tests/Fixtures/writers/google-20250919-pixel10-npld-picnic-table.jpg` (`dataHash` performed; the only failures are `signingCredential.expired` and `.untrusted`, as in its `c2patool` report); `.part` of other bindings → P07-7 |

### Candidates

- **P07-1 — `pad` (§18.5.2).** A data hash without `pad` is verified
  here. `c2pa-rs` cannot deserialise it (`DataHash.pad` has no default),
  so `c2patool` stops with an error, as it does for a missing `hash`
  (SPEC-012, step 23). A non-zero `pad` or `pad2` is accepted by both.
  Concrete case: a signer whose data hash leaves out `pad` gets `Valid` or
  `Trusted` here and no report from `c2patool`. The bytes are still bound,
  so this is a shape rule, not a hole. Also, `docs/conformance.md`
  (`PRED-CROSS-006`, `PRED-CONT-005`) reads "pad and pad2 are never read"
  as meeting the rule; §18.5.2 also requires `pad` to be there.
  **Possibly more lenient than c2patool.**
- **P07-2 — BMFF `alg` fallback (§18.6.2).** `BmffHashCheck::assertionOf()`
  uses `sha256` when the assertion has no `alg`. It never uses the claim's
  `alg`, though `DataHashCheck` does. `c2pa-rs` uses the assertion's
  `alg`, then the claim's, then `sha256`. Concrete case: a claim with
  `alg: sha384` and a v3 BMFF assertion without `alg` whose hash was
  computed with SHA-256. That is `Valid` here and
  `assertion.bmffHash.mismatch` in `c2patool` (the reverse case is
  stricter here). The same applies to a merkle map without `alg` under
  such an assertion. **Possibly more lenient than c2patool.**
- **P07-3 — hard bindings in cloud data (§18.5.1, §18.6.1, §18.7.1).**
  `c2pa.cloud-data` is not read. `c2pa-rs` reports
  `assertion.cloud-data.hardBinding` as a failure (`claim.rs`,
  `verify_cloud_data`, using `labels::is_hard_binding_label`). Concrete
  case: a manifest with a genuine embedded `c2pa.hash.data`, plus a cloud
  data assertion whose `label` names a hard binding. It is `Valid` or
  `Trusted` here and `Invalid` in `c2patool`. Separately, SPEC-039 says
  `assertion.cloud-data.*` is "refused by name", but nothing in `src/`
  refuses it. **Possibly more lenient than c2patool.**
- **P07-4 — unversioned `c2pa.hash.bmff` (§18.6.1).** The spec says to
  ignore it. Here, alone, it is `general.error` with the stale wording "BMFF
  ... are M8 and later". Beside a v3 it counts as a second hard binding
  (`assertion.multipleHardBindings`), where the spec would verify the v3
  alone. `c2pa-rs` counts it too and verifies it as version 1, so a file
  bound only by v1 is `Valid` there and `Invalid` here. No such file is
  held. **Stricter than c2patool** (today); the wording is
  documentation only.
- **P07-5 — `hash` with `merkle`, and Merkle trees over one `mdat`
  (§18.6.2, §18.6.3).** An assertion with both fields is refused.
  `fixedBlockSize` and `variableBlockSizes` are not implemented, and a
  non-fragmented Merkle tree fails for lack of `initHash`. `c2pa-rs`
  implements both (`bmff_hash.rs`). None of this is named in the *Out of
  scope* lists of SPEC-027, SPEC-028 or SPEC-029, or in
  `docs/comparison.md`. **Stricter than c2patool**; documentation at the
  least.
- **P07-6 — indexed `xpath` (§18.6.2).** Box paths are built without
  indices, so `/moov[1]/pssh` (the spec's own example) matches nothing.
  The box is then hashed and the binding fails. `c2pa-rs` reads indices
  (`bmff_io.rs` `fetch()`). This is fail closed but silent: the report
  says mismatch, not "index not read". **Stricter than c2patool**: a file
  `c2patool` calls `Valid` is `Invalid` here.
- **P07-7 — `.part` labels of other bindings (§18.9.2).**
  `HardBindings::FAMILIES` matches any label that continues
  `c2pa.hash.boxes.` or `c2pa.hash.bmff.`. That includes
  `c2pa.hash.boxes.part`, which counts as a second hard binding, so a JPEG
  with `c2pa.hash.data` and a box-hash part is
  `assertion.multipleHardBindings`. `c2pa-rs` matches by label root and
  would not count it. `c2pa.hash.data.part` is handled correctly. No file
  is held. **Stricter than c2patool** (unmeasured).
- **P07-8 — Codestream content type (§18.1).** An assertion box of the
  Codestream type is kept and hashed like any other unknown box, not
  refused. `c2pa-rs` does not refuse it either.
  **Stricter than c2patool if adopted.**

## §18.10 to §18.16 — Soft Binding, Cloud Data, Embedded Data, Thumbnail, Alternative Content, Actions, Ingredient

Most of these sections describe what a claim generator writes; the rules a
validator meets are mostly restated in §15.10.3.2 and §15.11, where the
failure codes live. This verifier reads three of the seven assertions:
actions (SPEC-018, SPEC-032, SPEC-033, SPEC-034, SPEC-037), ingredients
(SPEC-020, SPEC-021) and, for rendering only, thumbnails. It does not
decode soft-binding, cloud-data, embedded-data or alternative-content
assertions; their hashed URIs are checked like any other, and nothing in
them is fetched. Every actions rule below applies to version 2 claims
only, by decision (`docs/conformance.md`, *§15.10.3.2.3 for version 1
claims*). `c2pa-rs` was read at 0.91.1 (`claim.rs`: `verify_actions`,
`verify_icons`, `verify_soft_binding_alg`, `verify_cloud_data`).

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 18.10.1 | Consumers ignore the deprecated `url` field, and should ignore the deprecated `extent` field, of a soft binding (the hash over them still counts). | no code reads a soft binding's fields; the assertion's hashed URI is checked as for every assertion (`HashedUriCheck`) | covered by construction | read |
| 18.10.1, 18.10.3 | Label `c2pa.soft-binding`; `alg` and `blocks` present (no default `alg`); `pad` and `pad2` zero-filled. | `ActionsCheck::contentRules()` only looks for the label (for the watermark rule); the content is never decoded | **candidate** | read → P08-2 |
| 18.10.3.1 | A validator should not use `bindingMetadata` when validating a soft binding. | nothing reads it | covered by construction | read |
| 18.10.4 | `alg` should be on the C2PA soft binding algorithm list; a validator should not resolve bindings with deprecated algorithms. | no soft-binding resolution; the list is not bundled. `c2pa-rs` 0.91.1 has its registry check commented out | n/a | read |
| 18.10.5, 18.10.5.1 | Discovery and matching through a manifest repository: every soft binding in a found manifest must match `alg` and `value`. | no repository lookup, no network | n/a | read |
| 18.10.4 | The algorithm list's own format (identifier, type, media types, metadata). | | n/a (writer and list maintainer) | — |
| 18.11.1 | Cloud data is optional; its content should not be fetched during validation. | nothing is fetched (no network in the verification path) | covered by construction | read |
| 18.11.1 | Label `c2pa.cloud-data`; must not reference the actions, cloud-data, hash or ingredient labels (twelve listed). | nothing reads the assertion | **candidate** | read → P08-1 |
| 18.11.1–18.11.2 | `size` present and at least 1; `location` carries no `size` and no `dc:format` of its own. | nothing reads the assertion | **candidate** | read → P08-1 |
| 18.12.1 | An embedded data assertion's label starts with `c2pa.embedded-data` (instances as usual). | not checked | **candidate** | read → P08-8 |
| 18.12.2 | Its description box holds an IANA media type and does not set the External toggle; the data matches that type. | `Manifest::mediaType()` requires a NUL-terminated type and reads it; the toggles byte and an empty type are not judged | partial | read → P08-8 |
| 18.13.1.1 | At most one `c2pa.thumbnail.claim` per manifest. | `ManifestStore` renders a claim thumbnail; the count is not checked | **candidate** | read → P08-8 |
| 18.13.1.2 | An ingredient thumbnail's label starts with `c2pa.thumbnail.ingredient`. | used for rendering only (`ManifestStore`) | n/a (writer; nothing depends on it) | read |
| 18.14.1–18.14.2 | Label `c2pa.alternative-content-representation`; at most one with type `exif.originalPreservationImage`; its parameters use `multiAssetPartIndex` or `embeddedOriginalPreservationImage`, never both; a part index is validated only in the manifest holding the current hard binding. | not read; `c2pa.hash.multi-asset` is not a supported hard binding | **candidate** | read → P08-8 |
| 18.15.1 | The v2 assertion is labelled `c2pa.actions.v2`; a v2 action may come from a template. | `ActionsCheck::isActionsLabel()`; the v1 label in a v2 claim gets the v2 rules (SPEC-018 amendment 6) | covered | measured: `ActionsCheckTest` "SPEC-018 AC7" |
| 18.15.1 | Each `action` (in `actions` or `templates`) is a pre-defined name or an entity-specific namespaced name. | `checkData()` requires non-empty text; the syntax is not checked | partial | measured: `ActionsCheckTest` "SPEC-018 AC6"; syntax read → P08-6 |
| 18.15.2 | A standard manifest has at least one actions assertion in `created_assertions`. | required (`checkAssertions()` rule 1), but one in `gathered_assertions` counts too, as in `c2pa-rs` (SPEC-018 AC4) | partial | measured: `ActionsCheckTest` "SPEC-018 AC3", "SPEC-018 AC4" → P08-4 |
| 18.15.2 | The first action of the first actions assertion is `c2pa.created` or `c2pa.opened`. | `checkAssertions()` rule 1 | covered | measured: `ActionsCheckTest` "SPEC-018 AC1", "SPEC-018 AC3" |
| 18.15.2 | `c2pa.created` carries a `digitalSourceType`. | `checkAssertions()` (SPEC-032 rule A), version 2 claims; version 1 by decision (`docs/comparison.md`) | covered; by design for v1 | measured: `AssertionRulesTest` "AC1", "AC2" |
| 18.15.2 | An asset created with no content uses the `…/digitalsourcetype/empty` value. | cannot be judged from the bytes | n/a | — |
| 18.15.2 | `c2pa.opened` references its `parentOf` ingredient assertion through `parameters.ingredients`. | `contentRules()` (SPEC-033): exactly one reference of relationship `parentOf`, resolved by label as `c2pa-rs` does | covered (by label; by design: `docs/comparison.md`) | measured: `ActionsContentTest` "AC2", "AC3" |
| 18.15.2 | Update manifests are exempt from the opening rule. | `checkAssertions()` (`$isUpdateManifest`) | covered | measured: `UpdateManifestTest` "AC4: an update manifest that breaks §11.2.3" |
| 18.15.2 | No more than one `c2pa.created` or `c2pa.opened` across all actions assertions. | `contentRules()`: the opening count | covered | measured: `ActionsContentTest` "AC1: one opening" |
| 18.15.3 | Validators should read an absent `allActionsIncluded` as "more actions may have happened". | the report states nothing about completeness; the assertion is rendered as data | n/a | read |
| 18.15.4.2 | `reason` is one of four `c2pa.` values or a namespaced custom value. | not checked | **candidate** | read → P08-6 |
| 18.15.4.2 | A `c2pa.redacted` action carries a `reason`. | not checked | by design: SPEC-037 *Out of scope* (no validation step names it, no oracle checks it) | read |
| 18.15.4.3 | `when` is a CBOR date/time (RFC 8949 §3.4.1). | not checked | **candidate** | read → P08-3 |
| 18.15.4.4 | An action has at most one of `softwareAgent` and `softwareAgentIndex`; the index points into `softwareAgents`. | not checked | **candidate** | read → P08-5 |
| 18.15.4.5 | `digitalSourceType` is an IPTC term or one of the C2PA values. | only presence on `c2pa.created` (SPEC-032); the value is not checked | **candidate** | read → P08-5 |
| 18.15.4.6 | In v2, `changes` is a list of region maps. | not checked | **candidate** | read → P08-3 |
| 18.15.4.7 | Custom `parameters` keys use entity-specific namespacing. | not checked | **candidate** | read → P08-6 |
| 18.15.4.7 | `c2pa.opened` and `c2pa.placed` carry hashed URIs to their ingredient assertions. | `contentRules()`: present, non-empty, of the right relationship; resolved by label, not by hash | covered (by label; by design: `docs/comparison.md`, SPEC-033 open question 2) | measured: `ActionsContentTest` "AC2", "AC3" |
| 18.15.4.7 | `c2pa.removed` references a `componentOf` ingredient in a different manifest. | `contentRules()` looks it up in the current claim, as `c2pa-rs` | by design: `docs/comparison.md` (SPEC-033 open questions 2–3) | measured: `ActionsContentTest` "AC2" |
| 18.15.4.7 | (earlier versions) `c2pa.transcoded` / `c2pa.repackaged` reference the `parentOf` ingredient. | `contentRules()`: a reference, if present, must be `parentOf` (§15.10.3.2.3, `c2pa-rs` 2.c) | covered | measured: `ActionsContentTest` "AC4" |
| 18.15.4.7 | `c2pa.translated` carries `sourceLanguage` and `targetLanguage` as BCP 47 codes. | `contentRules()`: both non-empty text; BCP 47 syntax not checked, as in `c2pa-rs` (SPEC-033 rule 4) | partial | measured: `ActionsContentTest` "AC5: translation" |
| 18.15.4.7 | `c2pa.redacted` carries in `parameters.redacted` the JUMBF URI of the redacted assertion. | `contentRules()`, `redactionFault()` (SPEC-037); a `c2pa.redacted` without `parameters` passes | partial (the bare action by design: `docs/comparison.md`, SPEC-037 open question 1) | measured: `RedactedActionTest` "AC2"–"AC5" |
| 18.15.4.8 | `relatedAssertions`: a non-empty list of hashed URIs into the same manifest, never an actions or ingredient assertion. | `contentRules()` | covered | measured: `ActionsContentTest` "AC6: related assertions" |
| 18.15.5 | `c2pa.watermarked.bound` (and the deprecated `c2pa.watermarked`) needs a soft binding assertion in the manifest. | `contentRules()` → `assertion.action.softBindingMissing` | covered | measured: `ActionsContentTest` "AC7: watermarks" |
| 18.15.6.1, 18.15.7, 18.15.8 | A consumer overlays an action on its templates (and the `*` template), applies a template's localizations, and merges related actions. | the verifier presents no merged view of actions | n/a | read |
| 18.15.6.3 | A template icon is a hashed URI to an embedded data (`c2pa.icon`) or cloud data assertion, and appears only in templates of entity-specific actions. | `IconReferenceCheck`: the URI must name an assertion the claim lists, with the claim's hash. Its target type and the entity-specific restriction are not checked; data boxes are refused (by design, SPEC-034 amendment 1) | partial | measured: `IconReferenceTest` "AC1"–"AC5"; the rest read → P08-5 |
| 18.15.9–18.15.11 | Rendition signals, soft-binding lookup fields, deprecated actions not to be written. | any action name is accepted, so deprecated ones in older manifests pass | n/a (writer guidance and consumer signals) | read |
| 18.15 (all) | The rules above for a version 1 claim's `c2pa.actions`. | only "at most one actions assertion" (`checkAssertions()`) | by design: `docs/conformance.md`, *§15.10.3.2.3 for version 1 claims* | measured: `ActionsCheckTest` "SPEC-018 AC4", "SPEC-018 AC6"; `RedactedActionTest` "AC6" |
| 18.16.1 | Ingredient labels `c2pa.ingredient`, `.v2`, `.v3` with `__n` instances; v3 is current. | `IngredientAssertion::labelVersion()`; a version above 3 is `assertion.ingredient.malformed` | covered | measured: `IngredientAssertionTest` "AC2: nine malformed ingredient assertions…" (`v4`) |
| 18.16.2 | The ingredient's identifier comes from its manifest label, or from `instanceID`. | | n/a (writer) | — |
| 18.16.3 | `relationship` is present and one of `parentOf`, `componentOf`, `inputTo`. | `fromAssertion()` | covered | measured: `IngredientAssertionTest` "AC2" (`no-relationship`, `relationship-childof`, `relationship-int`) |
| 18.16.3 | A `parentOf` ingredient comes with a `c2pa.opened` action, a `componentOf` with a `c2pa.placed`. | the action → ingredient direction is enforced (SPEC-033); an ingredient no action names is not refused | partial | read → P08-7 |
| 18.16.4–18.16.6 | `dc:title` and `dc:format` are text (required in v1 and v2; `instanceID` too in v1); `dc:format` is a valid IANA media type (`multipart/mixed` for multi-file). | `fromAssertion()`: types and per-version presence; the media-type syntax is not checked | partial | measured: `IngredientAssertionTest` "AC2" (`v1-no-title`); syntax read → P08-6 |
| 18.16.7–18.16.9, 18.16.13, 18.16.14 | `description`, `data`/`dataTypes`, `informationalURI`, `metadata`, `softBindingsMatched`. | decoded into `data`, not judged (SPEC-020) | n/a (writer guidance; no rule a validator can check from the bytes) | read |
| 18.16.10 | An ingredient thumbnail is a thumbnail assertion, referenced by hashed URI. | `fromAssertion()` requires a hashed-URI shape (byte-string hash); the target is not checked | partial | read → P08-8 |
| 18.16.11–18.16.12.2 | Which ingredient manifests are copied, deduplicated or relabelled. | | n/a (writer) | — |
| 18.16.12.3 | `activeManifest` and `claimSignature` are hashed URIs. | `fromAssertion()` (a hash that is not bytes is malformed); a v3 without `claimSignature` is read | covered (the missing `claimSignature` by design: `docs/comparison.md`, SPEC-035) | measured: `IngredientAssertionTest` "AC2" (`hash-text`) |
| 18.16.12.3 | Never both `activeManifest` and `digitalSourceType`. | `fromAssertion()`; stricter than `c2pa-rs`, which enforces it only when writing | covered | measured: `IngredientAssertionTest` "AC2" (`manifest-and-dst`); `docs/conformance.md` `PRED-ASSE-018` |
| 18.16.12.3 | An ingredient's `digitalSourceType` takes a value allowed for the action field. | read as text; the value is not checked | **candidate** | read → P08-5 |
| 18.16.12.4 | A v3 ingredient with `activeManifest` records `validationResults`. | `fromAssertion()` | covered | measured: `IngredientAssertionTest` "AC2" (`manifest-no-results`) |
| 18.16.12.4 | A v3 ingredient without `activeManifest` carries no `validationResults`. | not checked | **candidate** | read → P08-7 |
| 18.16.12.4 | A recorded failure is the writer's acknowledgement; the validator drops what was recorded, never for the active manifest. | `IngredientManifestCheck::recorded()`, `drop()` | covered | measured: `IngredientManifestCheckTest` "AC4", "AC5" |
| 18.16.12.4 | Each status map holds a `code`; custom codes are namespaced (with `success` in v2); deltas are compared on type, code and url. | `recorded()` ignores entries without a text `code` and `url` (so they excuse nothing); the recorded type is not compared, as in `c2pa-rs` (`validation_results.rs`, `from_store`, which re-derives the kind from the code); custom-code syntax by decision (`docs/conformance.md`, `PRED-STRU-002`) | partial | read → P08-7 |
| 18.16.12.4 | `specVersion` is SemVer; `trustListURI` is absent when the C2PA Trust List was used. | | n/a (writer; informational) | — |

### Candidates

- **P08-1 — cloud data assertions (§18.11).** This verifier never reads a
  `c2pa.cloud-data` assertion. `c2pa-rs` 0.91.1 does, for every claim
  version (`verify_cloud_data`): an undecodable one (a missing `size` or
  `location`), a `size` below 1, a referenced label that is a hard
  binding or one of the actions, cloud-data and ingredient labels, all fail
  with `assertion.cloud-data.malformed`/`.hardBinding`. Concrete case: a
  signed manifest carrying a `c2pa.cloud-data` assertion whose `label` is
  `c2pa.hash.data` (or with `size: 0`) is `Invalid` in `c2patool` 0.28.1
  and `Valid`/`Trusted` here. No asset byte escapes the binding, since the
  hard binding must still sit in `created_assertions`. The location-map
  rule (no `size`, no `dc:format`) is not checked by `c2pa-rs` either.
  SPEC-039 says the `assertion.cloud-data.*` codes are "refused by name";
  no code in `src/` refuses this label (see P08-9). Risk: **possibly more
  lenient than c2patool**.
- **P08-2 — soft binding structure (§18.10.1, §18.10.3).** Not decoded
  here. `c2pa-rs` 0.91.1 decodes every soft binding
  (`verify_soft_binding_alg`) and logs `claim.malformed` when it cannot.
  `notes/step-125-spec033.md` records that `c2patool` 0.28.0 called a soft
  binding with a text block `value` `claim.malformed`. Concrete case: such
  a file, or one whose soft binding lacks `blocks`, is `Invalid` there and
  `Valid`/`Trusted` here. Zero-filled `pad`/`pad2` is not checked by
  `c2pa-rs` (read). Risk: **possibly more lenient than c2patool**.
- **P08-3 — typed action fields (§18.15.4.3, §18.15.4.6).** `checkData()`
  checks only the `actions` list and each `action` string. `c2pa-rs`
  decodes the whole v2 assertion through serde (`Actions::from_assertion`
  with `?` inside `verify_actions`): `when` must be text or tag 0
  (`DateT`), `softwareAgentIndex` an unsigned integer, `changes` a list of
  regions, `parameters` a map, `reason` and `digitalSourceType` text. A
  wrong type stops its validation with an error, not a verdict. Concrete
  case: `"when": 1(1700000000)` (an epoch tag) is `Valid`/`Trusted` here
  and no `Valid` from `c2patool`. Read only; a probe would show whether
  `c2patool` prints `Invalid` or exits. Risk: **possibly more lenient than
  c2patool**.
- **P08-4 — actions only in `gathered_assertions` (§18.15.2).** The text
  requires the actions assertion in `created_assertions`. SPEC-018 AC4
  accepts a gathered one, as `c2pa-rs` does (its comment notes that from
  2.4 it may only be in created assertions, but the code still looks at
  gathered). `docs/comparison.md` has no row for it. Risk: **stricter
  than c2patool if adopted**. Otherwise a comparison row.
- **P08-5 — values the spec fixes but nobody checks (§18.15.4.4,
  §18.15.4.5, §18.15.6.3, §18.16.12.3).** Both `softwareAgent` and
  `softwareAgentIndex` on one action, an index outside `softwareAgents`, a
  `digitalSourceType` that is no IPTC or C2PA term (on an action or an
  ingredient), and an icon that targets something other than an embedded
  or cloud data assertion, or sits in a template of a `c2pa.` action. `c2pa-rs`
  checks none of these: `DigitalSourceType::Other(String)` accepts any
  text, and `verify_icons` checks only that the target resolves and its
  hash. Risk: **stricter than c2patool if adopted**.
- **P08-6 — syntax of names (§18.15.1, §18.15.4.2, §18.15.4.7, §18.16.5).**
  Action names, `reason` values and custom parameter keys follow
  entity-specific namespacing; `dc:format` is a media type; translation
  languages are BCP 47. Neither this verifier nor `c2pa-rs` checks them
  (SPEC-033 rule 4 already records the BCP 47 case). No verdict depends on
  them. Risk: **documentation only** (or stricter than c2patool if
  adopted).
- **P08-7 — ingredient consistency (§18.16.3, §18.16.12.4).** Not
  refused: a `parentOf`/`componentOf` ingredient that no `c2pa.opened` or
  `c2pa.placed` action names; a v3 ingredient without `activeManifest`
  that still carries `validationResults`. `c2pa-rs` checks neither (its
  v3 rules in `ingredient.rs` run only when serialising). Also: a recorded
  status excuses a fault whatever list (success, informational, failure)
  the writer put it in, as in `c2pa-rs`. Risk: **stricter than c2patool if
  adopted**; the last point is **documentation only**.
- **P08-8 — embedded data, thumbnails, alternative content (§18.12–
  §18.14, §18.16.10).** Unchecked: the `c2pa.embedded-data` label prefix,
  an empty media type or a set External toggle in the description box, two
  `c2pa.thumbnail.claim` assertions (`c2pa-rs` refuses this only when
  building, in `Claim::build`), an ingredient `thumbnail` that targets no
  thumbnail assertion, and the alternative-content rules (at most one OPI,
  not both parameters). `c2pa-rs` reads no alternative-content assertion
  and does not test the toggle. Risk: **stricter than c2patool if
  adopted**. For alternative content, **documentation only** while
  `c2pa.hash.multi-asset` is not supported.
- **P08-9 — references in the documents.** SPEC-039 lists
  `assertion.cloud-data.*` as "refused by name", but no code in `src/`
  names `c2pa.cloud-data` except `ExternalReferenceCheck`'s forbidden list.
  SPEC-018 and the `ActionsCheck` docblock cite "C2PA 2.4 §18.x"; the
  section is §18.15 (§18.15.2 for the opening rule). Risk:
  **documentation only**.

## §18.17 to the end of §18 — Metadata, time-stamps, certificate status and the informational assertions

These twelve sections define assertions that carry information rather than
bind content: metadata, a later time-stamp, later revocation data, where a
copy lives, what kind of asset it is, a depth map, font details, external
references, live-video session keys, sustainability figures, a repository
receipt and an AI disclosure. Most rules address the claim generator. Only
three of the assertions change a verdict in `c2pa-rs` 0.91.1: the
external reference (checked here too, SPEC-032), and `c2pa.metadata`,
`c2pa.time-stamp` and `c2pa.certificate-status`, which `c2pa-rs` decodes
during validation and which this verifier does not read. Every assertion
here is still covered by the generic rules: its hashed URI is compared, and
its content box must be exactly one and decode (SPEC-013). §18.25 belongs
to the live-video method of §19.4, which this verifier does not read.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| 18.17.1 | Descriptive: why metadata lives in a signed assertion. | — | n/a | read |
| 18.17.2 | A metadata assertion's label ends in `.metadata`, after `c2pa` or an entity-specific namespace. | a label rule for the writer; the verifier gives no `.metadata` label a meaning of its own | n/a | read |
| 18.17.2 | One JSON content box holding JSON-LD. | `Manifest::assertionData()` and `only()`: one content box of one kind, else an error; JSON that does not decode is `assertion.json.invalid`. A CBOR box is accepted too, as `c2pa-rs` falls back to CBOR (`assertions/metadata.rs`, `from_assertion`) | partial | measured: `VerifierTest` "AC7: the parsers' faults become statuses with their codes and urls" (SPEC-013, `claim/json-broken.png`) |
| 18.17.2 | The JSON-LD object includes `@context`. | not checked: no `.metadata` assertion is read | **candidate** | read → P09-1 |
| 18.17.3 | `c2pa.metadata` holds only the fields of Appendix B. | a rule for the claim generator; `c2pa-rs` no longer checks the field list in validation (`claim.rs`, `verify_metadata`) | n/a | read; `docs/conformance.md` `PRED-STRU-017` |
| 18.17.4 | Partial redaction by an update manifest; the new assertion is shown with the update manifest's signer. | descriptive and a user-experience rule; the verifier has no user interface. Update manifests themselves: SPEC-022 | n/a | read |
| 18.18.1 | Descriptive: a later time-stamp keeps a manifest valid after its certificate expires. | — | n/a | read |
| 18.18.3 | Label `c2pa.time-stamp`; at most one per manifest. | not checked | **candidate** | read → P09-4 |
| 18.18.3 | A map with at least one entry; each key a manifest label (`urn:c2pa:…`), each value a byte string. | not read: no `assertion.timestamp.malformed` code exists here | **candidate** | read → P09-2 |
| 18.18.3 | Each value is an RFC 3161 token over the `signature` field of the named manifest's COSE_Sign1. | not read: the token is never verified and never used, so a manifest is judged at its own `sigTst`/`sigTst2`, or at now | by design: stricter, not laxer, in the usual case (`docs/conformance.md` §3, `PRED-TIME-002`/`003`; `notes/step-128-more-for-0.3.md` §3); see P09-2 for two cases where it is laxer | measured: `php bin/c2pa-verify tests/Fixtures/c2pa-rs/update_manifest.jpg` reports `Valid` and lists a `c2pa.time-stamp` assertion; `c2patool` also `Valid` (`notes/step-140-gaps-counted.md`) |
| 18.19.1 | A validator may need to go online for revocation status. | no network in the verification path; only stapled responses are read (SPEC-030) | by design (`docs/comparison.md`, the OCSP row) | read |
| 18.19.3 | Label `c2pa.certificate-status`; at most one per manifest. | not checked | **candidate** | read → P09-4 |
| 18.19.3 | At least one entry in `ocspVals`, each an OCSP response in the `rVals` form. | not read: the responses are neither decoded nor used for revocation | **candidate** | read → P09-3 |
| 18.20 | Label `c2pa.asset-ref`; at least one reference, each with a `uri`. | not read; `c2pa-rs` defines the type (`assertions/asset_reference.rs`) but does not validate it | **candidate** | read → P09-5 |
| 18.21.1 | Label `c2pa.asset-type.v2`; at most one; `dc:format` an IANA media type; each `type` from Tables 11/12 or an entity-specific name. | not read; `c2pa-rs` does not validate it in `claim.rs` or `store.rs` | **candidate** | read → P09-4, P09-5 |
| 18.21.3 | Which `dc:format` to choose. | writer guidance | n/a | read |
| 18.22.1 | Label starts `c2pa.depthmap.`; captured optically, not inferred from one 2D image. | the label is a writer rule; how a depth map was made cannot be checked from the file | n/a | read |
| 18.22.2–3 | `c2pa.depthmap.GDepth` follows the GDepth schema; `Format`, `Near`, `Far`, `Mime`, `Data` are required. | not read; `c2pa-rs` has only the label constants (`assertions/labels.rs`) | **candidate** | read → P09-5 |
| 18.23 | Label `font.info`; at most one; the schema's required fields (`fullName`, `familyName`, `style`, `weight`, `postScriptName`, `format`, `copyrightNotice`). | not read; nothing in `c2pa-rs` | **candidate** | read → P09-4, P09-5 |
| 18.24.1 | Label `c2pa.external-reference`. | `ExternalReferenceCheck::LABEL`; every instance, created or gathered (`references()`) | covered | measured: `AssertionRulesTest` "AC6: a well-formed external reference passes, and nothing is fetched" (SPEC-032) |
| 18.24.1 | It should sit in `gathered_assertions`; in `created_assertions` the signer answers for the data. | a should, and attribution; both lists are checked alike, as in `c2pa-rs` | n/a | read |
| 18.24.1 | When it references an assertion, `label` is present; it never names one of the thirteen listed labels. | `ExternalReferenceCheck::FORBIDDEN_LABELS` and `fault()`: the thirteen plus `c2pa.action`, as `c2pa-rs` (`assertions/external_reference.rs`, `validate`). Whether data *is* an assertion cannot be known without fetching it, so a missing `label` is not a fault | covered | measured: `AssertionRulesTest` "AC4: a forbidden external-reference label is malformed" (SPEC-032) |
| 18.24.1 | `location` is present and is a hashed or unhashed map holding a valid URI. | `fault()`: a map, a non-empty text `url`, `alg` and `hash` together and non-empty. The URI's syntax (for a hashed one, `http`/`https`) is not checked, nor by `c2pa-rs` | partial | measured: `AssertionRulesTest` "AC5: the location must hold a url, and a hash its algorithm" (SPEC-032); the syntax read → P09-5 |
| 18.24.1 | Leave `size` out when the data may change. | writer guidance | n/a | read |
| 18.24.1 | Referenced data is optional and not fetched during validation; fetched unhashed data is advisory. | nothing is ever fetched; the `url` is data | covered | measured: `AssertionRulesTest` "AC6: a well-formed external reference passes, and nothing is fetched" (a source scan for network calls) |
| 18.24.1 | Hashed references are validated per §15.10.3.2.2. | that section's pack | — | — |
| 18.24.2 | Entity-specific evidence types follow the namespace syntax; `processStart`/`processEnd` are advisory. | not checked; the times are not used | **candidate** (the syntax); n/a (the times) | read → P09-5 |
| 18.25 | `c2pa.session-keys`: at most one; each key a COSE key with a `kid`, a `minSequenceNumber`, `createdAt`, `validityPeriod` and a `signerBinding`; keys used only within their validity. | the live-video method of §19.4 is not read, so no session key is ever used; `c2pa-rs` has no such assertion | n/a | read; the `signerBinding` gap is `docs/conformance.md` `PRED-CRYP-024` |
| 18.26.1 | An action's `relatedAssertions` reference is a hashed JUMBF URI that resolves in the same manifest. | `ActionsCheck`: non-empty, resolvable in the current manifest, never actions or ingredients (SPEC-033), as `c2pa-rs` (`claim.rs`, rule 2.f) | covered | measured: `ActionsContentTest` "AC6: related assertions" (SPEC-033) |
| 18.26.1 | `energy_kwh`, `carbon_kgco2e`, `water_litres` each hold a mandatory non-negative `value`; `measurementMethod` is reverse-DNS. | not read; nothing in `c2pa-rs` | **candidate** | read → P09-5 |
| 18.27.1 | A repository receipt appears only in an update manifest. | not checked: `UpdateManifestCheck` checks what an update manifest may not carry, not where this assertion may appear | **candidate** | read → P09-6 |
| 18.27.1–2 | Label `c2pa.repository-receipt`; one JSON box with `@context`, `@type`, `repository` (`uri`, `manifestId`) and `anchor` (`uri`, `proof`). | the JSON box decodes (SPEC-013); its content is not read; nothing in `c2pa-rs` | **candidate** | read → P09-5 |
| 18.28.1, 18.28.3 | Descriptive: purpose, and how `humanOversightLevel` relates to `digitalSourceType`. | — | n/a | read |
| 18.28.2 | Label `c2pa.ai-disclosure`; `modelType` present, from Table 12; `scientificDomain` from the arXiv taxonomy; other fields typed as the schema says. | not read; nothing in `c2pa-rs` | **candidate** | read → P09-5 |

### Candidates

- **P09-1 — `@context` in metadata assertions (§18.17.2).** Not checked.
  `c2pa-rs` enforces it: for a version 2 claim, `Claim::verify_metadata`
  (`claim.rs`) decodes every assertion whose label matches
  `METADATA_LABEL_REGEX` (`c2pa.metadata`, `cawg.metadata`,
  `com.litware.metadata`, …) into `Metadata`, whose `@context` is a
  required map of text to text (`assertions/metadata.rs`); a failure is
  returned with `?` and ends the validation. Concrete case: a version 2
  claim with a `c2pa.metadata` JSON assertion that lacks `@context`,
  correctly hashed and signed, is refused by `c2patool` and `Valid` or
  `Trusted` here. `c2pa-rs` also refuses a string `@context`, which JSON-LD
  allows; that part would be stricter than the specification. Risk:
  **possibly more lenient than c2patool**. Next: a probe built from a
  signed fixture, judged by both `c2patool` versions.
- **P09-2 — the `c2pa.time-stamp` assertion (§18.18.3).** Not read.
  `c2pa-rs` reads it in `Store::get_store_validation_info` (`store.rs`)
  for every manifest in the store. Two cases are laxer here: (a) an
  assertion that does not decode as a map of text to byte strings is
  logged as `assertion.timestamp.malformed` and ends the validation there
  (`failure_as_err`), while this verifier reports the file `Valid` or
  `Trusted`; (b) a token that verifies against the named manifest's
  signature *replaces* that manifest's own `sigTst` as its signing time
  (`cose_validator.rs`, `verify_cose`), so a later update manifest that
  time-stamps its parent after the parent's certificate expired makes
  `c2pa-rs` call the parent expired, where this verifier, using the
  earlier `sigTst`, does not. Case (b) is arguably the specification's
  reading (the earlier token already proves existence) and `c2pa-rs`'s
  choice; case (a) is a plain gap. The opposite case, a token that
  rescues an expired certificate, is the known stricter one
  (`docs/conformance.md` §3). Risk: **possibly more lenient than
  c2patool** for (a) and (b). Next: `c2patool` cannot write the assertion
  (`notes/step-128-more-for-0.3.md`), so a probe must be built by hand.
- **P09-3 — the `c2pa.certificate-status` assertion (§18.19.3).** Not
  read. `c2pa-rs` decodes it in `get_store_validation_info`; a decoding
  failure is returned with `?` and ends the validation. Its responses
  decide revocation only when `builder.certificate_status_should_override`
  is set, and that is off by default (`settings/builder.rs`;
  `crypto/cose/ocsp.rs`, `check_ocsp_status`); a revoked response there is
  logged to an internal log only (`crypto/ocsp/mod.rs`,
  `from_der_checked`). Concrete case: an assertion whose `ocspVals` is
  missing or not a list of byte strings is refused by `c2patool` and
  `Valid` or `Trusted` here. A revoked response carried in the assertion
  is ignored by both under default settings, although §14.3.5 rules out
  `Valid` for a revoked credential. Risk: **possibly more lenient than
  c2patool** (the malformed case); documentation only (the revoked case,
  which matches `c2patool`'s default).
- **P09-4 — at most one (§18.18.3, §18.19.3, §18.21.1, §18.23.1,
  §18.25.2).** Two `c2pa.time-stamp`, `c2pa.certificate-status`,
  `c2pa.asset-type.v2`, `font.info` or `c2pa.session-keys` assertions in
  one manifest are not refused. `c2pa-rs` does not refuse them either:
  `timestamp_assertions()` and `certificate_status_assertions()`
  (`claim.rs`) return every instance, and no single-instance check exists
  for these labels. Risk: **stricter than c2patool if adopted**.
- **P09-5 — the schemas of informational assertions (§18.20, §18.21,
  §18.22, §18.23, §18.24.1–2, §18.26, §18.27, §18.28).** Required fields,
  value sets and syntax (asset-reference `uri`, asset-type values and
  `dc:format`, the five GDepth fields, the font fields, an external
  reference's URI syntax and evidence-type namespace, non-negative
  sustainability values and reverse-DNS methods, the repository receipt's
  JSON fields, AI disclosure's `modelType` and arXiv domain) are not
  checked. `c2pa-rs` 0.91.1 validates none of them either (its types in
  `assertions/` are not called from `claim.rs` or `store.rs`; the
  external reference's `validate()` checks no URI syntax). Whether §15.10
  asks a validator to check them is for that chapter's pack. Risk:
  **stricter than c2patool if adopted**.
- **P09-6 — a repository receipt outside an update manifest (§18.27.1).**
  A standard manifest carrying `c2pa.repository-receipt` is not refused.
  `c2pa-rs` has no such check (the label does not occur in its source).
  It would fit SPEC-022's update-manifest rules as one more line. Risk:
  **stricter than c2patool if adopted**.

## Appendix A — Embedding manifests (step 312)

Appendix A says where each format keeps its manifest store and, for some
formats, what the hard binding must leave out. Most of its sentences bind the
claim generator. For a validator they matter in three ways: where it looks for
the store, what it does when there are two stores or the store is in the wrong
place, and what the hash must leave out. This verifier reads JPEG, PNG, GIF,
WebP, WAV, AVI, MP3, FLAC (through the ID3 tag in front of the stream),
ISOBMFF (MP4, MOV, AVIF, HEIC, fragmented streams) and, opt-in with `--text`,
unstructured text (SPEC-060). `FormatDetector::detect()` identifies a format
from its first bytes, never from its file extension. Any other format gets
`general.error` (*unsupported file type*) and `Invalid`, with
`has_manifest: false`. This was measured on synthetic heads of TIFF, DNG, SVG,
PDF, EPUB/ZIP, HTML, JPEG XL (box and codestream), OpenType, Ogg and a
structured-text Python file, with `php bin/c2pa-verify <file>`. With `--text`,
any input that is valid UTF-8 and that no other format claims is read as plain
text. SVG, HTML and structured text then report no manifest. For JPEG, PNG,
GIF, RIFF, ID3 and text the hash rule is the same: each piece of the store must
lie inside an exclusion, and that exclusion may hold nothing else. This is
`DataHashCheck::check()`, SPEC-012 amendments 5 and 7. It is the §15.12 rule,
so it is not repeated per format below.

| § | rule (paraphrased) | where | verdict | how known |
|---|---|---|---|---|
| A.1 | The store's location depends on the asset's format. | `FormatDetector::detect()` picks the extractor from the first bytes. `Verifier::verify()` sends each format to its own extractor | covered | measured: `IsobmffManifestStoreExtractorTest` AC3, `GifTest` AC11, `Mp3Test` AC13, `FlacTest` AC4, `PlainTextTest` AC13 |
| A.1 | Native FLAC and Ogg Vorbis are only being considered. FLAC is listed under ID3. | FLAC is read through the ID3v2 tag in front of the stream, with `Id3ManifestStoreExtractor` unchanged (SPEC-057). `c2pa-rs` `flac_io` does the same | covered | measured: `FlacTest` AC1, AC5 |
| A.2 | A multi-part asset keeps its store in the primary part. That active manifest carries a multi-asset hash for every part. | `c2pa.hash.multi-asset` is not read; the primary part's own hard binding decides. `c2patool` does not validate it either (`docs/comparison.md`) | n/a | read |
| A.3.1 | JPEG: the store is the data of APP11 marker segments (JPEG XT). | `JpegManifestStoreExtractor::extract()` collects APP11 segments that start with `JP` up to SOS. Other APP11 users are skipped | covered | measured: `JpegManifestStoreExtractorTest` AC1, AC8, AC13 |
| A.3.1 | A store that spans several segments follows ISO 19566-5 D.2: the same CI, En, LBox and TBox in every piece, and Z counting up. | `walk()` checks En, LBox and TBox against the first piece. Z must equal the piece number; a first Z of 0 is read (SPEC-041) | covered | measured: `JpegManifestStoreExtractorTest` AC3, AC7, AC11; `FirstPieceSequenceTest` AC1–AC5 |
| A.3.1 | Segments are written in sequential order. | Z out of order is a `ContainerException` | covered | measured: `JpegManifestStoreExtractorTest` AC3 |
| A.3.1 | The segments are contiguous. | A gap between pieces does not stop extraction. The data hash then refuses an exclusion that also covers the gap | by design: SPEC-001 AC4, the same as `c2patool` (`c2pa-rs` `jpeg_io` does not check adjacency either) | measured: `JpegManifestStoreExtractorTest` AC4 |
| A.3.1 | One store per asset (`c2pa-rs`: `TooManyManifestStores`). | A second En or a second piece 1 is refused. So is any other JUMBF in APP11, including one that is not C2PA → P10-1 | covered (stricter) | measured: AC11; probe `other-jumbf.jpg` (a JUMBF labelled `other` added before the store): `general.error` here; `c2patool` 0.27.22 says `Invalid` with `assertion.dataHash.mismatch` |
| A.3.2 | PNG: the store is a `caBX` chunk. | `PngManifestStoreExtractor::walk()`. A second `caBX` is refused. CRC and LBox are checked | covered | measured: `PngManifestStoreExtractorTest` AC1, AC2, AC5, AC6, AC7 |
| A.3.2 | `caBX` should come before `IDAT` (a recommendation only). | The position is not required. A `caBX` after `IDAT` or before `IHDR` is read; the walk ends at `IEND` | covered (a recommendation) | measured: `PngManifestStoreExtractorTest` AC8, AC9 |
| A.3.3 | SVG: Base64 store in `c2pa:manifest` inside `metadata`. | not read: *unsupported file type*. With `--text` it is plain text with no manifest | n/a | measured: probe `c.svg`, with and without `--text` |
| A.3.4 | ID3: the store is the object of a GEOB frame in an ID3v2 tag (MP3, FLAC). | `Id3ManifestStoreExtractor`: a tag at offset 0, v2.3 or v2.4; v2.2 is refused by name | covered | measured: `Id3ManifestStoreExtractorTest` AC1, AC3; `Mp3Test` AC24 |
| A.3.4 | The GEOB's MIME type is present and is the JUMBF media type of §11.4. | Matched exactly, against `MIME_TYPES`. The legacy `application/x-c2pa-manifest-store` is also accepted | by design: SPEC-056 amendment 2, as `c2pa-rs` (`id3_helper::is_c2pa_mime_type`) | measured: `Id3ManifestStoreExtractorTest` AC2, AC15 |
| A.3.4 | One store per tag (`c2pa-rs`: `TooManyManifestStores`). | A second C2PA GEOB is a `ContainerException` | covered | measured: `Id3ManifestStoreExtractorTest` AC6 |
| A.3.5 | Ogg Vorbis: its own logical bitstream, first packet `\x00c2pa`. | not read: *unsupported file type* | n/a | measured: probe `j.ogg` |
| A.3.6 | TIFF/DNG: tag 52545, type 7; one store, in the last main IFD. | not read: *unsupported file type*. With `--text`, a short synthetic header that happens to be valid UTF-8 reads as text with no manifest | n/a | measured: probes `a.tif`, `b.dng` |
| A.3.7 | RIFF (WAV, AVI, WebP): the store is a chunk with id `C2PA`. | `RiffManifestStoreExtractor`: one `C2PA` chunk. A second one is refused (`c2patool` takes the first) | covered | measured: `WebpManifestStoreExtractorTest` AC1, AC7; `WavManifestStoreExtractorTest` AC6; `AviTest` AC1 |
| A.3.7 | The chunk is in the first RIFF chunk… | Only the first RIFF chunk is walked. Bytes after it are left to the data hash | covered | measured: `AviTest` AC2, AC3 |
| A.3.7 | …as its last sub-chunk. | Not checked. A `C2PA` chunk in any position is extracted, and the data hash judges the file | by design: SPEC-055 (decided 2026-10-05, ADR-0005), SPEC-003 AC8. Neither `c2patool` version checks the position (`c2pa-rs` `riff_io::read_c2pa`) | measured: `WavManifestStoreExtractorTest` AC7, `WebpManifestStoreExtractorTest` AC8, `AviTest` AC4 |
| A.3.8 | GIF: Application Extension `C2PA_GIF`, block size 11, the store in sub-blocks of at most 255 bytes, then a terminator. | `GifManifestStoreExtractor`: identifier, block size and sub-blocks; the range is the whole block | covered | measured: `GifTest` AC1, AC5, AC8 |
| A.3.8 | The authentication code is a version, 1.0 = `01 00 00`. | Only version 1.0 is a store. A block with another version is skipped as an unknown extension | covered | measured: `GifTest` AC3 |
| A.3.8 | Quantity: one. | A second `C2PA_GIF` block is refused, also when the first is empty (stricter than `c2patool`, named) | covered | measured: `GifTest` AC4, AC4 (amendment 1) |
| A.3.8 | The block sits after the header and before the first image descriptor. | The walk stops at the first `0x2C` or the trailer. A block after it is not read | covered | measured: `GifTest` AC9 |
| A.3.9 | JPEG XL: at most one top-level `jumb` superbox holding the store. | not read: *unsupported file type* (box form and bare codestream). `c2pa-rs` 0.91.1 does register `JpegXlIO` → P10-8 | n/a | measured: probes `g.jxl`, `h.jxl` |
| A.3.10 | Fonts: a `C2PA` table (preliminary). | not read: *unsupported file type*. `c2pa-rs` has no font handler | n/a | measured: probe `i.otf` |
| A.4 | PDF: embedded file streams; the stores of incremental updates are processed as one store; PDF signature ranges are in the exclusions. | not read: *unsupported file type* | n/a | measured: probe `d.pdf`, with and without `--text` |
| A.5.1, A.5.1.1 | BMFF: the store is in a top-level `uuid` box with the C2PA extended type. | `IsobmffManifestStoreExtractor::walk()` with `C2PA_UUID`, top level only. A second C2PA box is refused | covered | measured: `IsobmffManifestStoreExtractorTest` AC1, AC4, AC9 (MP4, MOV, AVIF, HEIC fixtures) |
| A.5.1.2 | The box is a FullBox with version 0 and flags 0. | `readStore()` reads the four bytes and does not interpret them | candidate → P10-2 | read |
| A.5.3 | The store box comes after `ftyp` and before the first `mdat` and any `moov`. | Not checked. Each included box's offset is in the BMFF digest, so a moved box fails the hash | candidate → P10-2 | read (`c2pa-rs` `c2pa_boxes_from_tree_and_map` does not check either) |
| A.5.3 | `box_purpose` is `manifest`, `original` or `update`. With an update manifest, `original` stays in place and `update` is the last box. | Only `manifest` is read. Any other purpose is a `ContainerException` naming it, `original` and `update` included | candidate → P10-3 | measured: `IsobmffManifestStoreExtractorTest` AC5 (merkle, unknown); `original`/`update` read in `readStore()` |
| A.5.3 | For `manifest`, `data` holds an 8-byte offset to the first merkle box (zero if none), then the store, then zero or more padding bytes. | The offset is read and not used. Everything after it is taken as the store, and `JumbfParser::parse()` refuses bytes after the root superbox | candidate → P10-4 | read |
| A.5.3 | fMP4: each initialization segment carries an identical manifest box (pull); in push streaming, at least one in ten. | The caller hands one init segment and its fragments (SPEC-028). Nothing compares init segments | n/a | read |
| A.5.4.1 | Large `mdat`s or a flat fMP4 in one file: one merkle `uuid` box per subset, after the last `mdat` or before each `moof`, padded to a fixed size. | Refused outright: a second C2PA `uuid` box is `general.error` | by design: `docs/comparison.md` (the order rule of `c2pa-rs` #2702) and SPEC-026 AC4 | measured: `IsobmffManifestStoreExtractorTest` AC4 |
| A.5.4.1 | fMP4 split over files: one merkle box per fragment file. A leaf is the hash of the whole fragment file minus the exclusions. | `BmffHashCheck::checkFragment()` with `merklePayload()` and `included()`, over the whole file plus its tail | covered | measured: `FragmentedVerifierTest` AC1, AC3, AC4 |
| A.5.4.1 | `location` counts 0, 1, 2… in the order of the subsets. | Must be inside [0, count) and must not repeat (SPEC-028 amendment 1); the count must be complete. The order across separate files is not checked, as in `c2patool` | partial | measured: `FragmentedVerifierTest` AC5, AC8; order: `docs/comparison.md` (equal on purpose, step 192) |
| A.5.4.2 | `uniqueId` and `localId` say which tree a merkle box belongs to; `hashes` runs from the leaf to the row stored in the manifest. | One merkle map only; more is refused by name. The ids are not compared. A proof climbs to the map's first hash, and a lower stored row fails because the proof is too short | by design: SPEC-028 Out of scope ("several renditions") | measured: `FragmentedVerifierTest` AC6; the climb read in `checkFragment()`, `path()` |
| A.5.6 | The C2PA `uuid` box (data filter: the UUID at offset 8), `/ftyp` and `/mfra` are always on the exclusion list. | The data filter is honoured; other entries give `assertion.bmffHash.additionalExclusionsPresent` (`hasAdditionalExclusions()`). Nothing checks that `/ftyp` and `/mfra` are listed, and `c2pa-rs` does not either; a missing entry only means more bytes are hashed | n/a (a rule for the generator) | measured: `BmffHashCheckTest` AC4, `BmffV2ExclusionsTest` AC5, `BmffShapeTest` AC5 |
| A.5.6 | When the map has both `hash` and `merkle`, `/mdat` is excluded from offset 16. | `assertionOf()` refuses an assertion with both: one or the other, never both | candidate → P10-5 | read |
| A.5.6 | A `subset` that runs past the end of its box is allowed; bytes past the box are never hashed. | `ranges()` clips at the box end and drops a subset that starts beyond it | covered | measured: `BmffV2ExclusionsTest` AC4 |
| A.5.5, A.5.7–A.5.9 | Dynamic streams, non-AV timed tracks, external references, 32-bit offsets past 4 GB. | rules for the generator. Externally referenced boxes are hashed like any other box | n/a | read |
| A.6 | ZIP (EPUB, OOXML, ODF, OpenXPS): collection data hash plus central directory hash; store at `META-INF/content_credential.c2pa`. | not read: *unsupported file type*. `c2pa.hash.collection.data` is known as a hard-binding label (`HardBindings`) but is not verified. `c2pa-rs` registers `ZipIO` → P10-8 | n/a | measured: probe `e.epub` |
| A.7 | HTML: at most one `script`/`link` association; `manifest.html.multipleManifests`; whitespace stripped before Base64. | not read: *unsupported file type*. With `--text`, plain text with no manifest. `c2pa-rs` 0.91.1 has no HTML handler | n/a | measured: probe `f.html`, with and without `--text` |
| A.8.2 | Text wrapper: magic `C2PATXT\0`, version 1, 32-bit length, JUMBF store. | `PlainTextManifestStoreExtractor::scan()`, `wrapper()`. The length must fit the run, and LBox must equal the length | covered | measured: `PlainTextTest` AC1, AC6 |
| A.8.3.2 | Variation selector to byte: `U+FE00`–`U+FE0F` → 0–15, `U+E0100`–`U+E01EF` → 16–255. | `SelectorReader::selectors()` | covered | measured: `PlainTextTest` AC1 (byte-exact), AC9 (amendment 2, any piece size) |
| A.8.4.1, A.8.7.1 | Validators may meet several wrappers; the exclusions select one. Yet A.8.7.1 makes more than one valid wrapper a failure. | Two wrappers are a `ContainerException`, so `general.error` (not `manifest.text.multipleWrappers` → P10-7) | by design: SPEC-060 open question 4 (decided 2026-10-07), stricter than the oracle | measured: `PlainTextTest` AC5, AC5 (amendment 2) |
| A.8.4.2 | Detection: scan for `U+FEFF`, then a run of selectors, then the magic in the first 8 bytes. | `scan()` with `SelectorReader::nextMarker()`. A lone mark, an emoji's selector or another magic is text | covered | measured: `PlainTextTest` AC3, AC11 |
| A.8.6.1, A.8.7.3 | The exclusions correspond to the wrapper exactly. | The range runs from the marker to the end of the run (AC9). The exclusion that holds it must equal it (SPEC-012 amendment 7). Another exclusion elsewhere in the text is honoured, with `assertion.dataHash.additionalExclusionsPresent` | partial → P10-6 | measured: `PlainTextTest` AC9; the extra exclusion read in `DataHashCheck::check()` |
| A.8.6.1, A.8.7.2 | Hash the NFC-normalised UTF-8 text; offsets count in the NFC text. | Raw bytes are hashed; nothing is normalised. That is the oracle's way when verifying (`c2pa-rs` normalises only when it signs). It may call a text `Valid` that was hashed without normalising, where a validator that follows the spec would not | by design: SPEC-060 open question 5 (decided 2026-10-07; `ext-intl` is not allowed) | measured: `PlainTextTest` AC8 (`nfd-text.txt`) |
| A.8.7.1 | Failure codes `manifest.text.corruptedWrapper` and `manifest.text.multipleWrappers`. | Not emitted. A malformed version-1 wrapper and a second wrapper are `general.error`. A candidate with the magic but another version is read as text, so the file has no manifest | candidate → P10-7 | measured: `PlainTextTest` AC4, AC5, AC6, AC7 |
| A.9 | Structured text: an armoured `BEGIN/END C2PA MANIFEST` block; `manifest.structuredText.*` codes. | not read: *unsupported file type*. With `--text`, plain text with no manifest. `c2pa-rs` reads it only with the `unstable_structured_text` feature | n/a | measured: probe `k.py`, with and without `--text` |

### Candidates

- **P10-1 — another JUMBF in JPEG APP11 (§A.3.1).** The verifier collects
  every APP11 segment that starts with `JP`. `c2pa-rs` (`jpeg_io::read_c2pa`)
  starts a store only where the first piece's description box UUID begins with `c2pa` (bytes 24–28 of the segment),
  and skips other JUMBF boxes. A JPEG that also carries another JUMBF (for
  example JPEG 360 metadata) is therefore `general.error` here, measured on
  `other-jumbf.jpg` ("piece out of order at offset 71"). If the signer wrote
  that JUMBF before signing, `c2patool` can call the file `Valid`. On the
  probe it says `Invalid`, only because the insertion moves the store.
  `docs/comparison.md` line 145 names only a neighbouring limit. **Stricter
  than `c2patool`, fail-closed: documentation only** (a row in
  `docs/comparison.md`), or read the first piece's label if a real file turns
  up.
- **P10-2 — BMFF box layout (§A.5.1.2, §A.5.3).** Nothing checks the FullBox
  version and flags, or the store box's position after `ftyp` and before
  `mdat`/`moov`. `c2pa-rs` checks neither (`get_uuid_box_purpose` skips the
  four bytes; `c2pa_boxes_from_tree_and_map` has no position check). A moved
  box changes the offsets that the BMFF digest includes, so no verdict
  depends on it. **Documentation only.**
- **P10-3 — BMFF `original`/`update` purposes (§A.5.3).** These are refused by
  name, as an unknown purpose would be. `c2pa-rs` (`BmffIO::read_c2pa`) merges
  `original` and `update` into one logical store and validates it. It refuses
  `update` without `original`, and two of either. No spec or doc records the
  difference; SPEC-026 AC5 covers only `merkle` and unknown purposes. **Stricter than
  `c2patool`, fail-closed: documentation only** until a file carries it.
- **P10-4 — padding after the store in the BMFF box (§A.5.3).** The spec
  allows zero or more padding bytes after the store. The verifier passes
  them to `JumbfParser::parse()`, which refuses bytes after the root
  superbox ("the root superbox ends at … but the store holds …"). `c2pa-rs`
  (`Store::from_jumbf_impl`) reads one superbox and ignores the rest. The
  same applies to a merkle box padded to a fixed size: `CborDecoder` refuses
  trailing bytes, and `c2pa-rs` strips trailing zeros. A padded file from
  another writer would be `Invalid` here and `Valid` in `c2patool`. Read, not
  measured; next is a probe. **Stricter than `c2patool`, fail-closed:
  documentation only, or a fix if a real writer pads.**
- **P10-5 — `hash` and `merkle` together (§A.5.6).** The spec describes a
  BMFF hash map with both, with `/mdat` excluded from offset 16.
  `BmffHashCheck::assertionOf()` refuses that combination. `c2pa-rs`
  (`verify_stream_hash`) checks the file hash and then the Merkle trees. **Stricter than
  `c2patool`, fail-closed: documentation only** until a fixture exists.
- **P10-6 — extra exclusions in a text asset (§A.8.6.1, §A.8.7.3).** The spec
  ties the exclusions to the wrapper. An extra exclusion over visible text
  leaves that text unhashed. The file is then `Valid`, with the
  informational `assertion.dataHash.additionalExclusionsPresent`. `c2pa-rs`
  runs the same generic data hash and has no rule of its own for text (read
  in `plain_text_io.rs`). Equal to `c2patool`; more lenient than the
  sentence in the spec. **Stricter than `c2patool` if adopted**: for `text`,
  refuse any exclusion that does not hold the wrapper.
- **P10-7 — text status codes (§A.8.7.1).** `manifest.text.corruptedWrapper`
  and `manifest.text.multipleWrappers` are never emitted; `general.error`
  stands in. A candidate whose magic matches but whose version is not 1 is
  read as text with no manifest. The spec calls that a corrupted wrapper.
  `c2pa-rs` 0.91.1 defines neither code. The project rule is §15 codes
  verbatim, and these are spec codes. **Stricter than `c2patool` if adopted**
  for the version case (no manifest becomes `Invalid`); a change of code only
  for the others.
- **P10-8 — the list of formats `c2patool` can do more with.** The row
  in `docs/comparison.md` names TIFF, SVG and PDF. `c2pa-rs` 0.91.1 also
  registers `JpegXlIO` and `ZipIO` (`jumbf_io.rs`), and structured and plain
  text behind unstable features. With `--text`, SVG, HTML and structured text
  are read as plain text with no manifest, not as *unsupported*. **Documentation
  only.**
