# Changelog

This project follows the milestones in `docs/milestones.md`; each entry
below names the milestone, the specs that closed it and the day it was
measured against `c2patool` 0.27.22. There is no tagged release yet and
nothing on Packagist; the first tag comes when the maintainer decides the
public API is stable. Dates are the day the work was committed.

## Unreleased

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
- SPEC-023: what a `composer require` actually installs — `.gitattributes`
  keeps the fixtures, notes, specs and tooling out of the distributed
  archive, and `bin/package-check.php` measures the archive rather than
  trusting the list.

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
