# SPEC-013: The Verifier — one call from file to verdict, the checks in the order §15 prescribes

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon                                  |
| Approved   | —                                                 |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

After M4 the repository holds four things that each answer one question
— three extractors (container → store bytes), `ClaimSignatureCheck`,
`HashedUriCheck`, `DataHashCheck` — and nothing that answers *the*
question: here is a file, what is the verdict? Only the tests string the
layers together, and every one of them does so slightly differently.
Two rules that belong to no single check have no home yet: the order
(§15.3's algorithm — locate, parse, signature, assertions, hard binding),
and SPEC-011 decision 1's second half — the data hash is not evaluated
when the claim's hashed URI for `c2pa.hash.data` did not match, because a
hash read from an assertion the claim does not vouch for proves nothing.
A consumer left to compose the checks itself can forget either, and the
forgetting yields a wrong `Valid`.

This spec adds the `Verifier` layer (reserved in `deptrac.yaml` from M0
as the one layer that may see every other): `Verifier::verify($stream)`
returns a `VerificationReport`. It is also the first end-to-end
comparison with the oracle: c2patool gives one `validation_state` and
one `validation_status` per *file*, not per check, and from now on so
does this verifier — measured against every JSON recorded in steps 14
through 26.

What the verdict still does not mean is stated by the report itself.
`Valid` after this spec says: the store is well-formed, the claim is
signed by the leaf certificate, every assertion is the one that was
signed, and the file is the one that was signed. It does not say the
certificate is trusted (M5) or that the signature was made in the
certificate's lifetime (M6). `checks_performed` names what was done, so
that no consumer mistakes this `Valid` for `Trusted`.

## Scope

**In scope**

- `Container\FormatDetector::detect($stream): ?string` — the format from
  the first bytes, nothing else read: `FF D8` → `jpeg`;
  `89 50 4E 47 0D 0A 1A 0A` → `png`; `RIFF` + 4 bytes + `WEBP` → `webp`;
  anything else, or fewer than twelve bytes, → `null`. The stream is
  rewound afterwards.
- `Verifier\Verifier::verify($stream): VerificationReport`, in this order,
  each step's outcome recorded:
  1. **Format** (`FormatDetector`). `null` → a report with `format`
     `unknown`, `hasManifest` false, one `general.error` on
     `self#jumbf=/c2pa` naming the first bytes, `Invalid`. Nothing else is
     read. (c2patool: `Error: Unsupported file type`, measured.)
  2. **Store** (the format's extractor). `null` → a report with
     `hasManifest` false, no statuses, `checksPerformed` `[]`, `Invalid` —
     an unsigned file is not a valid one, and not an error either.
     (c2patool: `Error: No claim found`, measured.) A
     `ContainerException` → `hasManifest` true (something was there),
     one `general.error` on `self#jumbf=/c2pa` with its message.
  3. **Parse** (`JumbfParser`, `ManifestStore::fromTree()`). A
     `JumbfException` or `CborException` → one `general.error` on
     `self#jumbf=/c2pa`; a `ManifestException` → its own `status`, on its
     own `url` where it carries one (amendment below), else
     `self#jumbf=/c2pa`. `Invalid`; no check runs.
  4. **Signature** (`ClaimSignatureCheck`), always; `checksPerformed`
     gains `signature`. The verdict does not stop here: c2patool goes on
     after `claimSignature.mismatch` and reports the hashed URIs and the
     data hash too (measured: `hashed-uri-truncated.json` carries all
     three failures), and every further status is a failure on top of a
     failure — more information, the same verdict.
  5. **Hashed URIs** (`HashedUriCheck`), always; `checksPerformed` gains
     `hashedUris`.
  6. **Data hash** (`DataHashCheck`) — only when step 5 returned
     `assertion.hashedURI.match` for the box labelled `c2pa.hash.data`
     (SPEC-011 decision 1, second half). When it ran, `checksPerformed`
     gains `dataHash`; when it was skipped, it does not, and nothing else
     says so: the absence is the statement.
  7. `ValidationResult::fromStatuses(all statuses in that order, checksPerformed)`.
- `Verifier\VerificationReport`, `final readonly`: `format` (`jpeg`,
  `png`, `webp`, `unknown`), `hasManifest`, `?ManifestStore $store`,
  `ValidationResult $result`. `toArray()` is c2patool's five keys —
  `active_manifest`, `manifests` (from `ManifestStore::toArray()`, or
  `null` and `[]` without a store), `validation_results`,
  `validation_state`, `validation_status` (from
  `ValidationResult::toArray()`) — plus `format`, `has_manifest`,
  `checks_performed`. `toJson()`. The sister library's
  `ManifestStoreParser::fromJson()` must accept it (SPEC-010 AC9's
  premise, now at the front door).
- **SPEC-007 amendment 3**: `ManifestException` gains `public readonly
  ?string $url` (default `null`), set where the box is known:
  `self#jumbf=/c2pa/<label>/c2pa.assertions/<name>` for an assertion's
  faults (`assertion.json.invalid`, the unknown-shape and
  embedded-file faults), `self#jumbf=/c2pa/<label>/c2pa.claim[.v2]` for
  the claim's, `self#jumbf=/c2pa/<label>/c2pa.signature` for the
  signature's. This is what SPEC-010 AC7 deferred to "the Verifier layer
  later": the absolute url for `assertion.json.invalid` where c2patool
  prints a bare label.
- `Verifier`'s constructor takes the four checks and the three
  extractors as optional dependencies with their defaults, so that
  limits (`maxPieces`, `maxExclusions`, `chunkSize`, …) pass through and
  tests can substitute.
- Deptrac: `Verifier` may already see everything; `FormatDetector` lives
  in `Container` (a leaf, `Support` only).

**Out of scope** (each needs its own spec before it may be built)

- Trust (M5: the chain, `signingCredential.trusted`/`.untrusted`,
  `Trusted`), the timestamp (M6), ingredients and manifest chains (M7:
  today only the active manifest is checked, as every check does), BMFF
  (M8). Their codes are the ones AC10 lists as *not yet emitted*.
- Assertion content rules (`assertion.required.missing`,
  `assertion.action.*`) — a later spec.
- A CLI, a PSR-7/PSR-18 façade, the sister-library adapter
  (`ReaderInterface`) — their own specs, on top of this one.
- Formats beyond the three: `FormatDetector` returns `null` for them
  until their container spec adds the magic bytes.
- Reading a path rather than a stream: the caller opens the file; this
  verifier never does I/O it was not handed.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-013')`. At least one criterion MUST
be an error / malformed-input path. Unknown input is an error, never an
assumption: this project fails closed.

The oracle for every criterion is the c2patool 0.27.22 JSON already
recorded under `tests/Fixtures/c2patool/` (steps 14–26) and the exit
messages measured in step 28 (`No claim found`, `Unsupported file
type`). No new variant is needed; two throw-away streams (an empty one,
a short one) are made in the tests.

- **AC1 — the four fixtures, front door: `Valid`, three checks, and the report is c2patool's**
  - Given `fixture-signed.{jpg,png,webp}` and
    `public-testfiles/adobe-20220124-C.jpg`, opened `rb`
  - When `Verifier::verify()` runs
  - Then `format` is `jpeg`/`png`/`webp`/`jpeg`, `hasManifest` true,
    `state` `Valid`, `checksPerformed` `['signature', 'hashedUris',
    'dataHash']`, the statuses are `claimSignature.validated`, then one
    `assertion.hashedURI.match` per claim entry (3/3/3/4), then
    `assertion.dataHash.match`, every url equal to c2patool's for that
    code; c2patool's `validation_state` is `Valid`; and
    `ManifestStoreParser::fromJson(toJson())` gives `validationState()`
    `Valid`, `validationStatusCodes()` `[]`, `isSignatureValid()` true,
    and `activeManifestLabel()` equal to `active_manifest`

- **AC2 — one changed pixel byte, front door: `Invalid` with `assertion.dataHash.mismatch`** *(M4's "done when" through the public call)*
  - Given `binding/pixel-changed.png` and `binding/pixel-changed.jpg`
  - When verified
  - Then `state` `Invalid`, `checksPerformed` all three, the only failure
    `assertion.dataHash.mismatch` with c2patool's url, and
    `validationStatusCodes()` of the sister parser on `toJson()` is
    `['assertion.dataHash.mismatch']` — equal to c2patool's
    `validation_status` codes for `pixel-changed` minus
    `signingCredential.untrusted`

- **AC3 — a broken signature does not stop the verifier**
  - Given `cose/claim-title-changed.png` and `cose/signature-changed.png`
  - When verified
  - Then `state` `Invalid`, `checksPerformed` all three (the hashed URIs
    all match, so the data hash ran and matched), the only failure
    `claimSignature.mismatch`; c2patool's recorded failures minus
    `signingCredential.untrusted` are the same one code

- **AC4 — the data hash is skipped when the claim does not vouch for it** *(SPEC-011 decision 1)*
  - Given `binding/pad-nonzero.png`, `binding/exclusions-overlap.png`,
    `binding/exclusion-past-end.png`, `binding/alg-sha1.png` (the
    assertion changed, the claim not)
  - When verified
  - Then `checksPerformed` is `['signature', 'hashedUris']`, no status
    has a code starting `assertion.dataHash`, the failures are exactly
    `[assertion.hashedURI.mismatch]` on `c2pa.hash.data`, `Invalid`; and
    c2patool's recorded failures for `exclusions-overlap` are a strict
    superset (it evaluates the data hash anyway: `.mismatch`) — the
    divergence is this decision, and the verdict is the same

- **AC5 — no manifest: not valid, not an error**
  - Given `fixture-unsigned.{jpg,png,webp}`
  - When verified
  - Then `format` is right, `hasManifest` false, `store` null, no
    statuses, `checksPerformed` `[]`, `state` `Invalid`; `toArray()` has
    `active_manifest` `null`, `manifests` `[]`, `has_manifest` false;
    and `ManifestStoreParser::fromJson(toJson())` gives `hasManifest()`
    false (c2patool: `Error: No claim found`, exit 1)

- **AC6 — an unknown format is an error, and nothing is read past the magic bytes** *(required: error / malformed input)*
  - Given `jpeg/not-a-jpeg.bin` (`This is not a JP…`), an empty
    `php://memory` stream, and a stream of eight bytes `89 50 4E 47 0D
    0A 1A` + `00` (a PNG signature with its last byte wrong)
  - When verified
  - Then each gives `format` `unknown`, `hasManifest` false, exactly one
    status `general.error` on `self#jumbf=/c2pa` whose explanation shows
    the bytes found (hex, at most twelve), `Invalid`; and the stream's
    position afterwards is 0 (c2patool: `Error: Unsupported file type`)

- **AC7 — the parsers' faults become statuses with their codes and urls**
  - Given `jpeg/truncated-in-piece-2.jpg` (a `ContainerException`),
    `jumbf/lbox-zero.png` (a `JumbfException`),
    `cbor/claim-indefinite-array.png` (a `ManifestException` carrying
    `claim.cbor.invalid`), `claim/second-claim.png` (`claim.multiple`),
    `claim/claim-no-signature.png` (`claim.malformed`),
    `claim/no-manifest.png` (`claim.missing`) and `claim/json-broken.png`
    (`assertion.json.invalid`)
  - When verified
  - Then each gives `hasManifest` true, exactly one status, `Invalid`,
    `checksPerformed` `[]`: the first two `general.error` on
    `self#jumbf=/c2pa` with the exception's message; the others the
    `ManifestException`'s code, on `self#jumbf=/c2pa/<label>/c2pa.claim.v2`
    for the claim faults where the label is known, on
    `self#jumbf=/c2pa` for `claim.missing`, and for `json-broken` on
    `self#jumbf=/c2pa/contentauth:urn:uuid:4d971750-1db4-4492-a87c-5c3e7ed33efc/c2pa.assertions/stds.schema-org.CreativeWork`
    — the code equal to c2patool's `assertion.json.invalid` (SPEC-010
    AC7, closed)

- **AC8 — the report's shape, and the sister parser reads it**
  - Given the reports of AC1 (PNG) and AC2 (PNG)
  - When `toArray()` runs
  - Then its keys are exactly `active_manifest`, `manifests`,
    `validation_results`, `validation_state`, `validation_status`,
    `format`, `has_manifest`, `checks_performed`; the first five have
    the shape of `tests/Fixtures/c2patool/png.json` (same keys under
    `manifests.<label>` as `ManifestStore::toArray()` gives, same three
    lists under `validation_results.activeManifest`); and
    `ManifestStoreParser::fromJson(toJson())` returns, for the PNG, the
    same `softwareAgents()`, `digitalSourceTypes()`, `isAiGenerated()`,
    `validationState()` and `activeManifestLabel()` as it returns for
    `png.json` itself — and `isTrusted()` false on both

- **AC9 — end to end, the file is streamed**
  - Given the 48 MiB temporary file of SPEC-012 AC9 (the PNG fixture
    plus 48 × 1 MiB), and `memory_get_peak_usage()` read before the call
  - When verified with the default limits
  - Then `state` `Invalid` with `assertion.dataHash.mismatch` as the only
    failure, `checksPerformed` all three, and the peak grew by less than
    4 MiB

- **AC10 — the drift alarm: every recorded c2patool JSON, state and failures**
  - Given every file under `tests/Fixtures/c2patool/` with a JSON (the
    four fixtures and the eighteen variants), each with its carrier
    (`fixture-signed.*`, `public-testfiles/…`, `binding/*.png`,
    `cose/*.png`, `claim/json-broken.png`)
  - When each carrier is verified
  - Then, per file, `validation_state` equals c2patool's; and after
    *normalising both sides by the divergences already recorded* —
    theirs minus the codes this verifier does not emit yet
    (`signingCredential.untrusted`, M5; `assertion.required.missing`,
    `assertion.action.redacted`, later specs); ours with
    `assertion.dataHash.malformed` read as `assertion.dataHash.mismatch`
    (SPEC-012 AC5/AC6), `algorithm.unsupported` read as the
    `.mismatch` of its box (`assertion.hashedURI.mismatch` for an
    assertion entry, SPEC-011 AC7; `assertion.dataHash.mismatch` for the
    data hash, SPEC-012 AC7), and the codes c2patool cannot emit
    dropped (`general.error`; `assertion.undeclared`, where it exits) —
    our failure set is a **subset** of theirs for every file, and
    **equal** for every file where `checksPerformed` is complete and no
    status was dropped. The subset-only files are named in the test and
    are exactly the two decided divergences: the data hash skipped after
    a hashed-URI mismatch (decision 1: the AC4 four, `hashed-uri-changed`,
    `hashed-uris-two-changed`, `hashed-uri-truncated`, `hash-as-text`,
    `exclusions-too-many`, `claim-alg-sha1`) and a parse fault that
    stops this verifier where c2patool goes on (`json-broken`). The
    test prints file, ours and theirs side by side on failure, so that a
    future c2patool version's drift is legible

## References

- Specification: C2PA 2.4 §15.3 (the validation algorithm: locate the
  active manifest, validate the claim, its signature, its assertions,
  its hard binding — in that order), §15.2.1 (results as a consolidated
  set, one `validation_state`), §15.10.3 / §15.12.1 (the assertion and
  data-hash checks as SPEC-011/012 implemented them). Read 2026-09-21.
- Oracle: `c2patool 0.27.22` — every JSON under
  `tests/Fixtures/c2patool/` (steps 14, 21, 23, 24, 26); its exit
  messages on `fixture-unsigned.*` (`Error: No claim found`) and
  `jpeg/not-a-jpeg.bin` (`Error: Unsupported file type`), measured
  2026-09-21 in step 28's preparation; its behaviour after a signature
  failure (`hashed-uri-truncated.json`: `claimSignature.mismatch`,
  `assertion.hashedURI.mismatch`, `assertion.dataHash.mismatch`) and
  after a hashed-URI failure (`exclusions-overlap.json`: it evaluates
  the data hash anyway); the sister library's `ManifestStoreParser`
  (`provemark/content-credentials`, dev dependency since SPEC-010).
- Reasoned: continuing after a signature failure (the oracle does; a
  failure on top of a failure changes nothing; the reader learns more);
  the skip after a hashed-URI failure (SPEC-011 decision 1, Maurice,
  2026-09-21 — the one place this verifier reports *less* than c2patool,
  by design: a data hash from an unvouched assertion proves nothing
  either way); "no manifest" as `Invalid` without statuses rather than
  an error (the file was read and understood; it carries nothing); an
  unknown format as an error (the file was not understood).

## API sketch

```php
// namespace Provemark\C2paVerifier\Container;
final readonly class FormatDetector
{
    /** @param resource $stream  @return 'jpeg'|'png'|'webp'|null  the stream is rewound afterwards */
    public function detect($stream): ?string;
}

// namespace Provemark\C2paVerifier\Verifier;

final readonly class Verifier
{
    public function __construct(
        private FormatDetector $formats = new FormatDetector,
        private JpegManifestStoreExtractor $jpeg = new JpegManifestStoreExtractor,
        private PngManifestStoreExtractor $png = new PngManifestStoreExtractor,
        private WebpManifestStoreExtractor $webp = new WebpManifestStoreExtractor,
        private JumbfParser $jumbf = new JumbfParser,
        private ClaimSignatureCheck $signature = new ClaimSignatureCheck,
        private HashedUriCheck $hashedUris = new HashedUriCheck,
        private DataHashCheck $dataHash = new DataHashCheck,
    ) {}

    /** @param resource $stream  readable and seekable */
    public function verify($stream): VerificationReport;
}

final readonly class VerificationReport
{
    public function __construct(
        public string $format,          // jpeg | png | webp | unknown
        public bool $hasManifest,
        public ?ManifestStore $store,
        public ValidationResult $result,
    ) {}

    /** @return array<string, mixed>  c2patool's five keys plus format, has_manifest, checks_performed */
    public function toArray(): array;
    public function toJson(): string;
}

// namespace Provemark\C2paVerifier\Manifest;   (SPEC-007 amendment 3)
final class ManifestException extends \RuntimeException
{
    public function __construct(string $message, public readonly StatusCode $status = StatusCode::GeneralError, ?\Throwable $previous = null, public readonly ?string $url = null) {}
}
```

## Open questions

- Non-blocker: whether `verify()` should also accept a path. No: the
  caller opens the file, the verifier never does I/O it was not handed;
  a convenience wrapper can live in the CLI spec.
- Non-blocker: the name of the no-manifest outcome. `hasManifest` false
  with an `Invalid` result, rather than a third `ValidationState`: c2pa
  has no such state, and a consumer that only looks at
  `validation_state` must not see `Valid`.
- Non-blocker: AC10's list of "not yet emitted" codes will shrink with
  M5 and M6; each of those specs amends this criterion when it starts
  emitting one.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
