# Step 51 — Every amendment on one page, for the maintainer's confirmation

*2026-09-22.* The spec template allows an approved spec to be amended
when a measurement made before or during its tests-first step shows
the criterion wrong; the amendment is written into the spec at once so
that the tests are never green against a text they contradict. Fifty-one
such amendments exist across seventeen specs (some rows below fold
several numbers of one spec into one line). A reader takes each as a
decision of the maintainer; this page is where that becomes true. One
line per amendment — what, why — sorted by weight; the last column is
for Maurice van Loon's word.

Legend for **weight**: **A** = a rule of the verifier changed (what is
`Valid`, `Invalid`, `Trusted`, or which code); **B** = the report's shape
or the API changed, verdicts unchanged; **C** = a test literal, a count,
a message, a seam, or a Deptrac line — nothing a user of the verifier
could notice.

Amendments Maurice already decided in so many words at the time
(marked *decided*) are listed for completeness, not for a second yes.

## A — rules of the verifier

| spec | # | what | why | decided / confirmed |
|---|---|---|---|---|
| SPEC-006 | 1 | Floats decode (half by hand, single/double by `unpack`); were refused | Nikon and Truepic camera files carry them; c2patool reads them | *decided* step 37 ("floats decode") |
| SPEC-006 | 2 | Indefinite-length CBOR strings, arrays and maps decode, bounded; were refused | nine c2pa-rs claims carry them; the resource concern is met by the bounds | *decided* step 39 ("optie a") |
| SPEC-007 | 4 | A `claim_generator_info` of CBOR `null` counts as absent (v1) — refused as malformed before; a `null` *required* field is still missing | c2pa-rs writes `null` in some v1 claims (`ocsp.jpg`) and reads it as none | |
| SPEC-012 | 5 | The store's exclusion must *cover* the store, not equal it | Truepic excludes the file head too; c2patool takes the range as written | *decided* step 38 ("covers in plaats van equals") |
| SPEC-013 | 5 | A store with more than one manifest is `Invalid` (`general.error`) until M7 | `E-uri-CIE-sig-CA` tampered only in an ingredient was `Trusted` here | *decided* step 38 ("optie 1: fail closed tot M7") |
| SPEC-013 | 7 | A manifest with a `cawg.identity` assertion is `Invalid` (`general.error`) until a spec validates it | `C_with_CAWG_data` was `Trusted` on a credential never examined; c2patool says `Valid` | **for confirmation — the one with substance** |
| SPEC-013 | 9 | (a) A remote manifest declared in XMP is reported as `remote_manifest`, never fetched; (b) the writers corpus is the fourth drift alarm | the Photoshop file was reported as "no Content Credentials"; c2patool fetches | |
| SPEC-013 | 10 | The data-hash check runs unless `c2pa.hash.data` is declared *and* its hashed URI mismatches; absent → `claim.hardBindings.missing` | a signed manifest without a hard binding was `Valid`/`Trusted` — the wrong `Valid` of step 47 | |
| SPEC-014 | 1 | Without settings the trust check runs and says `signingCredential.untrusted`; only `verify_trust: false` keeps it silent | c2patool says `untrusted` on every file without a trust file | |
| SPEC-015 | 1 | The certificate profile runs always, whatever the settings | c2patool reports `expired`/`invalid` with no settings and with `verify_trust: false` | |
| SPEC-015 | 3 | `sha384WithRSAEncryption` and `sha512WithRSAEncryption` accepted as signature algorithms | c2pa-rs has both; Truepic leaves use SHA-384/RSA (a reading error of mine in step 30) | |
| SPEC-015 | 4 (a) | Validity judged at a trusted timestamp's time when there is one; the `.expired` message names the time used and why | C2PA 2.4 §14.6.1 | |
| SPEC-016 | 3 | (a) A negative INTEGER read signed for the RFC 3161 nonce (was `malformed`); (b) `genTime` fractions kept | Amazon and `c2pa-ts` tokens carry negative nonces; OpenAI and `c2pa-ts` fractions; c2patool reads all | |
| SPEC-017 | 2 | `signature_info.time` renders the fraction; the two tokens above now validate | byte-equality with c2patool's `time` | |
| SPEC-017 | 3 | (a) The CMS signature is verified over the DER-*sorted* SET of signed attributes, not the re-tagged bytes as written; (b) raw R‖S ECDSA accepted by a DER-safe rule | RFC 5652 §5.4 / X.690 §11.6; `c2pa-ts` writes unsorted attributes and a raw signature; c2patool accepts | |
| SPEC-018 | 2 | `assertion.action.malformed` on the manifest carries the bare manifest label as url, as c2patool does | drift-alarm equality on code *and* url | |

## B — the report's shape, the API

| spec | # | what | why | confirmed |
|---|---|---|---|---|
| SPEC-001/002/003 | 3/1/1 | `ManifestStoreBytes::$ranges` — the byte ranges the store occupies in the file | SPEC-012's exclusion rule needs them | *approved with SPEC-012* |
| SPEC-007 | 1, 2, 3 | `ManifestException` carries a `StatusCode` and a `$url`; `$assertionStore` public; `Manifest::fromBox` wraps with `at($url, …)` | SPEC-010/011/013 needed them | *approved with those specs* |
| SPEC-008 | 1 | `CoseException` carries a `StatusCode` | SPEC-010 | *approved with SPEC-010* |
| SPEC-009 | 2 | the same for SPEC-009's exceptions (`algorithm.unsupported`, `signingCredential.invalid`) | SPEC-010 | *approved with SPEC-010* |
| SPEC-010 | 2 | `validation_status` holds failures only; informational statuses appear under `activeManifest.informational` | measured on c2patool (`exclusion-extra`) | |
| SPEC-010 | 3 | `validation_status` omitted when empty; `ValidationState::Trusted` and the three-state rule | measured on c2patool; SPEC-014 | *approved with SPEC-014* |
| SPEC-010 | 5 | The six `timeStamp.*` codes: two successes, four informational | c2pa-rs logs every timestamp fault informational | |
| SPEC-013 | 3 | `Verifier::verify($stream, ?TrustSettings)`; `trust` in `checks_performed` | SPEC-014 | *approved with SPEC-014* |
| SPEC-013 | 4 | `checks_performed` = signature, certificate, trust, hashedUris, dataHash without settings; `untrusted` always present without settings | SPEC-014 #1, SPEC-015 #1 | |
| SPEC-013 | 8 | The timestamp check first; `timestamp` heads `checks_performed`; `signature_info.time`; the `_TSA_NOT_CONFIGURED` alarm lists | SPEC-017 | |
| SPEC-014 | 2 | `ChainCheck::checkCertificates()` — the walk on a list of certificates | SPEC-017's TSA chain | |
| SPEC-015 | 4 (b–d) | `checkLeaf(…, $ekus)` override; AC7 compares the whole `signature_info`; the enum count | SPEC-017 | |
| SPEC-016 | 2 | `SignedData::signerCertificate()` — the signer by `sid`, not "the first certificate" | Truepic's token lists its root first | |
| SPEC-018 | 1 (b) | `ActionsCheck::checkAssertions()` seam | no signed v1 fixture can be made from the v2 one | |

## C — literals, counts, messages, layers

| spec | # | what | confirmed |
|---|---|---|---|
| SPEC-001 | 1, 2 | AC14–AC16 added (the end-of-file probe in `skip()`), messages | *approved at the time* |
| SPEC-004 | 1 | `hex()` moved to `Support\Bytes`; the `Support` layer | *approved with SPEC-005* |
| SPEC-009 | 1 | AC8's Ed25519-without-sodium boundary: PHP 8.4/8.5 yes, 8.3 no (measured on CI) | |
| SPEC-010 | 1, 4 | enum growth; the CBOR-fault example after SPEC-006 #2 | |
| SPEC-011 | 1, 2 | enum growth | |
| SPEC-012 | 1 | the url of `claim.hardBindings.missing` / `assertion.multipleHardBindings` is the manifest's | |
| SPEC-012 | 2 | (same fact as SPEC-010 #2, seen from this spec) | |
| SPEC-012 | 3 | AC1's WebP pad clause was impossible (SPEC-003 refuses the pad earlier); reworded | |
| SPEC-012 | 4 | enum growth, `validation_status` absent | |
| SPEC-013 | 1, 2, 6 | the subset-only list corrected by the recorded JSON; a Deptrac line; the CBOR-fault example | |
| SPEC-015 | 2 | AC7 without `time` (lifted again by #4); AC8's `trust` entry | |
| SPEC-016 | 1 | 38 timestamped files (not 37); the bounds pinned (d=18, 311 elements); Truepic's attributes | |
| SPEC-017 | 1 | file names, settings, wordings in the tests; the TSA chain ordered from the token | |
| SPEC-018 | 1 (a, c) | the empty-list fault has no url at c2patool (exit 1); `actions-first-edited` measured | |

## How to confirm

Per group, or per line: "bevestigd" (all of A, B, C), or a question or a
"no" naming the spec and number. A "no" on an A-line is a decision that
reverses a rule, and gets its own step: the spec text back, a test that
shows the reversed rule, and the note. Confirmations are dated on this
page and, for group A, in the spec's amendment line itself.
