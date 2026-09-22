# c2pa-verifier

A verifier for [C2PA](https://c2pa.org) Content Credentials in pure PHP.

It reads the manifest store out of a JPEG, PNG or WebP, checks the claim
signature, the hash binding to the asset, the certificate chain against a
trust list you supply, and the RFC 3161 timestamp, and returns a verdict that
means the same as [`c2patool`](https://github.com/contentauth/c2pa-rs)'s —
the same `validation_state`, the same C2PA 2.4 §15 status codes. It does
not sign, it holds no keys, and it opens no network connection while
verifying.

It is for hosts that can run **no** second process, **no** native PHP
extension and **no** binary — shared hosting, which is where most
WordPress and Drupal sites live. The other PHP routes to C2PA verification
(a signing service over HTTP, `ericmann/ext-c2pa`, shelling out to
`c2patool`) do not reach those hosts. This one is meant to.

## Status

Milestones M0–M6 are done: the verifier reads the three containers,
JUMBF and CBOR, claim v1 and v2, verifies COSE signatures (ES256/384/512,
PS256/384/512, Ed25519), the hashed URIs and the data hash, the
certificate profile and chain, and the timestamp — and closes with
`Trusted`, `Valid` or `Invalid`. Not yet: ingredient manifests (M7 — a
store with more than one manifest is refused, on purpose), ISOBMFF video
(M8), and the assertion-content rules beyond the actions assertion.
[`docs/milestones.md`](docs/milestones.md) has the plan and every step;
[`NOTES.md`](NOTES.md) the record; [`docs/comparison.md`](docs/comparison.md)
what it does, does not do, and where it differs from `c2patool`, measured.

Nothing is published to Packagist yet and there is no tagged release.
The public API below may still move.

## Use

```php
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

$settings = TrustSettings::fromJson(file_get_contents('trust.settings.json')); // or null
$report = (new Verifier)->verify(fopen('photo.jpg', 'rb'), $settings);

$report->result->state->value;   // 'Trusted' | 'Valid' | 'Invalid'
$report->hasManifest;            // false: no Content Credentials in the file
$report->remoteManifestUrl;      // a manifest declared by URL, never fetched
$report->toArray();              // c2patool's shape: active_manifest, manifests,
                                 // validation_results, validation_state, validation_status,
                                 // plus format, has_manifest, checks_performed, signature_info.time
```

The verdict, as `c2patool` means it:

- **`Trusted`** — everything verified *and* the signer's certificate chains
  to an anchor in your trust settings (or is on your allowed list).
- **`Valid`** — everything verified, but the signer is not on your lists.
  Without settings this is the best any file can get.
- **`Invalid`** — something failed, and `validation_status` says what, with
  a §15 code, the JUMBF URI of the thing that failed, and a sentence.

Trust settings are the JSON shape `c2patool --settings` reads:
`trust.trust_anchors` (PEM, contents not paths), `trust.allowed_list`,
`trust.trust_config` (EKU OIDs), `verify.verify_trust`. No list is
bundled: which roots you trust is your decision, and a timestamp authority
is trusted only through those same anchors.

Everything the verifier says about a file comes from the file. Values
in the report (labels, explanations, URLs) are untrusted text until you
escape them.

## Design rules

- **Read and verify only.** No signer, ever. No private key enters this
  repository, test key or otherwise; public test certificates do. The
  tooling that builds test variants signs with throw-away keys that are
  deleted before the script ends.
- **Pure PHP `^8.3`.** `strict_types`, `final`, `readonly` value objects,
  PHPStan level max, Deptrac boundaries. No `ext-*` beyond `openssl` and
  `mbstring` (`sodium` opt-in for Ed25519). No `exec`, no network in the
  verification path. No dependencies: CBOR, JUMBF, COSE, DER and the
  timestamp are read by code in this repository (ADR-0001, ADR-0003,
  ADR-0004).
- **`c2patool`'s verdict, verbatim.** `validation_state` and the C2PA 2.4
  §15 status codes are the only vocabulary; where this verifier differs
  from `c2patool` it is stricter by a named rule, never more lenient, and
  four fixture corpora (75 files from nine writers) are run as drift
  alarms in every test run.
- **No trust by name.** Only a chain that cryptographically reaches an
  anchor counts.
- **Fail closed.** Unknown box, unknown algorithm, unknown claim version,
  missing `x5chain`, no hard binding, no actions assertion: `Invalid` with
  a status code, never a silent `Valid`. A verifier that wrongly says
  `Valid` is worse than one that errors — and this project has found two
  such cases in itself, by asking what happens when something is
  *absent*; both are documented in [`SECURITY.md`](SECURITY.md) and closed.
- **Bounded input.** Every parser has hard limits and acceptance criteria
  for malformed input; 70 870 randomly mutated files have gone through the
  verifier without an exception escaping (`bin/fuzz.php`, replayable).

## Working on it

```bash
composer install
composer check      # spec-check, Pint (test mode), PHPStan level max, Deptrac, Pest
```

Every feature starts as a spec in [`specs/`](specs/) (template:
[`specs/TEMPLATE.md`](specs/TEMPLATE.md)), status `draft`. No implementation
code while it is `draft`; failing tests tagged `->group('SPEC-###')` before
the implementation; `implemented` only when the spec's Traceability table
names the test for every criterion. `bin/spec-check.php` enforces the parts
of that a script can, and is the first step of `composer check`. See
[`CONTRIBUTING.md`](CONTRIBUTING.md).

Architecture decisions are in [`docs/adr/`](docs/adr/).

## Relation to `provemark/content-credentials`

The sister library signs (through an isolated service) and reads (through
that service or `ext-c2pa`). This verifier is a third way to read, for hosts
the other two cannot reach. It ships its own report and depends on nothing;
the library may later add an adapter to it. The dependency, if any, points
from the library to the verifier, never the other way.

## How this is built

This verifier is written with Claude Code (Anthropic), directed and reviewed
by Maurice van Loon. Every contribution the assistant makes is recorded in
[`AI-LOG.md`](AI-LOG.md): the model, what was asked, what was produced, what
was measured and what was reasoned, and which decisions were taken by the
maintainer. The assistant is not listed as an author in commit metadata; this
section and the log are the disclosure.

## Licence

MIT. See [`LICENSE`](LICENSE). Test files under `tests/Fixtures/` carry
their own licences and attributions, named in the README of each corpus.
