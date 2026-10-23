# The conformance harness

The C2PA Conformance Program (v0.2, *Additional Conformance Requirements
Against the Content Credentials Specification*, "Support of crJSON format
for evaluation of product with validation functionality") asks a product
with validation functionality for a test harness that takes four inputs
and answers in crJSON:

1. an asset to validate,
2. a (test) C2PA Trust List,
3. a (test) C2PA TSA Trust List,
4. a validation time (RFC 3339).

With this verifier that is one command:

```sh
bin/c2pa-verify --crjson \
  --trust-list C2PA-TRUST-LIST.pem \
  --tsa-trust-list C2PA-TSA-TRUST-LIST.pem \
  --at 2026-10-09T05:31:00Z \
  asset.jpg
```

| input | option | spec |
|---|---|---|
| the asset | the last argument | SPEC-019 |
| the C2PA Trust List | `--trust-list <pem>`: every certificate in the file is a signer anchor | SPEC-077 |
| the C2PA TSA Trust List | `--tsa-trust-list <pem>`: every certificate is a time-stamp authority anchor | SPEC-077 |
| the validation time | `--at <RFC 3339>`: used where no trusted time-stamp gives a time | SPEC-073 |
| crJSON out | `--crjson` | SPEC-074, SPEC-075, SPEC-076 |

Each list may be the PEM bundle or the same list in the programme's JSON
form (ETSI TS 119 602 List of Trusted Entities; `C2PA-TRUST-LIST.json`,
`C2PA-TSA-TRUST-LIST.json`). The JSON form also says when an anchor
stopped being trusted, should the programme remove one: this verifier
then does not count it for a signature judged after that time (C2PA 2.4
§14.4.1's `notAfter`; SPEC-079). The PEM bundle carries no dates.

The two lists stay apart: an anchor of the signer list never vouches for
a time-stamp authority, and the reverse (C2PA 2.4 §14.4.1, §14.4.2;
SPEC-031). `--settings` cannot be combined with them, so that there is
one source of trust. A list file without a certificate is refused.

The exit status is the report's: 0 for `Trusted` or `Valid`, 1 for
`Invalid`, 2 when there is no report. crJSON has no verdict field; a
reader works the verdict out from the status codes. Where the file holds
no manifest store, `manifests` is `[]` and the report's own failure is
written to standard error.

From PHP, the same:

```php
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;

$settings = TrustSettings::fromTrustLists(
    file_get_contents('C2PA-TRUST-LIST.pem'),
    file_get_contents('C2PA-TSA-TRUST-LIST.pem'),
);
$stream = fopen('asset.jpg', 'rb');
echo (new Verifier)->verify($stream, $settings, new DateTimeImmutable('2026-10-09T05:31:00Z'))->toCrJson();
```

Where this verifier's crJSON differs from `c2pa-rs`'s, and why, is in
`docs/comparison.md`, "crJSON values", and in SPEC-075 and SPEC-076.
