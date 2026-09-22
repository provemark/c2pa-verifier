# Milestones

What this project builds, in which order, and what "done" means for each
step. This page is the plan; `NOTES.md` (from M0.5) is the record of what
actually happened, step by step. When the two disagree, the record wins and
this page is corrected.

Every milestone ends with a measurement against an external oracle —
`c2patool` (pinned version written into the note of the step that measured
it), the official `c2pa-org/public-testfiles`, and where applicable the
service reader of `provemark/content-credentials`. A milestone whose "done
when" has not been measured is not done.

## The milestones

| M | What | Done when |
|---|---|---|
| M0 | Repository skeleton: package, tool chain, spec template, traceability check, CI, notes, ADRs | `composer check` green on an empty `src/` — **done 2026-09-19**; first green CI run (all three PHP versions) on `bbb7299`, run `35444321627`, the same day |
| M1 | **Container → manifest store bytes.** JPEG APP11 (multi-segment, Box Instance Numbers), PNG `caBX`, WebP RIFF `C2PA`. Byte-exact extraction, nothing parsed. | SHA-256 of the extracted store equals what `c2patool --detailed` / a hexdump gives, for every fixture — **done 2026-09-20** (steps 02–07; the hashes in `notes/step-02`, `-04`, `-06`) |
| M2 | **JUMBF + CBOR → manifest store as data.** Boxes, superboxes, description boxes, content-type UUIDs; a CBOR decoder for the subset C2PA uses; claim v1 and v2; assertions; `claim_generator_info`. | the sister library's `ManifestStoreParser::fromJson()` accepts the output and every accessor equals its `/v1/read` — **done 2026-09-21** (steps 09–15; SPEC-007 AC6: the five content accessors equal c2patool's JSON through the sister parser; the crypto accessors join in M3–M6) |
| M3 | **COSE_Sign1.** Protected header, `x5chain`, Sig_structure, verify ES256/ES384/PS256/Ed25519. No trust yet. | `claimSignature.validated` equals c2patool on all fixtures; one altered byte in the claim → `claimSignature.mismatch` — **done 2026-09-21** (steps 16–22; SPEC-010 AC1/AC2 compare code and url with c2patool's JSON; ES256/384/512, PS256/384/512 and Ed25519 on `ext-openssl` + opt-in `sodium`, ADR-0001 amended) |
| M4 | **Hash binding.** `c2pa.hash.data` v1/v2: exclusions, `pad`, streaming hash. Hashed-URI checks on assertions. | one changed pixel byte → `assertion.dataHash.mismatch`; untouched file `Valid` — **done 2026-09-21** (steps 23–27; SPEC-011 AC1/AC3 and SPEC-012 AC1/AC2 compare code and url with c2patool's JSON; the store's exclusion checked against the container's own range; 48 MiB streamed under 4 MiB) |
| M5 | **Chain and trust.** Chain from `x5chain`, anchor from `trust_anchors`, EKU from `trust_config`, `allowed_list`. `Trusted` vs `Valid`. | verdicts equal `c2patool --settings` with and without the trust file; test cert without trust file → `signingCredential.untrusted` — **done 2026-09-21** (steps 30–35; ADR-0003; SPEC-014 the chain, SPEC-015 the profile; SPEC-015 AC9 measures both lines against c2patool's JSON on all four fixtures; the drift alarm covers 22 + 12 files) |
| M6 | **RFC 3161.** `sigTst` / `sigTst2` (ASN.1), TSA signature, signing time against certificate validity. | `hasTimestamp` and `timeStamp.*` codes equal c2patool on a timestamped fixture — **done 2026-09-22** (steps 40–42; ADR-0004; SPEC-016 the DER reader and the token as data, SPEC-017 the check; 35 corpus tokens `validated` with c2patool's `signature_info.time` byte for byte, `E-sig-CA`/`CA_ct` the same failures; the Truepic files `Trusted` and no longer `expired` with their root as anchor, equal to c2patool under the same settings; the one divergence by design — `timeStamp.trusted` needs an anchor here — informational and named) |
| M7 | **Ingredients and manifest chains.** `parentOf`, `componentOf`, manifest labels; an ingredient never masks a failure in the active manifest. | `c2pa-org/public-testfiles` with ingredients yield the same status list |
| M8 | **ISOBMFF** (MP4/MOV/AVIF): `c2pa.hash.bmff.v2`, Merkle trees, exclusions. Least documented; last. | sister-library fixtures + c2patool |
| later | GIF, TIFF, SVG, WAV, MP3, FLAC, AVI | one spec per format |

Fixed across all of them: read and verify only, never sign; pure PHP `^8.3`,
no `ext-*` beyond `openssl`, `mbstring` and opt-in `sodium`; no `exec`, no
network during verification; c2patool's `validation_state` and the C2PA 2.4
§15 status codes verbatim, no vocabulary of our own; trust settings in the
same JSON shape c2patool reads; **fail closed** — every unknown box,
algorithm, claim version or assertion is an error with a status code, never
a silent `Valid`.

## M0, step by step

Each step is one commit, explained before it is built, with its own
`AI-LOG.md` entry.

| Step | What | Status |
|---|---|---|
| M0.1 | `composer.json` (`provemark/c2pa-verifier`, MIT, `php ^8.3`, no packages in `require`), `LICENSE`, `src/`, `tests/Fixtures/README.md` | done, `53caa8d` |
| M0.2 | Pint, PHPStan level max, Deptrac (one layer per milestone), Pest; `composer check` as the single definition of green | done, `b351e86` |
| M0.3a | `specs/TEMPLATE.md`; SPEC-000 (the traceability checker) as draft, then approved | done, `60ec881`, `eff055e` |
| M0.3b | Red tests for SPEC-000, `->group('SPEC-000')`, fixture trees under `tests/Fixtures/spec-check/` | done, seen red (11 failed) |
| M0.3c | `bin/spec-check.php`; first step of `composer check`; SPEC-000 → `implemented` with Traceability | done, 11 passed, AC10 measured by hand |
| M0.4 | CI: `.github/workflows/ci.yml`, `composer check` on PHP 8.3 / 8.4 / 8.5 | M0.4b done 2026-09-19: private repo `provemark/c2pa-verifier`, first run read per job. Pest 5 needed PHP ^8.4, so the 8.3 leg could not install; fixed the same day with `pestphp/pest ^4.0` (as the sister library). CI red on purpose until step 03b; first green run on `bbb7299` (run `35444321627`): 8.3 / 8.4 / 8.5 each 27 passed |
| M0.5 | `README.md` (with the "How this is built" disclosure), `NOTES.md` + `notes/step-01-*.md`, ADR-0001 (dependencies), ADR-0002 (name, namespace, licence) | done |
| M0.6 | Measurement: `composer check` green on an empty `src/`; M0 closed | done — exit 0, `src/` held only `.gitkeep`; the CI run closed with the first green run on `bbb7299` |

Why M0.3 exists at all: Pest exits 1 on an empty suite (measured in M0.2),
which is the wanted behaviour — a suite that runs nothing must not be green.
So M0 needs one real test, every test needs a spec, and the first thing worth
specifying is the tool that enforces exactly that.

## Between M1 and M2

| Step | What | Status |
|---|---|---|
| SPEC-004 | One `StreamReader` for the Container layer; SPEC-001 amendment 2 (AC16: a JPEG ending exactly on a segment boundary) | implemented 2026-09-20: 8 tests red → green, 71 in all (step 08) |

## M2, step by step

| Step | What | Status |
|---|---|---|
| 09 | The store from the inside: JUMBF tree, CBOR inventory, COSE shape, `c2patool --detailed`, one foreign writer — `notes/step-09-manifest-store-inside.md` | done 2026-09-20 |
| 10 | Twenty-three malformed stores (`bin/make-jumbf-variants.php`, `tests/Fixtures/jumbf/`) through c2patool — `notes/step-10-jumbf-variants.md` | done 2026-09-21 |
| SPEC-005 | JUMBF: box frame, superbox, description box, content boxes, the C2PA UUIDs | implemented 2026-09-21: 19 tests red → green, 90 in all; `Support` layer added (SPEC-004 amendment 1) — step 11 |
| 12 | Sixteen CBOR values recorded (`tests/Fixtures/cbor/*.json`); four claim-level faults through c2patool (`bin/make-cbor-vectors.php`) — `notes/step-12-cbor-vectors.md` | done 2026-09-21 |
| SPEC-006 | CBOR: the measured subset, definite lengths only, fail closed on the rest | implemented 2026-09-21: 16 tests red → green, 106 in all — step 13 |
| 14 | c2patool's JSON recorded (`tests/Fixtures/c2patool/`); fifteen claim variants through c2patool (`bin/make-claim-variants.php`) — `notes/step-14-claim-variants.md` | done 2026-09-21 |
| SPEC-007 | Claim v1 and v2, assertion store, `claim_generator_info`, the JSON view for the sister library | implemented 2026-09-21: 14 tests red → green, 120 in all; AC6 (the sister library's `fromJson()`) green — step 15. **M2 complete** |

## M3, step by step

| Step | What | Status |
|---|---|---|
| 16 | The four signatures verified with `ext-openssl`, PSS measured with both key kinds, three broken variants through c2patool, `cose-lib` measured — `notes/step-16-cose-signature.md` | done 2026-09-21 |
| ADR-0001 | Amendment 1: COSE verification written here on `ext-openssl` | decided 2026-09-21 |
| 17 | Eleven structural COSE variants through c2patool (`bin/make-cose-variants.php`) — `notes/step-17-cose-variants.md` | done 2026-09-21 |
| SPEC-008 | COSE_Sign1: structure, headers, `x5chain`, the `Sig_structure` | implemented 2026-09-21: 12 tests red → green, 132 in all — step 18 |
| 19 | Fourteen signature vectors (`bin/make-signature-vectors.php`, `tests/Fixtures/signatures/`), the P-521 DER bug and the PSS-parameter refusal found — `notes/step-19-signature-vectors.md` | done 2026-09-21 |
| SPEC-009 | Signature verification per algorithm, key-fits-algorithm | implemented 2026-09-21: 11 tests red → green, 143 in all — step 20 |
| SPEC-010 | `Report`: the §15 codes for the claim signature and the Manifest layer's faults, verbatim; a partial report that names its checks | implemented 2026-09-21: 10 tests red → green, 153 in all; SPEC-007/008/009 amended (status codes on exceptions) — step 22. **M3 complete** |

## M4, step by step

| Step | What | Status |
|---|---|---|
| 23 | Hashed URIs and the streaming data hash reproduced for the four fixtures with a probe; thirteen binding variants through c2patool (`bin/make-binding-variants.php`, `tests/Fixtures/binding/`) — `notes/step-23-binding-measured.md` | done 2026-09-21 |
| SPEC-011 | The hashed-URI check: every entry of the claim hashed against its box, `assertion.hashedURI.match`/`.mismatch`, `assertion.undeclared` for boxes no entry names (unknown boxes too), `algorithm.unsupported` per §15.4.2/§13.1, redactions refused until M7; SPEC-007 amendment 2 (`assertionStore` public), Deptrac `Hash` → `Jumbf` | implemented 2026-09-21: 10 tests red → green, 163 in all; SPEC-007 amendment 2, SPEC-010 amendment 1 (AC10's test) — step 25 |
| 24a | The eight SPEC-011 variants made (`bin/make-hashed-uri-variants.php`, helpers shared in `bin/variant-helpers.php`) and measured through c2patool; no criterion contradicted, three divergences recorded — `notes/step-24-hashed-uri-variants.md` | done 2026-09-21 |
| 24b | The ten SPEC-011 tests (`tests/Unit/Hash/HashedUriCheckTest.php`), seen red: nine on the missing `Hash\HashedUriCheck`, AC9 on the enum still holding twelve codes | done 2026-09-21 |
| SPEC-012 | The data-hash check: exactly one `c2pa.hash.data`, shape checked, the store's exclusion equal to the store's file range (from `ManifestStoreBytes::$ranges`, SPEC-001/002/003 amendment), the asset hashed in 64 KiB chunks with the exclusions skipped, six codes incl. the first informational; Deptrac `Hash` → `Container` | implemented 2026-09-21: 10 tests red → green, 173 in all; amendments 1–3; SPEC-001/002/003 amended (`$ranges`), SPEC-010 amendment 2 (`validation_status` failures only, `Valid` needs a success), SPEC-011 amendment 1 — step 27. **M4 complete** |
| 26a | The store's range measured against the exclusion (exact on all four fixtures; two ranges for the gap JPEG); the twelve SPEC-012 variants made (`bin/make-data-hash-variants.php`) and measured through c2patool; AC8's url amended to the manifest's (amendment 1) — `notes/step-26-data-hash-variants.md` | done 2026-09-21 |
| 26b | The ten SPEC-012 tests (`tests/Unit/Hash/DataHashCheckTest.php`), seen red: eight on the missing `Hash\DataHashCheck`, AC1 on the missing `$ranges`, AC10 on the enum still holding fifteen codes; amendment 2 (`validation_status` holds failures only, measured) | done 2026-09-21 |
| SPEC-013 | The Verifier: format from the magic bytes, extractor, parse, then signature → hashed URIs → data hash (skipped after a hashed-URI mismatch on `c2pa.hash.data`, SPEC-011 decision 1); `VerificationReport` in c2patool's shape plus `format`/`has_manifest`/`checks_performed`; the sister parser reads it; the drift alarm over every recorded c2patool JSON; SPEC-007 amendment 3 (`ManifestException::$url`) | implemented 2026-09-21: 10 tests red → green, 183 in all; amendments 1–2; SPEC-007 amendment 3; Deptrac `Verifier` → `Support` — step 29 |
| 28 | The ten SPEC-013 tests (`tests/Unit/Verifier/VerifierTest.php`), seen red on the missing `Verifier`; AC10's subset list corrected by the recorded JSON (amendment 1) | done 2026-09-21 |

## M5, step by step

| Step | What | Status |
|---|---|---|
| 30 | Trust measured: the certificates, the fixtures' chains, c2patool under nine settings (`tests/Fixtures/trust/`, `tests/Fixtures/c2patool/trusted/`), c2pa-rs's trust and profile checks read from source, `ext-openssl`'s reach — `notes/step-30-trust-measured.md` | done 2026-09-21 |
| ADR-0003 | X.509 chain and profile written here on `ext-openssl` (no `phpseclib`, no temp files, no `checkpurpose` except as a second oracle); allowed list first; the EKU list as c2pa-rs keeps it (Maurice: option a); RFC 3161 open until M6 | accepted 2026-09-21 |
| SPEC-014 | Trust: `TrustSettings` (the shared format, contents not paths, whole or absent), `Certificate` on `openssl_x509_parse`/`_verify`, `ChainCheck` (allowed list first, then the walk to an anchor — DER-equal or signed by one, no trust by name), `Trusted` as a state with the measured rule (`untrusted` alone keeps `Valid`), `validation_status` omitted when empty, `Verifier::verify($stream, ?TrustSettings)` (SPEC-013 amendment 3); the second oracle `openssl_x509_checkpurpose` | implemented 2026-09-21: 10 tests red → green, 193 in all; SPEC-010 amendment 3, SPEC-011 amendment 2, SPEC-012 amendment 4, SPEC-013 amendment 3; Deptrac `Trust` → `Cose`, `Support` — step 32 |
| 33 | The certificate profile measured: `bin/make-profile-variants.php` (throw-away hierarchy, the manifest re-signed, keys deleted), twelve variants through c2patool (`tests/Fixtures/profile/`, `tests/Fixtures/c2patool/profile/`), c2pa-rs's KU/AKI/critical-extension rules read — `notes/step-33-profile-measured.md` | done 2026-09-21 |
| SPEC-015 | The certificate profile on `openssl_x509_parse`: end-entity, v3, validity at now (M6: timestamp) → `.expired`, algorithm/key lists, KU as c2pa-rs (option a), EKU built-in six + `trust_config`, AKI present → `signingCredential.invalid` per fault; `signature_info` per manifest (serial hex → decimal); unknown critical extensions named as the one accepted-more-than-oracle gap until M6 | approved 2026-09-21 |
| 34a | The deferred measurements: the profile is checked without settings and with `verify_trust` off, and independently of the chain (`expired` + wrong anchor → both codes) — SPEC-015 amendment 1 (`certificate` always, after the signature) | done 2026-09-21 |
| 34b | The ten SPEC-015 tests (`tests/Unit/Trust/CertificateProfileCheckTest.php`), seen red on the missing `CertificateProfileCheck`, `Certificate::fromParsed()`, `signingCredential.expired`, `signature_info` and the `certificate` check; AC9 also exposes that without settings this verifier says nothing where c2patool says `untrusted` — to be settled in step 35 as SPEC-014 amendment 1 | done 2026-09-21 |
| SPEC-015 | (the row above) | implemented 2026-09-21: 10 tests red → green, 203 in all; amendments 1–2; SPEC-014 amendment 1, SPEC-013 amendment 4 — step 35. **M5 complete** |

## Between M5 and M6: the official test files

| Step | What | Status |
|---|---|---|
| 36 | The 26 JPEGs of `c2pa-org/public-testfiles` (`legacy/1.4/image/jpeg/`) as fixtures with c2patool's JSON; 20 of 26 states equal on first contact; two findings — floats (SPEC-006) and ingredient manifests (SPEC-013, fail closed until M7) — `notes/step-36-public-testfiles.md` | done 2026-09-21 |
| 37 | SPEC-006 amendment 2: floats decode (AC7 turned around, red → green); SPEC-015 amendment 3: `sha384/512WithRSAEncryption`; the four camera files through the front door — Nikon equal to c2patool, Truepic raises the exclusion-rule question (SPEC-012) and needs M6 — `notes/step-37-floats.md` | done 2026-09-21 |
| 38a | SPEC-012 amendment 5: the store's exclusion must *cover* the store, not equal it (Maurice's decision) — AC3 red on Truepic, then green; every step-23 variant still fails | done 2026-09-21 |
| 38b | SPEC-013 amendment 5, AC11: a store with more than one manifest is `Invalid` with `general.error` until M7 (Maurice: fail closed); the official corpus as a second drift alarm (`SPEC013_PUBLIC_CORPUS`, `_MULTI`, `_NO_TIMESTAMP` in `tests/Pest.php`) — 204 tests; `notes/step-38-cover-and-multi-manifest.md` | done 2026-09-21 |
| 39 | The 33 c2pa-rs fixtures as a third corpus (`tests/Fixtures/c2pa-rs/`, 17 c2patool JSONs); SPEC-006 amendment 3 (indefinite lengths, bounded), SPEC-007 amendment 4 (`null` claim_generator_info), SPEC-013 amendment 7 + AC12 (`cawg.identity` refused until validated; `SPEC013_RS_*` in `tests/Pest.php`); SPEC-010 amendment 4, SPEC-013 amendment 6 (the CBOR-fault example) — 206 tests; `notes/step-39-c2pa-rs-corpus.md` | done 2026-09-21 |
| 31a | `x5chain-leaf-only` (protected header shortened, pad grown, store length kept) and two settings variants made (`bin/make-trust-variants.php`) and measured through c2patool — `notes/step-31-trust-variants.md` | done 2026-09-21 |
| 31b | The ten SPEC-014 tests (`tests/Unit/Trust/ChainCheckTest.php`), seen red: eight on the missing `Trust\TrustSettings`, AC9 on the missing enum case, AC10 on the enum holding twenty-one codes; `SPEC013_CORPUS` moved to `tests/Pest.php`, shared | done 2026-09-21 |

## M6, step by step

| Step | What | Status |
|---|---|---|
| 40 | The timestamp measured: five TSAs' tokens in bytes (`sigTst` = `TimeStampResp`, `sigTst2` = `TimeStampToken`), the countersigned bytes proven by hand on four tokens (`CounterSignature` Sig_structure: v1 over the claim, v2 over the signature bstr), c2patool's `timeStamp.*` over 41 oracle JSONs (informational, never `Invalid`), c2pa-rs's nine checks in order, `ext-openssl`'s reach (no `TSTInfo`; CMS signature by `openssl_verify` over re-tagged `signedAttrs`, no temp file; `openssl_cms_verify` unusable for a TSA chain), and one unresolved point (`timeStamp.trusted` without anchors) — `notes/step-40-timestamp-measured.md` | done 2026-09-22 |
| ADR-0004 | RFC 3161 on a small own DER reader (`src/Asn1/`, ten tags, definite lengths, bounded), the CMS signature by `openssl_verify` over re-tagged `signedAttrs` (no temp file), the TSA judged with M5's profile and chain — trusted only through configured anchors (departs from c2patool's unexplained `trusted`), `timeStamp.*` informational, the time as the one effect; `phpseclib` rejected as measured, kept as fallback | accepted 2026-09-22 |
| SPEC-016 | The DER reader (`src/Asn1/`: ten tags, definite and minimal lengths, `maxDepth`/`maxElements`/`maxBytes`, every fault with its offset) and the timestamp token as data (`TimeStampResp` or bare `ContentInfo` → `SignedData` with exactly one `SignerInfo` → `TSTInfo`; `signedAttributesForVerification()` re-tagged for SPEC-017); ten criteria measured against `openssl asn1parse` / `ts -reply -text` on five tokens, `CA_ct.jpg`'s minute 63 as the corpus's malformed case; no new fixtures — the tokens come out of the corpus files through `CoseSign1` | implemented 2026-09-22 (step 41b) |
| 41a | The 64 SPEC-016 tests (`tests/Unit/Asn1/DerReaderTest.php`, `tests/Unit/Timestamp/TimeStampTokenTest.php`), seen red on the three missing entry points; the twelve AC6/AC7 patches proven with `openssl asn1parse`; amendment 1 (38 files, `maxDepth` 20 / `maxElements` 512, Truepic's attributes); the `Asn1` layer in Deptrac — `notes/step-41-timestamp-tests.md` | done 2026-09-22 |
| 41b | `src/Asn1/{TagClass,Der,DerReader,Asn1Exception}`, `src/Timestamp/{TimestampHeader,TimeStampToken,SignedData,SignerInfo,TstInfo,TstAccuracy,TimestampException}`; `Bytes::hexToDecimal` moved to Support; 64 red → green, 270 tests, `composer check` green; SPEC-016 amendment 2 (`signerCertificate()` by sid — Truepic's root-first order; two-certificate ECDSA tokens in `ocsp*.jpg`; five test literals corrected) and → `implemented` with Traceability | done 2026-09-22 |
| SPEC-017 | The timestamp check: c2pa-rs's seven steps in order (parse, signer by sid, messageDigest, the CMS signature — RSA PKCS#1, ECDSA, PSS via `Cose\RsaPss` — validity at genTime, the imprint against the `CounterSignature` bytes, the TSA's profile with `timeStamping` alone and chain through M5); the six `timeStamp.*` codes, all informational; `signature_info.time`; the trusted time handed to SPEC-015; trust only through configured anchors (Truepic root and DigiCert's cross-certificate as public fixture anchors); the `_NO_TIMESTAMP` alarm exceptions replaced by one named `_TSA_NOT_CONFIGURED` | implemented 2026-09-22 (step 42b) |
| 42a | The 15 SPEC-017 tests (`tests/Unit/Timestamp/TimestampCheckTest.php`), seen red on `TimestampCheck`, the enum cases and `signature_info.time`; two public TSA anchors cut from the tokens (`truepic-root`, `digicert-trusted-root-g4`, plus `full-plus-digicert-g4`) with six c2patool JSONs under them (`tests/Fixtures/c2patool/timestamp/`: Truepic → `Trusted`, `exp-test1` un-expires); the SPEC-016 helpers moved to `tests/Support/{Corpus,DerPatch}.php`; the alarm lists renamed `_TSA_NOT_CONFIGURED` — `notes/step-42-timestamp-check-tests.md` | done 2026-09-22 |
| 42b | `Timestamp\{TimestampCheck,TimestampResult}` (seven steps, three signature families incl. ECDSA, the TSA chain ordered from the token, trust via M5's seams), six `StatusCode` cases, `checkLeaf(…, $ekus, $reason)`, `ChainCheck::checkCertificates()`, the Verifier wiring and `signature_info.time`; 15 red → green, 285 in all, `composer check` green; SPEC-010 #5, SPEC-013 #8, SPEC-014 #2, SPEC-015 #4, SPEC-017 #1 and → `implemented`. **M6 complete** | done 2026-09-22 |
| 43 | More fixtures: nine repositories surveyed, five files from four new writers (OpenAI, Amazon Bedrock ES384, TrustNXT's `c2pa-ts`, Adobe Photoshop 2026 with a remote manifest, a CAWG ICA file) as `tests/Fixtures/writers/` with c2patool's JSON; three findings — negative nonces refused (two tokens), fractional `genTime` dropped (two), a declared remote manifest passed over in silence — `notes/step-43-more-fixtures.md` | done 2026-09-22 |
| 44 | The fixes: signed nonces (`Der::integer(signed:)`), `genTime` fractions kept and rendered as c2patool's `time`, the DER-canonical (sorted) SET as the CMS signature's input — a correctness hole five well-behaved TSAs had hidden, found through `c2pa-ts` — raw R‖S ECDSA accepted by a DER-safe rule, and `remote_manifest` in the report; SPEC-016 #3, SPEC-017 #2–3, SPEC-013 #9 with AC11/AC11/AC13–14 red → green; the writers corpus the fourth drift alarm; 293 tests — `notes/step-44-writers-fixes.md` | done 2026-09-22 |
| 45 | Light fuzzing: `bin/fuzz.php` (eight mutation kinds, seeded and replayable) — 70 870 mutated files over 101 corpus files, 0 exceptions escaped, 312 `Valid` survivors all confirmed `Valid` by c2patool (COSE pad, the timestamp token, uncovered segment headers); peak 36 MiB, slowest run 0.03 s — `notes/step-45-fuzz.md` | done 2026-09-22 |
| 46 | More writers via Wikimedia Commons (originals kept, licences stated): a Pixel 10 camera file (public domain; three-month signer, Google's own TSA, `c2pa.hash.data.part`/`multi-asset` beside the data hash) and a Lightroom Classic file (CC BY-SA 4.0, Adobe production certificate) into the writers corpus; the two Google intermediates as anchors make the Pixel file `Trusted` as c2patool (SPEC-017 AC12); Leica/Sony/Samsung uploads all stripped; **a hole found by reasoning**: a signed manifest with no hard binding would be `Valid` (step 47) — `notes/step-46-more-writers.md` | done 2026-09-22 |
| 47 | **The wrong `Valid` closed**: `bin/make-no-hard-binding-variant.php` (the hard-binding assertion removed, the claim re-signed with throw-away keys) — `Valid` here, `Trusted` with its root as anchor, refused by c2patool; SPEC-013 AC15 red on the verdict, then the data-hash gate turned around (runs unless `hash.data` is declared *and* its hashed URI mismatches) — `claim.hardBindings.missing`, `Invalid`; SPEC-013 amendment 10; `claim-alg-sha1` now equals c2patool's set; 295 tests — `notes/step-47-no-hard-binding.md` | done 2026-09-22 |
| 48 | The absence audit: every gate inventoried ("and when Y is not there?"), four *signed* variants (`bin/make-absence-variants.php`, `tests/Fixtures/absence/`: no actions, no thumbnail, `hash.data` gathered, created empty) measured against c2patool with and without the throw-away root — one more wrong `Valid`: a v2 manifest without an actions assertion is `Invalid` at c2patool (`assertion.action.malformed`, "first action must be created or opened") and `Valid`/`Trusted` here; the other three agree or both refuse — `notes/step-48-absence-audit.md`; SPEC-018 proposed | done 2026-09-22 |
| SPEC-018 | The actions assertion: for a claim v2, the first actions assertion (created list first, then gathered) must exist with a non-empty `actions` list opening with `c2pa.created`/`c2pa.opened`, every actions assertion well-formed → `assertion.action.malformed` on the manifest's / the assertion's url as c2patool; claim v1 at most one; the content family (2.b–2.f) named out of scope until M7; `ActionsCheck` between `hashedUris` and `dataHash`, unvouched assertions not read; measured: changes no corpus verdict, refuses the audit's file | implemented 2026-09-22 (step 49b) |
| 49a | The six SPEC-018 tests (`tests/Unit/Manifest/ActionsCheckTest.php`), seen red — the first red line the verdict itself (`no-actions.png` `Valid`); two more signed variants (`actions-first-edited`, `actions-empty`) measured: c2patool refuses both, the second with exit 1 and no report; SPEC-018 amendment 1 (the empty-list url, the `checkAssertions()` seam) — `notes/step-49-actions-check.md` | done 2026-09-22 |
| 49b | `Manifest\ActionsCheck` (three rules, two seams), `assertion.action.malformed`, the Verifier's `actions` step with the unread gate; 6 red → green, 301 tests; SPEC-018 amendment 2 (c2patool's bare-label url for this rule, copied) and → `implemented`; the second wrong `Valid` closed | done 2026-09-22 |
| 50 | Release hygiene: `README.md` rewritten (status, use, verdicts, trust settings, the two findings), `SECURITY.md` (scope, reporting, the findings named), `CONTRIBUTING.md` (the way of working as rules), `CHANGELOG.md` (per milestone, unreleased), `docs/comparison.md` (less / equal / different, measured, by name) — `notes/step-50-release-hygiene.md` | done 2026-09-22 |
| 51 | Every amendment on one page for the maintainer's confirmation — 51 amendments over 17 specs sorted by weight (A: rules of the verifier, B: report/API, C: literals), those already decided marked; SPEC-006's floats amendment, left as a stub in step 37, written out and the list renumbered — `notes/step-51-amendments-for-confirmation.md` | confirmed by Maurice van Loon 2026-09-22, all three groups |

## After M6: the command line, then M7

Maurice's decision (2026-09-22): the repository stays private until M7
(ingredient manifests) is done as well; the command line comes first
because every later measurement is easier with it.

| Step | What | Status |
|---|---|---|
| SPEC-019 | The command line: `bin/c2pa-verify <file> [--settings <path>]` — `VerificationReport::toJson()` plus one newline on stdout, `Error: …` on stderr, exit 0 (Trusted/Valid) / 1 (Invalid, report still printed) / 2 (no report: usage, unreadable file, unreadable or invalid settings); a thin `Cli\Command::run()` around the public API, the executable a shim; c2patool's exit status measured and departed from in two rows (Invalid exits 0 there; a missing settings file is ignored there) — both fail-open, so not copied | implemented 2026-09-22 (step 52b) |
| 52a | The twelve SPEC-019 tests (`tests/Unit/Cli/CommandTest.php`), seen red (`Class … Cli\Command not found` ×12): stdout byte-equal to the API on every corpus file, exit 0/1/2, the two departures from c2patool asserted (AC3, AC7), the directory case measured (`fopen` succeeds on a directory; the command refuses it) — `notes/step-52-command-line.md` | done 2026-09-22 |
| 52b | `Cli\Command` (four steps, no `@`, no temp file), the `bin/c2pa-verify` shim, Composer `bin`, the `Cli` Deptrac layer; 12 red → green; **AC11 found `toJson()` throwing `JsonException` on the OpenAI file** — `claim_generator_info` rendered raw (a 32-byte icon hash), fixed as SPEC-007 amendment 5 with its own red-then-green test; 314 tests; README, comparison, CHANGELOG | done 2026-09-22 |

## M7, step by step

| Step | What | Status |
|---|---|---|
| 53 | Ingredients measured: C2PA 2.4 §15.11/§15.12/§11.2.3/§18.16 read; c2pa-rs `verify_store` → `ingredient_checks` → `from_store` → `validation_state` read and named; the 18 multi-manifest corpus files walked with the own parsers — active = last box everywhere, 12 files hash the ingredient manifest by the **legacy claim-CBOR hash**, 6 by the box payload, no redactions anywhere, one `c2um`; c2patool drops failures the ingredient assertion *attested* (`CIE-sig-CA`: a broken ingredient signature, `Trusted`) — proposals: SPEC-020 (assertion + graph), SPEC-021 (validation, deltas, state), SPEC-022 (update manifests); attested failures copied with the CAI-12751 guard; redactions refused until a fixture exists — `notes/step-53-ingredients-measured.md` | done 2026-09-22 |
| SPEC-020 | The ingredient assertion (v1/v2/v3, the malformed rules of §15.11.3.2 and c2pa-rs's required fields) and the manifest graph (the walk from the active manifest, references by label, `missing`, `unreferenced`, redactions collected, bounds 32/256, cycles); `ingredient.unknownProvenance`, `ingredient.manifest.missing`, `assertion.ingredient.malformed`; statuses scoped by ingredient URI → `validation_results.ingredientDeltas`; `ingredients` rendered as c2patool; no verdict changes — the amendment-5 refusal stays until SPEC-021 | implemented 2026-09-22 (step 54b) |
| 54a | The thirteen SPEC-020 tests seen red (10 on missing classes, 3 substantive: no `ingredientDeltas`, no `ingredients` rendered, no scope); `bin/make-ingredient-variants.php` — eleven signed variants with one ingredient assertion added (a CBOR encoder in the script), c2patool measured: six hard exits, `manifest-no-results` `assertion.ingredient.malformed`, two rules c2pa-rs lacks (`digitalSourceType` beside `activeManifest`, a text hash) — `notes/step-54-ingredient-assertion-and-graph.md` | done 2026-09-22 |
| 54b | `Manifest\{Relationship,IngredientAssertion,ManifestGraph}`, three `StatusCode` cases, the scope on `ValidationStatus`, `ingredientDeltas` in the report, `ingredients` in the rendering, the graph in the Verifier; 13 red → green, 327 tests; SPEC-020 amendments 1–3 (the two remote files carry no store; `alg: sha256`, the unreferenced `E-clm` manifest, `manifest_data` and the thumbnail's home; the delta order as a subsequence) and → `implemented`; no corpus verdict changed | done 2026-09-22 |
| 55 | What validating the ingredient manifests would say, measured before the spec: six references hash the manifest box, eleven the claim's CBOR (legacy, silent at c2patool); the existing checks on every referenced manifest plus c2pa-rs's rule that a fault the ingredient assertion *recorded* is dropped give c2patool's delta failures on 14 of 17 files and its state on 16 of 18 (the two: `signingCredential.expired`, the named TSA leniency); a cross-corpus false alarm chased down with `openssl verify` — `notes/step-55-ingredient-validation-measured.md` | done 2026-09-22 |
| SPEC-021 | Validating the ingredient manifests: the box hash (`ingredient.manifest.validated`/`.mismatch`, legacy silent), then the manifest itself — signature, profile, chain, timestamp, hashed URIs, actions, never the data hash — scoped to the naming assertion; what the assertion recorded is dropped (with the CAI-12751 guard: never a status about the active manifest); redactions refused until a fixture; lifts SPEC-013 amendment 5 | implemented 2026-09-22 (step 56b) |
| 56a | The nine SPEC-021 tests seen red (7 failed, 2 already true: the redaction refusal and the data hash); `bin/make-ingredient-manifest-variants.php` — three signed variants (a broken ingredient signature *with* a matching box hash, an assertion that records faults of the active manifest, a redacting claim) measured against c2patool; the JPEG APP11 writer asserts a byte-exact round trip before it writes — `notes/step-56-ingredient-validation.md` | done 2026-09-22 |
| 56b | `Verifier\IngredientManifestCheck` (box hash + legacy, the manifest's own checks without the data hash, `recorded()`/`drop()` with the active-manifest guard), two `StatusCode` cases, `ingredients` in `checks_performed`, SPEC-013 amendment 5 lifted; 7 red → 9 green, 336 tests, 0 fuzz faults; five older criteria changed with it (SPEC-021 amendment 3) and two test literals corrected (1–2); seventeen multi-manifest files measured instead of refused | done 2026-09-22 |
| SPEC-022 | Update manifests (`c2um`): the box read (SPEC-005 AC13 amended for `c2um` only), §11.2.3's four rules with `manifest.update.invalid` / `.wrongParents` and §15.11's `manifest.multipleParents`, the binding found through the `parentOf` chain (§15.12) and its stale exclusion adjusted to the store's current range (§15.12.1.1 — measured: 18874 against 43607), an empty `claim_generator_info` counted as absent; closes the last `_MULTI` file | implemented 2026-09-22 (step 57b) |
| 57a | The nine SPEC-022 tests seen red, each for its own reason; `bin/make-update-manifest-variants.php` — six variants (a byte outside the store, a disallowed action, a hash assertion in an update manifest, `parentOf` → `inputTo`, a chain with no standard parent, two parents on the PNG fixture) measured against c2patool, three of which it refuses without JSON; **a leniency found**: two `parentOf` ingredients is `Trusted` here and `manifest.multipleParents` there — `notes/step-57-update-manifests.md` | done 2026-09-22 |
| 57b | `c2um` read (`c2tm` refused in its place), `Manifest::$isUpdateManifest`, `UpdateManifestCheck` (§11.2.3's rules, `manifest.multipleParents`, the binding through the `parentOf` chain), the §15.12.1.1 exclusion adjustment with the cover rule over it, three `StatusCode` cases; 9 red → green, 345 tests, 0 fuzz faults. Four findings: c2pa-rs cannot apply its own hash-in-update rule (we are stricter, named), SPEC-018's update exemption existed only on paper, an empty `claim_generator_info` is kept, and the drop set is store-wide (SPEC-021 amendment 4). **M7 complete** | done 2026-09-22 |
| 58 | The amendments since step 51 on one page for confirmation — seventeen over eight specs, sorted by weight (A: six rules of the verifier, among them the lifted multi-manifest refusal and the one place this verifier is stricter than c2patool on a rule c2patool has but cannot reach; B: two; C: nine) — `notes/step-58-amendments-for-confirmation.md` | confirmed by Maurice van Loon 2026-09-22, all three groups |
| 59 | The coverage matrix: measured first what the fixtures cover (Es512, Ps384, Ps512, Ed25519 in **no file**, sha512 in none, WebP in one — our own), then filled it — `bin/make-matrix-fixtures.php` signs the three unsigned fixtures with all seven algorithms in all three formats with c2pa-rs's test certificates (keys deleted at the end of the run), plus two files whose data hash is sha384/sha512, made by surgery because c2patool always writes sha256; 23 files, 376 KB, each `Trusted` with the roots and `Valid` without; the fifth drift alarm (SPEC-013 AC16–AC18, amendment 12); 348 tests — and within minutes it found a bug: **every Ed25519-signed file was `Invalid` on PHP 8.3** (the key kind was read from PHP's type, which names Ed25519 only from 8.4) — SPEC-015 amendment 5, 349 tests — `notes/step-59-coverage-matrix.md` | done 2026-09-22 |
| 60 | The absence audit for M7: the inventory of every gate SPEC-020/021/022 added, and signed stores for the three that had no file — built on **this project's first self-made two-manifest store** (the fixture's manifest copied, relabelled, re-signed, named by a v3 ingredient assertion). No new hole: an ingredient manifest without actions costs the file its verdict (as c2patool), and the two that stay `Trusted` — a manifest nobody references, an assertion without `claimSignature` — are the specification's own answer, now named in `docs/comparison.md`; 353 tests — `notes/step-60-m7-absence-audit.md` | done 2026-09-22 |
| 61 | A second, independent oracle: `richardwooding/c2pa` v0.22.0 (pure Go) run over 257 files in a container, with matched anchors — **no file where another implementation found a fault this verifier missed**; one false `Invalid` in the Go verifier (`update_manifest.jpg`: it does not apply §15.12.1.1's exclusion adjustment, decided by hashing the file both ways), thirteen refusals of ours it accepts (strictness already named), two profile rules it refuses that this verifier accepts by the maintainer's step-33 decision; `tools/go-oracle/` — `notes/step-61-second-oracle.md` | done 2026-09-22 |

## After M0

M1 opens with SPEC-001 (JPEG APP11 → manifest store bytes) as a draft. It
is first because it is measurable with a hash and no cryptography, and
because JPEG is the hardest of the three containers; PNG (SPEC-002) and WebP
(SPEC-003) follow. A signed JPEG fixture is produced when SPEC-001 starts,
with the signing command and tool version recorded.

## M1, step by step

| Step | What | Status |
|---|---|---|
| 02 | Signed JPEG fixture (c2patool 0.27.22, test certs), the segment layout measured, c2patool's behaviour on gaps and swapped pieces measured — `notes/step-02-jpeg-fixture.md` | done |
| SPEC-001 | JPEG APP11 → manifest store bytes: draft → approval → red tests → implementation | implemented 2026-09-19: 14 tests red → green, `composer check` exit 0; AC7 kept stricter than c2patool; amendment 1 the same day (AC14, AC15: truncation before the first piece, markers without a length field) — step 03 |
| 04 | Signed PNG fixture (c2patool 0.27.22, test certs), the chunk layout measured, ten variants through c2patool, c2pa-rs `png_io.rs` read — `notes/step-04-png-fixture.md` | done |
| SPEC-002 | PNG `caBX` → bytes: draft → approval → red tests → implementation | implemented 2026-09-20: 15 tests red → green, `composer check` exit 0; AC6/AC7/AC11 stricter than c2patool (step 05) |
| 06 | Signed WebP fixture (c2patool 0.27.22, test certs), the RIFF layout and pad byte measured, fifteen variants through c2patool, c2pa-rs `riff_io.rs` read — `notes/step-06-webp-fixture.md` | done |
| SPEC-003 | WebP RIFF `C2PA` → bytes: draft → approval → red tests → implementation | implemented 2026-09-20: 21 tests red → green, `composer check` exit 0, 63 tests in all; AC4/5/7/9/10/11/12 stricter than c2patool (step 07). **M1 complete** |
