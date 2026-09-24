# Changelog

This project follows the milestones in `docs/milestones.md`; each entry
below names the milestone, the specs that closed it and the day it was
measured against `c2patool` 0.27.22. Dates are the day the work was
committed.

## Unreleased

### Added
- SPEC-033: the actions content rules `c2patool` enforces, for v2 claims.
  - Only one opening action.
  - `c2pa.opened`, `c2pa.placed` and `c2pa.removed` need ingredient
    references of the right relationship, and `c2pa.transcoded` and
    `c2pa.repackaged` need a `parentOf` when they name one.
  - `c2pa.translated` needs both languages.
  - `relatedAssertions` must be non-empty and resolvable, and must not
    name actions or ingredients.
  - A watermark action needs a soft binding.
  - Two new status codes: `assertion.action.ingredientMismatch` and
    `assertion.action.softBindingMissing`. The surface is 114 symbols.
  - No corpus verdict changed.
- SPEC-034: icon references, checked as C2PA 2.4 §15.10.3.3 asks, in
  `claim_generator_info` and, in v2 claims, in `softwareAgents`,
  `templates` and an action's `softwareAgent`. A hashed-URI icon must name
  an assertion the claim lists, with the hash the claim records. An
  external url, or a data box of earlier versions, is `assertion.missing`.
  No new status code, and no corpus verdict changed. Closes the gap of
  issue #11.
- SPEC-035: redactions (C2PA 2.4 §6.8, §15.10.3.1, §15.11.3.3.1).
  - A child that redacts an assertion of its parent is read and judged,
    no longer refused: `Trusted` where both `c2patool` versions say so.
  - A v2 ingredient manifest with redacted assertions is bound by the
    hash of its signature box: `ingredient.claimSignature.validated`
    (informational), `.mismatch` or `.missing`.
  - A redacted actions assertion, a self-redaction, and a redacted box
    that still holds content are `assertion.action.redacted`,
    `assertion.selfRedacted` and `assertion.notRedacted`.
  - A redacted hard binding stays refused.
  - Six new status codes. The surface is 120 symbols.
  - Two existing files change their failure codes but stay `Invalid`:
    `general.error` becomes the redaction codes `c2patool` 0.28.0 reports.
- SPEC-036: a redaction of a hard-binding assertion (`c2pa.hash.data`,
  `.boxes`, `.bmff`, `.collection.data`) is reported as
  `assertion.hardBinding.redacted`, as `c2patool` 0.28.0 reports it,
  instead of `general.error`. It is still `Invalid`. `c2patool` 0.27.22
  says the deprecated `assertion.dataHash.redacted`. One new status code;
  the surface is 121 symbols.
- SPEC-037: a `c2pa.redacted` action with `parameters` must name, in
  `redacted`, an assertion that the named manifest's claim lists
  (C2PA 2.4 §15.10.3.2.3, as `c2patool` reads it). A missing, relative
  or foreign reference is `assertion.action.redactionMismatch`, and an
  unlisted label is `assertion.notRedacted`. This closes four shapes both
  `c2patool` versions call `Invalid` and this verifier called `Trusted`
  (step 131). A bare `c2pa.redacted` passes, as in `c2patool`. One new
  status code; the surface is 122 symbols.
- SPEC-038: the BMFF hash's shape (issue #4), as `c2patool` checks it.
  - An absent or empty `exclusions` list and unsorted or overlapping
    `subset` ranges are `assertion.bmffHash.malformed`.
  - A box that a `subset` touches keeps its offset in the hash, at the
    box's start, as `c2pa-rs` hashes it. This closes five shapes both
    `c2patool` versions judge differently from this verifier (step 133).
  - `assertion.bmffHash.additionalExclusionsPresent` is reported,
    informational, as `c2patool` 0.28.0 reports it: on nearly every BMFF
    file, since `c2pa-rs`'s writer excludes `/free` and `/skip`. No
    verdict changes.
  - Two new status codes; the surface is 124 symbols. Conformance gaps
    go from 14 to 11.
- SPEC-039: `claimSignature.insideValidity`, the success both `c2patool`
  versions list directly before `claimSignature.validated`, now appears
  in the same place, for the active manifest and for ingredients. As in
  `c2patool`, it accompanies every verified signature, an expired
  signer's included. No verdict changes. One new status code; the surface
  is 125 symbols.

## 0.2.0 — 2026-09-24

Safety fixes, and the first measurement against `c2patool` 0.28.0. Every
file whose result 0.28.0 changed was examined. Two were holes here and
are closed; the rest are aligned or named in `docs/comparison.md`.

**A `0.2`, not a `0.1.1`:** a settings file with a top-level
`trust.allowed_list` that worked in 0.1.0 is refused now. That is a
break, and `^0.1` does not pull it in. Everything else a 0.1.0 caller
wrote keeps working. The public API grew (eleven classes, 112 symbols)
and lost nothing.

### Added
- SPEC-032: two rules the oracles enforce.
  - A `c2pa.created` action without `digitalSourceType` in a v2 claim is
    `assertion.action.malformed`, as in `c2patool` 0.27 and 0.28. v1
    claims are untouched, as `c2pa-rs` leaves them.
  - The `c2pa.external-reference` checks of C2PA 2.4 §15.10.3.2.2: a
    `location` with a `url`, `alg` and `hash` together, and fourteen
    forbidden labels. They report the new code
    `assertion.external-reference.malformed`. Nothing is fetched.
  - The contract's surface grows to 112 symbols. No corpus verdict
    changed.
- SPEC-031: `trust.anchors`, the settings shape of `c2patool` 0.28
  (`c2pa` 0.91). Each entry has its own `trust_kind`, `allowed_list` and
  `trust_config`. Every entry counts only for its own kind (C2PA 2.4
  §14.4.1–§14.4.3). An entry's EKUs widen only the chains that reach it.
  The legacy `trust.trust_anchors` is still read. New contract class
  `Trust\TrustAnchorSet`; `TrustSettings::$anchorSets` and
  `MAX_ANCHOR_ENTRIES` (SPEC-025 amendment 4: eleven classes, 111
  symbols).

### Fixed
- A one-certificate `x5chain` written as a bare byte string (RFC 9360; what
  `c2pa-rs` writes for a signer directly under a root) was refused as
  `signingCredential.invalid`. It is now read as a chain of one, under
  the same rules as an array element (SPEC-008 amendment 2). No corpus
  file has that shape, so no recorded verdict changed.

### Changed
- **A hard binding referenced only from `gathered_assertions` is
  `claim.hardBindings.missing`**: C2PA 2.4 §10.2.2 requires
  `created_assertions` to reference it. 0.1.0 accepted such a manifest,
  as `c2patool` 0.27 did; `c2patool` 0.28 refuses it too. No real file
  in the corpus has that shape (SPEC-013 amendment 13).
- **The allowed list never makes a timestamp authority trusted** (C2PA 2.4
  §14.4.3). Through the PHP constructor it still could, and a trusted TSA
  moves the moment a signer is judged at, so an expired signer could stop
  being expired (step 114). SPEC-017 amendment 5. No settings file is
  affected.
- **A top-level `trust.allowed_list` is refused**, with a message saying
  where it belongs (`trust.anchors[].allowed_list`). `c2patool` 0.28
  moved it there and ignores a loose one without a word (step 107).
  SPEC-014 amendment 3.

### Security
- **Fixed: an exclusion wider than the manifest store was accepted.**
  Up to and including 0.1.0, a `c2pa.hash.data` exclusion that held the
  manifest store *and* other bytes passed as long as it covered the store.
  The three official `truepic-20230212-*` files exclude the whole EXIF
  segment that way, and a copy with its EXIF capture date changed stayed
  `Trusted`. C2PA 2.4 (VAL-ASSE-0043/0044) requires
  `assertion.dataHash.mismatch`, and that is what this version gives
  (SPEC-012 amendment 7, which reverses amendment 5; SPEC-017 amendment 4).
  Found 2026-09-24 through `c2patool` 0.28.0 (step 108), fixed the same
  day (step 109). Over 864 runs, it changed the verdict of those three
  files and no others.
- `docs/conformance.md`: `PRED-IMG-004` had been marked enforced since
  step 90 while it was not. The table now says when it became true.

## 0.1.0 — 2026-09-23

The first tag. A `0.x` on purpose: the public API is recorded and guarded
(ten classes, 99 symbols, a snapshot that fails the build on drift), but
nobody outside this project has used it yet, and a `1.0` would promise a
stability that has not been earned. `^0.1` receives every 0.1.x fix; a
change that breaks the API will be `0.2.0`.

Everything below shipped in this tag.

### Revocation without a network (2026-09-22)
- SPEC-030: the OCSP responses a signer staples into its own signature
  (`rVals.ocspVals`) are read, parsed per RFC 6960, matched to the
  signer's certificate and verified under a responder tied to its own
  issuer. A verified `revoked` makes the file `Invalid`
  (`signingCredential.ocsp.revoked`); everything unreadable,
  unverifiable, stale or about another certificate is
  `signingCredential.ocsp.skipped` and costs no verdict.
- The shape of that rule comes from one measurement: **`rVals` sits in
  the COSE unprotected bucket**, so anyone holding the file can add,
  alter or strip it. A stapled response may therefore lower trust and
  never raise it, and may never fail a file it cannot prove anything
  about — otherwise editing one unsigned byte would deny any valid asset.
- **Every file now reports whether revocation was checked at all**, and
  `checksPerformed` carries `revocation`. A skipped check that leaves no
  trace is the shape of silence this project refuses elsewhere. This is a
  second deliberate divergence from `c2patool`, which emits no OCSP code
  of its own on the two fixtures that carry a stapled response.
- Still out of scope, by rule rather than by milestone: any revocation
  that needs the network — online OCSP, an AIA fetch, a CRL.
- `StatusCode` grows by four cases; the recorded public surface goes from
  95 symbols to 99 (SPEC-025 amendment 3).

### Every obligation of the specification, listed (2026-09-22)
- `docs/conformance.md`: the 111 predicates of
  `encypherai/c2pa-conformance-suite` that apply to the formats this
  verifier reads, laid one by one next to what it actually does — 54
  enforced, 12 partial, 7 closed by refusing the feature, 21 out of scope
  by design, **17 gaps**, each with what it would cost. The catalogue is
  used as a checklist of named obligations, not as an oracle: that
  suite's own JPEG path disagrees with `c2patool`, this verifier and the
  Go implementation on files all three accept.
- The table found the gap SPEC-030 then closed, and says plainly that it
  is reasoned rather than measured except where an entry names a test.

### M8 — ISOBMFF (2026-09-22)
- SPEC-026: the ISOBMFF container. One top-level `uuid` box with the C2PA
  UUID and a 21-byte preamble, read with a bounded box walk; a second
  such box, a `purpose` this verifier does not read, or a header that
  does not fit is an error naming it. MP4, MOV, AVIF and HEIC, each held
  by a fixture here — a format is not named anywhere unless a file in
  this repository carries it.
- SPEC-027: `c2pa.hash.bmff.v3`. The digest was measured by instrumenting
  `c2pa-rs` rather than guessed: for each top-level box no exclusion
  matches, in file order, the eight-byte big-endian offset and then the
  box's bytes.
- SPEC-028: fragmented streams. A DASH init segment and its fragments as
  one verdict — the init against `initHash`, every fragment against the
  Merkle root, and the count as part of the promise: a withheld, repeated
  or foreign fragment is `Invalid` and **named**, which `c2patool` 0.27.22
  does not do (it answers in text, not JSON, and says only that something
  failed). `FragmentedVerifier` is the tenth class of the public contract,
  and takes the fragments one open stream at a time.
- SPEC-029: `c2pa.hash.bmff.v2`, after `c2pa-rs`'s own `video1.mp4` turned
  one up. The digest is identical to v3's; what differs is the exclusion
  list, and v2's needs nested box paths and `subset` filters. A filter this
  verifier cannot honour is refused only once its path resolves to a box
  the file actually has.

### Assurance: what a version number promises (2026-09-22)
- SPEC-025: the public API is ten classes and 99 recorded symbols, every
  other public class marked `@internal`. The surface is recorded in
  `tests/Fixtures/api/public-surface.txt` so a change to the promise shows
  up as a diff in review; `bin/api-check.php` is a step of `composer
  check` and fails the build on drift.
- SPEC-024: resource bounds. A manifest store that used to end a 128 MB
  process fatally now returns `Invalid` in 6 MB and 2 ms. Every parser has
  a limit and every limit has a message.
- Mutation testing with Pest's `--mutate`: **98.06 %**.

### The published package (2026-09-22)
- SPEC-023: what a `composer require` actually installs. `.gitattributes`
  keeps the 63 MB of fixtures, the tooling and the tool configuration out
  of the distributed archive; the documentation stays in, because the
  README and the log link to `notes/`, `specs/` and `docs/` and the spec
  requires every relative link in shipped markdown to resolve to something
  also shipped. `bin/package-check.php` measures the archive rather than
  trusting the list: 245 files, 2.5 MB, every top-level path classified.

### Coverage and a fix (2026-09-22)
- `tests/Fixtures/matrix/`: the three unsigned fixtures signed with all
  seven signature algorithms in all three containers, plus two files
  whose data hash is sha384 and sha512 — the cells the four corpora left
  empty (Es512, Ps384, Ps512 and Ed25519 were in no file at all, and
  WebP in one). The fifth drift alarm compares every one with
  `c2patool`'s JSON, with and without the test roots.
- Fixed: **on PHP 8.3 every Ed25519-signed file was `Invalid`**
  (`signingCredential.invalid`, "key of type other") and `Trusted` on
  8.4 and 8.5 — the key's kind is now read from the
  SubjectPublicKeyInfo's algorithm OID, as the RSASSA-PSS case already
  was. The C2PA rule never changed; no fixture could show it until the
  matrix existed (SPEC-015 amendment 5).

### M7 — ingredient and update manifests (2026-09-22)
- SPEC-022: update manifests (`c2um`) are read and judged — C2PA 2.4
  §11.2.3's rules (`manifest.update.invalid`,
  `manifest.update.wrongParents`), §15.11's one-parent rule
  (`manifest.multipleParents`, which closes a leniency: two parents were
  `Trusted` here), the hard binding found up the `parentOf` chain
  (§15.12) and its stale exclusion adjusted to the store's current range
  (§15.12.1.1) with the cover rule still over it. Time-stamp manifests
  (`c2tm`) are refused in their place; compressed manifests (`c2cm`)
  stay refused. **Every multi-manifest file in the four corpora is now
  measured rather than refused.**
- Stricter than `c2patool` by the specification: a hash assertion in an
  update manifest is `manifest.update.invalid` here and `Trusted` there
  (the rule sits in unreachable code in c2pa-rs) — `docs/comparison.md`.
- Fixed: the opening rule of SPEC-018 no longer applies to an update
  manifest (it never should have — the spec said so, the code could not);
  an empty `claim_generator_info` is read rather than refused; the set of
  statuses an ingredient assertion's record silences is the store's, not
  one assertion's (SPEC-021 amendment 4).
- SPEC-021: the manifests an ingredient assertion names are validated —
  the box hash it recorded (`ingredient.manifest.validated` /
  `.mismatch`; the pre-1.3 hash over the claim accepted silently) and
  then the manifest itself: timestamp, signature, certificate profile,
  chain and trust, hashed URIs, actions. Never the data hash: an
  ingredient's hard binding covers its own asset. A fault the ingredient
  assertion *recorded* is dropped, as the specification says and
  `c2patool` does — except when it names the active manifest, which no
  ingredient assertion may speak for. **A store with more than one
  manifest is no longer refused** (SPEC-013 amendment 5 lifted):
  seventeen corpus files are measured now, sixteen with c2patool's
  verdict exactly. Still refused by name: update manifests (`c2um`),
  CAWG identity assertions, claims with redactions.
- SPEC-020: the ingredient assertion (`c2pa.ingredient`, `.v2`, `.v3`)
  and the graph it draws over the manifest store — the walk from the
  active manifest with bounds and cycle detection,
  `ingredient.unknownProvenance`, `ingredient.manifest.missing`,
  `assertion.ingredient.malformed`; statuses scoped to their ingredient
  assertion and rendered under `validation_results.ingredientDeltas`,
  and `ingredients` per manifest, both as `c2patool` prints them.
  No verdict changed: a store with more than one manifest is still
  refused until SPEC-021 validates the manifests the graph found.

### The command line (2026-09-22)
- SPEC-019: `bin/c2pa-verify <file> [--settings <path>]` — the report as
  `toJson()` on standard output, `Error: …` on standard error, exit status
  0 (`Trusted`/`Valid`), 1 (`Invalid`, report printed), 2 (no report).
  Registered as a Composer `bin`. `c2patool`'s exit status was measured
  first and departed from where it is fail-open (exit 0 on `Invalid`; a
  missing settings file ignored).
- Fixed: `toJson()` threw `JsonException` on a manifest whose
  `claim_generator_info` carries a byte string (OpenAI's generator icon);
  bytes now render as base64 like every other value (SPEC-007
  amendment 5, found by SPEC-019's corpus criterion).

### M6 — RFC 3161 timestamps (2026-09-22)
- SPEC-016: an own DER reader (`src/Asn1/`) and the timestamp token as data
  (`src/Timestamp/`) — `TimeStampResp`/`TimeStampToken`, `SignedData`,
  `SignerInfo`, `TSTInfo`, bounded, every fault with its offset.
- SPEC-017: the timestamp check — the CMS signature (RSA PKCS#1, ECDSA,
  RSASSA-PSS; DER and raw R‖S; the DER-sorted `SET` of signed attributes),
  the imprint over the `CounterSignature` bytes, the TSA's profile and
  chain through the trust settings, the six `timeStamp.*` codes (all
  informational), `signature_info.time`, and the time the signer's
  validity is judged at — only a validated *and* trusted timestamp
  supplies it. ADR-0004.
- SPEC-018: the actions assertion — a 2.x manifest opens with
  `c2pa.created` or `c2pa.opened`, or it is `assertion.action.malformed`.
- SPEC-013 amendment 9: a remote manifest declared by URL is reported
  (`remote_manifest`), never fetched.
- Fixed: a correctly signed manifest with no hard binding was `Valid`
  (SPEC-013 amendment 10, `claim.hardBindings.missing`); a 2.x manifest
  without an actions assertion was `Valid` (SPEC-018). See `SECURITY.md`.
- Fixed: negative RFC 3161 nonces refused as malformed; fractional `genTime`
  dropped from `signature_info.time`; the CMS signature verified over the
  attributes as written rather than the DER-sorted `SET` (SPEC-016
  amendment 3, SPEC-017 amendments 2–3 — found through the writers corpus).
- Fixtures: `tests/Fixtures/writers/` (OpenAI, Amazon Bedrock, `c2pa-ts`,
  Adobe Photoshop, a CAWG file, a Pixel 10 photo, a Lightroom Classic
  export) as a fourth drift alarm; TSA anchors cut from the tokens;
  `tests/Fixtures/absence/` (signed manifests with one thing absent);
  `bin/fuzz.php` (70 870 mutated files, no exception escaped).

### M5 — certificate chain and trust (2026-09-21)
- SPEC-014: trust settings in `c2patool`'s JSON shape, the allowed list,
  the chain walk to an anchor on `openssl_x509_verify`; `Trusted`.
- SPEC-015: the C2PA 2.4 §14.5 certificate profile; `signature_info`.
  ADR-0003.
- Fixtures: `c2pa-org/public-testfiles` (24 JPEGs) and `c2pa-rs`'s own (17
  files) as drift alarms; CBOR floats and indefinite lengths accepted and
  bounded (SPEC-006 amendments 2–3); a data-hash exclusion must *cover*
  the store (SPEC-012 amendment 5); multi-manifest stores and CAWG
  assertions refused until validated (SPEC-013 amendments 5, 7).

### M4 — hash binding (2026-09-21)
- SPEC-011: the hashed URI of every assertion the claim names;
  undeclared assertions refused.
- SPEC-012: `c2pa.hash.data` over the asset, streamed in 64 KiB chunks,
  exclusions sorted and checked, one hard binding per manifest.

### M3 — COSE_Sign1 (2026-09-21)
- SPEC-008: the COSE_Sign1 structure, protected `x5chain`, Sig_structure.
- SPEC-009: ES256/384/512, PS256/384/512 (an own EMSA-PSS verifier for
  ordinary RSA keys), Ed25519 (opt-in `sodium`), R‖S → DER.
- SPEC-010: the report — `validation_state`, the §15 status codes
  verbatim, `validation_results` and `validation_status` as `c2patool`
  prints them.

### M2 — JUMBF and CBOR (2026-09-21)
- SPEC-005: the JUMBF box tree with bounds. SPEC-006: a CBOR decoder for
  the subset C2PA uses. SPEC-007: claim v1 and v2, assertions,
  `claim_generator_info`; equal to `c2patool`'s JSON through the sister
  library's parser.

### M1 — containers (2026-09-20)
- SPEC-001: JPEG APP11 (multi-segment). SPEC-002: PNG `caBX`. SPEC-003:
  WebP RIFF `C2PA`. SPEC-004: a bounded stream reader. Byte-exact
  extraction, measured by hash against `c2patool`.

### M0 — skeleton (2026-09-19)
- SPEC-000: the spec template and `bin/spec-check.php`; CI on PHP
  8.3/8.4/8.5; Pint, PHPStan level max, Deptrac, Pest; ADR-0001
  (dependencies: none), ADR-0002 (name, namespace, MIT).
