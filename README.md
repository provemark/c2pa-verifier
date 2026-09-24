# c2pa-verifier

A verifier for [C2PA](https://c2pa.org) Content Credentials in pure PHP.

> **First version. Read this before you rely on it.**
>
> This code is thoroughly tested and has never been used. Those are two
> different things and both are true. Every fixture in this repository is
> measured against `c2patool`, its answers have been compared with a second
> implementation in Go and a third in Python, and 111 named obligations of
> the C2PA specification have been walked one by one — but it has never run
> outside this repository:
> nobody has yet pointed it at their own files, their own trust list or
> their own hosting.
>
> So treat a verdict as something to check, not as an answer. If you are
> going to use it, verify the same file with `c2patool` as well and open an
> issue where the two differ. **That comparison is the single most useful
> thing anyone can send back**, and it is the one thing this project cannot
> do for itself.
>
> What is already known to be missing is written down rather than left to
> be discovered: [`docs/conformance.md`](docs/conformance.md) lists 14
> named gaps, and [`docs/comparison.md`](docs/comparison.md) every place
> this verifier and `c2patool` answer differently, and why.
>
> It is written with Claude Code, under a method that is itself part of the
> evidence: a specification before every feature, tests seen failing before
> they pass, a traceability table the build enforces, and every change of
> plan amended and confirmed rather than made quietly. See
> [How this is built](#how-this-is-built) — and judge the method, not the
> tool.

It reads the manifest store out of a JPEG, PNG, WebP or ISOBMFF file
(MP4, MOV, AVIF and HEIC, each held by a fixture here), checks the claim
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

Milestones M0–M8 are done: the verifier reads JPEG, PNG and WebP and, since
M8, ISOBMFF — MP4, MOV, AVIF, HEIC and fragmented DASH streams — then JUMBF
and CBOR, claim v1 and v2, and verifies COSE signatures (ES256/384/512,
PS256/384/512, Ed25519), the hashed URIs, the data hash and the BMFF hash
(`v2` and `v3`), the certificate profile and chain, and the timestamp —
closing with `Trusted`, `Valid` or `Invalid`. It also follows a file's
provenance backwards: the manifests an ingredient assertion names are
validated in their own right, update manifests included, and a fault in one
of them is reported against the ingredient that brought it in rather than
hidden.

Since SPEC-030 it also reads the **OCSP responses a signer staples into its
own signature**, so a certificate its own manifest reports as revoked is no
longer called trusted — and every file says whether revocation was checked
at all.

Not yet: revocation that needs the network (an online OCSP query, an AIA
fetch, a CRL), which this verifier will not make, and the assertion-content
rules beyond the actions and ingredient assertions.
[`docs/conformance.md`](docs/conformance.md) is the honest version of that
sentence: all 111 applicable obligations of C2PA 2.4, one by one, with what
this verifier does about each and what the 14 gaps would cost.
[`docs/milestones.md`](docs/milestones.md) has the plan and every step;
[`NOTES.md`](NOTES.md) the record; [`docs/comparison.md`](docs/comparison.md)
what it does, does not do, and where it differs from `c2patool`, measured.

```bash
composer require provemark/c2pa-verifier
```

The current tag is **`v0.2.0`**, still a `0.x` on purpose. `^0.2` receives
every 0.2.x fix, and a change that breaks the API below will be `0.3.0`.
Coming from `0.1.0`: one thing broke. A top-level `trust.allowed_list` in
the settings is refused now, and belongs inside a `trust.anchors` entry
(see `CHANGELOG.md`).

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

Trust settings are the JSON shape `c2patool --settings` reads, every value
the *contents* of a file, never a path:

- `trust.anchors`: the shape of `c2patool` 0.28 and later, a list of
  entries. Each entry has `trust_anchors` (PEM), a `trust_kind` and,
  optionally, its own `allowed_list` and `trust_config`. **Every entry
  counts only for its own kind** (C2PA 2.4 §14.4): `"manifest"` anchors
  signers, `"tsa"` anchors timestamp authorities, and `"cawg"` anchors
  nothing here. An entry's `trust_config` widens the accepted EKUs only for
  a chain that reaches that entry.
- `trust.trust_anchors`: the older single PEM string, still read. It
  anchors signers and timestamp authorities both.
- `trust.trust_config` (EKU OIDs) and `verify.verify_trust`.

A top-level `trust.allowed_list` is **refused**, with a message saying
where it belongs. `c2patool` 0.28 moved it into the entries and ignores a
loose one without a word. No list is bundled: which roots you trust is
your decision.

Everything the verifier says about a file comes from the file. Values
in the report (labels, explanations, URLs) are untrusted text until you
escape them.

### From the shell

```sh
bin/c2pa-verify photo.jpg --settings trust.settings.json   # vendor/bin/c2pa-verify once installed
```

The same report as `toJson()`, on standard output; the verdict in the exit
status — **0** `Trusted` or `Valid`, **1** `Invalid` (the report is still
printed), **2** no report (a usage fault, a file that cannot be opened,
settings that cannot be read or are not trust settings — one `Error: …`
line on standard error). Two deliberate differences from `c2patool`: it
exits 0 on an `Invalid` report, and it silently ignores a `--settings`
file that does not exist; both are fail-open (SPEC-019).

## Trying it, and what to send back

If you are reading this because you might use it, the most valuable thing
you can do takes about a minute per file — `composer require
provemark/c2pa-verifier`, and then:

1. **Run both.** Verify your file here and with
   `c2patool <file> --settings <your trust settings>`, on the same file and
   the same settings, and compare `validation_state` and the status codes.
2. **Send the difference, not the file** — the format and where the file
   came from (which tool signed it), the two verdicts side by side, and the
   status codes each gave. A file you can share helps, but is not needed to
   start, and never send anything you would not publish.
3. **Say what your host is.** PHP version, whether `openssl` is available,
   and how large the files are. This library exists for hosts nobody tests
   on, so a report from cheap shared hosting is worth more than one from a
   laptop.

Known divergences are already recorded in
[`docs/comparison.md`](docs/comparison.md) — if yours is on that list, it is
expected and explained; if it is not, it is news, and worth an issue.

Two divergences are deliberate and will not change: this verifier trusts a
timestamp authority only when you configure an anchor for it (`c2patool`
falls back to your operating system's trust store), and it reports on every
file whether revocation was checked, which `c2patool` does not.

## Public API

Eleven classes are the contract. Their public members are what this package
promises; a release may add to them, and will not remove or rename them
without saying so.

| class | what it is for |
|---|---|
| `Verifier\Verifier` | the one call: `verify($stream, $settings)` |
| `Verifier\FragmentedVerifier` | a DASH init segment and its fragments as one verdict: `verify($init, $fragments, $settings)`, one open fragment stream at a time |
| `Verifier\VerificationReport` | what comes back: `$result`, `$format`, `$hasManifest`, `$remoteManifestUrl`, `$signatureInfo`, `toArray()`, `toJson()` |
| `Report\ValidationResult` | the verdict and the statuses behind it |
| `Report\ValidationStatus` | one status: its code, the JUMBF URI it concerns, a sentence |
| `Report\ValidationState` | `Trusted`, `Valid`, `Invalid` |
| `Report\StatusCode` | the C2PA 2.4 §15 vocabulary, verbatim |
| `Trust\TrustSettings` | the trust file, through `fromJson()` |
| `Trust\TrustAnchorSet` | one `trust.anchors` entry, as `TrustSettings::$anchorSets` holds it |
| `Trust\TrustException` | the one exception that reaches you: settings that are not settings |
| `Cli\Command` | what `bin/c2pa-verify` runs |

**Everything else in `src/` is marked `@internal`, and may change in any release**
— the container extractors, the JUMBF and CBOR readers, the COSE
and ASN.1 layers, the certificate and timestamp code, and the exception
types those layers throw. They are public because each layer is tested on
its own, not because they are supported. Your IDE and PHPStan will tell you
which you are looking at: open the class, read the docblock.

One of them is worth naming, because it is useful and easy to reach:
`$report->store` is the parsed manifest store, and it is `@internal`. It
works, it will keep working, and it is not part of the promise — reading it
reaches the whole parse model, which will change as this verifier gains
formats. If you need something from it that the report does not give you,
that is worth an issue rather than a workaround.

The surface is recorded in `tests/Fixtures/api/public-surface.txt` and
checked on every run: `bin/api-check.php` is a step of `composer check`, and
so runs in CI on all three PHP versions. A symbol cannot join or leave the
contract by accident, and the list of contract classes lives in that script
alone.

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
  five fixture corpora — 93 files: this project's own signed variants,
  `c2pa-org/public-testfiles`, `c2pa-rs`'s own fixtures, seven files from
  other writers, and a matrix of every signature algorithm in every
  container — are run as drift alarms in every test run. A second, independent implementation — the
  Go verifier `richardwooding/c2pa` — has been run over the same files,
  to catch what agreeing with one oracle can hide.
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
composer check      # spec-check, api-check, Pint (test mode), PHPStan level max, Deptrac, Pest
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
by Maurice van Loon. That is said here rather than left to be noticed, and
what matters is not the tool but the method it was held to. Every rule below
is checkable in this repository — that is the point of stating them.

- **A specification before any code.** Each feature starts as a document in
  [`specs/`](specs/) with a status; no implementation may be written while
  it is `draft`, and only the maintainer moves it to `approved`. There are
  31 of them.
- **A traceability table per specification**, naming the test for every
  acceptance criterion. `bin/spec-check.php` fails the build if a spec
  claims to be `implemented` and a row is empty, so the link cannot rot.
- **Tests written first and seen failing**, with the failing output quoted
  in the step's note. A test that was never red does not count as evidence
  that anything works.
- **One concept per step, explained and approved before it is built.**
  [`AI-LOG.md`](AI-LOG.md) records every session: the model, what was
  asked, what was produced, what was measured *with the command*, what was
  merely reasoned from reading, and which decisions the maintainer took.
  The distinction between measured and reasoned is kept in every note.
- **Changes of plan are written down, not made quietly.** When a
  specification turned out to be wrong, it was amended, numbered, weighed
  and confirmed by the maintainer before anything went green — 118 times so
  far: 115 confirmed, 3 written with SPEC-036 and awaiting confirmation.
  [`NOTES.md`](NOTES.md) is the running record; each step has its own
  note in [`notes/`](notes/), written for someone who was not there.
- **Independent oracles, not self-agreement.** Every verdict is measured
  against `c2patool` 0.27.22 on recorded fixtures, compared file by file
  with its successor 0.28.0, where each difference has been examined and
  named, and with a second implementation in Go and a third in Python.
- **What is missing is published too**, in
  [`docs/conformance.md`](docs/conformance.md): 111 obligations of the
  specification, one by one, including the 14 this verifier does not yet
  meet.

What none of that is: an independent security audit. Nobody outside this
project has reviewed the code, which is the other half of the notice at the
top of this file.

The assistant is not listed as an author in commit metadata; this section
and the log are the disclosure.

## Licence

MIT. See [`LICENSE`](LICENSE). Test files under `tests/Fixtures/` carry
their own licences and attributions, named in the README of each corpus.
