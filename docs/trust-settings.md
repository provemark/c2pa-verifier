# Trust settings for real files: a recipe

This verifier bundles no trust list. Which roots you trust is your
decision, and a list copied into a package goes stale without anyone
noticing. This page shows how to build a settings file from the lists
the C2PA itself publishes. It adds one extra anchor for timestamp
authorities, and says what that choice means.

Measured on 2026-09-25 (`notes/step-146-official-trust-lists.md`). On 78
files from current writers taken from Wikimedia Commons, these settings
gave every file the same verdict as at least one of `c2patool` 0.27.22
and 0.28.0, and the same verdict as both for 63 of them.

## The three files

| file | from | what it anchors |
|---|---|---|
| `C2PA-TRUST-LIST.pem` | [`c2pa-org/conformance-public`, `trust-list/`](https://github.com/c2pa-org/conformance-public/tree/main/trust-list) (CC BY 4.0) | the certificate authorities the C2PA conformance programme accepts for signers |
| `C2PA-TSA-TRUST-LIST.pem` | the same directory | the timestamp authorities the programme accepts |
| `DigiCertTrustedRootG4.crt.pem` | [DigiCert](https://cacerts.digicert.com/DigiCertTrustedRootG4.crt.pem) | the timestamp authorities Adobe and Microsoft use, see below |

Check the DigiCert root before you use it. Its SHA-256 fingerprint is
`55:2F:7B:DC:F1:A7:AF:9E:6C:E6:72:01:7F:4F:12:AB:F7:72:40:C7:8E:76:1A:C2:03:D1:D9:D2:0A:C8:99:88`:

```sh
openssl x509 -in DigiCertTrustedRootG4.crt.pem -noout -fingerprint -sha256
```

## Why the DigiCert root is a separate decision

A signing certificate expires. A file signed while it was valid stays
valid only if a timestamp authority vouches for when it was signed. This
verifier trusts that vouching only from an authority you anchor
(ADR-0004). Otherwise any authority could place an expired or stolen
key's signature inside its validity.

Adobe Firefly and Microsoft Bing Image Creator timestamp with DigiCert
responders under **DigiCert Trusted Root G4**, and that root is not on the
C2PA's TSA list. `c2patool` accepts these timestamps anyway: for a claim of
version 1, `c2pa-rs` does not check the timestamp authority's trust at all
(`claim.rs`). This verifier does not do that. Without the DigiCert anchor,
every such file whose signer has expired is `Invalid` here: all seven
Firefly files measured, and Bing's from 2026-10-01, when its signer
expires.

Adding the root means: *I accept the time DigiCert's timestamp authorities
put on a signature.* Leave it out if you would rather see those files as
`Invalid` than trust that.

## Building the file

The settings file holds the contents of the PEM files, not their paths.
The C2PA lists become separate entries, so that the signer list anchors
no timestamp authority and the reverse (C2PA 2.4 §14.4):

```sh
php -r '
$entry = fn (string $file, string $kind): array =>
    ["trust_anchors" => file_get_contents($file), "trust_kind" => $kind];
echo json_encode([
    "verify" => ["verify_trust" => true],
    "trust" => ["anchors" => [
        $entry("C2PA-TRUST-LIST.pem", "manifest"),
        $entry("C2PA-TSA-TRUST-LIST.pem", "tsa"),
        $entry("DigiCertTrustedRootG4.crt.pem", "tsa"),
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
' > trust.settings.json

bin/c2pa-verify --settings trust.settings.json photo.jpg
```

`c2patool` 0.27.22 and 0.28.0 both read the same file.

## What to expect

On the 78 files of step 146, with this file:

| writer | verdict here |
|---|---|
| Gemini, ChatGPT, GPT Image 2 and 2.5, Google Pixel 10 Pro and Pro XL | `Trusted`: their signers reach a C2PA anchor. `c2patool` 0.28.0 says `Invalid` for the OpenAI files signed under Trufo (an EKU rule, `docs/comparison.md`) |
| Adobe Firefly, Adobe Lightroom | `Valid`: verified; Adobe's signing CA is not on the C2PA list |
| GPT Image 1 and 1.5 | `Invalid`: their signer has expired and the files carry no timestamp; GPT Image 1 also opens without an actions assertion. Both `c2patool` versions agree |
| Google Pixel 10 (two files) | `Invalid`: `assertion.dataHash.mismatch`, in both `c2patool` versions too: the image bytes no longer match what was signed |
| Microsoft Bing Image Creator | `Invalid`: this verifier does not yet read its hard binding (`c2pa.hash.boxes`) or its signature URI; see `docs/comparison.md` |

Refresh the two C2PA lists when the programme updates them. The date of
the copy you used is worth keeping next to the settings file.
