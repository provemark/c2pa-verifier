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
| M3 | **COSE_Sign1.** Protected header, `x5chain`, Sig_structure, verify ES256/ES384/PS256/Ed25519. No trust yet. | `claimSignature.validated` equals c2patool on all fixtures; one altered byte in the claim → `claimSignature.mismatch` |
| M4 | **Hash binding.** `c2pa.hash.data` v1/v2: exclusions, `pad`, streaming hash. Hashed-URI checks on assertions. | one changed pixel byte → `assertion.dataHash.mismatch`; untouched file `Valid` |
| M5 | **Chain and trust.** Chain from `x5chain`, anchor from `trust_anchors`, EKU from `trust_config`, `allowed_list`. `Trusted` vs `Valid`. | verdicts equal `c2patool --settings` with and without the trust file; test cert without trust file → `signingCredential.untrusted` |
| M6 | **RFC 3161.** `sigTst` / `sigTst2` (ASN.1), TSA signature, signing time against certificate validity. | `hasTimestamp` and `timeStamp.*` codes equal c2patool on a timestamped fixture |
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
| ADR-0001 | Amendment proposed: COSE verification written here on `ext-openssl` | awaiting the maintainer |
| SPEC-008 | COSE_Sign1: structure, headers, `x5chain`, the `Sig_structure` | — |
| SPEC-009 | Signature verification per algorithm, key-fits-algorithm | — |
| SPEC-010 | `Report`: `claimSignature.validated` / `.mismatch`, `assertion.json.invalid` | — |

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
