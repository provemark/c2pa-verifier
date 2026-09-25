# Step 147 — A recipe for trust settings, not a bundled list

*2026-09-25. Documentation. No code or test changed.*

## Why

Step 146 showed that the official C2PA lists alone leave every claim v1
file with an expired signer `Invalid` here. Adobe Firefly is one example,
and Bing will be from 2026-10-01. The reason is that their timestamps come
from DigiCert responders under a root that is on neither list. A user
running this verifier on real files needs to know that, and how to set it
up, without the project deciding their trust for them.

## What was decided

Maurice van Loon chose a **recipe** over a bundled settings file, and
chose to recommend the DigiCert root in it:

- The README promises *"No list is bundled: which roots you trust is your
  decision."* The recipe keeps that promise. A copied list would go stale
  unnoticed, and the C2PA lists are CC BY 4.0.
- **DigiCert Trusted Root G4 is recommended as a `"tsa"` anchor**, with
  what that means said in one sentence, and the option to leave it out.

## What changed

- `docs/trust-settings.md` (new) covers:
  - the three files and where to get them;
  - the DigiCert root's SHA-256 fingerprint and how to check it;
  - why the root is a separate decision (ADR-0004; `c2pa-rs` skips TSA
    trust for claim v1);
  - a PHP snippet that builds split settings: the signer list as
    `"manifest"`, the TSA list and the DigiCert root as `"tsa"`;
  - the measured verdicts per writer.
- `README.md` points to the recipe. The sentence that `c2patool` *"falls
  back to your operating system's trust store"* is replaced by what step
  146 read in the source: `c2patool` accepts authorities you did not
  configure, and for a claim v1 it does not check their trust at all.

## Measured

- DigiCert's self-signed `DigiCertTrustedRootG4.crt.pem` from
  `cacerts.digicert.com` has SHA-256
  `55:2F:7B:…:99:88`, valid 2013-08-01 to 2038-01-15. As a `"tsa"`
  anchor it makes all seven Firefly files `Valid`, as the cross-certificate
  fixture did in step 146.
- The snippet's output is equal, as JSON, to the settings file measured
  with.
- Under that file, on step 141's 78 files:
  - `bin/c2pa-verify`, `c2patool` 0.27.22 and 0.28.0 agree on 63;
  - 6 agree with 0.27.22 only (the Trufo EKU, step 112);
  - 9 agree with 0.28.0's `Invalid` only, for a different reason (Bing).

  Every file gets the verdict of at least one `c2patool` version.
- `bin/package-check.php`: 308 files in the archive, every top-level path
  classified.
