# Step 42 — The SPEC-017 tests seen red (42a), then the timestamp check until they are green (42b): M6 complete

*2026-09-22.* SPEC-017 approved; this is the tests-first half of step
42. One test file, `tests/Unit/Timestamp/TimestampCheckTest.php` (AC1–AC10,
15 tests), two public anchors with settings files, six c2patool JSONs
under those settings, the SPEC-016 test helpers moved into
`tests/Support/`, and the drift-alarm lists renamed. Nothing under `src/`.

## Red, for the right reason

```
$ vendor/bin/pest --group=SPEC-017
Tests:    15 failed (13 assertions)
$ vendor/bin/pest
Tests:    15 failed, 270 passed (2902 assertions)
```

Ten tests fail on `Timestamp\TimestampCheck` not found, two on the
missing enum cases (`StatusCode::TimeStampTrusted`, `::TimeStampMismatch`),
AC9 on `signature_info` lacking `time`, AC8 on `checksPerformed` and
AC10 on `exp-test1.png` staying `expired` under the settings that carry
the DigiCert cross-certificate — the one assertion that fails on
*behaviour* rather than absence, and exactly the behaviour SPEC-017
adds. The 13 assertions that passed are preconditions: AC1's count of
files (below), the OID and tag checks before the patches, and AC10's
renamed constants. Pint passes; PHPStan reports only the missing
symbols.

## The anchors, measured before the tests (AC6, AC9, AC10)

Two public certificates cut out of the corpus tokens with
`openssl ts -reply -token_out | openssl pkcs7 -print_certs`:

- `tests/Fixtures/trust/truepic-root.pem` — `CN=RootCA, OU=Lens,
  O=Truepic`, self-signed, 2021-12-09 to 2036-12-05: the root of the
  Truepic TSA and, as it turns out, of the Truepic camera signers too.
- `tests/Fixtures/trust/digicert-trusted-root-g4.pem` — the
  `DigiCert Trusted Root G4` cross-certificate issued by `DigiCert
  Assured ID Root CA` (2022-08-01 to 2031-11-09), the third certificate
  of every DigiCert token in the corpus.

Each as the only anchor in a settings file (`store.cfg` as
`trust_config`), plus `full-plus-digicert-g4.settings.json` — the C2PA
test anchors and the cross-certificate together. c2patool 0.27.22
under them (`tests/Fixtures/c2patool/timestamp/`, README alongside):

| file | settings | c2patool |
|---|---|---|
| Truepic ×3 | `truepic-root` | **`Trusted`** — `timeStamp.validated`, `timeStamp.trusted`, `signingCredential.trusted`, no `expired`: the one-day signer is judged at the stamp, and the signer chain reaches the same root (the open question of SPEC-017 AC6, answered: `Trusted`, not `Valid`) |
| `C.jpg`, `CACA.jpg` | `digicert-trusted-root-g4` | `Valid` — `timeStamp.trusted`, signer `untrusted` (the C2PA test signer reaches no DigiCert anchor); for `CACA.jpg` the first run where c2patool trusts the 2025 responder |
| `C.jpg` | `truepic-root` | `Valid`, `timeStamp.trusted` still — c2patool's `trusted` for the DigiCert 2023 TSA does not depend on the anchor (step 40 §5, once more) |
| `exp-test1.png` | `full-plus-digicert-g4` | `Invalid` (a self-signed ingredient manifest); the active manifest `validated`, `trusted`, signer `untrusted`, **no `expired`** — `cai-prod` (2022-03-01 to 2023-03-01) judged at the stamp, 2022-04-20 |

## What the tests pin

- **AC1**: the files whose c2patool JSON carries `timeStamp.validated`
  on the active manifest *and* whose store this verifier reads: **35**
  (36 JSONs say `validated`; `cloud.jpg`'s manifest is remote). Each
  must `validate` through `TimestampCheck::check()` with c2patool's
  url and `signature_info.time` byte for byte, and — on the
  single-manifest files — reach the report with `timeStamp.validated`
  first and `timestamp` heading `checks_performed`, the verdict
  unchanged except the Truepic three.
- **AC2**: `E-sig-CA` (both corpora) `mismatch`, `CA_ct` `malformed` with
  SPEC-016's message; verdicts as c2patool's.
- **AC3**: three algorithms — RSA PKCS#1 sha256 (`C.jpg`),
  `sha384WithRSAEncryption` (Truepic), ECDSA P-256 (`ocsp.jpg`, the Adobe
  TSA) — each once whole (`validated`, `untrusted`) and once with one
  bit of the CMS signature flipped (`untrusted` alone, naming the
  algorithm and "signature"); and `rsaEncryption` patched to
  `md2WithRSAEncryption` by its last OID byte.
- **AC4**: through `judge()`: one `messageDigest` byte → `mismatch`
  naming `messageDigest`; the `sid` serial's last byte → `malformed`
  "no certificate"; the `TimeStampToken` rebuilt with a 2010 `genTime`
  → `outsideValidity` naming 2010-01-01, 2023-07-14 and 2034-10-13.
- **AC5**: `countersignedBytes()` on four manifests equals the four
  imprints of step 40 (and `TstInfo::$hashedMessage` byte for byte);
  `C.jpg`'s token against the `sigTst2`-style bytes → `mismatch`.
- **AC6**: the Truepic three under `truepic-root`: `validated`,
  `trusted` (naming the TSA), no `expired`, state and failures equal to
  c2patool's under the same file; without: `untrusted` "no trust anchors
  configured" and `expired` "now … not trusted". `C.jpg` under the
  cross-certificate: `trusted`; state `Valid` as c2patool's.
- **AC7**: `tsaSettings(null)` = no anchors, no allowed list,
  `trust_config` `[timeStamping]`, `verify_trust` true;
  `checkLeaf(..., ekus: [timeStamping])` passes the DigiCert TSA leaf and
  refuses our own `emailProtection` leaf naming EKU — which the ordinary
  list still accepts (SPEC-015 unchanged).
- **AC8**: four files without a header → `present` false, nothing, no
  `timestamp` in `checks_performed`, no `time`; Nikon `expired` "now …
  no timestamp"; `checkHeader()` (the seam the spec left open — a
  `TimestampHeader` built by hand) with one token, and with the same
  token twice → "1 of 2 tokens".
- **AC9**: `C.jpg` under the cross-certificate: successes start
  `validated`, `trusted`, `claimSignature.validated`; `signature_info`
  equals c2patool's block byte for byte with `time` as the fifth key;
  `checks_performed` `['timestamp', 'signature', 'certificate', 'trust',
  'hashedUris', 'dataHash']`; `fixture-signed.jpg` unchanged.
- **AC10**: `SPEC013_*_NO_TIMESTAMP` gone; `SPEC013_PUBLIC_TSA_NOT_CONFIGURED`
  the Truepic three, `SPEC013_RS_TSA_NOT_CONFIGURED` = `ocsp`,
  `ocsp_with_assertion`, `exp-test1` (expired at now under `full`, whose
  anchors no DigiCert TSA reaches); `exp-test1.png` un-expires under
  `full-plus-digicert-g4` and stays `Invalid`.

## Bookkeeping

- `tests/Support/Corpus.php` (`fixtures()`, `manifestStore()`, `cose()`,
  `headerValue()`) and `tests/Support/DerPatch.php` (`element()`,
  `length()`, `splice()`, `signerInfoSet()`, `signedAttrs()`,
  `attribute()`) hold what the SPEC-016 test file had as functions, so
  that both test files patch tokens the same way; SPEC-016's
  Traceability names them. A first, greedy extraction of those functions
  swallowed the SPEC-016 tests between them — caught by the SPEC-016
  group going from 64 to 31 tests, restored from git, redone one function
  at a time; the group is 64 green again.
- `VerifierTest` AC11/AC12 use the renamed lists; the AC12 comment "exp-test1
  is expired at c2patool too" was wrong (step 40's note had the same
  error, corrected in step 41's commit) and now says why the file is
  expired here.
- The SPEC-013 group stays at 12 passed; the whole suite 270 passed +
  15 red.

---

# 42b — Green: the timestamp check, and M6 closed

*2026-09-22, the same day.* `composer check` green: 18 specs, Pint,
PHPStan max 0 errors, Deptrac 0 violations, `Tests: 285 passed (3377
assertions)` — the 15 red ones green and the 270 still green, after the
adjustments the amendments name.

## What was written

- `Timestamp\TimestampCheck` — `check()` (the header off the manifest's
  COSE_Sign1), `checkHeader()` (the first token; "1 of N tokens judged"
  in the `validated` explanation when there are more), `judge()` (steps
  2–7 in c2pa-rs's order, one status per fault, then `validated` and the
  trust outcome), `countersignedBytes()` (the `CounterSignature`
  Sig_structure; payload by header name), `tsaSettings()` (the operator's
  anchors and allowed list, `trust_config` = `timeStamping`). The CMS
  signature by `openssl_verify` for `rsaEncryption` and the
  `shaNNNWithRSAEncryption` spellings and for `ecdsa-with-SHANNN` (the
  DER signature as it is), by `Cose\RsaPss` for RSASSA-PSS on an
  ordinary RSA key (no corpus token: reasoned). The TSA chain ordered
  from the token by issuer → subject links from the signer, so Truepic's
  root-first order walks like DigiCert's signer-first one; then M5's
  `checkLeaf(…, ekus: [timeStamping])` and `ChainCheck::checkCertificates()`,
  their `signingCredential.*` renamed `timeStamp.trusted` / `.untrusted`.
- `Timestamp\TimestampResult` — `present`, `statuses`, `time`,
  `trusted`, and `trustedTime()`: non-null only when validated *and*
  trusted, the one thing that reaches the verdict.
- `StatusCode`: six cases; `isSuccess()` for `validated` and `trusted`,
  `isInformational()` for the other four. `ValidationResult` unchanged.
- `CertificateProfileCheck`: `$ekus` override, `$reason` for the
  `.expired` message; `ChainCheck::checkCertificates()`.
- `Verifier`: the timestamp check first, `timestamp` heading
  `checks_performed` when a header is present, the trusted time and a
  reason to the profile check, `time` in `signature_info` when the token
  validated.

## Measured on the corpora, front door

| file | settings | this verifier | c2patool |
|---|---|---|---|
| `truepic-20230212-camera.jpg` | `truepic-root` | `Trusted`; `validated`, `trusted`; `time` 2023-02-12T18:44:26+00:00; no `expired` | `Trusted`, the same codes |
| the same | none | `Invalid`; `validated`, `untrusted`; `expired` "checked at now (the timestamp's TSA is not trusted)" | `Valid` (`trusted` without an anchor — the divergence ADR-0004 names) |
| `C.jpg` | none | `Valid`; `validated`, `untrusted`; `time` 2024-08-06T21:53:37+00:00 | `Trusted` under `full`; `time` equal |
| `nikon-20221019-building.jpeg` | none | `Invalid`; no timestamp entries; `expired` "checked at now (no timestamp)" | `Invalid`, `expired` |
| `exp-test1.png` | `full-plus-digicert-g4` | `Invalid` (six manifests); no `expired` | `Invalid`; no `expired` |

AC1 over the 35 validated corpus tokens: every `signature_info.time`
equals c2patool's string byte for byte, every `timeStamp.validated` sits
first with c2patool's url.

## What the first green run corrected

Six of 15 failed with the code in place; all six were test literals
(SPEC-017 amendment 1): the Nikon file is `.jpeg`, the signed fixtures
live at the fixtures root, the front-door part of AC1 must run under
`full` as SPEC-013 does (the public oracle was made that way — Adobe is
`Trusted` there), `ChainCheck`'s wording is "no trust anchors are
configured", the EKU fault says `ExtendedKeyUsage` — and one more
`toContain($needle, $message)` slip, Pest's variadic trap for the fourth
time in this project, turned into `in_array` + `toBeTrue($message)`. Then
nine older tests: the enum-count and success/informational loops of
SPEC-010/011/012/015 (skip or count the six), `checks_performed` and the
code order on the Adobe file in SPEC-013/014 (`timestamp` first), SPEC-015
AC7 comparing the whole `signature_info` again, and SPEC-013 AC1's
oracle-presence loop skipping `timeStamp.untrusted` — the one designed
divergence, named there as in ADR-0004.

## M6, done when

"`hasTimestamp` and `timeStamp.*` codes equal c2patool on a timestamped
fixture" — measured wider than that: 35 tokens across five TSAs, two
tampered ones, three anchor settings. Equal everywhere but where
ADR-0004 decided to differ, and that difference is informational, named
in the alarms, and removed by configuring the anchor. M7 is next:
ingredient manifests, which every `_MULTI` exception is waiting for.

