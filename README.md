# c2pa-verifier

A verifier for [C2PA](https://c2pa.org) Content Credentials in pure PHP.

It reads the manifest store out of a file and checks the signature, the hash
binding, the certificate chain against a trust list, and the timestamp, and
returns a verdict that means the same as `c2patool`'s. It does not sign, it
holds no keys, and it opens no network connection while verifying.

It is for hosts that can run **no** second process, **no** native PHP
extension and **no** binary — cheap shared hosting, which is where most
WordPress and Drupal sites live. The existing PHP routes to C2PA
verification (a signing service over HTTP, `ericmann/ext-c2pa`, shelling out
to `c2patool`) do not reach those hosts. This one is meant to.

## Status

**M0: skeleton.** Nothing verifies anything yet. `src/` is empty on purpose;
the first feature, extracting the manifest store from a JPEG, starts as
SPEC-001 once the skeleton is done. See [`docs/milestones.md`](docs/milestones.md)
for the plan and [`NOTES.md`](NOTES.md) for the record.

## Design rules

- **Read and verify only.** No signer, ever. No private key enters this
  repository, test key or otherwise; public test certificates do.
- **Pure PHP `^8.3`.** `strict_types`, `final`, `readonly` value objects,
  PHPStan level max, Deptrac boundaries. No `ext-*` beyond `openssl`,
  `mbstring` and opt-in `sodium`. No `exec`, no network in the verification
  path.
- **c2patool's verdict, verbatim.** `validation_state` (`Valid` / `Invalid`
  / `Trusted`) and the C2PA 2.4 §15 status codes (`claimSignature.validated`,
  `assertion.hashedURI.mismatch`, `signingCredential.untrusted`, …) are the
  only vocabulary. Trust settings use the same JSON shape `c2patool` reads.
- **No trust by name.** Only a chain that cryptographically reaches an anchor
  counts.
- **Fail closed.** Unknown box, unknown algorithm, unknown claim version,
  missing `x5chain`: `Invalid` with a status code, never a silent `Valid`.
  A verifier that wrongly says `Valid` is worse than one that errors.
- **Bounded input.** Every parser has hard limits and acceptance criteria for
  malformed input. Manifest values are untrusted until proven otherwise.

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
of that a script can, and is the first step of `composer check`.

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

MIT. See [`LICENSE`](LICENSE).
