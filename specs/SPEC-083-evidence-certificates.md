# SPEC-083: Evidence in the report — the certificates of `x5chain`

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-10-27                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-082 hands out the anchor a signer's chain reached, not the
certificates between the signer and that anchor. They are in the file, in
the claim signature's `x5chain` header (C2PA 2.4 §14.2: exactly one
credential across both header buckets; §14.5: `x5chain` as RFC 9360
defines it, its certificates untrusted input). Getting them out needs a
JUMBF, CBOR and COSE reader, which is the work an archive should not have
to redo to show later that the chain held. crJSON does not carry them
either: it summarises the leaf only (SPEC-076), and crJSON §3.7 says
further certificates of the chain "should not be extracted".

This verifier already reads them, bounded: `CoseSign1` takes at most 16
certificates (`DEFAULT_MAX_CHAIN`), each within a byte limit, and refuses a
leaf that is not an X.509 certificate (SPEC-008, SPEC-024).

This is the second of four specs that hand out the evidence behind a
verdict (candidate C1 of the revocation-and-evidence proposal, split by
Maurice's decision of 2026-10-27).

Measured on 2026-10-27 (the verifier at `15d378c`, `CoseSign1::$chain`):

- `timestamp/tsa-matrix/control.png`: two certificates, SHA-256
  `64b274043395a7d67c6c30387b3fc514e47b8c1912b0be487efda979c8fba5a9`
  (540 bytes, the leaf) and
  `6c431bd08d9ab0be5df53afe3372f25351eb0e6e6351f6a43ef5d5f98cb3520b`
  (523 bytes).
- `hostile/certificate-time-nul.jpg`: two certificates that COSE reads and
  X.509 refuses (`a certificate in x5chain could not be read`), SHA-256
  `fa7c16f8…` (651 bytes) and `3c4b6150…` (622 bytes).
- `cose/leaf-der-broken.png`, `cose/chain-empty.png`,
  `cose/x5chain-missing.png`: `CoseSign1` refuses the signature.
- `c2pa-rs/CACA.jpg`: two certificates in each of its two manifests.

## Scope

**In scope**

- `ManifestEvidence::$certificates`: the DER bytes of every certificate of
  that manifest's `x5chain`, exactly as the file carries them, in its
  order, the leaf first; a `list<string>`.
- The same manifests as `evidence()` holds (SPEC-082): the active one and
  each ingredient manifest judged.
- A certificate X.509 refuses is handed out as its bytes: the evidence is
  what the file carried, and the verdict already says it is faulty
  (`signingCredential.invalid`). When `CoseSign1` refuses the signature —
  no `x5chain`, an empty one, one over a limit, a leaf that is not X.509,
  any other COSE fault — the list is empty. Nothing new is refused.
- A new last constructor parameter of `ManifestEvidence`, optional, so the
  contract (SPEC-025) grows by one property, in the same minor as SPEC-081
  and SPEC-082.

**Out of scope** (each needs its own spec before it may be built)

- The time-stamping authority's certificates: they travel inside the
  token, which the third spec of C1 hands out whole.
- Which header bucket the chain came from: a concept of its own.
- The anchor's own certificate: the caller holds the lists, and SPEC-082
  names the anchor by its SHA-256.
- The certificates in `toArray()`, `toJson()`, `toCrJson()` or the CLI.

## Behavior

- **AC1 — the chain, byte for byte**
  - Given `timestamp/tsa-matrix/control.png`, verified with and without
    its settings
  - Then the active manifest's `certificates` holds two strings, whose
    SHA-256 are the two values above in that order, of 540 and 523 bytes,
    and they are the same with settings as without.

- **AC2 — bytes X.509 refuses are still the bytes**
  - Given `hostile/certificate-time-nul.jpg`
  - Then `certificates` holds the two strings measured above, and the
    verdict is unchanged (`signingCredential.invalid`, as before).

- **AC3 — each manifest its own chain**
  - Given `c2pa-rs/CACA.jpg` with `trust/full.settings.json`
  - Then both manifests in `evidence()` carry their own `certificates`,
    each equal to the `x5chain` of that manifest's signature.

- **AC4 — no chain to show** *(required: error / malformed input)*
  - Given `cose/leaf-der-broken.png`, `cose/chain-empty.png` and
    `cose/x5chain-missing.png`
  - Then the active manifest is in `evidence()` with `certificates` an
    empty list, and nothing throws.

- **AC5 — the evidence agrees with the file**
  - Given every corpus file, under no settings
  - Then for every manifest in `evidence()`, `certificates` equals the
    bytes of `CoseSign1::ofManifest()`'s chain when that reads, and is
    empty when it throws; no list holds more than 16 entries.

- **AC6 — the verdict does not move**
  - Given every corpus file under no settings, `trust/full.settings.json`
    and the programme's lists
  - Then `toJson()` and `toCrJson()` are byte-identical before and after
    this spec.

- **AC7 — the contract**
  - `ManifestEvidence :: property certificates` is in
    `public-surface.txt`; the surface test fails without it.

## References

- Specification: C2PA 2.4 §14.2 (one credential), §14.5 (`x5chain`,
  RFC 9360); crJSON 2.4 §3.7 (only the leaf summarised).
- Measured: the chains above, with `CoseSign1::ofManifest()` over the
  verifier at `15d378c` (2026-10-27); the fixture search for certificates
  X.509 refuses (the explanation `a certificate in x5chain could not be
  read`: `hostile/certificate-time-nul.jpg`, `integer-bound/serial-257.jpg`,
  `profile-ed25519/leaf-spki-params.png`).
- Oracle: none for the evidence itself; AC5 holds it to the COSE reader,
  and AC6 to this verifier before the change.

## API sketch

```php
namespace Provemark\C2paVerifier\Verifier;

final readonly class ManifestEvidence
{
    public function __construct(
        public string $label,
        public int $judgedAt,
        public string $timeSource,
        public ?AnchorEvidence $signer,
        public ?AnchorEvidence $tsa,
        /** @var list<string> DER, x5chain's order, the leaf first; empty when COSE refused it */
        public array $certificates = [],
    ) {}
}
```

## Open questions

None.

## Traceability

All in `tests/Unit/Verifier/EvidenceCertificatesTest.php`, group
`SPEC-083`, unless named otherwise. Seen red before the implementation (all
9 tests, on the missing property and the missing line in the recorded
surface). AC3 adds `c2pa-rs/ocsp.jpg` to `CACA.jpg`: the two manifests of
`CACA.jpg` turned out to share one signer's chain, so only a file whose
manifests differ shows that each gets its own (measured 2026-10-27: 35
corpus files have manifests with different chains).

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | AC1: the chain, byte for byte, the same with settings as without | src/Verifier/IngredientManifestCheck.php :: evidenceOf() |
| AC2                  | AC2: certificates X.509 refuses are still handed out as their bytes | src/Verifier/IngredientManifestCheck.php :: evidenceOf() |
| AC3                  | AC3: each manifest carries its own chain | src/Verifier/IngredientManifestCheck.php :: evidenceOf(), manifest(); src/Verifier/Verifier.php :: check() |
| AC4                  | AC4: no chain to show when COSE refuses the signature (3 datasets) | src/Verifier/IngredientManifestCheck.php :: evidenceOf() |
| AC5                  | AC5: over the corpus, the evidence agrees with the COSE reader | src/Verifier/IngredientManifestCheck.php :: evidenceOf(); src/Cose/CoseSign1.php :: ofManifest() |
| AC6                  | AC6: the reports carry no certificate bytes; measured over the corpus (step 462) | src/Verifier/ManifestEvidence.php |
| AC7                  | AC7: certificates is a contract property; tests/Unit/ApiSurfaceTest.php :: AC1: the recorded surface is exactly what the contract classes expose today | src/Verifier/ManifestEvidence.php :: $certificates; tests/Fixtures/api/public-surface.txt |
